<?php

namespace App\Utils;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Trade schemes ("buy 12 get 1 free"). One place for the rules, used by the POS / Add Sale screens (activeFor, as
 * JSON for public/js/trade_scheme.js) and when a sale is saved (applyToLines, from TransactionUtil::createOrUpdateSellLines).
 *
 * Quantities: a scheme counts what is bought in its unit (unit_id, e.g. CTN) and gives free_qty in its free unit;
 * sell lines store base units, so everything is converted with the unit multipliers.
 */
class TradeSchemeUtil
{
    private static $installed = null;

    public static function installed(): bool
    {
        if (self::$installed === null) {
            self::$installed = Schema::hasTable('trade_schemes') && Schema::hasColumn('transaction_sell_lines', 'trade_scheme_id');
        }

        return self::$installed;
    }

    /** Base units in one of this unit (CTN of 24 → 24); 1 for a base unit or none. */
    public static function multiplier($unit_id): float
    {
        if (empty($unit_id)) {
            return 1;
        }
        $unit = DB::table('units')->where('id', $unit_id)->first(['base_unit_id', 'base_unit_multiplier']);

        return ($unit && $unit->base_unit_id && (float) $unit->base_unit_multiplier > 0) ? (float) $unit->base_unit_multiplier : 1;
    }

    /**
     * Free quantity (in the free unit) for $qty bought (in the scheme unit). Best slab first; with "repeat" the slab is
     * used as many times as it fits, then the rest goes to the next lower slab (12+1 repeating: 25 → 2 free).
     */
    public static function freeQty($slabs, float $qty, bool $repeat): float
    {
        $slabs = collect($slabs)->filter(fn ($s) => (float) $s->buy_qty > 0)->sortByDesc(fn ($s) => (float) $s->buy_qty)->values();
        $free = 0;
        $left = $qty + 0.00001;
        foreach ($slabs as $s) {
            $buy = (float) $s->buy_qty;
            if ($left < $buy) {
                continue;
            }
            $times = $repeat ? floor($left / $buy) : 1;
            $free += $times * (float) $s->free_qty;
            $left -= $times * $buy;
            if (! $repeat) {
                break;
            }
        }

        return round($free, 4);
    }

