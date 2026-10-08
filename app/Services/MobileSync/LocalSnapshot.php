<?php

namespace App\Services\MobileSync;

use Illuminate\Support\Facades\DB;

/**
 * Builds what the local PC pushes to the cloud for the order-booker app (config/mobile_sync.php): bookers,
 * products with price and stock at the booker location, customers with their due, and unpaid invoices.
 */
class LocalSnapshot
{
    // Users with this role (per business, "Order Booker#<business_id>") can log in to the app.
    const BOOKER_ROLE = 'Order Booker';

    private $business_id;

    private $location_id;

    public function __construct(int $business_id, int $location_id)
    {
        $this->business_id = $business_id;
        $this->location_id = $location_id;
    }

    public function all(): array
    {
        return [
            'locations' => $this->locations(),
            'users' => $this->users(),
            'products' => $this->products(),
            'customers' => $this->customers(),
            'routes' => $this->routes(),
            'invoices' => $this->invoices(),
            'settings' => self::settings(),
        ];
    }

    /** Booker app settings chosen on Sell > Mobile orders (kept in the `system` table, sent with every push). */
    public static function settings(): array
    {
        $saved = json_decode((string) DB::table('system')->where('key', 'mobile_booker_settings')->value('value'), true) ?: [];

        // Booking more than the free stock: allowed with a "short stock" warning (default), or not allowed.
        return ['allow_short_stock' => (bool) ($saved['allow_short_stock'] ?? true)];
    }

    /** Active business locations bookers can book for. */
    public function locations(): array
    {
        return DB::table('business_locations')->where('business_id', $this->business_id)->where('is_active', 1)
            ->whereNull('deleted_at')->orderBy('id')->get(['id', 'name'])
            ->map(function ($l) {
                return ['id' => (int) $l->id, 'name' => $l->name];
            })->all();
    }

    public function users(): array
    {
        $active = array_column($this->locations(), 'id');

        return DB::table('users as u')
            ->join('model_has_roles as mr', function ($join) {
                $join->on('mr.model_id', '=', 'u.id')->where('mr.model_type', 'App\User');
            })
            ->join('roles as r', 'r.id', '=', 'mr.role_id')
            ->where('r.name', self::BOOKER_ROLE.'#'.$this->business_id)
            ->where('u.business_id', $this->business_id)
            ->whereNull('u.deleted_at')
            ->select('u.id', 'u.username', 'u.password', 'u.first_name', 'u.last_name', 'u.allow_login', 'u.status', 'u.mobile_can_edit_shops')
            ->get()
            ->map(function ($u) use ($active) {
                $prefix = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $u->username), 0, 3)) ?: 'B';

                // Locations as the POS user has them (User Management > Access locations); none set = the default one.
                $permitted = \App\User::find($u->id)->permitted_locations($this->business_id);
                $locations = $permitted === 'all' ? $active : array_values(array_intersect($active, array_map('intval', (array) $permitted)));
                if (empty($locations)) {
                    $locations = [$this->location_id];
                }

