<?php

namespace App\Console\Commands;

use App\Services\MobileSync\SyncStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * One-way copy of the local database to the cloud copy's database (MOBILE_SYNC_MIRROR=true), so the cloud shows
 * all sales, stock and reports. Runs on the local PC from mobile-sync:run. Everything goes as SQL over HTTPS to
 * POST /api/sync/mirror/sql with the sync key; the cloud runs it (no Remote MySQL needed).
 *
 *  - Triggers on every local table write each insert / update / delete into sync_changes (table + id), whatever
 *    made the change (POS, imports, phpMyAdmin). Tables without a single-column key log the table only.
 *  - Each run turns just those rows into SQL: insert-or-update for rows that exist, delete for rows that are gone.
 *    A table logged without ids, or whose SQL the cloud rejects (columns changed...), is sent whole.
 *  - The first run (or --full) sends a mysqldump of everything in small compressed pieces.
 *
 * The cloud keeps its own tables (mb_* booker orders, migrations, sessions...). Its web screens are read-only
 * (App\Http\Middleware\CloudReadOnly) because the copy overwrites everything else.
 */
class MobileSyncMirror extends Command
{
    protected $signature = 'mobile-sync:mirror {--full : send the whole database again} {--dry-run : write the full dump to storage/mirror.sql instead}';

    protected $description = 'Send local database changes to the cloud database as SQL (one way, local -> cloud)';

    // Never copied: the cloud's own tables and the change log itself.
    const KEEP_ON_CLOUD = ['migrations', 'sessions', 'cache', 'cache_locks', 'jobs', 'failed_jobs', 'sync_changes', 'system'];

    // Rows per INSERT, and SQL bytes per request (before gzip).
    const BATCH = 200;

    const PIECE_BYTES = 2 * 1024 * 1024;

    private $from;

    public function handle()
    {
        if (config('mobile_sync.role') !== 'local') {
            $this->error('MOBILE_SYNC_ROLE is not "local" on this copy; nothing to do.');

            return 1;
        }
        if (! $this->option('dry-run') && (empty(config('mobile_sync.cloud_url')) || strlen((string) config('mobile_sync.sync_key')) < 20)) {
            $this->error('Set MOBILE_SYNC_CLOUD_URL and MOBILE_SYNC_KEY (20+ characters) in .env');

            return 1;
        }
        $this->from = config('database.connections.'.config('database.default'));
        // TIMESTAMP values travel in UTC both ways: mysqldump writes them in UTC (only its first piece says so),
        // the rows read here are read in UTC, and the cloud runs every piece in UTC.
        DB::statement("SET time_zone = '+00:00'");

        $this->installTriggers();
        $started = microtime(true);
        $checkpoint = DB::table('system')->where('key', 'mobile_mirror_checkpoint')->value('value');

        try {
            if ($this->option('dry-run')) {
                $file = fopen(storage_path('mirror.sql'), 'w');
                $this->dump($this->tables(), function ($sql) use ($file) {
                    fwrite($file, $sql);
                });
                fclose($file);

                return $this->finish(true, 'dump written to storage/mirror.sql', $started);
            }
            if ($this->option('full') || $checkpoint === null) {
                // Changes made while the dump runs are sent again by the next run; sending a row twice is harmless.
                $new_checkpoint = (int) DB::table('sync_changes')->max('id');
                // Progress = share of the table data already sent (tables are dumped in this order).
                $sizes = collect(DB::select('SELECT TABLE_NAME AS t, DATA_LENGTH AS s FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?', [$this->from['database']]))
                    ->pluck('s', 't');
                $tables = $this->tables();
                $total = max(1, array_sum(array_map(function ($t) use ($sizes) {
                    return (int) ($sizes[$t] ?? 0) + 16384;
                }, $tables)));
                $before = [];
                $sum = 0;
                foreach ($tables as $t) {
                    $before[$t] = $sum;
                    $sum += (int) ($sizes[$t] ?? 0) + 16384;
                }
                $current = $tables[0] ?? '';
                $report = function () use (&$current, $before, $total, $tables) {
                    $n = array_search($current, $tables) + 1;
                    SyncStatus::progress('First full copy of the shop data to the cloud', 40 + (int) (59 * ($before[$current] ?? 0) / $total),
                        "table {$n} of ".count($tables).": {$current}");
                };
                $report();
                $pieces = $this->dump($tables, function ($sql) use ($report) {
                    $this->send($sql);
                    $report();
                }, function ($table) use (&$current) {
                    $current = $table;
                });
                $this->setCheckpoint($new_checkpoint);

                return $this->finish(true, "full copy, {$pieces} pieces", $started);
            }

            return $this->finish(true, $this->sendChanges((int) $checkpoint), $started);
        } catch (\Throwable $e) {
            return $this->finish(false, $e->getMessage(), $started);
        }
    }

