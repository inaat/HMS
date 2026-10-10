<?php

namespace App\Console\Commands;

use App\Services\MobileSync\LocalSnapshot;
use App\Services\MobileSync\MobileInbox;
use App\Services\MobileSync\SyncStatus;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Local PC side of the order-booker sync (config/mobile_sync.php). Run it every 1-2 minutes from Windows Task
 * Scheduler. The local PC always calls the cloud, so no open port or static IP is needed here.
 *
 *   1. collect new orders, payments and customers from the cloud (they wait in Sell > Mobile orders)
 *   2. report statuses back (received / approved / rejected / invoiced)
 *   3. push bookers, products, stock, customers and unpaid invoices up
 */
class MobileSyncRun extends Command
{
    protected $signature = 'mobile-sync:run';

    protected $description = 'Order-booker sync with the cloud copy: collect orders and payments, report statuses, push stock and customers';

    public function handle()
    {
        $this->single = config('mobile_sync.role') === 'single';
        if (config('mobile_sync.role') !== 'local' && ! $this->single) {
            $this->error('MOBILE_SYNC_ROLE is not "local" or "single" on this copy; nothing to do.');

            return 1;
        }
        if (! $this->single && (empty(config('mobile_sync.cloud_url')) || strlen((string) config('mobile_sync.sync_key')) < 20)) {
            $this->error('Set MOBILE_SYNC_CLOUD_URL and MOBILE_SYNC_KEY (20+ characters) in .env');

            return 1;
        }

        // One run at a time: the Task Scheduler, every open POS page and "Sync now" may all ask at once.
        $lock = fopen(storage_path('app/mobile-sync-'.preg_replace('/\W/', '', DB::getDatabaseName()).'.lock'), 'c');
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            $this->line('A sync is already running.');

            return 0;
        }

        $business_id = config('mobile_sync.business_id');
        $location_id = config('mobile_sync.location_id');
        $inbox = new MobileInbox($business_id, $location_id);
        $this->errors = [];

        try {
            // 1. Collect.
            SyncStatus::progress('Collecting orders and payments from bookers', 5);
            $response = $this->send('GET', '/api/sync/inbox');
            if ($response->successful()) {
                $added = $inbox->store((array) $response->json());
                // Which phone each booker is logged in on (Sell > Mobile orders > Booker phones)
                if (is_array($response->json('sessions'))) {
                    DB::table('system')->updateOrInsert(['key' => 'mobile_sessions'], ['value' => json_encode($response->json('sessions'))]);
                }
                $this->line(sprintf('collected  customers %d  orders %d  payments %d  shop edits %d  visits %d', $added['customers'], $added['orders'], $added['payments'], $added['shop_edits'], $added['visits']));
                if (! $this->single) {
                    $this->downloadPhotos($inbox->missingPhotos());   // single: the photos are already on this server
                }
            } else {
                $this->failed('Collect', $response);
            }

            // 2. Report statuses (also marks orders whose sales order has been invoiced).
            SyncStatus::progress('Sending order statuses to bookers', 15);
            $inbox->markInvoiced();
            $ack = $inbox->pendingAcks();
            if ($inbox->customerUpdateAcks) {
                $ack['customer_updates'] = $inbox->customerUpdateAcks;
            }
            if ($inbox->visitAcks) {
                $ack['visits'] = $inbox->visitAcks;
            }
            if (! empty($ack['ids']) || ! empty($ack['customer_updates']) || ! empty($ack['visits'])) {
                $ids = $ack['ids'];
                unset($ack['ids']);
                $response = $this->send('POST', '/api/sync/ack', $ack);
                if ($response->successful()) {
                    $inbox->ackDone($ids);
                    $this->line('reported   '.count($ids).' status change(s)');
                } else {
                    $this->failed('Report', $response);
                }
            }

            // 3. Push.
            SyncStatus::progress('Sending products, stock and customers to bookers', 25);
            $snapshot = (new LocalSnapshot($business_id, $location_id))->all();
            $response = $this->send('POST', '/api/sync/push', $snapshot);
            if ($response->successful()) {
                LocalSnapshot::logoutsDone((array) $response->json('result.logged_out'));
                foreach (array_intersect_key((array) $response->json('result'), $snapshot) as $set => $counts) {
                    if (! is_array($snapshot[$set]) || ! isset($counts['inserted'])) {
                        continue;
                    }
                    $this->line(sprintf('%-10s sent %5d  new %4d  changed %4d  removed %4d', $set, count($snapshot[$set]),
                        $counts['inserted'] ?? 0, $counts['updated'] ?? 0, $counts['switched_off'] ?? 0));
                }
            } else {
                $this->failed('Push', $response);
            }

            // 4. Copy every other local change to the cloud database (MOBILE_SYNC_MIRROR=true); it reports its own
            // progress from 40% to 100%.
            if (config('mobile_sync.mirror') && ! $this->single) {
                SyncStatus::progress('Copying shop data to the cloud', 40);
                if ($this->call('mobile-sync:mirror') !== 0) {
                    $this->errors[] = json_decode((string) DB::table('system')->where('key', 'mobile_sync_last_mirror')->value('value'), true)['what'] ?? 'Copy failed';
                }
            }
            $offline = false;
        } catch (ConnectionException $e) {
            // No internet (or the cloud is down): stop here; everything waits and goes with the next sync.
            $offline = true;
            $this->errors[] = 'No internet or the cloud site is not reachable. Your work is safe and will be sent automatically when the internet is back.';
            $this->error(end($this->errors));
        } catch (\Throwable $e) {
            // Any other failure still ends the run, so the Cloud sync panel never stays on "Syncing…".
            $offline = false;
            $this->errors[] = 'Sync stopped: '.mb_substr($e->getMessage(), 0, 200);
            $this->error(end($this->errors));
            report($e);
        }