                return [
                    'id' => $u->id,
                    'username' => $u->username,
                    'password' => $u->password,
                    'name' => trim($u->first_name.' '.$u->last_name),
                    'code' => $prefix.$u->id,
                    'allow_login' => ($u->allow_login && $u->status === 'active') ? 1 : 0,
                    'locations' => json_encode($locations),
                    'can_edit' => (int) $u->mobile_can_edit_shops,
                ];
            })
            ->all();
    }

    /**
     * Every product with its stock and price per location. price / stock_qty / reserved_qty are those of the default
     * location (MOBILE_SYNC_LOCATION_ID) for older app versions; loc_stock / loc_price hold every location that
     * sells the product.
     */
    public function products(): array
    {
        $locations = DB::table('business_locations')->whereIn('id', array_column($this->locations(), 'id'))->get()->keyBy('id');

        $group_prices = [];
        foreach ($locations as $loc) {
            if (! empty($loc->selling_price_group_id)) {
                $group_prices[$loc->id] = DB::table('variation_group_prices')->where('price_group_id', $loc->selling_price_group_id)
                    ->get()->keyBy('variation_id');
            }
        }

        // Sales orders not yet fully invoiced still hold stock, per location.
        $reserved = [];
        DB::table('transaction_sell_lines as sl')
            ->join('transactions as t', 't.id', '=', 'sl.transaction_id')
            ->where('t.business_id', $this->business_id)
            ->where('t.type', 'sales_order')
            ->where('t.status', '!=', 'completed')
            ->groupBy('t.location_id', 'sl.variation_id')
            ->selectRaw('t.location_id, sl.variation_id, SUM(GREATEST(sl.quantity - sl.so_quantity_invoiced, 0)) as qty')
            ->get()->each(function ($r) use (&$reserved) {
                $reserved[$r->variation_id][$r->location_id] = (float) $r->qty;
            });

        $stock = [];
        DB::table('variation_location_details')->whereIn('location_id', $locations->keys())->get(['variation_id', 'location_id', 'qty_available'])
            ->each(function ($r) use (&$stock) {
                $stock[$r->variation_id][$r->location_id] = (float) $r->qty_available;
            });

        $sold_at = DB::table('product_locations')->whereIn('location_id', $locations->keys())->get()->groupBy('product_id')
            ->map(function ($rows) {
                return $rows->pluck('location_id')->map('intval')->all();
            });

        $rows = DB::table('variations as v')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->join('product_variations as pv', 'pv.id', '=', 'v.product_variation_id')
            ->leftJoin('units as un', 'un.id', '=', 'p.unit_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoin('brands as b', 'b.id', '=', 'p.brand_id')
            ->where('p.business_id', $this->business_id)
            ->whereIn('p.type', ['single', 'variable'])
            ->whereNull('v.deleted_at')
            ->select(
                'v.id as variation_id', 'p.id as product_id', 'p.name', 'p.type', 'pv.name as product_variation_name',
                'v.name as variation_name', 'v.sub_sku', 'un.short_name as unit', 'c.name as category', 'b.name as brand',
                'v.sell_price_inc_tax', 'p.enable_stock', 'p.is_inactive', 'p.not_for_selling', 'p.unit_id', 'p.sub_unit_ids'
            )
            ->orderBy('v.id')
            ->get();

        $all_units = DB::table('units')->where('business_id', $this->business_id)->whereNull('deleted_at')->orderBy('id')->get();

        return $rows->map(function ($r) use ($group_prices, $reserved, $stock, $sold_at, $all_units) {
            $loc_stock = [];
            $loc_price = [];
            foreach ($sold_at->get($r->product_id, []) as $loc) {
                $price = (float) $r->sell_price_inc_tax;
                $group = isset($group_prices[$loc]) ? $group_prices[$loc]->get($r->variation_id) : null;
                if (! empty($group)) {
                    $price = $group->price_type === 'percentage' ? $price * (float) $group->price_inc_tax / 100 : (float) $group->price_inc_tax;
                }
                $loc_price[$loc] = round($price, 4);
                $loc_stock[$loc] = [round($stock[$r->variation_id][$loc] ?? 0, 4), round($reserved[$r->variation_id][$loc] ?? 0, 4)];
            }
            $default = $this->location_id;

            $name = $r->name;
            if ($r->type === 'variable') {
                $name .= ' - '.$r->product_variation_name.' '.$r->variation_name;
            }

            return [
                'variation_id' => $r->variation_id,
                'product_id' => $r->product_id,
                'name' => $name,
                'sku' => $r->sub_sku,
                'unit' => $r->unit,
                'units' => json_encode($this->productUnits($all_units, $r->unit_id, $r->sub_unit_ids)),
                'category' => $r->category,
                'brand' => $r->brand,
                'price' => $loc_price[$default] ?? (empty($loc_price) ? round((float) $r->sell_price_inc_tax, 4) : reset($loc_price)),
                'enable_stock' => (int) $r->enable_stock,
                'stock_qty' => $loc_stock[$default][0] ?? 0,
                'reserved_qty' => $loc_stock[$default][1] ?? 0,
                'loc_stock' => json_encode((object) $loc_stock),
                'loc_price' => json_encode((object) $loc_price),
                'image' => null,
                'active' => ($r->is_inactive || $r->not_for_selling || empty($loc_price)) ? 0 : 1,
            ];
        })->all();
    }

    /**
     * Units the product can be sold in, same rule as Util::getSubUnits: the base unit and its sub units, limited
     * to the product's sub_unit_ids when it has any. Sell lines store quantity and price per base unit.
     */
    private function productUnits($all_units, $unit_id, $sub_unit_ids): array
    {
        $base = $all_units->firstWhere('id', $unit_id);
        if (empty($base)) {
            return [];
        }
        $related = array_map('intval', json_decode($sub_unit_ids ?? '', true) ?: []);
        $allowed = function ($id) use ($related) {
            return empty($related) || in_array((int) $id, $related, true);
        };
        $subs = $all_units->where('base_unit_id', $base->id);

        $units = [];
        if ($subs->isEmpty() || $allowed($base->id)) {
            $units[] = ['id' => $base->id, 'name' => $base->actual_name, 'multiplier' => 1, 'allow_decimal' => (int) $base->allow_decimal];
        }
        foreach ($subs as $sub) {
            if ($allowed($sub->id)) {
                $units[] = ['id' => $sub->id, 'name' => $sub->actual_name, 'multiplier' => (float) $sub->base_unit_multiplier, 'allow_decimal' => (int) $sub->allow_decimal];
            }
        }

        return $units;
    }

    public function customers(): array
    {
        $due = $this->customerDue();

        return DB::table('contacts')
            ->where('business_id', $this->business_id)
            ->whereIn('type', ['customer', 'both'])
            ->whereNull('deleted_at')
            ->where('contact_status', 'active')
            ->orderBy('id')
            ->get(['id', 'name', 'supplier_business_name', 'mobile', 'address_line_1', 'address_line_2', 'city', 'credit_limit',
                'route_id', 'position', 'shop_photo', 'outlet_type', 'outlet_class', 'visit_sequence'])
            ->map(function ($c) use ($due) {
                return [
                    'local_id' => $c->id,
                    'name' => $c->name ?: ($c->supplier_business_name ?: '#'.$c->id),
                    'business_name' => $c->supplier_business_name,
                    'mobile' => in_array(trim((string) $c->mobile), ['0', '-'], true) ? null : $c->mobile,
                    'address' => trim($c->address_line_1.' '.$c->address_line_2) ?: null,
                    'city' => $c->city,
                    'credit_limit' => $c->credit_limit === null ? null : round((float) $c->credit_limit, 4),
                    'balance_due' => round((float) ($due[$c->id] ?? 0), 4),
                    'status' => 'active',
                    'route_id' => $c->route_id,
                    'position' => $c->position ?: null,
                    'photo_url' => $c->shop_photo ?: null,
                    'outlet_type' => $c->outlet_type,
                    'outlet_class' => $c->outlet_class,
                    'visit_sequence' => $c->visit_sequence,
                ];
            })
            ->all();
    }

    /** Booker routes with their weekdays (1 = Monday .. 7 = Sunday). */
    public function routes(): array
    {
        return DB::table('booker_routes')->where('business_id', $this->business_id)->orderBy('name')
            ->get(['id', 'name', 'location_id', 'days', 'booker_id', 'booker_ids', 'is_active'])
            ->map(function ($r) {
                return ['id' => $r->id, 'name' => $r->name, 'location_id' => $r->location_id, 'days' => $r->days,
                    'booker_id' => $r->booker_id, 'active' => (int) $r->is_active,
                    'booker_ids' => json_encode(\App\Http\Controllers\BookerRouteController::bookerIds($r))];
            })
            ->all();
    }

    /** Final sells and opening balance less what was paid against them, as Util::getContactDue counts it. */
    private function customerDue()
    {
        return DB::table('transactions as t')
            ->where('t.business_id', $this->business_id)
            ->whereIn('t.type', ['sell', 'opening_balance'])
            ->groupBy('t.contact_id')
            ->selectRaw("t.contact_id, SUM(
                IF((t.type = 'sell' AND t.status = 'final') OR t.type = 'opening_balance', t.final_total, 0)
                - IF(t.type = 'sell' AND t.status = 'final',
                    (SELECT IFNULL(SUM(IF(tp.is_return = 1, -1 * tp.amount, tp.amount)), 0) FROM transaction_payments tp WHERE tp.transaction_id = t.id), 0)
                - IF(t.type = 'opening_balance',
                    (SELECT IFNULL(SUM(tp.amount), 0) FROM transaction_payments tp WHERE tp.transaction_id = t.id), 0)
            ) as due")
            ->pluck('due', 'contact_id');
    }

    /** Unpaid / partly paid final sells, so a booker can collect against a chosen invoice. */
    public function invoices(): array
    {
        return DB::table('transactions as t')
            ->join('contacts as c', 'c.id', '=', 't.contact_id')
            ->where('t.business_id', $this->business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereIn('t.payment_status', ['due', 'partial'])
            ->whereIn('c.type', ['customer', 'both'])
            ->whereNull('c.deleted_at')
            ->select('t.id', 't.contact_id', 't.invoice_no', 't.transaction_date', 't.final_total',
                DB::raw('(SELECT IFNULL(SUM(IF(tp.is_return = 1, -1 * tp.amount, tp.amount)), 0) FROM transaction_payments tp WHERE tp.transaction_id = t.id) as paid'))
            ->orderBy('t.id')
            ->get()
            ->map(function ($t) {
                $due = round((float) $t->final_total - (float) $t->paid, 4);

                return [
                    'id' => $t->id,
                    'contact_id' => $t->contact_id,
                    'invoice_no' => $t->invoice_no,
                    'transaction_date' => $t->transaction_date,
                    'final_total' => round((float) $t->final_total, 4),
                    'paid' => round((float) $t->paid, 4),
                    'due' => $due,
                    'active' => $due > 0.0001 ? 1 : 0,
                ];
            })
            ->filter(function ($t) {
                return $t['active'] === 1;
            })
            ->values()
            ->all();
    }
}