    /** Send the rows changed since $checkpoint. */
    private function sendChanges(int $checkpoint): string
    {
        $changes = DB::table('sync_changes')->where('id', '>', $checkpoint)->orderBy('id')->limit(20000)->get(['id', 'tbl', 'pk']);
        if ($changes->isEmpty()) {
            return 'no changes';
        }

        $rows = 0;
        $whole = [];
        $groups = $changes->groupBy('tbl');
        $done = 0;
        foreach ($groups as $table => $list) {
            SyncStatus::progress('Copying shop changes to the cloud', 40 + (int) (59 * $done++ / max(1, $groups->count())),
                $changes->count().' changes');
            $key = $this->singleKey($table);
            if (empty($key) || $list->contains('pk', null) || ! in_array($table, $this->tables())) {
                if (in_array($table, $this->tables())) {
                    $whole[] = $table;
                }
                continue;
            }
            try {
                $rows += $this->sendRows($table, $key, $list->pluck('pk')->unique()->values()->all());
            } catch (\Throwable $e) {
                // The cloud table no longer matches (new column, missing table...): send it whole.
                $whole[] = $table;
            }
        }
        if (! empty($whole)) {
            $this->dump($whole, function ($sql) {
                $this->send($sql);
            });
        }

        $last = (int) $changes->last()->id;
        $this->setCheckpoint($last);
        DB::table('sync_changes')->where('id', '<=', $last)->delete();

        return $changes->count().' changes, '.$rows.' rows'.(empty($whole) ? '' : ', whole: '.implode(', ', $whole));
    }

    /** The current local rows as insert-or-update SQL, and deletes for ids that are gone. */
    private function sendRows(string $table, string $key, array $ids): int
    {
        $pdo = DB::getPdo();
        $quote = function ($v) use ($pdo) {
            return $v === null ? 'NULL' : $pdo->quote((string) $v);
        };
        $count = 0;
        $sql = '';
        foreach (array_chunk($ids, self::BATCH) as $chunk) {
            $rows = DB::table($table)->whereIn($key, $chunk)->get()->map(function ($r) {
                return (array) $r;
            })->all();
            $gone = array_values(array_diff($chunk, array_column($rows, $key)));

            if (! empty($rows)) {
                $cols = array_keys($rows[0]);
                $values = array_map(function ($r) use ($quote) {
                    return '('.implode(',', array_map($quote, array_values($r))).')';
                }, $rows);
                // Insert-or-update, never REPLACE: REPLACE deletes first and would cascade to child rows.
                $sql .= "INSERT INTO `{$table}` (`".implode('`,`', $cols).'`) VALUES '.implode(',', $values)
                    .' ON DUPLICATE KEY UPDATE '.implode(',', array_map(function ($c) {
                        return "`{$c}`=VALUES(`{$c}`)";
                    }, $cols)).";\n";
                $count += count($rows);
            }
            if (! empty($gone)) {
                // MySQL fires no triggers for rows removed by ON DELETE CASCADE, so the children never reach
                // sync_changes: delete with foreign keys on and let the cloud cascade the same way.
                $sql .= "SET FOREIGN_KEY_CHECKS=1;\nDELETE FROM `{$table}` WHERE `{$key}` IN (".implode(',', array_map($quote, $gone)).");\nSET FOREIGN_KEY_CHECKS=0;\n";
                $count += count($gone);
            }
            if (strlen($sql) > self::PIECE_BYTES) {
                $this->send($sql);
                $sql = '';
            }
        }
        if ($sql !== '') {
            $this->send($sql);
        }

        return $count;
    }

