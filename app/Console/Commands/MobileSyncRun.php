<?php

namespace App\Console\Commands;

use App\Services\MobileSync\LocalSnapshot;
use App\Services\MobileSync\MobileInbox;
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
        if (config('mobile_sync.role') !== 'local') {
            $this->error('MOBILE_SYNC_ROLE is not "local" on this copy; nothing to do.');

            return 1;
        }
        if (empty(config('mobile_sync.cloud_url')) || strlen((string) config('mobile_sync.sync_key')) < 20) {
            $this->error('Set MOBILE_SYNC_CLOUD_URL and MOBILE_SYNC_KEY (20+ characters) in .env');

            return 1;
        }

        $business_id = config('mobile_sync.business_id');
        $location_id = config('mobile_sync.location_id');
        $inbox = new MobileInbox($business_id, $location_id);
        $ok = true;

        // 1. Collect.
        $response = $this->cloud()->get('/api/sync/inbox');
        if ($response->successful()) {
            $added = $inbox->store((array) $response->json());
            $this->line(sprintf('collected  customers %d  orders %d  payments %d', $added['customers'], $added['orders'], $added['payments']));
        } else {
            $ok = $this->failed('Collect', $response);
        }

        // 2. Report statuses (also marks orders whose sales order has been invoiced).
        $inbox->markInvoiced();
        $ack = $inbox->pendingAcks();
        if (! empty($ack['ids'])) {
            $ids = $ack['ids'];
            unset($ack['ids']);
            $response = $this->cloud()->post('/api/sync/ack', $ack);
            if ($response->successful()) {
                $inbox->ackDone($ids);
                $this->line('reported   '.count($ids).' status change(s)');
            } else {
                $ok = $this->failed('Report', $response);
            }
        }

        // 3. Push.
        $snapshot = (new LocalSnapshot($business_id, $location_id))->all();
        $response = $this->cloud()->post('/api/sync/push', $snapshot);
        if ($response->successful()) {
            foreach ((array) $response->json('result') as $set => $counts) {
                $this->line(sprintf('%-10s sent %5d  new %4d  changed %4d  removed %4d', $set, count($snapshot[$set]),
                    $counts['inserted'] ?? 0, $counts['updated'] ?? 0, $counts['switched_off'] ?? 0));
            }
        } else {
            $ok = $this->failed('Push', $response);
        }

        // 4. Copy every other local change to the cloud database (MOBILE_SYNC_MIRROR=true).
        if (config('mobile_sync.mirror')) {
            $ok = $this->call('mobile-sync:mirror') === 0 && $ok;
        }

        DB::table('system')->updateOrInsert(['key' => 'mobile_sync_last_run'], ['value' => json_encode([
            'at' => now()->toDateTimeString(), 'ok' => $ok,
        ])]);

        return $ok ? 0 : 1;
    }

    private function failed(string $step, $response): bool
    {
        $this->error($step.' failed: HTTP '.$response->status().' '.substr($response->body(), 0, 300));

        return false;
    }

    private function cloud()
    {
        return Http::baseUrl(config('mobile_sync.cloud_url'))
            ->withHeaders(['X-Sync-Key' => config('mobile_sync.sync_key')])
            ->acceptJson()
            ->timeout(120)
            ->retry(2, 3000, null, false);
    }
}
