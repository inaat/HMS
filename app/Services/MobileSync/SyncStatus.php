<?php

namespace App\Services\MobileSync;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the local PC's cloud sync (mobile-sync:run) is doing, for the "Cloud sync" panel and its progress bar, and
 * starting it in the background from the browser, so nobody has to run a command: every open POS page asks for a
 * sync every couple of minutes (layouts/partials/mobile_autosync) and Sync now asks at once.
 */
class SyncStatus
{
    const KEY = 'mobile_sync_progress';

    // A run that has not reported for this long has died (PC slept, PHP killed); a new one may start.
    const STALE_SECONDS = 300;

    /**
     * MOBILE_SYNC_ROLE=single: run mobile-sync:run inside this request ($option '--collect' / '--push' = one half).
     * Fills the booker tables (bookers, products, stock, customers) and brings their orders into Mobile orders.
     */
    public static function runSingle(string $option = ''): bool
    {
        @set_time_limit(300);
        try {
            return \Illuminate\Support\Facades\Artisan::call('mobile-sync:run', $option ? [$option => true] : []) === 0;
        } catch (\Throwable $e) {
            report($e);
            self::done(false, 'Sync stopped: '.mb_substr($e->getMessage(), 0, 200));

            return false;
        }
    }

    public static function progress(string $step, int $percent, string $detail = ''): void
    {
        self::put(['running' => true, 'step' => $step, 'percent' => max(0, min(100, $percent)), 'detail' => $detail]);
    }

    public static function done(bool $ok, string $message, bool $offline = false): void
    {
        self::put(['running' => false, 'ok' => $ok, 'offline' => $offline, 'message' => $message, 'percent' => 100]);
    }

    public static function get(): array
    {
        $p = json_decode((string) DB::table('system')->where('key', self::KEY)->value('value'), true) ?: [];
        $age = time() - strtotime($p['at'] ?? '2000-01-01');
        // A started sync reports its first step within seconds; still "Starting" after a minute means it never ran.
        if (! empty($p['running']) && ($age > self::STALE_SECONDS || (($p['step'] ?? '') === 'Starting' && $age > 60))) {
            $p['running'] = false;
            $p['ok'] = false;
            $p['message'] = 'The last sync stopped half way; it will start again.';
        }

        return $p;
    }

    /** Start mobile-sync:run in the background unless one is running. Returns whether it started. */
    public static function start(): bool
    {
        // Single server: no background process (shared hosting); run it now, it takes a few seconds with no HTTP hop.
        if (config('mobile_sync.role') === 'single') {
            return self::runSingle();
        }
        if (config('mobile_sync.role') !== 'local' || ! empty(self::get()['running'])) {
            return false;
        }
        self::progress('Starting', 1);

        $php = self::phpBinary();
        $artisan = base_path('artisan');
        $log = storage_path('logs/mobile-sync.log');
        if (PHP_OS_FAMILY === 'Windows') {
            pclose(popen('start "" /B "'.$php.'" "'.$artisan.'" mobile-sync:run >> "'.$log.'" 2>&1', 'r'));
        } else {
            exec(escapeshellarg($php).' '.escapeshellarg($artisan).' mobile-sync:run >> '.escapeshellarg($log).' 2>&1 &');
        }

        return true;
    }

    /** Everything the panel shows. */
    public static function snapshot(): array
    {
        $value = function ($key) {
            return json_decode((string) DB::table('system')->where('key', $key)->value('value'), true) ?: null;
        };

        return [
            'progress' => self::get(),
            'last_run' => $value('mobile_sync_last_run'),
            'last_mirror' => $value('mobile_sync_last_mirror'),
            'mirror_on' => (bool) config('mobile_sync.mirror'),
            'changes_waiting' => Schema::hasTable('sync_changes') ? DB::table('sync_changes')->count() : 0,
            'waiting_approval' => Schema::hasTable('mobile_inbox') ? DB::table('mobile_inbox')->where('status', 'waiting')->count() : 0,
        ];
    }

    private static function put(array $data): void
    {
        DB::table('system')->updateOrInsert(['key' => self::KEY], ['value' => json_encode($data + ['at' => now()->toDateTimeString()])]);
    }

    /** php.exe for the command line: Laragon keeps it next to the loaded php.ini (the web server may be Apache). */
    public static function phpBinary(): string
    {
        if (! empty(config('mobile_sync.php_bin'))) {
            return config('mobile_sync.php_bin');
        }
        $ini = php_ini_loaded_file();
        foreach ([$ini ? dirname($ini) : null, PHP_BINDIR] as $dir) {
            foreach (['php.exe', 'php'] as $name) {
                if ($dir && is_file($dir.DIRECTORY_SEPARATOR.$name)) {
                    return $dir.DIRECTORY_SEPARATOR.$name;
                }
            }
        }

        return 'php';
    }
}