    /**
     * mysqldump the given tables (structure + data), converted so MariaDB on shared hosting loads a MySQL 8 dump,
     * handed to $out in pieces of whole statements. Returns the number of pieces.
     */
    private function dump(array $tables, callable $out, ?callable $onTable = null): int
    {
        $cmd = array_merge([$this->mysqlBin().'mysqldump', '--host='.$this->from['host'], '--port='.$this->from['port'], '--user='.$this->from['username'],
            '--single-transaction', '--quick', '--skip-lock-tables', '--skip-add-locks', '--no-tablespaces', '--skip-triggers',
            '--skip-routines', '--skip-events', '--set-gtid-purged=OFF', '--default-character-set=utf8mb4', '--hex-blob',
            '--net-buffer-length=1000000', $this->from['database'], ], $tables);

        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge(getenv(), ['MYSQL_PWD' => (string) $this->from['password']]));
        if (! is_resource($proc)) {
            throw new \Exception('Cannot start mysqldump');
        }

        $piece = '';
        $pieces = 0;
        while (($line = fgets($pipes[1])) !== false) {
            // mysqldump saves session settings into @OLD_* / @saved_cs_client and restores them later; pieces run
            // in separate sessions, so those variables would be NULL there. The cloud sets each session itself.
            if (strpos($line, '@OLD_') !== false || strpos($line, '@saved_cs_client') !== false) {
                continue;
            }
            if ($onTable && preg_match('/^-- Table structure for table `(\w+)`/', $line, $m)) {
                $onTable($m[1]);
            }
            $piece .= $this->forMariaDb($line);
            // Every mysqldump statement ends a line with ";" — cut pieces only there.
            if (strlen($piece) >= self::PIECE_BYTES && substr(rtrim($line), -1) === ';') {
                $out($piece);
                $piece = '';
                $pieces++;
            }
        }
        if (trim($piece) !== '') {
            $out($piece);
            $pieces++;
        }
        $err = trim(preg_replace('/^.*Using a password.*$\n?/m', '', stream_get_contents($pipes[2])));
        if (proc_close($proc) !== 0) {
            throw new \Exception('mysqldump failed: '.$err);
        }

