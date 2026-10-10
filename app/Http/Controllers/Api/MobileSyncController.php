<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cloud side of the local PC <-> cloud link (header X-Sync-Key, see App\Http\Middleware\MobileSync).
 *
 *   POST /api/sync/push   local sends full snapshots of bookers, products, customers, unpaid invoices
 *   GET  /api/sync/inbox  local collects orders, payments and customers bookers sent
 *   POST /api/sync/ack    local reports what happened to them (received / approved / rejected / invoiced)
 */
class MobileSyncController extends Controller
{
    const ORDER_STATUSES = ['received', 'approved', 'rejected', 'invoiced'];

    const PAYMENT_STATUSES = ['received', 'approved', 'rejected'];

    /**
     * Each set that is present is a complete snapshot: rows not in it are switched off. Only rows whose values
     * changed get a new updated_at, so the phones download just the real changes.
     */
    public function push(Request $request)
    {
        $result = [];

        DB::transaction(function () use ($request, &$result) {
            if (is_array($request->input('users'))) {
                $user_fields = ['username', 'password', 'name', 'code', 'allow_login', 'locations'];
                if (Schema::hasColumn('mb_users', 'can_edit')) {
                    $user_fields[] = 'can_edit';
                }
                $result['users'] = $this->syncSet('mb_users', 'id', $request->input('users'), $user_fields, ['allow_login' => 0]);

                // A booker who is blocked or removed locally loses every phone login at once.
                $blocked = DB::table('mb_users')->where('allow_login', 0)->pluck('id');
                DB::table('mb_tokens')->whereIn('user_id', $blocked)->delete();
            }

            if (is_array($request->input('products'))) {
                $result['products'] = $this->syncSet('mb_products', 'variation_id', $request->input('products'),
                    ['product_id', 'name', 'sku', 'unit', 'units', 'category', 'brand', 'price', 'enable_stock', 'stock_qty', 'reserved_qty', 'loc_stock', 'loc_price', 'image', 'active'],
                    ['active' => 0]);
            }

            if (is_array($request->input('customers'))) {
                $customers = $request->input('customers');

                // A booker-made customer the local PC has created since: link it before matching on local_id.
                foreach ($customers as $c) {
                    if (! empty($c['uuid']) && ! empty($c['local_id'])) {
                        DB::table('mb_customers')->where('uuid', $c['uuid'])->whereNull('local_id')
                            ->update(['local_id' => (int) $c['local_id']]);
                    }
                }

                $result['customers'] = $this->syncSet('mb_customers', 'local_id', $customers,
                    ['name', 'business_name', 'mobile', 'address', 'city', 'credit_limit', 'balance_due', 'status',
                        'route_id', 'position', 'photo_url', 'outlet_type', 'outlet_class', 'visit_sequence'],
                    ['status' => 'deleted']);
            }

            if (is_array($request->input('routes')) && Schema::hasTable('mb_routes')) {
                $route_fields = ['name', 'location_id', 'days', 'booker_id', 'active'];
                if (Schema::hasColumn('mb_routes', 'booker_ids')) {
                    $route_fields[] = 'booker_ids';
                }
                $result['routes'] = $this->syncSet('mb_routes', 'id', $request->input('routes'), $route_fields, ['active' => 0]);
            }

            if (is_array($request->input('invoices'))) {
                $result['invoices'] = $this->syncSet('mb_invoices', 'id', $request->input('invoices'),
                    ['contact_id', 'invoice_no', 'transaction_date', 'final_total', 'paid', 'due', 'active'],
                    ['active' => 0]);
            }

            if (is_array($request->input('locations'))) {
                DB::table('mb_meta')->updateOrInsert(['key' => 'locations'], ['value' => json_encode($request->input('locations')), 'updated_at' => now()]);
            }

            // "Log out phone" from the office: drop every login of that booker (user_id "all" = every booker).
            $result['logged_out'] = [];
            foreach ((array) $request->input('logouts') as $l) {
                if (! empty($l['id']) && ! empty($l['user_id'])) {
                    $tokens = DB::table('mb_tokens');
                    if ($l['user_id'] !== 'all') {
                        $tokens->where('user_id', (int) $l['user_id']);
                    }
                    $tokens->delete();
                    $result['logged_out'][] = $l['id'];
                }
            }

            if (is_array($request->input('schemes'))) {
                DB::table('mb_meta')->updateOrInsert(['key' => 'schemes'], ['value' => json_encode($request->input('schemes')), 'updated_at' => now()]);
            }

            if (is_array($request->input('settings'))) {
                DB::table('mb_meta')->updateOrInsert(['key' => 'settings'], ['value' => json_encode($request->input('settings')), 'updated_at' => now()]);
            }

            DB::table('mb_meta')->updateOrInsert(['key' => 'last_push_at'], ['value' => now()->toDateTimeString(), 'updated_at' => now()]);
        });

        return response()->json(['success' => true, 'result' => $result, 'server_time' => now()->toDateTimeString()]);
    }