    /**
     * Schemes running at this location on this date, with slabs, unit multipliers and budget left — the shape the
     * sale screens use.
     */
    public static function activeFor($business_id, $location_id, $date = null, $exclude_transaction_id = null)
    {
        if (! self::installed()) {
            return collect();
        }
        $date = $date ? \Carbon::parse($date)->format('Y-m-d') : now()->format('Y-m-d');
        $schemes = DB::table('trade_schemes')->where('business_id', $business_id)->where('is_active', 1)
            ->where(function ($q) use ($date) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $date);
            })
            ->where(function ($q) use ($date) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', $date);
            })
            ->get()
            ->filter(function ($s) use ($location_id) {
                $locations = array_map('intval', json_decode((string) $s->location_ids, true) ?: []);

                return empty($locations) || empty($location_id) || in_array((int) $location_id, $locations, true);
            })->values();
        if ($schemes->isEmpty()) {
            return $schemes;
        }

        $slabs = DB::table('trade_scheme_slabs')->whereIn('trade_scheme_id', $schemes->pluck('id'))->get()->groupBy('trade_scheme_id');
        $used = self::freeUsed($schemes->whereNotNull('budget_qty')->pluck('id')->all(), $exclude_transaction_id);
        $free_variations = DB::table('variations as v')->join('products as p', 'p.id', '=', 'v.product_id')
            ->whereIn('v.id', $schemes->pluck('free_variation_id')->filter()->all() ?: [0])
            ->get(['v.id', 'v.product_id', 'p.name', 'p.type', 'v.name as variation'])->keyBy('id');

        return $schemes->map(function ($s) use ($slabs, $used, $free_variations) {
            $s->slabs = collect($slabs->get($s->id, []))->map(fn ($x) => (object) ['buy_qty' => (float) $x->buy_qty, 'free_qty' => (float) $x->free_qty])
                ->sortBy('buy_qty')->values();
            $s->unit_mult = self::multiplier($s->unit_id);
            $free_unit = $s->free_mode === 'same' ? ($s->free_unit_id ?: $s->unit_id) : $s->free_unit_id;
            $s->free_unit_mult = self::multiplier($free_unit);
            $s->free_unit_name = $free_unit ? DB::table('units')->where('id', $free_unit)->value('short_name') : null;
            $s->unit_name = $s->unit_id ? DB::table('units')->where('id', $s->unit_id)->value('short_name') : null;
            // budget is in the free unit; used is counted in base units
            $s->budget_left = $s->budget_qty === null ? null
                : max(0, round((float) $s->budget_qty - (float) ($used[$s->id] ?? 0) / $s->free_unit_mult, 4));
            $fv = $s->free_variation_id ? $free_variations->get($s->free_variation_id) : null;
            $s->free_product_id = $fv->product_id ?? null;
            $s->free_name = $fv ? $fv->name.($fv->type === 'variable' ? ' - '.$fv->variation : '') : null;
            $s->label = $s->code.' '.self::slabText($s);

            return $s;
        });
    }

    /** "12+1" or "12+1, 24+3" (+ "repeat") for labels. */
    public static function slabText($s): string
    {
        return collect($s->slabs ?? [])->map(fn ($x) => rtrim(rtrim(number_format($x->buy_qty, 4, '.', ''), '0'), '.').'+'
            .rtrim(rtrim(number_format($x->free_qty, 4, '.', ''), '0'), '.'))->implode(', ');
    }

    /** Free quantity already given (base units) per scheme, on final sales and sales orders. */
    public static function freeUsed(array $scheme_ids, $exclude_transaction_id = null): array
    {
        if (empty($scheme_ids)) {
            return [];
        }

        return DB::table('transaction_sell_lines as l')->join('transactions as t', 't.id', '=', 'l.transaction_id')
            ->whereIn('l.trade_scheme_id', $scheme_ids)
            ->where('t.type', 'sell')->whereIn('t.status', ['final'])
            ->when($exclude_transaction_id, fn ($q) => $q->where('t.id', '!=', $exclude_transaction_id))
            ->groupBy('l.trade_scheme_id')->selectRaw('l.trade_scheme_id, SUM(l.scheme_free_qty) as used')
            ->pluck('used', 'l.trade_scheme_id')->all();
    }

    /**
     * Applies the running schemes to sale lines built in code (booker orders → sales order, MobileInbox), the way
     * the sale screens do: same product → fixed discount on the line worth the free quantity; another product → a
     * free line at 100% discount. Lines use the Add Sale keys (quantity / prices per chosen unit,
     * base_unit_multiplier). Free lines get the free product's default selling price and tax.
     */
    public static function withSchemes(array $lines, $business_id, $location_id, $date): array
    {
        if (! self::installed() || empty($lines)) {
            return $lines;
        }
        $schemes = self::activeFor($business_id, $location_id, $date);
        if ($schemes->isEmpty()) {
            return $lines;
        }
        $find = fn ($l) => $schemes->first(fn ($s) => (int) $s->product_id === (int) $l['product_id'] && (! $s->variation_id || (int) $s->variation_id === (int) $l['variation_id']));
        $tax_rates = DB::table('tax_rates')->where('business_id', $business_id)->pluck('amount', 'id');
        $extra = [];
        foreach ($lines as $k => $l) {
            $s = $find($l);
            if (! $s) {
                continue;
            }
            $m = (float) ($l['base_unit_multiplier'] ?? 1) ?: 1;
            $base_qty = (float) $l['quantity'] * $m;
            $free = self::freeQty($s->slabs, $base_qty / $s->unit_mult, (bool) $s->repeat);
            if ($s->budget_left !== null) {
                $free = min($free, (float) $s->budget_left);
            }
            if ($free <= 0) {
                continue;
            }
            if ($s->free_mode === 'same') {
                $free_base = min($free * $s->free_unit_mult, $base_qty);
                $unit_price = (float) $l['unit_price'];
                $discount = round($unit_price * $free_base / $base_qty, 4);
                $rate = ! empty($l['tax_id']) ? (float) ($tax_rates[$l['tax_id']] ?? 0) : 0;
                $net = $unit_price - $discount;
                $lines[$k]['line_discount_type'] = 'fixed';
                $lines[$k]['line_discount_amount'] = $discount;
                $lines[$k]['item_tax'] = $net * $rate / 100;
                $lines[$k]['unit_price_inc_tax'] = $net + $net * $rate / 100;
                $lines[$k]['trade_scheme_id'] = $s->id;
            } else {
                $v = DB::table('variations as v')->join('products as p', 'p.id', '=', 'v.product_id')->where('v.id', $s->free_variation_id)
                    ->first(['v.id', 'v.product_id', 'v.default_sell_price', 'p.unit_id', 'p.tax', 'p.enable_stock', 'p.type']);
                if (! $v) {
                    continue;
                }
                $free_unit = $s->free_unit_id && (float) $s->free_unit_mult != 1 ? $s->free_unit_id : null;
                $fm = $free_unit ? (float) $s->free_unit_mult : 1;
                $lines[$k]['trade_scheme_id'] = $s->id;
                $extra[] = [
                    'product_id' => $v->product_id, 'variation_id' => $v->id, 'quantity' => $free,
                    'unit_price' => (float) $v->default_sell_price * $fm, 'unit_price_inc_tax' => 0, 'item_tax' => 0, 'tax_id' => $v->tax,
                    'line_discount_type' => 'percentage', 'line_discount_amount' => 100,
                    'sub_unit_id' => $free_unit, 'product_unit_id' => $v->unit_id, 'base_unit_multiplier' => $fm,
                    'enable_stock' => $v->enable_stock, 'product_type' => $v->type, 'sell_line_note' => '',
                    'trade_scheme_id' => $s->id, 'scheme_role' => 'free',
                ];
            }
        }

        return array_merge($lines, $extra);
    }

    /** Running schemes as plain data for the booker phones (sync), with the product / unit names they show. */
    public static function forPhones($business_id): array
    {
        return self::activeFor($business_id, null)->map(fn ($s) => [
            'id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'label' => $s->label,
            'product_id' => (int) $s->product_id, 'variation_id' => $s->variation_id ? (int) $s->variation_id : null,
            'unit_id' => $s->unit_id ? (int) $s->unit_id : null, 'unit_name' => $s->unit_name, 'unit_mult' => $s->unit_mult,
            'slabs' => $s->slabs->map(fn ($x) => ['buy_qty' => $x->buy_qty, 'free_qty' => $x->free_qty])->all(), 'repeat' => (bool) $s->repeat,
            'free_mode' => $s->free_mode, 'free_variation_id' => $s->free_variation_id ? (int) $s->free_variation_id : null, 'free_name' => $s->free_name,
            'free_unit_name' => $s->free_unit_name, 'free_unit_mult' => $s->free_unit_mult,
            'location_ids' => array_map('intval', json_decode((string) $s->location_ids, true) ?: []),
            'starts_at' => $s->starts_at, 'ends_at' => $s->ends_at, 'budget_left' => $s->budget_left,
        ])->values()->all();
    }

    /**
     * Report rows: every final sale line that used a scheme between two dates. Free goods are valued at sale price
     * (line price before discount) and at cost (the purchase lines the sale took its stock from, else the
     * product's default purchase price). Filters: scheme_id, location_id, supplier_id, funded_by, customer_id.
     */
    public static function freeGoods($business_id, $start, $end, array $filters = [])
    {
        if (! self::installed()) {
            return collect();
        }
        $cost = DB::table('transaction_sell_lines_purchase_lines as m')->join('purchase_lines as pl', 'pl.id', '=', 'm.purchase_line_id')
            ->groupBy('m.sell_line_id')
            ->selectRaw('m.sell_line_id, SUM(m.quantity * pl.purchase_price) / NULLIF(SUM(m.quantity), 0) as unit_cost');

        return DB::table('transaction_sell_lines as l')
            ->join('transactions as t', 't.id', '=', 'l.transaction_id')
            ->join('trade_schemes as s', 's.id', '=', 'l.trade_scheme_id')
            ->join('variations as v', 'v.id', '=', 'l.variation_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoin('contacts as c', 'c.id', '=', 't.contact_id')
            ->leftJoin('business_locations as bl', 'bl.id', '=', 't.location_id')
            ->leftJoinSub($cost, 'cost', 'cost.sell_line_id', '=', 'l.id')
            ->where('t.business_id', $business_id)->where('t.type', 'sell')->where('t.status', 'final')
            ->whereDate('t.transaction_date', '>=', $start)->whereDate('t.transaction_date', '<=', $end)
            ->when(! empty($filters['scheme_id']), fn ($q) => $q->where('s.id', $filters['scheme_id']))
            ->when(! empty($filters['location_id']), fn ($q) => $q->where('t.location_id', $filters['location_id']))
            ->when(! empty($filters['supplier_id']), fn ($q) => $q->where('s.supplier_id', $filters['supplier_id']))
            ->when(! empty($filters['funded_by']), fn ($q) => $q->where('s.funded_by', $filters['funded_by']))
            ->when(! empty($filters['customer_id']), fn ($q) => $q->where('t.contact_id', $filters['customer_id']))
            ->orderBy('t.transaction_date')
            ->select('l.id', 'l.transaction_id', 't.invoice_no', 't.transaction_date', 't.contact_id', 'l.quantity', 'l.scheme_free_qty',
                'l.unit_price_before_discount', 'l.variation_id', 's.id as scheme_id', 's.code', 's.name as scheme_name', 's.funded_by', 's.supplier_id',
                'p.name as product_name', 'p.type as product_type', 'v.name as variation_name', 'bl.name as location',
                DB::raw("COALESCE(NULLIF(c.supplier_business_name, ''), c.name) as customer"),
                DB::raw('COALESCE(cost.unit_cost, v.default_purchase_price, 0) as unit_cost'))
            ->get()
            ->map(function ($r) {
                $r->product = $r->product_name.($r->product_type === 'variable' ? ' - '.$r->variation_name : '');
                $r->free_qty = (float) $r->scheme_free_qty;
                $r->sale_value = round($r->free_qty * (float) $r->unit_price_before_discount, 4);
                $r->cost_value = round($r->free_qty * (float) $r->unit_cost, 4);

                return $r;
            });
    }

    /**
     * Saving a sale: checks every line that says it used a scheme and records the free quantity the scheme really
     * gives (base units, within the budget). Lines with a scheme that does not apply lose the link; the price and
     * discount stay as staff entered them. Free lines of another product ("scheme_role" = free) are capped at what
     * the bought lines earned.
     *
     * @param  array  $products  the posted products, changed in place: trade_scheme_id, scheme_free_qty
     */
    public static function applyToLines(&$products, $transaction, $location_id, callable $num_uf): void
    {
        if (! self::installed() || empty($products)) {
            return;
        }
        $wanted = collect($products)->pluck('trade_scheme_id')->filter()->unique()->values()->all();
        if (empty($wanted)) {
            return;
        }
        $schemes = self::activeFor($transaction->business_id, $location_id, $transaction->transaction_date, $transaction->id)->keyBy('id');
        $budget = $schemes->mapWithKeys(fn ($s) => [$s->id => $s->budget_left === null ? null : $s->budget_left * $s->free_unit_mult]);

        $base_qty = function ($p) use ($num_uf) {
            $multiplier = 1;
            if (! empty($p['sub_unit_id']) && (empty($p['product_unit_id']) || $p['sub_unit_id'] != $p['product_unit_id']) && ! empty($p['base_unit_multiplier'])) {
                $multiplier = (float) $num_uf($p['base_unit_multiplier']);
            }

            return (float) $num_uf($p['quantity']) * $multiplier;
        };
        $take = function ($scheme_id, $qty) use (&$budget) {
            if ($budget[$scheme_id] === null) {
                return $qty;
            }
            $qty = min($qty, max(0, $budget[$scheme_id]));
            $budget[$scheme_id] -= $qty;

            return $qty;
        };

        // 1. bought lines: same-product free goods are part of the line; other-product free goods are earned
        $earned = [];
        foreach ($products as $k => $p) {
            $id = (int) ($p['trade_scheme_id'] ?? 0);
            if (! $id || ($p['scheme_role'] ?? '') === 'free') {
                continue;
            }
            $s = $schemes->get($id);
            $matches = $s && (int) $p['product_id'] === (int) $s->product_id && (! $s->variation_id || (int) $p['variation_id'] === (int) $s->variation_id);
            if (! $matches) {
                $products[$k]['trade_scheme_id'] = null;
                $products[$k]['scheme_free_qty'] = 0;
                continue;
            }
            $free = self::freeQty($s->slabs, $base_qty($p) / $s->unit_mult, (bool) $s->repeat) * $s->free_unit_mult;
            if ($s->free_mode === 'same') {
                $free = min($free, $base_qty($p));
                $products[$k]['scheme_free_qty'] = round($take($id, $free), 4);
                if ($products[$k]['scheme_free_qty'] <= 0) {
                    $products[$k]['trade_scheme_id'] = null;
                }
            } else {
                $products[$k]['scheme_free_qty'] = 0;
                $earned[$id] = ($earned[$id] ?? 0) + $free;
            }
        }

        // 2. free lines of another product
        foreach ($products as $k => $p) {
            $id = (int) ($p['trade_scheme_id'] ?? 0);
            if (! $id || ($p['scheme_role'] ?? '') !== 'free') {
                continue;
            }
            $s = $schemes->get($id);
            if (! $s || $s->free_mode !== 'other' || (int) $p['variation_id'] !== (int) $s->free_variation_id || empty($earned[$id])) {
                $products[$k]['trade_scheme_id'] = null;
                $products[$k]['scheme_free_qty'] = 0;
                continue;
            }
            $free = $take($id, min($base_qty($p), $earned[$id]));
            $earned[$id] -= $free;
            $products[$k]['scheme_free_qty'] = round($free, 4);
            if ($free <= 0) {
                $products[$k]['trade_scheme_id'] = null;
            }
        }
    }
}
