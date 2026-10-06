<?php

namespace App\Utils;

use App\Investor;
use App\InvestorCapital;
use App\InvestorDeal;
use App\InvestorPayout;
use App\InvestorSettlement;
use App\InvestorSettlementLine;
use Illuminate\Support\Facades\DB;

/**
 * Investor profit share: works out each deal's share for a period, carries losses forward, locks settlements
 * and gives each investor's capital / earned / paid / balance. Separate from the Accounts module.
 */
class InvestorUtil extends Util
{
    protected $transactionUtil;

    //profit of the same scope + period is only calculated once per request
    protected $profit_cache = [];

    public function __construct(TransactionUtil $transactionUtil)
    {
        $this->transactionUtil = $transactionUtil;
    }

    /**
     * Profit a deal shares in, for $start..$end (already limited to the deal's own dates).
     * brand / product: gross profit (sale - purchase cost, returns removed). overall: net profit as in Profit / Loss.
     */
    public function scopeProfit($business_id, InvestorDeal $deal, $start, $end)
    {
        $key = implode('|', [$deal->scope, $deal->brand_id, $deal->product_id, $deal->location_id, $start, $end]);
        if (array_key_exists($key, $this->profit_cache)) {
            return $this->profit_cache[$key];
        }

        if ($deal->scope == 'overall') {
            $details = $this->transactionUtil->getProfitLossDetails($business_id, $deal->location_id, $start, $end, null, 'all');
            $profit = (float) $details['net_profit'] + $this->returnTiming($business_id, $deal->location_id, $start, $end);
        } else {
            $filters = $this->dealFilters($deal);
            $profit = (float) $this->transactionUtil->getGrossProfit($business_id, $start, $end, $deal->location_id, null, 'all', $filters + ['gross_of_returns' => true])
                - (float) $this->returnLines($business_id, $start, $end, $deal->location_id, $filters)->sum('profit');
        }

        return $this->profit_cache[$key] = round($profit, 4);
    }

    protected function dealFilters(InvestorDeal $deal)
    {
        if ($deal->scope == 'brand') {
            return ['brand_id' => $deal->brand_id];
        }

        return $deal->scope == 'product' ? ['product_id' => $deal->product_id] : [];
    }

