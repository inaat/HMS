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
            'users' => $this->users(),
            'products' => $this->products(),
            'customers' => $this->customers(),
            'invoices' => $this->invoices(),
        ];
    }

    public function users(): array
    {
        return DB::table('users as u')
            ->join('model_has_roles as mr', function ($join) {
                $join->on('mr.model_id', '=', 'u.id')->where('mr.model_type', 'App\User');
            })
            ->join('roles as r', 'r.id', '=', 'mr.role_id')
            ->where('r.name', self::BOOKER_ROLE.'#'.$this->business_id)
            ->where('u.business_id', $this->business_id)
            ->whereNull('u.deleted_at')
            ->select('u.id', 'u.username', 'u.password', 'u.first_name', 'u.last_name', 'u.allow_login', 'u.status')
            ->get()
            ->map(function ($u) {
                $prefix = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $u->username), 0, 3)) ?: 'B';

                return [
                    'id' => $u->id,
                    'username' => $u->username,
                    'password' => $u->password,
                    'name' => trim($u->first_name.' '.$u->last_name),
                    'code' => $prefix.$u->id,
                    'allow_login' => ($u->allow_login && $u->status === 'active') ? 1 : 0,
                ];
            })
            ->all();
    }

    public function products(): array
    {
        $location = DB::table('business_locations')->find($this->location_id);
        $price_group_id = $location->selling_price_group_id ?? null;

        $group_prices = empty($price_group_id) ? collect() : DB::table('variation_group_prices')
            ->where('price_group_id', $price_group_id)->get()->keyBy('variation_id');

        // Sales orders at this location not yet fully invoiced still hold stock.
        $reserved = DB::table('transaction_sell_lines as sl')
            ->join('transactions as t', 't.id', '=', 'sl.transaction_id')
            ->where('t.business_id', $this->business_id)
            ->where('t.location_id', $this->location_id)
            ->where('t.type', 'sales_order')
            ->where('t.status', '!=', 'completed')
            ->groupBy('sl.variation_id')
            ->selectRaw('sl.variation_id, SUM(GREATEST(sl.quantity - sl.so_quantity_invoiced, 0)) as qty')
            ->pluck('qty', 'variation_id');

        $rows = DB::table('variations as v')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->join('product_variations as pv', 'pv.id', '=', 'v.product_variation_id')
            ->join('product_locations as pl', function ($join) {
                $join->on('pl.product_id', '=', 'p.id')->where('pl.location_id', $this->location_id);
            })
            ->leftJoin('units as un', 'un.id', '=', 'p.unit_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoin('brands as b', 'b.id', '=', 'p.brand_id')
            ->leftJoin('variation_location_details as vld', function ($join) {
                $join->on('vld.variation_id', '=', 'v.id')->where('vld.location_id', $this->location_id);
            })
            ->where('p.business_id', $this->business_id)
            ->whereIn('p.type', ['single', 'variable'])
            ->whereNull('v.deleted_at')
            ->select(
                'v.id as variation_id', 'p.id as product_id', 'p.name', 'p.type', 'pv.name as product_variation_name',
                'v.name as variation_name', 'v.sub_sku', 'un.short_name as unit', 'c.name as category', 'b.name as brand',
                'v.sell_price_inc_tax', 'p.enable_stock', 'p.is_inactive', 'p.not_for_selling', 'vld.qty_available',
                'p.unit_id', 'p.sub_unit_ids'
            )
            ->orderBy('v.id')
            ->get();

        $all_units = DB::table('units')->where('business_id', $this->business_id)->whereNull('deleted_at')->orderBy('id')->get();

        return $rows->map(function ($r) use ($group_prices, $reserved, $all_units) {
            $price = (float) $r->sell_price_inc_tax;
            $group = $group_prices->get($r->variation_id);
            if (! empty($group)) {
                $price = $group->price_type === 'percentage' ? $price * (float) $group->price_inc_tax / 100 : (float) $group->price_inc_tax;
            }

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
                'price' => round($price, 4),
                'enable_stock' => (int) $r->enable_stock,
                'stock_qty' => round((float) $r->qty_available, 4),
                'reserved_qty' => round((float) ($reserved[$r->variation_id] ?? 0), 4),
                'image' => null,
                'active' => ($r->is_inactive || $r->not_for_selling) ? 0 : 1,
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
            ->get(['id', 'name', 'supplier_business_name', 'mobile', 'address_line_1', 'address_line_2', 'city', 'credit_limit'])
            ->map(function ($c) use ($due) {
                return [
                    'local_id' => $c->id,
                    'name' => $c->name ?: ($c->supplier_business_name ?: '#'.$c->id),
                    'business_name' => $c->supplier_business_name,
                    'mobile' => $c->mobile,
                    'address' => trim($c->address_line_1.' '.$c->address_line_2) ?: null,
                    'city' => $c->city,
                    'credit_limit' => $c->credit_limit === null ? null : round((float) $c->credit_limit, 4),
                    'balance_due' => round((float) ($due[$c->id] ?? 0), 4),
                    'status' => 'active',
                ];
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
