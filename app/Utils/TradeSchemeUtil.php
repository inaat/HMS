<?php

namespace App\Utils;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Trade schemes (supplier trade plans: "buy 12 get 1 free", "2 boxes → 2%", "bill Rs 2,500 → 1 Buttons free",
 * "6 CTN → 1.5% for class A"). One place for the rules, used by the sale screens (activeFor → public/js/trade_scheme.js,
 * which mirrors evaluate()) and when a sale is saved (applyToLines, from TransactionUtil::createOrUpdateSellLines).
 *
 * A scheme:
 *  - buys (scope): one product (unit_id = counting unit), a group of products (product_ids) or a whole brand;
 *  - condition: quantity (one product: its unit; group / brand: each product's box-carton ("big") or pieces) or the
 *    value in Rs of the matching lines;
 *  - reward: free goods (same product as a discount on its line, or another product as a free line) or a % discount
 *    on the matching lines, the % optionally by customer class (Outlet class A–E);
 *  - channel: all / retail / wholesale (customer Outlet type "Wholesale" = wholesale, else retail).
 * Several % schemes on one line add up. Sale lines keep what they got in scheme_data: [{id, free_qty (base), discount}].
 */
class TradeSchemeUtil
{
    private static $installed = null;

    private static $big = [];

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

    /** Base units in the product's biggest unit it is sold in (box / carton); 1 when it has none. */
    public static function bigMultiplier($product_id): float
    {
        if (! isset(self::$big[$product_id])) {
            $p = DB::table('products')->where('id', $product_id)->first(['unit_id', 'sub_unit_ids']);
            $allowed = array_map('intval', json_decode((string) ($p->sub_unit_ids ?? ''), true) ?: []);
            $max = $p ? (float) DB::table('units')->where('base_unit_id', $p->unit_id)
                ->when(! empty($allowed), fn ($q) => $q->whereIn('id', $allowed))->max('base_unit_multiplier') : 0;
            self::$big[$product_id] = $max > 0 ? $max : 1;
        }

        return self::$big[$product_id];
    }

    /**
     * Free quantity (in the free unit) for $qty bought (in the scheme unit, or Rs for a value condition). Best slab
     * first; with "repeat" the slab is used as many times as it fits, then the rest goes to the next lower slab
     * (12+1 repeating: 25 → 2 free).
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

    /** The highest slab reached (for % rewards). */
    public static function bestSlab($slabs, float $measure)
    {
        return collect($slabs)->filter(fn ($s) => (float) $s->buy_qty > 0 && $measure + 0.00001 >= (float) $s->buy_qty)
            ->sortByDesc(fn ($s) => (float) $s->buy_qty)->first();
    }

    /** % of a slab for a customer class: the class's own %, else (class unknown / not listed) the lowest class %. */
    public static function slabPercent($slab, $class): float
    {
        $by_class = array_filter((array) ($slab->class_percents ?? []), fn ($v) => $v !== null && $v !== '');
        if (! empty($by_class)) {
            return (float) ($class && isset($by_class[$class]) ? $by_class[$class] : min($by_class));
        }

        return (float) ($slab->percent ?? 0);
    }

    /** Customer class (Outlet class, e.g. "A") and channel (Outlet type "Wholesale" → wholesale, else retail). */
    public static function customer($contact_id): array
    {
        $c = $contact_id ? DB::table('contacts')->where('id', $contact_id)->first(['outlet_type', 'outlet_class']) : null;
        $class = strtoupper(substr(trim((string) ($c->outlet_class ?? '')), 0, 1)) ?: null;

        return ['class' => $class, 'channel' => stripos((string) ($c->outlet_type ?? ''), 'wholesale') !== false ? 'wholesale' : 'retail'];
    }

    /** Does a sale line (product / variation) fall under the scheme? */
    public static function matches($s, $product_id, $variation_id): bool
    {
        if (($s->scope ?? 'product') === 'product') {
            return (int) $product_id === (int) $s->product_id && (! $s->variation_id || (int) $variation_id === (int) $s->variation_id);
        }

        return in_array((int) $product_id, $s->p_ids ?? [], true) || in_array((int) $variation_id, $s->v_ids ?? [], true);
    }

    /**
     * Schemes running at this location on this date, with slabs, unit multipliers, matching product ids and budget
     * left — the shape the sale screens use.
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
            ->orderBy('id')->get()
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
            $s->scope = $s->scope ?? 'product';
            $s->reward_type = $s->reward_type ?? 'free';
            $s->condition_type = $s->condition_type ?? 'qty';
            $s->channel = $s->channel ?? 'all';
            $s->slabs = collect($slabs->get($s->id, []))->map(fn ($x) => (object) [
                'buy_qty' => (float) $x->buy_qty, 'free_qty' => (float) $x->free_qty,
                'percent' => isset($x->percent) && $x->percent !== null ? (float) $x->percent : null,
                'class_percents' => json_decode((string) ($x->class_percents ?? ''), true) ?: [],
            ])->sortBy('buy_qty')->values();
            // what is bought: product / variation ids the screen and the server match on
            $s->p_ids = [];
            $s->v_ids = [];
            if ($s->scope === 'brand') {
                $s->p_ids = DB::table('products')->where('brand_id', $s->brand_id)->pluck('id')->map(fn ($v) => (int) $v)->all();
            } elseif ($s->scope === 'products') {
                foreach (json_decode((string) $s->product_ids, true) ?: [] as $token) {
                    if (preg_match('/^v(\d+)$/', (string) $token, $m)) {
                        $s->v_ids[] = (int) $m[1];
                    } elseif (preg_match('/^p(\d+)$/', (string) $token, $m)) {
                        $s->p_ids[] = (int) $m[1];
                    }
                }
            }
            $s->unit_mult = self::multiplier($s->unit_id);
            $free_unit = $s->free_mode === 'same' ? ($s->free_unit_id ?: $s->unit_id) : $s->free_unit_id;
            $s->free_unit_mult = self::multiplier($free_unit);
            $s->free_unit_name = $free_unit ? DB::table('units')->where('id', $free_unit)->value('short_name') : null;
            $s->unit_name = $s->unit_id ? DB::table('units')->where('id', $s->unit_id)->value('short_name') : null;
            // budget is in the free unit; used is counted in base units
            $s->budget_left = $s->budget_qty === null || $s->reward_type === 'percent' ? null
                : max(0, round((float) $s->budget_qty - (float) ($used[$s->id] ?? 0) / $s->free_unit_mult, 4));
            $fv = $s->free_variation_id ? $free_variations->get($s->free_variation_id) : null;
            $s->free_product_id = $fv->product_id ?? null;
            $s->free_name = $fv ? $fv->name.($fv->type === 'variable' ? ' - '.$fv->variation : '') : null;
            $s->label = $s->code.' '.self::slabText($s);

            return $s;
        });
    }

    /** "12+1, 24+3", "2 → 2%", "Rs 2,500 → 1" or "6 → A 1.5% / C 1%" for labels. */
    public static function slabText($s): string
    {
        $n = fn ($v) => rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.');
        $value = ($s->condition_type ?? 'qty') === 'value';

        return collect($s->slabs ?? [])->map(function ($x) use ($s, $n, $value) {
            $buy = $value ? 'Rs '.number_format((float) $x->buy_qty) : $n($x->buy_qty);
            if (($s->reward_type ?? 'free') !== 'percent') {
                return $buy.($value ? ' → ' : '+').$n($x->free_qty);
            }
            $by_class = array_filter((array) ($x->class_percents ?? []), fn ($v) => $v !== null && $v !== '');
            if (! empty($by_class)) {
                return $buy.' → '.collect($by_class)->map(fn ($v, $k) => $k.' '.$n($v).'%')->implode(' / ');
            }

            return $buy.' → '.$n($x->percent).'%';
        })->implode(', ');
    }

    /** Free quantity already given (base units) per scheme, on final sales. */
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
     * What the running schemes give a bill (public/js/trade_scheme.js does the same on screen).
     *
     * @param  array  $lines  idx => [product_id, variation_id, base_qty, value (Rs before discount), free (bool)]
     * @param  array  $customer  ['class' => 'A'|null, 'channel' => 'retail'|'wholesale']
     * @return array ['lines' => idx => [[id, type same|percent, free_base, pct]], 'free' => scheme_id => free qty (free unit)]
     */
    public static function evaluate($schemes, array $lines, array $customer): array
    {
        $out = ['lines' => [], 'free' => []];
        foreach ($schemes as $s) {
            if ($s->channel !== 'all' && $s->channel !== $customer['channel']) {
                continue;
            }
            $hit = array_filter($lines, fn ($l) => empty($l['free']) && self::matches($s, $l['product_id'], $l['variation_id']));
            if (empty($hit)) {
                continue;
            }
            // one product, free goods of the same product: per line, as a discount worth the free quantity
            if ($s->reward_type === 'free' && $s->free_mode === 'same') {
                foreach ($hit as $idx => $l) {
                    $free = self::freeQty($s->slabs, $l['base_qty'] / ($s->unit_mult ?: 1), (bool) $s->repeat);
                    if ($s->budget_left !== null) {
                        $free = min($free, (float) $s->budget_left);
                    }
                    $free_base = min($free * $s->free_unit_mult, $l['base_qty']);
                    if ($free_base > 0) {
                        $out['lines'][$idx][] = ['id' => $s->id, 'type' => 'same', 'free_base' => round($free_base, 4), 'pct' => 0];
                    }
                }
                continue;
            }
            // the rest work on the matching lines together: quantity (scheme unit / boxes / pieces) or value
            $measure = 0;
            foreach ($hit as $l) {
                if ($s->condition_type === 'value') {
                    $measure += $l['value'];
                } else {
                    $factor = $s->scope === 'product' ? ($s->unit_mult ?: 1) : ($s->count_unit === 'big' ? self::bigMultiplier($l['product_id']) : 1);
                    $measure += $l['base_qty'] / $factor;
                }
            }
            if ($s->reward_type === 'percent') {
                $slab = self::bestSlab($s->slabs, $measure);
                $pct = $slab ? self::slabPercent($slab, $customer['class']) : 0;
                if ($pct > 0) {
                    foreach (array_keys($hit) as $idx) {
                        $out['lines'][$idx][] = ['id' => $s->id, 'type' => 'percent', 'free_base' => 0, 'pct' => $pct];
                    }
                }
            } else {
                $free = self::freeQty($s->slabs, $measure, (bool) $s->repeat);
                if ($s->budget_left !== null) {
                    $free = min($free, (float) $s->budget_left);
                }
                if ($free > 0 && $s->free_variation_id) {
                    $out['free'][$s->id] = $free;
                    foreach (array_keys($hit) as $idx) {
                        $out['lines'][$idx][] = ['id' => $s->id, 'type' => 'earn', 'free_base' => 0, 'pct' => 0];
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Applies the running free-goods schemes of one product to sale lines built in code (booker orders → sales
     * order, MobileInbox): same product → fixed discount on the line worth the free quantity; another product → a
     * free line at 100% discount. Lines use the Add Sale keys (quantity / prices per chosen unit, base_unit_multiplier).
     */
    public static function withSchemes(array $lines, $business_id, $location_id, $date): array
    {
        if (! self::installed() || empty($lines)) {
            return $lines;
        }
        $schemes = self::activeFor($business_id, $location_id, $date)
            ->filter(fn ($s) => $s->scope === 'product' && $s->reward_type === 'free' && $s->condition_type === 'qty' && $s->channel === 'all');
        if ($schemes->isEmpty()) {
            return $lines;
        }
        $find = fn ($l) => $schemes->first(fn ($s) => self::matches($s, $l['product_id'], $l['variation_id']));
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
                $lines[$k]['trade_scheme_ids'] = (string) $s->id;
            } else {
                $v = DB::table('variations as v')->join('products as p', 'p.id', '=', 'v.product_id')->where('v.id', $s->free_variation_id)
                    ->first(['v.id', 'v.product_id', 'v.default_sell_price', 'p.unit_id', 'p.tax', 'p.enable_stock', 'p.type']);
                if (! $v) {
                    continue;
                }
                $free_unit = $s->free_unit_id && (float) $s->free_unit_mult != 1 ? $s->free_unit_id : null;
                $fm = $free_unit ? (float) $s->free_unit_mult : 1;
                $lines[$k]['trade_scheme_ids'] = (string) $s->id;
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

    /** Running one-product free-goods schemes as plain data for the booker phones (sync). */
    public static function forPhones($business_id): array
    {
        return self::activeFor($business_id, null)
            ->filter(fn ($s) => $s->scope === 'product' && $s->reward_type === 'free' && $s->condition_type === 'qty' && $s->channel === 'all')
            ->map(fn ($s) => [
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

    /** What a sale line got: scheme_data, or the older single-scheme columns. */
    public static function lineSchemes($line): array
    {
        $data = json_decode((string) ($line->scheme_data ?? ''), true);
        if (is_array($data) && ! empty($data)) {
            return $data;
        }

        return ! empty($line->trade_scheme_id) ? [['id' => (int) $line->trade_scheme_id, 'free_qty' => (float) $line->scheme_free_qty, 'discount' => 0]] : [];
    }

    /**
     * Report rows: one row per scheme a final sale line got, between two dates. Free goods are valued at sale price
     * (line price before discount) and at cost (the purchase lines the sale took its stock from, else the product's
     * default purchase price); a % discount counts at its amount in both. Filters: scheme_id, location_id,
     * supplier_id, funded_by, customer_id.
     */
    public static function freeGoods($business_id, $start, $end, array $filters = [])
    {
        if (! self::installed()) {
            return collect();
        }
        $cost = DB::table('transaction_sell_lines_purchase_lines as m')->join('purchase_lines as pl', 'pl.id', '=', 'm.purchase_line_id')
            ->groupBy('m.sell_line_id')
            ->selectRaw('m.sell_line_id, SUM(m.quantity * pl.purchase_price) / NULLIF(SUM(m.quantity), 0) as unit_cost');
        $with_data = Schema::hasColumn('transaction_sell_lines', 'scheme_data');

        $lines = DB::table('transaction_sell_lines as l')
            ->join('transactions as t', 't.id', '=', 'l.transaction_id')
            ->join('variations as v', 'v.id', '=', 'l.variation_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoin('contacts as c', 'c.id', '=', 't.contact_id')
            ->leftJoin('business_locations as bl', 'bl.id', '=', 't.location_id')
            ->leftJoinSub($cost, 'cost', 'cost.sell_line_id', '=', 'l.id')
            ->where('t.business_id', $business_id)->where('t.type', 'sell')->where('t.status', 'final')
            ->whereDate('t.transaction_date', '>=', $start)->whereDate('t.transaction_date', '<=', $end)
            ->where(function ($q) use ($with_data) {
                $q->whereNotNull('l.trade_scheme_id');
                if ($with_data) {
                    $q->orWhereNotNull('l.scheme_data');
                }
            })
            ->when(! empty($filters['location_id']), fn ($q) => $q->where('t.location_id', $filters['location_id']))
            ->when(! empty($filters['customer_id']), fn ($q) => $q->where('t.contact_id', $filters['customer_id']))
            ->orderBy('t.transaction_date')
            ->select('l.id', 'l.transaction_id', 't.invoice_no', 't.transaction_date', 't.contact_id', 'l.quantity', 'l.scheme_free_qty', 'l.trade_scheme_id',
                $with_data ? 'l.scheme_data' : DB::raw('NULL as scheme_data'),
                'l.unit_price_before_discount', 'l.variation_id', 'p.name as product_name', 'p.type as product_type', 'v.name as variation_name', 'bl.name as location',
                DB::raw("COALESCE(NULLIF(c.supplier_business_name, ''), c.name) as customer"),
                DB::raw('COALESCE(cost.unit_cost, v.default_purchase_price, 0) as unit_cost'))
            ->get();

        $schemes = DB::table('trade_schemes')->where('business_id', $business_id)->get()->keyBy('id');
        $rows = collect();
        foreach ($lines as $l) {
            foreach (self::lineSchemes($l) as $got) {
                $s = $schemes->get($got['id'] ?? 0);
                if (! $s
                    || (! empty($filters['scheme_id']) && (int) $s->id !== (int) $filters['scheme_id'])
                    || (! empty($filters['supplier_id']) && (int) $s->supplier_id !== (int) $filters['supplier_id'])
                    || (! empty($filters['funded_by']) && $s->funded_by !== $filters['funded_by'])) {
                    continue;
                }
                $free = (float) ($got['free_qty'] ?? 0);
                $discount = (float) ($got['discount'] ?? 0);
                if ($free <= 0 && $discount <= 0) {
                    continue;
                }
                $r = clone $l;
                $r->scheme_id = $s->id;
                $r->code = $s->code;
                $r->scheme_name = $s->name;
                $r->funded_by = $s->funded_by;
                $r->supplier_id = $s->supplier_id;
                $r->product = $l->product_name.($l->product_type === 'variable' ? ' - '.$l->variation_name : '');
                $r->free_qty = $free;
                $r->discount = $discount;
                $r->sale_value = round($free * (float) $l->unit_price_before_discount + $discount, 4);
                $r->cost_value = round($free * (float) $l->unit_cost + $discount, 4);
                $rows->push($r);
            }
        }

        return $rows;
    }

    /**
     * Saving a sale: re-works the schemes for the posted lines (evaluate) and records on each line what it really
     * got (scheme_data, within budgets) — only for schemes the screen applied (trade_scheme_ids / free lines). The
     * price and discount stay as staff entered them.
     *
     * @param  array  $products  the posted products, changed in place: trade_scheme_id, scheme_free_qty, scheme_data
     */
    public static function applyToLines(&$products, $transaction, $location_id, callable $num_uf): void
    {
        if (! self::installed() || empty($products)) {
            return;
        }
        $claimed = [];
        foreach ($products as $k => $p) {
            $ids = array_filter(array_map('intval', explode(',', (string) ($p['trade_scheme_ids'] ?? ''))));
            if (! empty($p['trade_scheme_id']) && ($p['scheme_role'] ?? '') !== 'free') {
                $ids[] = (int) $p['trade_scheme_id'];      // older screens / booker orders
            }
            $claimed[$k] = array_values(array_unique($ids));
            $products[$k]['trade_scheme_id'] = ($p['scheme_role'] ?? '') === 'free' ? ($p['trade_scheme_id'] ?? null) : null;
            $products[$k]['scheme_free_qty'] = 0;
            $products[$k]['scheme_data'] = null;
        }
        $any = array_filter($claimed) || collect($products)->where('scheme_role', 'free')->isNotEmpty();
        if (! $any) {
            return;
        }

        $schemes = self::activeFor($transaction->business_id, $location_id, $transaction->transaction_date, $transaction->id);
        $by_id = $schemes->keyBy('id');
        $budget = $schemes->mapWithKeys(fn ($s) => [$s->id => $s->budget_left === null ? null : $s->budget_left * $s->free_unit_mult]);
        $take = function ($scheme_id, $qty) use (&$budget) {
            if (! isset($budget[$scheme_id]) || $budget[$scheme_id] === null) {
                return $qty;
            }
            $qty = min($qty, max(0, $budget[$scheme_id]));
            $budget[$scheme_id] -= $qty;

            return $qty;
        };

        $lines = [];
        foreach ($products as $k => $p) {
            $multiplier = 1;
            if (! empty($p['sub_unit_id']) && (empty($p['product_unit_id']) || $p['sub_unit_id'] != $p['product_unit_id']) && ! empty($p['base_unit_multiplier'])) {
                $multiplier = (float) $num_uf($p['base_unit_multiplier']);
            }
            $qty = (float) $num_uf($p['quantity']);
            $lines[$k] = ['product_id' => $p['product_id'], 'variation_id' => $p['variation_id'], 'base_qty' => $qty * $multiplier,
                'value' => $qty * (float) $num_uf($p['unit_price'] ?? 0), 'free' => ($p['scheme_role'] ?? '') === 'free'];
        }
        $result = self::evaluate($schemes, $lines, self::customer($transaction->contact_id));

        // bought lines: same-product free goods and % discounts the screen applied
        foreach ($result['lines'] as $k => $effects) {
            $got = [];
            $free_base = 0;
            foreach ($effects as $e) {
                if (! in_array((int) $e['id'], $claimed[$k] ?? [], true)) {
                    continue;
                }
                if ($e['type'] === 'same') {
                    $free_base = round($take($e['id'], $e['free_base']), 4);
                    if ($free_base > 0) {
                        $got[] = ['id' => (int) $e['id'], 'free_qty' => $free_base, 'discount' => 0];
                        $products[$k]['trade_scheme_id'] = (int) $e['id'];
                        $products[$k]['scheme_free_qty'] = $free_base;
                    }
                }
            }
            $base = $lines[$k]['base_qty'];
            $paid_value = $base > 0 ? $lines[$k]['value'] * (1 - $free_base / $base) : 0;
            foreach ($effects as $e) {
                if ($e['type'] === 'percent' && in_array((int) $e['id'], $claimed[$k] ?? [], true)) {
                    $got[] = ['id' => (int) $e['id'], 'free_qty' => 0, 'discount' => round($paid_value * $e['pct'] / 100, 4), 'pct' => $e['pct']];
                }
            }
            if (! empty($got)) {
                $products[$k]['scheme_data'] = json_encode($got);
                $products[$k]['trade_scheme_id'] = $products[$k]['trade_scheme_id'] ?: $got[0]['id'];
            }
        }

        // free lines of another product: capped at what the bill earned
        $earned = $result['free'];
        foreach ($products as $k => $p) {
            if (($p['scheme_role'] ?? '') !== 'free') {
                continue;
            }
            $s = $by_id->get((int) ($p['trade_scheme_id'] ?? 0));
            if (! $s || (int) $p['variation_id'] !== (int) $s->free_variation_id || empty($earned[$s->id])) {
                $products[$k]['trade_scheme_id'] = null;
                continue;
            }
            $free = $take($s->id, min($lines[$k]['base_qty'], $earned[$s->id] * $s->free_unit_mult));
            $earned[$s->id] -= $free / ($s->free_unit_mult ?: 1);
            if ($free <= 0) {
                $products[$k]['trade_scheme_id'] = null;
                continue;
            }
            $products[$k]['scheme_free_qty'] = round($free, 4);
            $products[$k]['scheme_data'] = json_encode([['id' => (int) $s->id, 'free_qty' => round($free, 4), 'discount' => 0]]);
        }
    }
}
