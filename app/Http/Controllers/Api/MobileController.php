<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Order-booker app API, served by the cloud copy (see config/mobile_sync.php).
 *
 * The app keeps everything in SQLite and works offline:
 *   POST /api/mobile/login   username + password -> token, plus the next order / receipt numbers for this booker
 *   GET  /api/mobile/sync    changes since ?since=<server_time from the last sync>, plus current available stock
 *   POST /api/mobile/upload  the outbox: new customers, orders, payments; each carries the phone's UUID, so a
 *                            resend is answered "duplicate" instead of being saved twice
 *   POST /api/mobile/logout
 *
 * Bookers only book orders and record collections. Nothing here makes an invoice or changes stock; the local PC
 * approves each order / payment and reports the status back.
 */
class MobileController extends Controller
{
    const PAYMENT_METHODS = ['cash', 'cheque', 'bank_transfer'];

    public function login(Request $request)
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
            'device_name' => 'nullable|string|max:191',
        ]);

        $user = DB::table('mb_users')->where('username', $request->input('username'))->where('allow_login', 1)->first();
        if (empty($user) || ! Hash::check($request->input('password'), $user->password)) {
            return response()->json(['message' => 'Wrong username or password'], 422);
        }

        // One phone per booker: while another phone is logged in, refuse. The office frees it with
        // "Log out phone" on Sell > Mobile orders (lost / changed phone); it reaches the cloud with the next sync.
        $other = DB::table('mb_tokens')->where('user_id', $user->id)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('last_used_at')->first();
        if (! empty($other)) {
            return response()->json([
                'message' => 'Already logged in on another phone'.($other->device_name ? ' ('.$other->device_name.')' : '')
                    .'. Log out there first, or ask the office to log that phone out.',
                'code' => 'other_device',
            ], 409);
        }

        $token = Str::random(64);
        $days = (int) config('mobile_sync.token_days');
        DB::table('mb_tokens')->insert([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'device_name' => $request->input('device_name'),
            'expires_at' => $days > 0 ? now()->addDays($days) : null,
            'last_used_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'token' => $token,
            'user' => ['id' => $user->id, 'name' => $user->name, 'username' => $user->username, 'code' => $user->code],
            'locations' => $this->bookerLocations($user),
            // The phone numbers its slips itself (works offline); continue after the highest number already sent,
            // so a reinstall or a second phone does not reuse numbers.
            'next_order_seq' => (int) DB::table('mb_orders')->where('user_id', $user->id)->max('seq') + 1,
            'next_receipt_seq' => (int) DB::table('mb_payments')->where('user_id', $user->id)->max('seq') + 1,
            'business' => $this->businessName(),
            'server_time' => now()->toDateTimeString(),
        ]);
    }

    /** Shop name for slip headers; the cloud has the business table from the full copy (mobile-sync:mirror). */
    private function businessName(): ?string
    {
        try {
            return DB::table('business')->orderBy('id')->value('name');
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function logout(Request $request)
    {
        DB::table('mb_tokens')->where('token_hash', hash('sha256', (string) $request->bearerToken()))->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Rows changed since ?since (empty = everything). Inactive / deleted rows are included so the app can drop
     * them. Stock is always sent in full because other bookers' orders change it without touching the product.
     */
    public function sync(Request $request)
    {
        // Single server: refresh the booker tables from the POS (stock, prices, customers) first, at most once a minute.
        if (config('mobile_sync.role') === 'single' && \Cache::add('mobile_single_push', 1, 60)) {
            try {
                \Artisan::call('mobile-sync:run', ['--push' => true]);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $user = $request->attributes->get('mb_user');
        $server_time = now()->toDateTimeString();
        $since = $request->input('since');
        $since = ! empty($since) && strtotime($since) ? date('Y-m-d H:i:s', strtotime($since)) : null;

        $changed = function ($query) use ($since) {
            return empty($since) ? $query : $query->where('updated_at', '>=', $since);
        };

        // price and stock are per base unit (unit); units lists what the booker can sell in, price x multiplier each.
        $products = $changed(DB::table('mb_products'))
            ->select('variation_id', 'product_id', 'name', 'sku', 'unit', 'units', 'category', 'brand', 'price', 'loc_price', 'enable_stock', 'image', 'active')
            ->get()
            ->each(function ($p) {
                $p->units = json_decode($p->units ?? '[]', true) ?: [];
                $p->loc_price = json_decode($p->loc_price ?? '', true);
            });

        $customers = $changed(DB::table('mb_customers'))
            ->select('id', 'local_id', 'uuid', 'name', 'business_name', 'mobile', 'address', 'city', 'credit_limit', 'balance_due', 'status',
                'route_id', 'position', 'photo_url', 'outlet_type', 'outlet_class', 'visit_sequence')
            ->get();

        // Routes are few: always sent in full (the app shows the booker's own routes per weekday).
        $routes = Schema::hasTable('mb_routes')
            ? DB::table('mb_routes')->where('active', 1)->orderBy('name')
                ->get(array_merge(['id', 'name', 'location_id', 'days', 'booker_id'], Schema::hasColumn('mb_routes', 'booker_ids') ? ['booker_ids'] : []))
                ->each(function ($r) {
                    $r->days = json_decode($r->days ?? '[]', true) ?: [];
                    $r->booker_ids = \App\Http\Controllers\BookerRouteController::bookerIds($r);
                })
            : [];

        $invoices = $changed(DB::table('mb_invoices'))
            ->select('id', 'contact_id', 'invoice_no', 'transaction_date', 'final_total', 'paid', 'due', 'active')
            ->get();

        $customer_name = function ($table) {
            return DB::raw("(SELECT c.name FROM mb_customers c WHERE c.local_id = $table.contact_id OR c.uuid = $table.customer_uuid LIMIT 1) as customer_name");
        };

        $orders = $changed(DB::table('mb_orders')->where('user_id', $user->id))
            ->select('uuid', 'number', 'status', 'local_so_no', 'invoice_no', 'reject_reason', 'short_stock', 'total', 'location_id', 'order_date as created', $customer_name('mb_orders'))
            ->get();

        $payments = $changed(DB::table('mb_payments')->where('user_id', $user->id))
            ->select('uuid', 'number', 'status', 'local_ref', 'reject_reason', 'amount', 'method', 'paid_on as created', $customer_name('mb_payments'))
            ->get();

        return response()->json([
            'server_time' => $server_time,
            'business' => $this->businessName(),
            'stock_updated_at' => DB::table('mb_meta')->where('key', 'last_push_at')->value('value'),
            'stock' => $this->availableStock(),
            'locations' => $locations = $this->bookerLocations($user),
            'stock_by_location' => collect($locations)->mapWithKeys(function ($l) {
                return [$l['id'] => (object) $this->availableStock($l['id'])];
            }),
            'products' => $products,
            'customers' => $customers,
            'routes' => $routes,
            'can_edit_shops' => $this->canEditShops($user->id),
            'outlet_types' => \App\Http\Controllers\BookerRouteController::OUTLET_TYPES,
            'visit_radius_m' => (int) config('mobile_sync.visit_radius_m', 100),
            'allow_short_stock' => $this->allowShortStock(),
            'schemes' => $this->schemesFor($locations),
            'invoices' => $invoices,
            'orders' => $orders,
            'payments' => $payments,
        ]);
    }

    public function upload(Request $request)
    {
        $user = $request->attributes->get('mb_user');
        $result = ['customers' => [], 'orders' => [], 'payments' => [], 'customer_updates' => [], 'visits' => []];

        // Customers first: an order in the same upload may be for a customer the booker just added.
        foreach ((array) $request->input('customers', []) as $row) {
            $result['customers'][] = $this->saveCustomer($user, (array) $row);
        }
        foreach ((array) $request->input('orders', []) as $row) {
            $result['orders'][] = $this->saveOrder($user, (array) $row);
        }
        foreach ((array) $request->input('payments', []) as $row) {
            $result['payments'][] = $this->savePayment($user, (array) $row);
        }
        foreach ((array) $request->input('customer_updates', []) as $row) {
            $result['customer_updates'][] = $this->saveCustomerUpdate($user, (array) $row);
        }
        foreach ((array) $request->input('visits', []) as $row) {
            $result['visits'][] = $this->saveVisit($user, (array) $row);
        }

        return response()->json($result + ['server_time' => now()->toDateTimeString()]);
    }

    private function saveCustomer($user, array $row): array
    {
        $uuid = $this->uuid($row);
        if (empty($uuid)) {
            return ['uuid' => $row['uuid'] ?? null, 'result' => 'error', 'message' => 'Missing or bad uuid'];
        }
        if (DB::table('mb_customers')->where('uuid', $uuid)->exists()) {
            return ['uuid' => $uuid, 'result' => 'duplicate'];
        }
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            return ['uuid' => $uuid, 'result' => 'error', 'message' => 'Customer name is required'];
        }

        DB::table('mb_customers')->insert([
            'uuid' => $uuid,
            'name' => Str::limit($name, 191, ''),
            'business_name' => $this->str($row, 'business_name'),
            'mobile' => $this->str($row, 'mobile'),
            'address' => $this->str($row, 'address'),
            'city' => $this->str($row, 'city'),
            'position' => $this->position($row['position'] ?? null),
            'photo_url' => $this->savePhoto($row['photo'] ?? null, $uuid),
            'route_id' => $this->routeId($row['route_id'] ?? null),
            'outlet_type' => $this->str($row, 'outlet_type', 50),
            'outlet_class' => $this->outletClass($row['outlet_class'] ?? null),
            'balance_due' => 0,
            'created_by' => $user->id,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['uuid' => $uuid, 'result' => 'saved'];
    }

    /**
     * A booker's edit of an existing shop (location, photo, phone, address, route, type...). Only the changed
     * fields are sent; the PC fills empty fields at once and keeps changes to existing values for approval.
     */
    private function saveCustomerUpdate($user, array $row): array
    {
        $uuid = $this->uuid($row);
        if (empty($uuid)) {
            return ['uuid' => $row['uuid'] ?? null, 'result' => 'error', 'message' => 'Missing or bad uuid'];
        }
        if (DB::table('mb_customer_updates')->where('uuid', $uuid)->exists()) {
            return ['uuid' => $uuid, 'result' => 'duplicate'];
        }
        if (! $this->canEditShops($user->id)) {
            return ['uuid' => $uuid, 'result' => 'error', 'message' => 'Shop editing is locked by the office'];
        }
        $contact_id = (int) ($row['contact_id'] ?? 0);
        if (! DB::table('mb_customers')->where('local_id', $contact_id)->where('status', 'active')->exists()) {
            return ['uuid' => $uuid, 'result' => 'error', 'message' => 'This customer is not on the office list any more'];
        }

        $in = (array) ($row['fields'] ?? []);
        $fields = [];
        foreach (['name', 'business_name', 'mobile', 'address', 'city'] as $key) {
            if (($v = $this->str($in, $key)) !== null) {
                $fields[$key] = $v;
            }
        }
        if (($v = $this->str($in, 'outlet_type', 50)) !== null) {
            $fields['outlet_type'] = $v;
        }
        if ($v = $this->outletClass($in['outlet_class'] ?? null)) {
            $fields['outlet_class'] = $v;
        }
        if ($v = $this->routeId($in['route_id'] ?? null)) {
            $fields['route_id'] = $v;
        }
        if ($v = $this->position($in['position'] ?? null)) {
            $fields['position'] = $v;
        }
        $photo = $this->savePhoto($row['photo'] ?? null, $uuid);
        if (empty($fields) && ! $photo) {
            return ['uuid' => $uuid, 'result' => 'error', 'message' => 'Nothing was changed'];
        }

        DB::table('mb_customer_updates')->insert([
            'uuid' => $uuid,
            'user_id' => $user->id,
            'data' => json_encode([
                'contact_id' => $contact_id,
                'fields' => $fields,
                'accuracy_m' => isset($in['accuracy_m']) ? round((float) $in['accuracy_m'], 1) : null,
                'edited_at' => $this->dateTime($row, 'created'),
            ]),
            'photo' => $photo,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['uuid' => $uuid, 'result' => 'saved'];
    }

    /**
     * A shop visit: check-in time and GPS (the phone's distance to the shop is re-checked on the PC), optional
     * photo, check-out time and the result (order / payment / no order + reason).
     */
    private function saveVisit($user, array $row): array
    {
        $uuid = $this->uuid($row);
        if (empty($uuid)) {
            return ['uuid' => $row['uuid'] ?? null, 'result' => 'error', 'message' => 'Missing or bad uuid'];
        }
        if (DB::table('mb_visits')->where('uuid', $uuid)->exists()) {
            return ['uuid' => $uuid, 'result' => 'duplicate'];
        }
        if (empty($row['contact_id']) && empty($row['customer_uuid'])) {
            return ['uuid' => $uuid, 'result' => 'error', 'message' => 'Shop missing'];
        }
        $num = function ($key) use ($row) {
            return isset($row[$key]) && is_numeric($row[$key]) ? (float) $row[$key] : null;
        };
        $data = [
            'contact_id' => ! empty($row['contact_id']) ? (int) $row['contact_id'] : null,
            'customer_uuid' => ! empty($row['customer_uuid']) ? (string) $row['customer_uuid'] : null,
            'route_id' => ! empty($row['route_id']) ? (int) $row['route_id'] : null,
            'started_at' => $this->dateTime($row, 'started_at'),
            'ended_at' => ! empty($row['ended_at']) ? $this->dateTime($row, 'ended_at') : null,
            'lat' => $num('lat'), 'lng' => $num('lng'), 'accuracy_m' => $num('accuracy_m'), 'distance_m' => $num('distance_m'),
            'outcome' => in_array($row['outcome'] ?? '', ['order', 'payment', 'no_order', 'closed', 'other'], true) ? $row['outcome'] : 'no_order',
            'reason' => $this->str($row, 'reason'),
            'note' => $this->str($row, 'note', 1000),
            'order_uuids' => array_values(array_filter((array) ($row['order_uuids'] ?? []), 'is_string')),
            'payment_uuids' => array_values(array_filter((array) ($row['payment_uuids'] ?? []), 'is_string')),
        ];

        DB::table('mb_visits')->insert([
            'uuid' => $uuid,
            'user_id' => $user->id,
            'data' => json_encode($data),
            'photo' => $this->savePhoto($row['photo'] ?? null, $uuid),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['uuid' => $uuid, 'result' => 'saved'];
    }

    /** User Management > Edit user > "Can edit shops" (off = the booker cannot edit shops in the app). */
    private function canEditShops($user_id): bool
    {
        return ! Schema::hasColumn('mb_users', 'can_edit') || (bool) DB::table('mb_users')->where('id', $user_id)->value('can_edit');
    }

    /** "34.1234567,71.1234567" or null. */
    private function position($value): ?string
    {
        if (! preg_match('/^\s*(-?\d{1,3}(?:\.\d+)?)\s*,\s*(-?\d{1,3}(?:\.\d+)?)\s*$/', (string) $value, $m)
            || abs((float) $m[1]) > 90 || abs((float) $m[2]) > 180 || ((float) $m[1] == 0 && (float) $m[2] == 0)) {
            return null;
        }

        return round((float) $m[1], 7).','.round((float) $m[2], 7);
    }

    private function routeId($value): ?int
    {
        $id = (int) $value;

        return $id > 0 && Schema::hasTable('mb_routes') && DB::table('mb_routes')->where('id', $id)->where('active', 1)->exists() ? $id : null;
    }

    private function outletClass($value): ?string
    {
        $value = strtoupper(trim((string) $value));

        return in_array($value, ['A', 'B', 'C'], true) ? $value : null;
    }

    /**
     * A JPEG from the phone (base64, data: prefix allowed, at most 4 MB) saved as public/uploads/booker/<uuid>.jpg;
     * the PC downloads the same path. Returns that path, or null when there is no valid photo.
     */
    private function savePhoto($data, string $uuid): ?string
    {
        if (! is_string($data) || $data === '') {
            return null;
        }
        $bytes = base64_decode(preg_replace('#^data:image/\w+;base64,#', '', $data), true);
        if ($bytes === false || strlen($bytes) > 4 * 1024 * 1024) {
            return null;
        }
        $info = @getimagesizefromstring($bytes);
        if (! $info || $info[2] !== IMAGETYPE_JPEG) {
            return null;
        }
        $path = 'uploads/booker/'.$uuid.'.jpg';
        if (! is_dir(public_path('uploads/booker'))) {
            mkdir(public_path('uploads/booker'), 0755, true);
        }
        file_put_contents(public_path($path), $bytes);

        return $path;
    }

    private function saveOrder($user, array $row): array
    {
        $uuid = $this->uuid($row);
        if (empty($uuid)) {
            return ['uuid' => $row['uuid'] ?? null, 'result' => 'error', 'message' => 'Missing or bad uuid'];
        }
        $existing = DB::table('mb_orders')->where('uuid', $uuid)->first();
        if (! empty($existing)) {
            return ['uuid' => $uuid, 'result' => 'duplicate', 'status' => $existing->status];
        }

        $customer = $this->customerRef($row);
        if (is_string($customer)) {
            return ['uuid' => $uuid, 'result' => 'error', 'message' => $customer];
        }

        $lines = array_values(array_filter((array) ($row['lines'] ?? []), function ($l) {
            return is_array($l) && ! empty($l['variation_id']) && (float) ($l['quantity'] ?? 0) > 0;
        }));
        if (empty($lines)) {
            return ['uuid' => $uuid, 'result' => 'error', 'message' => 'Order has no items'];
        }

        // The location the order is for: one of the booker's locations (their first one when the app sends none).
        $allowed = array_column($this->bookerLocations($user), 'id');
        $location = (int) ($row['location_id'] ?? 0) ?: $allowed[0];
        if (! in_array($location, $allowed, true)) {
            return ['uuid' => $uuid, 'result' => 'error', 'message' => 'You may not book for this location'];
        }

        $products = DB::table('mb_products')->whereIn('variation_id', array_column($lines, 'variation_id'))->get()->keyBy('variation_id');
        $available = $this->availableStock($location);

        $total = 0;
        $short = false;
        $insert_lines = [];
        $qty_by_variation = [];
        foreach ($lines as $l) {
            $product = $products->get((int) $l['variation_id']);
            if (empty($product) || ! $product->active) {
                return ['uuid' => $uuid, 'result' => 'error', 'message' => 'Product '.$l['variation_id'].' is not available'];
            }
            // The booker orders in one of the product's units (e.g. CTN 24); store it the way sell lines do:
            // quantity and price per base unit, plus the chosen sub unit.
            $units = collect(json_decode($product->units ?? '[]', true) ?: []);
            $unit = empty($l['sub_unit_id']) ? $units->firstWhere('multiplier', 1) : $units->firstWhere('id', (int) $l['sub_unit_id']);
            if (empty($unit) && $units->isNotEmpty()) {
                return ['uuid' => $uuid, 'result' => 'error', 'message' => $product->name.': unit not allowed'];
            }
            $multiplier = (float) ($unit['multiplier'] ?? 1) ?: 1;

            $sub_qty = round((float) $l['quantity'], 4);
            if (! empty($unit) && empty($unit['allow_decimal']) && floor($sub_qty) != $sub_qty) {
                return ['uuid' => $uuid, 'result' => 'error', 'message' => $product->name.': '.$unit['name'].' quantity must be a whole number'];
            }
            // Fixed prices: the app sends the price it showed per chosen unit; staff check it at approval.
            $sub_price = isset($l['unit_price']) && is_numeric($l['unit_price']) ? round((float) $l['unit_price'], 4) : round((json_decode($product->loc_price ?? '', true)[$location] ?? $product->price) * $multiplier, 4);
            $qty = round($sub_qty * $multiplier, 4);

            $insert_lines[] = [
                'variation_id' => $product->variation_id,
                'product_id' => $product->product_id,
                'sub_unit_id' => $unit['id'] ?? null,
                'multiplier' => $multiplier,
                'sub_unit_qty' => $sub_qty,
                'sub_unit_price' => $sub_price,
                'quantity' => $qty,
                'unit_price' => round($sub_price / $multiplier, 4),
                'line_total' => round($sub_qty * $sub_price, 4),
            ];
            $total += $sub_qty * $sub_price;
            $qty_by_variation[$product->variation_id] = ($qty_by_variation[$product->variation_id] ?? 0) + $qty;
        }
        foreach ($qty_by_variation as $variation_id => $qty) {
            if ($products[$variation_id]->enable_stock && $qty > ($available[$variation_id] ?? 0)) {
                // includes products not stocked at this location
                $short = true;
            }
        }

        DB::transaction(function () use ($user, $row, $uuid, $customer, $total, $short, $insert_lines, $location) {
            $order_id = DB::table('mb_orders')->insertGetId([
                'uuid' => $uuid,
                'user_id' => $user->id,
                'number' => $this->str($row, 'number') ?? $user->code.'-'.(int) ($row['seq'] ?? 0),
                'seq' => max(0, (int) ($row['seq'] ?? 0)),
                'contact_id' => $customer['contact_id'],
                'location_id' => $location,
                'customer_uuid' => $customer['customer_uuid'],
                'order_date' => $this->dateTime($row, 'order_date'),
                'note' => $this->str($row, 'note', 5000),
                'total' => round($total, 4),
                'short_stock' => $short ? 1 : 0,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            foreach ($insert_lines as &$line) {
                $line['order_id'] = $order_id;
            }
            DB::table('mb_order_lines')->insert($insert_lines);
        });

        // The customer gets the order slip on WhatsApp right away, through the business's connected device.
        $sent = $this->whatsappOrder(DB::table('mb_orders')->where('uuid', $uuid)->first());

        return ['uuid' => $uuid, 'result' => 'saved', 'status' => 'pending', 'short_stock' => $short, 'whatsapp' => $sent === true];
    }

    /**
     * Running trade schemes for the booker's locations (pushed by the PC, LocalSnapshot::all → mb_meta "schemes").
     * The phone only shows them; the office applies them when it makes the sales order (MobileInbox).
     */
    private function schemesFor(array $locations): array
    {
        $all = json_decode((string) DB::table('mb_meta')->where('key', 'schemes')->value('value'), true) ?: [];
        $ids = array_map('intval', array_column($locations, 'id'));
        $today = now()->format('Y-m-d');

        return array_values(array_filter($all, function ($s) use ($ids, $today) {
            return (empty($s['location_ids']) || array_intersect($ids, $s['location_ids']))
                && (empty($s['starts_at']) || $s['starts_at'] <= $today) && (empty($s['ends_at']) || $s['ends_at'] >= $today);
        }));
    }

    /** Sent up by the local PC with every push (LocalSnapshot::settings); allowed until the PC says otherwise. */
    private function allowShortStock(): bool
    {
        $settings = json_decode((string) DB::table('mb_meta')->where('key', 'settings')->value('value'), true) ?: [];

        return (bool) ($settings['allow_short_stock'] ?? true);
    }

    private function savePayment($user, array $row): array
    {
        $uuid = $this->uuid($row);
        if (empty($uuid)) {
            return ['uuid' => $row['uuid'] ?? null, 'result' => 'error', 'message' => 'Missing or bad uuid'];
        }
        $existing = DB::table('mb_payments')->where('uuid', $uuid)->first();
        if (! empty($existing)) {
            return ['uuid' => $uuid, 'result' => 'duplicate', 'status' => $existing->status];
        }

        $customer = $this->customerRef($row);
        if (is_string($customer)) {
            return ['uuid' => $uuid, 'result' => 'error', 'message' => $customer];
        }

        $amount = round((float) ($row['amount'] ?? 0), 4);
        if ($amount <= 0) {
            return ['uuid' => $uuid, 'result' => 'error', 'message' => 'Amount must be more than 0'];
        }
        $method = $row['method'] ?? 'cash';
        if (! in_array($method, self::PAYMENT_METHODS, true)) {
            return ['uuid' => $uuid, 'result' => 'error', 'message' => 'Unknown payment method'];
        }

        // Optional: which invoices this pays. Empty means the local PC pays the oldest dues first.
        $allocations = [];
        foreach ((array) ($row['allocations'] ?? []) as $a) {
            if (! empty($a['invoice_id']) && (float) ($a['amount'] ?? 0) > 0) {
                $allocations[] = ['invoice_id' => (int) $a['invoice_id'], 'amount' => round((float) $a['amount'], 4)];
            }
        }
        if (! empty($allocations)) {
            if (empty($customer['contact_id'])) {
                return ['uuid' => $uuid, 'result' => 'error', 'message' => 'A new customer has no invoices to pay'];
            }
            $own = DB::table('mb_invoices')->where('contact_id', $customer['contact_id'])
                ->whereIn('id', array_column($allocations, 'invoice_id'))->count();
            if ($own !== count(array_unique(array_column($allocations, 'invoice_id')))) {
                return ['uuid' => $uuid, 'result' => 'error', 'message' => 'Invoice does not belong to this customer'];
            }
            if (array_sum(array_column($allocations, 'amount')) > $amount + 0.0001) {
                return ['uuid' => $uuid, 'result' => 'error', 'message' => 'Invoice amounts are more than the payment'];
            }
        }

        DB::table('mb_payments')->insert([
            'uuid' => $uuid,
            'user_id' => $user->id,
            'number' => $this->str($row, 'number') ?? $user->code.'-R-'.(int) ($row['seq'] ?? 0),
            'seq' => max(0, (int) ($row['seq'] ?? 0)),
            'contact_id' => $customer['contact_id'],
            'customer_uuid' => $customer['customer_uuid'],
            'amount' => $amount,
            'method' => $method,
            'cheque_number' => $this->str($row, 'cheque_number'),
            'bank_ref' => $this->str($row, 'bank_ref'),
            'note' => $this->str($row, 'note', 5000),
            'allocations' => empty($allocations) ? null : json_encode($allocations),
            'paid_on' => $this->dateTime($row, 'paid_on'),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['uuid' => $uuid, 'result' => 'saved', 'status' => 'pending'];
    }

    /**
     * Free stock per variation: what the local PC last reported, less local sales orders not yet invoiced, less
     * booker orders the local PC has not turned into sales orders yet.
     */
    private function availableStock(?int $location = null): array
    {
        $default = (int) config('mobile_sync.location_id');
        $location = $location ?: $default;
        $waiting = DB::table('mb_order_lines as l')
            ->join('mb_orders as o', 'o.id', '=', 'l.order_id')
            ->whereIn('o.status', ['pending', 'received'])
            ->whereRaw('COALESCE(o.location_id, ?) = ?', [$default, $location])
            ->groupBy('l.variation_id')
            ->selectRaw('l.variation_id, SUM(l.quantity) as qty')
            ->pluck('qty', 'variation_id');

        $stock = [];
        foreach (DB::table('mb_products')->where('active', 1)->get(['variation_id', 'enable_stock', 'stock_qty', 'reserved_qty', 'loc_stock']) as $p) {
            $per = json_decode($p->loc_stock ?? '', true);
            if (is_array($per)) {
                if (! isset($per[$location])) {
                    continue; // not sold at this location
                }
                [$qty, $reserved] = $per[$location];
            } elseif ($location === $default) {
                [$qty, $reserved] = [$p->stock_qty, $p->reserved_qty];
            } else {
                continue;
            }
            $stock[$p->variation_id] = $p->enable_stock ? round($qty - $reserved - (float) ($waiting[$p->variation_id] ?? 0), 4) : null;
        }

        return $stock;
    }

    /** Locations this booker may book for, [{id, name}], as their POS user has them (default location if none). */
    private function bookerLocations($user): array
    {
        $ids = json_decode((string) DB::table('mb_users')->where('id', $user->id)->value('locations'), true) ?: [(int) config('mobile_sync.location_id')];
        $names = collect(json_decode((string) DB::table('mb_meta')->where('key', 'locations')->value('value'), true) ?: [])->pluck('name', 'id');

        return array_map(function ($id) use ($names) {
            return ['id' => (int) $id, 'name' => $names[$id] ?? 'Location '.$id];
        }, $ids);
    }

    /** ['contact_id' => .., 'customer_uuid' => ..] or an error message. */
    private function customerRef(array $row)
    {
        if (! empty($row['contact_id'])) {
            $ok = DB::table('mb_customers')->where('local_id', (int) $row['contact_id'])->where('status', 'active')->exists();

            return $ok ? ['contact_id' => (int) $row['contact_id'], 'customer_uuid' => null] : 'Customer not found';
        }
        if (! empty($row['customer_uuid'])) {
            $customer = DB::table('mb_customers')->where('uuid', $row['customer_uuid'])->where('status', '!=', 'deleted')->first();
            if (empty($customer)) {
                return 'New customer not found; send the customer first';
            }

            // Already created on the local PC: use its real id.
            return ['contact_id' => $customer->local_id, 'customer_uuid' => $customer->uuid];
        }

        return 'Customer is required';
    }

    /** "Send on WhatsApp" in the app: the slip / receipt again, to the customer, through the connected device. */
    public function whatsapp(Request $request)
    {
        $user = $request->attributes->get('mb_user');
        $uuid = $this->uuid(['uuid' => $request->input('uuid')]);
        $order = DB::table('mb_orders')->where('uuid', $uuid)->where('user_id', $user->id)->first();
        $payment = empty($order) ? DB::table('mb_payments')->where('uuid', $uuid)->where('user_id', $user->id)->first() : null;
        if (empty($order) && empty($payment)) {
            return response()->json(['message' => 'Not found. Sync first, then try again.'], 404);
        }
        $sent = $order ? $this->whatsappOrder($order) : $this->whatsappPayment($payment);

        return $sent === true ? response()->json(['success' => true, 'message' => 'Sent to the customer on WhatsApp'])
            : response()->json(['message' => $sent], 422);
    }

    private function whatsappOrder($order)
    {
        if (empty($order)) {
            return 'Order not found';
        }
        $lines = DB::table('mb_order_lines as l')->leftJoin('mb_products as p', 'p.variation_id', '=', 'l.variation_id')
            ->where('l.order_id', $order->id)->get(['l.*', 'p.name', 'p.units']);
        $customer = $this->customerOf($order);
        $text = 'Dear '.($customer->name ?? 'customer').",

Your order has been booked.
Order: {$order->number}
Date: {$order->order_date}
Booked by: "
            .DB::table('mb_users')->where('id', $order->user_id)->value('name')."
--------------------
";
        foreach ($lines as $l) {
            $unit = collect(json_decode($l->units ?? '[]', true))->firstWhere('id', (int) $l->sub_unit_id)['name'] ?? '';
            $text .= ($l->name ?? 'Item')."
  ".(float) $l->sub_unit_qty.' '.$unit.' x '.number_format((float) $l->sub_unit_price, 2).' = '.number_format((float) $l->line_total, 2)."
";
        }
        $text .= "--------------------
Total: ".number_format((float) $order->total, 2)."
".($order->note ? 'Note: '.$order->note."
" : '');

        return $this->sendWhatsapp($customer, $text."
Thank you,
".$this->businessName());
    }

    private function whatsappPayment($payment)
    {
        $customer = $this->customerOf($payment);
        $text = 'Dear '.($customer->name ?? 'customer').",

We have received a payment of: ".number_format((float) $payment->amount, 2)
            ."
Receipt: {$payment->number}
Collected by: ".DB::table('mb_users')->where('id', $payment->user_id)->value('name')
            ."
Date: {$payment->paid_on}

Thank you,
".$this->businessName();

        return $this->sendWhatsapp($customer, $text);
    }

    private function customerOf($row)
    {
        return DB::table('mb_customers')->where(function ($q) use ($row) {
            $q->where('local_id', $row->contact_id ?: 0)->orWhere('uuid', $row->customer_uuid ?: '-');
        })->first();
    }

    /**
     * Through the business's connected WhatsApp device (whatsapp_devices, copied from the shop PC), as the POS sends
     * its payment messages. Returns true, or why it was not sent.
     */
    private function sendWhatsapp($customer, string $text)
    {
        if (! config('mobile_sync.whatsapp')) {
            return 'WhatsApp sending is switched off (MOBILE_SYNC_WHATSAPP=false)';
        }
        if (empty($customer) || strlen(preg_replace('/\D/', '', (string) $customer->mobile)) < 10) {
            return 'The customer has no mobile number';
        }
        try {
            $business_id = (int) config('mobile_sync.business_id');
            if (! \App\WhatsappDevice::where('business_id', $business_id)->where('status', 'connected')->exists()) {
                return 'WhatsApp is not connected in the POS (Settings > WhatsApp)';
            }
            (new \App\Services\WhatsappApiService())->sendTestMsg(\App\WhatsappDevice::instanceFor($business_id), $customer->mobile, $text);

            return true;
        } catch (\Throwable $e) {
            \Log::warning('Booker WhatsApp failed: '.$e->getMessage());

            return 'WhatsApp sending failed, try again';
        }
    }

    private function uuid(array $row): ?string
    {
        $uuid = strtolower(trim((string) ($row['uuid'] ?? '')));

        return Str::isUuid($uuid) ? $uuid : null;
    }

    private function str(array $row, string $key, int $max = 191): ?string
    {
        $value = trim((string) ($row[$key] ?? ''));

        return $value === '' ? null : Str::limit($value, $max, '');
    }

    private function dateTime(array $row, string $key): string
    {
        $time = ! empty($row[$key]) ? strtotime($row[$key]) : false;

        return date('Y-m-d H:i:s', $time ?: time());
    }
}