        $ok = empty($this->errors);
        DB::table('system')->updateOrInsert(['key' => 'mobile_sync_last_run'], ['value' => json_encode([
            'at' => now()->toDateTimeString(), 'ok' => $ok, 'offline' => $offline, 'error' => $ok ? null : implode(' | ', $this->errors),
        ])]);
        SyncStatus::done($ok, $ok ? 'Everything is up to date.' : implode(' | ', $this->errors), $offline);
        flock($lock, LOCK_UN);

        return $ok ? 0 : 1;
    }

    private $errors = [];

    /** MOBILE_SYNC_ROLE=single: this server is also the bookers' server (no shop PC, no HTTP hop). */
    private $single = false;

    /**
     * One step of the sync: an HTTP call to the cloud copy, or — single server — the same API code run here directly
     * (App\Http\Controllers\Api\MobileSyncController), returned in the same shape (successful / json / status / body).
     */
    private function send(string $method, string $path, array $data = [])
    {
        if (! $this->single) {
            return $method === 'GET' ? $this->cloud()->get($path, $data) : $this->cloud()->post($path, $data);
        }
        $action = ['/api/sync/inbox' => 'inbox', '/api/sync/ack' => 'ack', '/api/sync/push' => 'push'][$path];
        $request = \Illuminate\Http\Request::create($path, $method, $data);
        $response = app(\App\Http\Controllers\Api\MobileSyncController::class)->{$action}($request);
        $body = $response instanceof \Illuminate\Http\JsonResponse ? $response->getData(true) : [];

        return new class($body, $response->getStatusCode())
        {
            private $body;

            private $status;

            public function __construct($body, $status)
            {
                $this->body = $body;
                $this->status = $status;
            }

            public function successful()
            {
                return $this->status >= 200 && $this->status < 300;
            }

            public function status()
            {
                return $this->status;
            }

            public function body()
            {
                return json_encode($this->body);
            }

            public function json($key = null)
            {
                return $key === null ? $this->body : data_get($this->body, $key);
            }
        };
    }

    /** Shop photos bookers took are kept on the cloud; the PC keeps its own copy under public/uploads/booker. */
    private function downloadPhotos(array $paths): void
    {
        foreach ($paths as $path) {
            $response = $this->cloud()->accept('image/jpeg')->get('/api/sync/file', ['path' => $path]);
            if ($response->successful() && strlen($response->body()) > 0) {
                if (! is_dir(dirname(public_path($path)))) {
                    mkdir(dirname(public_path($path)), 0755, true);
                }
                file_put_contents(public_path($path), $response->body());
            }
        }
    }

    private function failed(string $step, $response): void
    {
        $message = $step.' failed: HTTP '.$response->status().' '.mb_substr((string) ($response->json('message') ?? $response->body()), 0, 200);
        $this->errors[] = $message;
        $this->error($message);
    }

    private function cloud()
    {
        return Http::baseUrl(config('mobile_sync.cloud_url'))
            ->withHeaders(['X-Sync-Key' => config('mobile_sync.sync_key')])
            ->acceptJson()
            ->connectTimeout(15)
            ->timeout(120)
            ->retry(2, 3000, function ($e) {
                // Retry a busy server, but give up at once when there is no connection at all.
                return ! $e instanceof ConnectionException;
            }, false);
    }
}