    public function inbox(Request $request)
    {
        $limit = min(500, max(1, (int) $request->input('limit', 200)));

        $orders = DB::table('mb_orders')->where('status', 'pending')->orderBy('id')->limit($limit)->get();
        $lines = DB::table('mb_order_lines')->whereIn('order_id', $orders->pluck('id'))->get()->groupBy('order_id');
        foreach ($orders as $order) {
            $order->lines = $lines->get($order->id, collect())->values();
        }

        $payments = DB::table('mb_payments')->where('status', 'pending')->orderBy('id')->limit($limit)->get();
        foreach ($payments as $payment) {
            $payment->allocations = json_decode($payment->allocations ?? '[]', true) ?: [];
        }

        $customers = DB::table('mb_customers')->where('status', 'pending')->whereNull('local_id')->orderBy('id')->get();

        // Shop edits from bookers (location, photo, phone...): the PC applies or queues them for approval.
        $customer_updates = Schema::hasTable('mb_customer_updates')
            ? DB::table('mb_customer_updates')->where('status', 'pending')->orderBy('id')->limit($limit)->get()
                ->map(function ($u) {
                    return ['uuid' => $u->uuid, 'user_id' => $u->user_id, 'photo' => $u->photo, 'created_at' => (string) $u->created_at]
                        + (json_decode($u->data, true) ?: []);
                })
            : [];

        $visits = Schema::hasTable('mb_visits')
            ? DB::table('mb_visits')->where('status', 'pending')->orderBy('id')->limit($limit)->get()
                ->map(function ($v) {
                    return ['uuid' => $v->uuid, 'user_id' => $v->user_id, 'photo' => $v->photo] + (json_decode($v->data, true) ?: []);
                })
            : [];

        // Which phone each booker is logged in on, for the office's "Booker phones" list.
        $sessions = DB::table('mb_tokens')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderBy('user_id')->orderByDesc('last_used_at')
            ->get(['user_id', 'device_name', 'created_at', 'last_used_at']);

        return response()->json(compact('customers', 'orders', 'payments', 'customer_updates', 'visits', 'sessions'));
    }

    public function ack(Request $request)
    {
        $now = now();
        $done = ['orders' => 0, 'payments' => 0, 'customers' => 0];

        DB::transaction(function () use ($request, $now, &$done) {
            foreach ((array) $request->input('customers', []) as $c) {
                if (! empty($c['uuid']) && ! empty($c['local_id'])) {
                    $done['customers'] += DB::table('mb_customers')->where('uuid', $c['uuid'])
                        ->update(['local_id' => (int) $c['local_id'], 'status' => 'active', 'updated_at' => $now]);
                }
            }

            $updates = array_filter((array) $request->input('customer_updates', []), 'is_string');
            if ($updates && Schema::hasTable('mb_customer_updates')) {
                $done['customer_updates'] = DB::table('mb_customer_updates')->whereIn('uuid', $updates)
                    ->update(['status' => 'received', 'updated_at' => $now]);
            }

            $visits = array_filter((array) $request->input('visits', []), 'is_string');
            if ($visits && Schema::hasTable('mb_visits')) {
                $done['visits'] = DB::table('mb_visits')->whereIn('uuid', $visits)->update(['status' => 'received', 'updated_at' => $now]);
            }

            foreach ((array) $request->input('orders', []) as $o) {
                if (empty($o['uuid']) || ! in_array($o['status'] ?? null, self::ORDER_STATUSES, true)) {
                    continue;
                }
                $update = ['status' => $o['status'], 'updated_at' => $now];
                foreach (['local_so_id', 'local_so_no', 'invoice_no', 'reject_reason'] as $field) {
                    if (array_key_exists($field, $o)) {
                        $update[$field] = $o[$field];
                    }
                }
                if ($o['status'] === 'received') {
                    $update['received_at'] = $now;
                }
                $done['orders'] += DB::table('mb_orders')->where('uuid', $o['uuid'])->update($update);
            }

            foreach ((array) $request->input('payments', []) as $p) {
                if (empty($p['uuid']) || ! in_array($p['status'] ?? null, self::PAYMENT_STATUSES, true)) {
                    continue;
                }
                $update = ['status' => $p['status'], 'updated_at' => $now];
                foreach (['local_ref', 'reject_reason'] as $field) {
                    if (array_key_exists($field, $p)) {
                        $update[$field] = $p[$field];
                    }
                }
                if ($p['status'] === 'received') {
                    $update['received_at'] = $now;
                }
                $done['payments'] += DB::table('mb_payments')->where('uuid', $p['uuid'])->update($update);
            }
        });

        return response()->json(['success' => true, 'updated' => $done]);
    }