    /**
     * Sell returns MADE in the period (by return date), any sale date: one row per returned line with the
     * sale value and purchase cost taken back and the profit removed.
     * Investor periods get locked and paid, so a return of an old sale must reduce the period it happens in,
     * never a period that is already settled. Covers returns against an invoice and returns without invoice.
     */
    public function returnLines($business_id, $start, $end, $location_id = null, $filters = [])
    {
        //cost of one unit of that sell line: its linked purchase lines, else the default purchase price
        $unit_cost = 'IF(P.enable_stock = 0 AND P.type != "combo", 0, COALESCE(
            IF(P.type = "combo",
                (SELECT SUM(t2.quantity * COALESCE(pl2.purchase_price, v2.default_purchase_price, 0))
                    FROM transaction_sell_lines AS c2
                    JOIN transaction_sell_lines_purchase_lines AS t2 ON t2.sell_line_id = c2.id
                    JOIN variations AS v2 ON v2.id = c2.variation_id
                    LEFT JOIN purchase_lines AS pl2 ON pl2.id = t2.purchase_line_id
                    WHERE c2.parent_sell_line_id = sl.id) / NULLIF(sl.quantity, 0),
                (SELECT SUM(t1.quantity * COALESCE(pl1.purchase_price, V.default_purchase_price, 0)) / NULLIF(SUM(t1.quantity), 0)
                    FROM transaction_sell_lines_purchase_lines AS t1
                    LEFT JOIN purchase_lines AS pl1 ON pl1.id = t1.purchase_line_id
                    WHERE t1.sell_line_id = sl.id)
            ), V.default_purchase_price, 0))';
        $sale_price = '(sl.unit_price_inc_tax - COALESCE(sl.item_tax, 0))';
        $without_invoice_qty = '(SELECT COALESCE(SUM(r.quantity), 0) FROM return_sell_lines AS r WHERE r.transaction_sell_id = sl.id)';

        $base = function ($query) use ($business_id, $start, $end, $location_id, $filters) {
            $query->join('transactions as sale', 'sale.id', '=', 'sl.transaction_id')
                ->join('products as P', 'P.id', '=', 'sl.product_id')
                ->join('variations as V', 'V.id', '=', 'sl.variation_id')
                ->leftJoin('brands as B', 'B.id', '=', 'P.brand_id')
                ->leftJoin('units as U', 'U.id', '=', 'P.unit_id')
                ->leftJoin('contacts as C', 'C.id', '=', 'ret.contact_id')
                ->where('ret.business_id', $business_id)
                ->where('ret.type', 'sell_return')
                ->where('ret.status', 'final')
                ->where('sl.children_type', '!=', 'combo')
                ->whereDate('ret.transaction_date', '>=', $start)
                ->whereDate('ret.transaction_date', '<=', $end)
                ->when(! empty($location_id), fn ($q) => $q->where('ret.location_id', $location_id))
                ->when(! empty($filters['brand_id']), fn ($q) => $q->where('P.brand_id', $filters['brand_id']))
                ->when(! empty($filters['product_id']), fn ($q) => $q->where('P.id', $filters['product_id']));

            return $query;
        };
        $columns = function ($qty, $price) use ($unit_cost) {
            return [
                'ret.id as return_id', 'ret.return_parent_id', 'ret.invoice_no as return_no', 'ret.transaction_date as return_date',
                'sale.id as transaction_id', 'sale.invoice_no', 'sale.transaction_date as sale_date',
                DB::raw("COALESCE(NULLIF(C.supplier_business_name, ''), C.name) as customer"),
                'P.id as product_id', 'P.name as product', 'P.sku', 'B.name as brand', 'U.short_name as unit',
                DB::raw("$qty as qty"),
                DB::raw("$price as price"),
                DB::raw("$qty * $price as value"),
                DB::raw("$qty * $unit_cost as cost"),
            ];
        };

        //returned against the invoice (quantity_returned minus what came back without invoice)
        $with_invoice = $base(DB::table('transaction_sell_lines as sl')
            ->join('transactions as ret', function ($join) {
                $join->on('ret.return_parent_id', '=', 'sl.transaction_id');
            }))
            ->whereRaw("sl.quantity_returned - $without_invoice_qty > 0")
            ->select($columns("(sl.quantity_returned - $without_invoice_qty)", $sale_price))
            ->get();

        //returned without invoice (each line points at the old sell line it came from, refund at its own price)
        $without_invoice = $base(DB::table('return_sell_lines as rsl')
            ->join('transactions as ret', 'ret.id', '=', 'rsl.return_transaction_id')
            ->join('transaction_sell_lines as sl', 'sl.id', '=', 'rsl.transaction_sell_id'))
            ->select($columns('rsl.quantity', 'COALESCE(rsl.unit_price, 0)'))
            ->get();

        return $with_invoice->concat($without_invoice)
            ->map(function ($row) {
                $row->qty = (float) $row->qty;
                $row->value = (float) $row->value;
                $row->cost = (float) $row->cost;
                $row->profit = $row->value - $row->cost;

                return $row;
            })
            ->sortBy('return_date')->values();
    }

    /**
     * Overall deals: the Profit / Loss report takes a return off the day of the ORIGINAL sale. Move it to the
     * day of the return (same idea as brand / product deals): + returns in P&L's period figures, - returns made in the period.
     */
    public function returnTiming($business_id, $location_id, $start, $end)
    {
        $sale_dated = (float) $this->transactionUtil->getGrossProfit($business_id, $start, $end, $location_id, null, 'all')
            + $this->transactionUtil->getWithoutInvoiceReturnAdjustment($business_id, $start, $end, $location_id, null, 'all', 'price');
        $return_dated = (float) $this->transactionUtil->getGrossProfit($business_id, $start, $end, $location_id, null, 'all', ['gross_of_returns' => true])
            - (float) $this->returnLines($business_id, $start, $end, $location_id)->sum('profit');

        return round($return_dated - $sale_dated, 4);
    }

    /**
     * Day-weighted average capital of an investor between two dates (invests minus withdrawals).
     */
    public function averageCapital($investor_id, $start, $end)
    {
        $start = \Carbon::parse($start)->startOfDay();
        $end = \Carbon::parse($end)->startOfDay();
        $total_days = $start->diffInDays($end) + 1;

        $signed = "SUM(IF(type = 'invest', amount, -amount))";
        $balance = (float) InvestorCapital::where('investor_id', $investor_id)->whereDate('date', '<', $start->toDateString())->sum(DB::raw("IF(type = 'invest', amount, -amount)"));

        $changes = InvestorCapital::where('investor_id', $investor_id)
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->groupBy('date')
            ->orderBy('date')
            ->selectRaw("date, $signed as change_amount")
            ->get();

        $weighted = 0;
        $cursor = $start->copy();
        foreach ($changes as $change) {
            $day = \Carbon::parse($change->date)->startOfDay();
            $weighted += $balance * $cursor->diffInDays($day);
            $balance += (float) $change->change_amount;
            $cursor = $day;
        }
        $weighted += $balance * ($cursor->diffInDays($end) + 1);

        return round($weighted / max($total_days, 1), 4);
    }

    /**
     * Last locked line of a deal before a date (for loss carried forward)
     */
    public function previousLine($deal_id, $before_date)
    {
        return InvestorSettlementLine::join('investor_settlements as s', 's.id', '=', 'investor_settlement_lines.settlement_id')
            ->where('investor_settlement_lines.deal_id', $deal_id)
            ->where('s.status', 'locked')
            ->whereDate('s.period_end', '<', $before_date)
            ->orderByDesc('s.period_end')
            ->select('investor_settlement_lines.*')
            ->first();
    }

    /**
     * Live calculation of every active deal for a period (nothing is saved).
     *
     * @return \Illuminate\Support\Collection of line arrays
     */
    public function calculate($business_id, $start, $end, $investor_id = null)
    {
        $deals = InvestorDeal::with(['investor', 'brand', 'product', 'location'])
            ->where('business_id', $business_id)
            ->where('is_active', 1)
            ->whereDate('start_date', '<=', $end)
            ->where(function ($q) use ($start) {
                $q->whereNull('end_date')->orWhereDate('end_date', '>=', $start);
            })
            ->whereHas('investor', fn ($q) => $q->where('is_active', 1))
            ->when(! empty($investor_id), fn ($q) => $q->where('investor_id', $investor_id))
            ->orderBy('investor_id')
            ->get();

        return $deals->map(function ($deal) use ($business_id, $start, $end) {
            //only the part of the period the deal is running
            $from = max($start, \Carbon::parse($deal->start_date)->toDateString());
            $to = empty($deal->end_date) ? $end : min($end, \Carbon::parse($deal->end_date)->toDateString());

            $profit = $this->scopeProfit($business_id, $deal, $from, $to);

            $avg_capital = 0;
            if ($deal->share_type == 'capital') {
                $avg_capital = $this->averageCapital($deal->investor_id, $from, $to);
                //no pool capital = no share (never divide by zero)
                $percent = (float) $deal->pool_capital > 0 ? min(100, $avg_capital / (float) $deal->pool_capital * 100) : 0;
            } else {
                $percent = (float) $deal->share_percent;
            }

            $share = round($profit * $percent / 100, 4);
            $previous = $this->previousLine($deal->id, $start);
            $loss_bf = $previous ? (float) $previous->loss_carried_forward : 0;
            $net = $share - $loss_bf;

            return [
                'deal_id' => $deal->id,
                'investor_id' => $deal->investor_id,
                'investor_name' => $deal->investor->name,
                'scope_label' => $deal->scopeLabel(),
                'share_type' => $deal->share_type,
                'period_from' => $from,
                'period_to' => $to,
                'profit_base' => $profit,
                'share_percent_used' => round($percent, 4),
                'avg_capital_used' => $avg_capital,
                'share_amount' => $share,
                'loss_brought_forward' => $loss_bf,
                'loss_carried_forward' => $net < 0 ? round(-$net, 4) : 0,
                'payable' => $net > 0 ? round($net, 4) : 0,
            ];
        })->values();
    }

    /**
     * Investor report for a period: every deal with its share line plus where the profit comes from.
     * brand / product deals: one row per product (qty sold, sales, purchase cost, gross profit, investor share);
     * overall deals: the Profit / Loss summary (sales, cost of goods, gross profit, discounts, expenses, net profit).
     *
     * @return \Illuminate\Support\Collection of objects (line, deal, products, pl)
     */
    public function report($business_id, $investor_id, $start, $end, $with_sales = false)
    {
        $lines = $this->calculate($business_id, $start, $end, $investor_id);
        $deals = InvestorDeal::with(['brand', 'product', 'location'])->whereIn('id', $lines->pluck('deal_id'))->get()->keyBy('id');

        return $lines->map(function ($line) use ($business_id, $deals, $with_sales) {
            $deal = $deals[$line['deal_id']];
            $products = collect();
            $pl = null;

            $filters = $this->dealFilters($deal);
            $percent = $line['share_percent_used'];
            $is_overall = $deal->scope == 'overall';
            //overall deals share net profit, so a per-line share would be misleading there
            $share_of = fn ($profit) => $is_overall ? null : round((float) $profit * $percent / 100, 4);

            //returns made in the period (by return date): they take profit off this period
            $returns = $is_overall && ! $with_sales ? collect() : $this->returnLines($business_id, $line['period_from'], $line['period_to'], $deal->location_id, $filters)
                ->map(function ($row) use ($share_of) {
                    $row->share = $share_of($row->profit);

                    return $row;
                });

            //every sold line of the deal, as sold (date, invoice, customer, qty, sale, cost, profit, share) for the detail tabs
            $sales = collect();
            if ($with_sales) {
                $sales = $this->transactionUtil->getGrossProfit($business_id, $line['period_from'], $line['period_to'], $deal->location_id, null, 'all', $filters + ['group_by_line' => true, 'gross_of_returns' => true])
                    ->map(function ($row) use ($share_of) {
                        $row->share = $share_of($row->profit);

                        return $row;
                    });
            }
            if ($is_overall) {
                $details = $this->transactionUtil->getProfitLossDetails($business_id, $deal->location_id, $line['period_from'], $line['period_to'], null, 'all');
                $pl = [
                    'total_sell' => (float) $details['total_sell'],
                    'cogs' => (float) $details['cogs'],
                    'gross_profit' => (float) $details['gross_profit'],
                    'total_sell_discount' => (float) $details['total_sell_discount'],
                    'total_expense' => (float) $details['total_expense'],
                    'total_builty' => (float) ($details['total_builty'] ?? 0),
                    'other' => (float) $details['net_profit'] - (float) $details['gross_profit'] + (float) $details['total_sell_discount'] + (float) $details['total_expense'] + (float) ($details['total_builty'] ?? 0),
                    'pl_net_profit' => (float) $details['net_profit'],
                    'return_timing' => $this->returnTiming($business_id, $deal->location_id, $line['period_from'], $line['period_to']),
                ];
                $pl['net_profit'] = $pl['pl_net_profit'] + $pl['return_timing'];
            } else {
                //per product: sold in the period minus returned in the period
                $sold = $this->transactionUtil->getGrossProfit($business_id, $line['period_from'], $line['period_to'], $deal->location_id, null, 'all', $filters + ['group_by_product' => true, 'gross_of_returns' => true])
                    ->keyBy('product_id');
                foreach ($returns->groupBy('product_id') as $product_id => $rows) {
                    if (! $sold->has($product_id)) {
                        $first = $rows->first();
                        $sold[$product_id] = (object) ['product_id' => $product_id, 'product' => $first->product, 'sku' => $first->sku,
                            'brand' => $first->brand, 'unit' => $first->unit, 'qty' => 0, 'sales' => 0, 'cost' => 0, 'profit' => 0, ];
                    }
                    $sold[$product_id]->ret_qty = $rows->sum('qty');
                    $sold[$product_id]->ret_value = $rows->sum('value');
                    $sold[$product_id]->ret_cost = $rows->sum('cost');
                }
                $products = $sold->map(function ($row) use ($share_of) {
                    $row->ret_qty = $row->ret_qty ?? 0;
                    $row->ret_value = $row->ret_value ?? 0;
                    $row->ret_cost = $row->ret_cost ?? 0;
                    $row->net_sales = (float) $row->sales - $row->ret_value;
                    $row->net_cost = (float) $row->cost - $row->ret_cost;
                    $row->profit = $row->net_sales - $row->net_cost;
                    $row->share = $share_of($row->profit);

                    return $row;
                })->sortByDesc('net_sales')->values();
            }

            return (object) ['line' => (object) $line, 'deal' => $deal, 'products' => $products, 'pl' => $pl, 'sales' => $sales, 'returns' => $returns];
        });
    }

    /**
     * Why a period cannot be locked (null = ok): it must not overlap another settlement and must come after the last one
     */
    public function periodError($business_id, $start, $end, $ignore_id = null)
    {
        if ($end < $start) {
            return 'The end date is before the start date';
        }
        $settlements = InvestorSettlement::where('business_id', $business_id)
            ->when(! empty($ignore_id), fn ($q) => $q->where('id', '!=', $ignore_id));

        $overlap = (clone $settlements)->whereDate('period_start', '<=', $end)->whereDate('period_end', '>=', $start)->first();
        if (! empty($overlap)) {
            return 'This period overlaps the settlement of '.$this->format_date($overlap->period_start).' to '.$this->format_date($overlap->period_end);
        }
        $later = (clone $settlements)->whereDate('period_start', '>', $end)->exists();
        if ($later) {
            return 'A later period is already settled; settlements must be locked in date order';
        }

        return null;
    }

    /**
     * Calculate and freeze a period
     */
    public function lock($business_id, $start, $end, $user_id, $note = null)
    {
        return DB::transaction(function () use ($business_id, $start, $end, $user_id, $note) {
            $lines = $this->calculate($business_id, $start, $end);
            $settlement = InvestorSettlement::create([
                'business_id' => $business_id,
                'period_start' => $start,
                'period_end' => $end,
                'status' => 'locked',
                'locked_at' => \Carbon::now(),
                'locked_by' => $user_id,
                'note' => $note,
            ]);
            foreach ($lines as $line) {
                $settlement->lines()->create(collect($line)->only([
                    'investor_id', 'deal_id', 'scope_label', 'profit_base', 'share_percent_used', 'avg_capital_used',
                    'share_amount', 'loss_brought_forward', 'loss_carried_forward', 'payable',
                ])->all());
            }

            return $settlement;
        });
    }

    /**
     * Capital / earned / paid / balance per investor
     *
     * @return array keyed by investor id
     */
    public function balances($business_id)
    {
        $capital = InvestorCapital::where('business_id', $business_id)->groupBy('investor_id')
            ->selectRaw("investor_id, SUM(IF(type = 'invest', amount, -amount)) as total")->pluck('total', 'investor_id');
        $earned = InvestorSettlementLine::join('investor_settlements as s', 's.id', '=', 'investor_settlement_lines.settlement_id')
            ->where('s.business_id', $business_id)->where('s.status', 'locked')
            ->groupBy('investor_settlement_lines.investor_id')
            ->selectRaw('investor_settlement_lines.investor_id, SUM(payable) as total')->pluck('total', 'investor_id');
        $paid = InvestorPayout::where('business_id', $business_id)->groupBy('investor_id')
            ->selectRaw('investor_id, SUM(amount) as total')->pluck('total', 'investor_id');

        $out = [];
        foreach (Investor::where('business_id', $business_id)->pluck('id') as $id) {
            $out[$id] = [
                'capital' => (float) ($capital[$id] ?? 0),
                'earned' => (float) ($earned[$id] ?? 0),
                'paid' => (float) ($paid[$id] ?? 0),
            ];
            $out[$id]['balance'] = $out[$id]['earned'] - $out[$id]['paid'];
        }

        return $out;
    }
}