        return $pieces;
    }

    private function send(string $sql): void
    {
        $response = Http::baseUrl(config('mobile_sync.cloud_url'))
            ->withHeaders(['X-Sync-Key' => config('mobile_sync.sync_key')])
            ->acceptJson()
            ->timeout(300)
            ->retry(3, 5000, function ($e) {
                return $e instanceof \Illuminate\Http\Client\ConnectionException;
            }, false)
            ->post('/api/sync/mirror/sql', ['sql' => base64_encode(gzencode($sql, 6))]);

        if (! $response->successful()) {
            throw new \Exception('cloud: HTTP '.$response->status().' '.mb_substr((string) ($response->json('message') ?? $response->body()), 0, 400));
        }
    }

    /**
     * Change-log triggers on every copied table. Re-checked each run, so tables added by later migrations are
     * covered too.
     */
    private function installTriggers(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('sync_changes')) {
            DB::statement('CREATE TABLE `sync_changes` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `tbl` VARCHAR(64) NOT NULL,
                `pk` VARCHAR(64) NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB COMMENT=\'Rows changed locally, waiting to be copied to the cloud (mobile-sync:mirror)\'');
        }

        $existing = collect(DB::select('SELECT TRIGGER_NAME AS n FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ?', [$this->from['database']]))
            ->pluck('n')->flip();

        foreach ($this->tables() as $table) {
            $key = $this->singleKey($table);
            foreach (['ins' => 'INSERT', 'upd' => 'UPDATE', 'del' => 'DELETE'] as $suffix => $event) {
                $name = substr('msync_'.$table, 0, 58).'_'.$suffix;
                if ($existing->has($name)) {
                    continue;
                }
                $row = $event === 'DELETE' ? 'OLD' : 'NEW';
                $value = $key ? "{$row}.`{$key}`" : 'NULL';
                $body = "INSERT INTO `sync_changes` (`tbl`, `pk`) VALUES ('{$table}', {$value});";
                if ($event === 'UPDATE' && $key) {
                    // A changed key: the old id is gone on the cloud too.
                    $body = "BEGIN {$body} IF NOT (OLD.`{$key}` <=> NEW.`{$key}`) THEN INSERT INTO `sync_changes` (`tbl`, `pk`) VALUES ('{$table}', OLD.`{$key}`); END IF; END";
                }
                DB::unprepared("CREATE TRIGGER `{$name}` AFTER {$event} ON `{$table}` FOR EACH ROW {$body}");
            }
        }
    }

    /** Local base tables that are copied. */
    private function tables(): array
    {
        static $tables = null;
        if ($tables === null) {
            $tables = collect(DB::select("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'", [$this->from['database']]))
                ->pluck('t')
                ->reject(function ($t) {
                    return in_array($t, self::KEEP_ON_CLOUD) || strpos($t, 'mb_') === 0;
                })
                ->values()->all();
        }

        return $tables;
    }

    private function singleKey(string $table): ?string
    {
        static $keys = null;
        if ($keys === null) {
            $keys = collect(DB::select("SELECT TABLE_NAME AS t, MAX(COLUMN_NAME) AS c, COUNT(*) AS n FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = ? AND CONSTRAINT_NAME = 'PRIMARY' GROUP BY TABLE_NAME", [$this->from['database']]))
                ->filter(function ($r) {
                    return (int) $r->n === 1;
                })->pluck('c', 't');
        }

        return $keys[$table] ?? null;
    }

    private function setCheckpoint(int $id): void
    {
        DB::table('system')->updateOrInsert(['key' => 'mobile_mirror_checkpoint'], ['value' => (string) $id]);
    }

    private function finish(bool $ok, string $what, float $started): int
    {
        $seconds = round(microtime(true) - $started, 1);
        DB::table('system')->updateOrInsert(['key' => 'mobile_sync_last_mirror'], ['value' => json_encode([
            'at' => now()->toDateTimeString(), 'ok' => $ok, 'what' => mb_substr($what, 0, 300), 'seconds' => $seconds,
        ])]);
        $ok ? $this->line("mirror     {$what} ({$seconds} s)") : $this->error("mirror     failed: {$what}");

        return $ok ? 0 : 1;
    }

    /** MySQL 8 dump -> loadable by MariaDB (shared hosting) as well as MySQL. */
    private function forMariaDb(string $line): string
    {
        if (strpos($line, '0900_') !== false) {
            $line = str_replace(['utf8mb4_0900_ai_ci', 'utf8mb4_0900_as_cs', 'utf8mb4_0900_as_ci', 'utf8mb4_0900_bin'], 'utf8mb4_unicode_ci', $line);
        }
        // No ngram parser on MariaDB: keep a plain word index under the name ProductUtil knows for that case.
        if (strpos($line, 'ngram') !== false && strpos($line, 'FULLTEXT') !== false) {
            $line = str_replace(['`ft_products_name_sku`', '/*!50100 WITH PARSER `ngram` */'], ['`ft_products_name_sku_word`', ''], $line);
        }

        return $line;
    }

    /** mysqldump from the same folder as the running MySQL server (Laragon) when not on PATH. */
    private function mysqlBin(): string
    {
        $dir = config('mobile_sync.mysql_bin');
        if (! empty($dir)) {
            return rtrim($dir, '\\/').DIRECTORY_SEPARATOR;
        }
        $base = DB::selectOne('SELECT @@basedir AS d')->d ?? '';
        $guess = rtrim($base, '\\/').DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR;

        return is_file($guess.'mysqldump'.(PHP_OS_FAMILY === 'Windows' ? '.exe' : '')) ? $guess : '';
    }
}