    /**
     * Local database -> cloud database (mobile-sync:mirror): SQL the local PC made, gzip + base64 in "sql",
     * complete statements only. Either a piece of a mysqldump (whole tables) or the changed rows of one table
     * (insert-or-update, and deletes run with foreign keys on so the cloud cascades like the local database did).
     * Guarded by the sync key; the cloud's own tables (mb_*, migrations, sessions...) are refused.
     */
    public function mirrorSql(Request $request)
    {
        $sql = @gzdecode(base64_decode((string) $request->input('sql'), true) ?: '');
        if ($sql === false || $sql === '') {
            return response()->json(['message' => 'Empty or broken SQL piece'], 422);
        }
        if (preg_match('/^\s*(DROP TABLE IF EXISTS|CREATE TABLE|INSERT INTO|LOCK TABLES|ALTER TABLE|DELETE FROM|UPDATE)\s+`(mb_\w+|migrations|sessions|system|sync_changes)`/mi', $sql)) {
            return response()->json(['message' => 'Refused: cloud-only table'], 422);
        }

        $this->mirrorSession();
        try {
            DB::unprepared($sql);
        } catch (\Throwable $e) {
            return response()->json(['message' => mb_substr($e->getMessage(), 0, 1000)], 500);
        }

        return response()->json(['success' => true]);
    }

    private function mirrorSession(): void
    {
        @set_time_limit(300);
        DB::statement('SET NAMES utf8mb4');
        DB::statement("SET time_zone = '+00:00'");
        DB::statement("SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'");
        DB::statement('SET FOREIGN_KEY_CHECKS = 0');
    }

    /**
     * Upsert a full snapshot into $table keyed on $key, touching updated_at only when a row really changed, and
     * apply $missing to rows the snapshot no longer contains.
     */
    /** A photo a booker uploaded (shop / visit), for the PC to keep a copy. */
    public function file(Request $request)
    {
        $path = (string) $request->input('path');
        if (! preg_match('#^uploads/booker/[a-z0-9-]+\.jpg$#', $path) || ! is_file(public_path($path))) {
            abort(404);
        }

        return response()->file(public_path($path), ['Content-Type' => 'image/jpeg']);
    }

    private function syncSet(string $table, string $key, array $rows, array $fields, array $missing): array
    {
        $now = now();
        $existing = DB::table($table)->whereNotNull($key)->pluck('row_hash', $key);
        $seen = [];
        $inserted = $updated = 0;

        foreach ($rows as $row) {
            if (! isset($row[$key])) {
                continue;
            }
            $id = $row[$key];
            $seen[$id] = true;

            $values = [];
            foreach ($fields as $field) {
                $values[$field] = $row[$field] ?? null;
            }
            $values['row_hash'] = md5(json_encode(array_values($values)));

            if (! $existing->has($id)) {
                DB::table($table)->insert([$key => $id] + $values + ['created_at' => $now, 'updated_at' => $now]);
                $inserted++;
            } elseif ($existing[$id] !== $values['row_hash']) {
                DB::table($table)->where($key, $id)->update($values + ['updated_at' => $now]);
                $updated++;
            }
        }

        $gone = $existing->keys()->reject(function ($id) use ($seen) {
            return isset($seen[$id]);
        });
        $switched_off = 0;
        foreach ($gone->chunk(500) as $ids) {
            $query = DB::table($table)->whereIn($key, $ids->all());
            foreach ($missing as $field => $value) {
                $query->where($field, '!=', $value);
            }
            $switched_off += $query->update($missing + ['row_hash' => '', 'updated_at' => $now]);
        }

        return compact('inserted', 'updated', 'switched_off');
    }
}
