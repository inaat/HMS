{{-- Investor statement, same print style as the Commission Agent Report (report/partials/commission_agent_pdf).
     Used on the statement page and in the PDF / WhatsApp file ($for_pdf). --}}
@php
    $date_format = session('business.date_format');
    $d = fn ($date) => ! empty($date) ? \Carbon::parse($date)->format($date_format) : '';
    $range_text = $d($start).' ~ '.$d($end);
    $period_status = ! empty($period['settlement']) ? 'Locked' : (! empty($period['error']) ? 'Cannot lock: '.$period['error'] : 'Not locked yet (live figures)');
    $period_payable = $period['payable'];
    $period_paid = $period['paid'];
    $period_due = $period['due'];
    $all_sales = $report->sum(fn ($r) => $r->pl ? $r->pl['total_sell'] : $r->products->sum('net_sales'));
    $all_profit = $report->sum(fn ($r) => $r->line->profit_base);
    $all_share = $report->sum(fn ($r) => $r->line->share_amount);
@endphp
<style>
    .inv-print { font-family: sans-serif; font-size: 11px; color: #111; }
    .inv-print h2 { margin: 0; font-size: 16px; text-align: center; }
    .inv-print h3 { margin: 2px 0 8px; font-size: 13px; text-align: center; font-weight: normal; }
    .inv-print h4 { margin: 14px 0 4px; font-size: 12px; }
    .inv-print table { width: 100%; border-collapse: collapse; }
    .inv-print th, .inv-print td { border: 1px solid #999; padding: 3px 5px; }
    .inv-print th { background: #eee; text-align: left; }
    .inv-print .r { text-align: right; }
    .inv-print .total td { background: #ddd; font-weight: bold; }
    .inv-print .summary th { width: 20%; white-space: nowrap; }
    .inv-print .summary td { width: 30%; }
    .inv-print .r { white-space: nowrap; }
    .inv-print .lines th, .inv-print .lines td { font-size: 10px; }
    .inv-print .payable th, .inv-print .payable td { font-size: 13px; font-weight: bold; background: #ddd; }
    .inv-print .muted { color: #666; text-align: center; }
</style>

<div class="inv-print">
    <h2>{{ optional($business)->name ?? session('business.name') }}</h2>
    <h3>Investor Statement</h3>

    <table class="summary">
        <tr>
            <th>Investor</th><td><b>{{ $investor->name }}</b></td>
            <th>Mobile</th><td>{{ $investor->mobile }}</td>
        </tr>
        <tr>
            <th>Period</th><td><b>{{ $range_text }}</b></td>
            <th>Statement date</th><td>{{ \Carbon::now()->format($date_format) }}</td>
        </tr>
        <tr>
            <th>Sales (after returns)</th><td>@format_currency($all_sales)</td>
            <th>Profit</th><td>@format_currency($all_profit)</td>
        </tr>
        <tr>
            <th>Investor share</th><td>@format_currency($all_share)</td>
            <th>Status</th><td>{{ $period_status }}</td>
        </tr>
        <tr>
            <th>Payable this period</th><td>@format_currency($period_payable)</td>
            <th>Paid for this period</th><td>@format_currency($period_paid)</td>
        </tr>
        <tr class="payable">
            <th colspan="3" class="r">Due for this period</th>
            <td>@format_currency($period_due)</td>
        </tr>
    </table>

    @foreach ($report as $row)
        @php
            $line = $row->line;
            $percent_text = number_format($line->share_percent_used, 2).'%'.($line->share_type == 'capital' ? ' (by capital)' : '');
            $sum_qty = $row->products->sum('qty');
            $sum_sales = $row->products->sum('sales');
            $sum_ret_qty = $row->products->sum('ret_qty');
            $sum_ret = $row->products->sum('ret_value');
            $sum_cost = $row->products->sum('net_cost');
            $sum_profit = $row->products->sum('profit');
            $sum_share = $row->products->sum('share');
            $part = $line->period_from != $start || $line->period_to != $end ? ' ('.$d($line->period_from).' ~ '.$d($line->period_to).')' : '';
        @endphp
        <h4>{{ $line->scope_label }}: {{ $percent_text }} of {{ $row->deal->scope == 'overall' ? 'net' : 'gross' }} profit{{ $part }}</h4>
        @if ($row->pl)
            @php $pl = $row->pl; @endphp
            <table class="lines">
                <tr><th>Total sales</th><td class="r">@format_currency($pl['total_sell'])</td></tr>
                <tr><th>Purchase cost of goods sold</th><td class="r">@format_currency($pl['cogs'])</td></tr>
                <tr class="total"><td>Gross profit</td><td class="r">@format_currency($pl['gross_profit'])</td></tr>
                <tr><th>Sales discount</th><td class="r">@format_currency($pl['total_sell_discount'])</td></tr>
                <tr><th>Expenses</th><td class="r">@format_currency($pl['total_expense'])</td></tr>
                @if ($pl['total_builty'] != 0)
                    <tr><th>Builty</th><td class="r">@format_currency($pl['total_builty'])</td></tr>
                @endif
                @if (abs($pl['other']) > 0.009)
                    <tr><th>Other (returns, adjustments, ...)</th><td class="r">@format_currency($pl['other'])</td></tr>
                @endif
                <tr><th>Net profit as in Profit / Loss report</th><td class="r">@format_currency($pl['pl_net_profit'])</td></tr>
                @if (abs($pl['return_timing']) > 0.009)
                    <tr><th>Returns moved to the day of the return (old sales returned now / sales of this period returned later)</th><td class="r">@format_currency($pl['return_timing'])</td></tr>
                @endif
                <tr class="total"><td>Net profit for the investor</td><td class="r">@format_currency($pl['net_profit'])</td></tr>
            </table>
        @else
            <table class="lines">
                <tr>
                    <th style="width: 20px;">#</th><th>Product</th><th>SKU</th><th>Brand</th>
                    <th class="r">Qty sold</th><th class="r">Sales</th><th class="r">Qty returned</th><th class="r">Returns</th>
                    <th class="r">Purchase cost</th><th class="r">Gross profit</th><th class="r">Investor share</th>
                </tr>
                @forelse ($row->products as $i => $p)
                    @php
                        $p_qty = (float) $p->qty;
                        $p_sales = (float) $p->sales;
                        $p_ret_qty = (float) $p->ret_qty;
                        $p_ret = (float) $p->ret_value;
                        $p_cost = (float) $p->net_cost;
                        $p_profit = (float) $p->profit;
                        $p_share = (float) $p->share;
                    @endphp
                    <tr>
                        <td>{{ $i + 1 }}</td>
                        <td>{{ $p->product }}</td>
                        <td>{{ $p->sku }}</td>
                        <td>{{ $p->brand }}</td>
                        <td class="r">{{ @num_format($p_qty) }} {{ $p->unit }}</td>
                        <td class="r">@format_currency($p_sales)</td>
                        <td class="r">@if ($p_ret_qty > 0) {{ @num_format($p_ret_qty) }} @endif</td>
                        <td class="r">@if ($p_ret_qty > 0) &minus;@format_currency($p_ret) @endif</td>
                        <td class="r">@format_currency($p_cost)</td>
                        <td class="r">@format_currency($p_profit)</td>
                        <td class="r">@format_currency($p_share)</td>
                    </tr>
                @empty
                    <tr><td colspan="11" class="muted">No sales or returns in this period</td></tr>
                @endforelse
                <tr class="total">
                    <td colspan="4">Total</td>
                    <td class="r">{{ @num_format($sum_qty) }}</td>
                    <td class="r">@format_currency($sum_sales)</td>
                    <td class="r">{{ $sum_ret_qty > 0 ? @num_format($sum_ret_qty) : '' }}</td>
                    <td class="r">@if ($sum_ret_qty > 0) &minus;@format_currency($sum_ret) @endif</td>
                    <td class="r">@format_currency($sum_cost)</td>
                    <td class="r">@format_currency($sum_profit)</td>
                    <td class="r">@format_currency($sum_share)</td>
                </tr>
            </table>
            @if ($row->returns->count())
                <table class="lines" style="margin-top: 3px;">
                    <tr><th colspan="9">Returns made in this period (taken off this period, whatever the sale date)</th></tr>
                    <tr>
                        <th>Return date</th><th>Return no.</th><th>Sold on / invoice</th><th>Customer</th><th>Product</th>
                        <th class="r">Qty</th><th class="r">Refund value</th><th class="r">Cost back</th><th class="r">Profit removed</th>
                    </tr>
                    @foreach ($row->returns as $rt)
                        @php
                            $rt_qty = $rt->qty;
                            $rt_value = $rt->value;
                            $rt_cost = $rt->cost;
                            $rt_profit = $rt->profit;
                        @endphp
                        <tr>
                            <td>{{ $d($rt->return_date) }}</td>
                            <td>{{ $rt->return_no }}</td>
                            <td>{{ $d($rt->sale_date) }} / {{ $rt->invoice_no }}</td>
                            <td>{{ $rt->customer }}</td>
                            <td>{{ $rt->product }}</td>
                            <td class="r">{{ @num_format($rt_qty) }} {{ $rt->unit }}</td>
                            <td class="r">@format_currency($rt_value)</td>
                            <td class="r">@format_currency($rt_cost)</td>
                            <td class="r">@format_currency($rt_profit)</td>
                        </tr>
                    @endforeach
                </table>
            @endif
        @endif
        @php
            $l_profit = $line->profit_base;
            $l_share = $line->share_amount;
            $l_bf = $line->loss_brought_forward;
            $l_cf = $line->loss_carried_forward;
            $l_payable = $line->payable;
        @endphp
        <table class="lines" style="margin-top: 3px;">
            <tr>
                <td>Profit @format_currency($l_profit) &times; {{ $percent_text }} = share <b>@format_currency($l_share)</b>
                    @if ($l_bf > 0) &nbsp;&minus; old loss recovered @format_currency($l_bf) @endif
                    @if ($l_cf > 0) &nbsp;| loss carried to next period @format_currency($l_cf) @endif
                </td>
                <td class="r" style="width: 22%;">Payable: <b>@format_currency($l_payable)</b></td>
            </tr>
        </table>
    @endforeach
    @if ($report->isEmpty())
        <h4>Profit share</h4>
        <table><tr><td class="muted">
            No deal is running in this period.
            @foreach ($deals as $deal)
                <br>{{ $deal->scopeLabel() }}: from {{ $d($deal->start_date) }}{{ ! empty($deal->end_date) ? ' to '.$d($deal->end_date) : '' }}
            @endforeach
        </td></tr></table>
    @endif

    <h4>All periods</h4>
    <table class="summary">
        <tr>
            <th>Capital</th><td>@format_currency($balance['capital'])</td>
            <th>Profit earned (locked)</th><td>@format_currency($balance['earned'])</td>
        </tr>
        <tr>
            <th>Paid</th><td>@format_currency($balance['paid'])</td>
            <th>Balance due</th><td><b>@format_currency($balance['balance'])</b></td>
        </tr>
    </table>

    <h4>Deals</h4>
    <table>
        <tr><th>Share of</th><th>Share</th><th>From</th><th>To</th></tr>
        @forelse ($deals as $deal)
            <tr>
                <td>{{ $deal->scopeLabel() }}</td>
                <td>
                    @if ($deal->share_type == 'capital')
                        By capital (total capital @format_currency($deal->pool_capital))
                    @else
                        {{ @num_format($deal->share_percent) }}% of {{ $deal->scope == 'overall' ? 'net' : 'gross' }} profit
                    @endif
                </td>
                <td>{{ $d($deal->start_date) }}</td>
                <td>{{ ! empty($deal->end_date) ? $d($deal->end_date) : 'Open' }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="muted">No active deals</td></tr>
        @endforelse
    </table>

    <h4>Profit share per settlement</h4>
    <table class="lines">
        <tr>
            <th>Period</th>
            <th>Share of</th>
            <th class="r">Profit</th>
            <th class="r">Share %</th>
            <th class="r">Share</th>
            <th class="r">Loss b/f</th>
            <th class="r">Loss c/f</th>
            <th class="r">Payable</th>
        </tr>
        @forelse ($lines as $line)
            <tr>
                <td>{{ $d($line->settlement->period_start) }} ~ {{ $d($line->settlement->period_end) }}</td>
                <td>{{ $line->scope_label }}</td>
                <td class="r">@format_currency($line->profit_base)</td>
                <td class="r">{{ @num_format($line->share_percent_used) }}%</td>
                <td class="r">@format_currency($line->share_amount)</td>
                <td class="r">@if ($line->loss_brought_forward > 0) @format_currency($line->loss_brought_forward) @endif</td>
                <td class="r">@if ($line->loss_carried_forward > 0) @format_currency($line->loss_carried_forward) @endif</td>
                <td class="r"><b>@format_currency($line->payable)</b></td>
            </tr>
        @empty
            <tr><td colspan="8" class="muted">No locked settlements yet</td></tr>
        @endforelse
        <tr class="total">
            <td colspan="7">Total earned</td>
            <td class="r">@format_currency($lines->sum('payable'))</td>
        </tr>
    </table>

    <h4>Payouts</h4>
    <table>
        <tr>
            <th>Date</th><th>For period</th><th>Method</th><th>Reference / note</th><th class="r">Amount</th>
            @if (empty($for_pdf))<th class="no-print" style="width: 40px;"></th>@endif
        </tr>
        @forelse ($payouts as $payout)
            <tr>
                <td>{{ $d($payout->paid_on) }}</td>
                <td>{{ $payout->settlement ? $d($payout->settlement->period_start).' ~ '.$d($payout->settlement->period_end) : '-' }}</td>
                <td>{{ $methods[$payout->method] ?? $payout->method }}</td>
                <td>{{ $payout->reference }} {{ $payout->note }}</td>
                <td class="r">@format_currency($payout->amount)</td>
                @if (empty($for_pdf))
                    <td class="no-print" style="text-align: center;">
                        @can('investor.payout')
                            <a class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-error investor-delete" data-href="{{ action([\App\Http\Controllers\InvestorController::class, 'deletePayout'], [$investor->id, $payout->id]) }}" data-confirm="Delete this payout?"><i class="fa fa-trash"></i></a>
                        @endcan
                    </td>
                @endif
            </tr>
        @empty
            <tr><td colspan="{{ empty($for_pdf) ? 6 : 5 }}" class="muted">No payouts yet</td></tr>
        @endforelse
        <tr class="total">
            <td colspan="4">Total paid</td>
            <td class="r">@format_currency($payouts->sum('amount'))</td>
            @if (empty($for_pdf))<td class="no-print"></td>@endif
        </tr>
    </table>

    <h4>Capital</h4>
    <table>
        <tr><th>Date</th><th>Type</th><th>Method</th><th>Reference / note</th><th class="r">Amount</th></tr>
        @forelse ($capitals as $entry)
            @php
                //@format_currency pastes its argument several times: give it a plain value, never an expression
                $capital_amount = $entry->type == 'invest' ? (float) $entry->amount : -1 * (float) $entry->amount;
            @endphp
            <tr>
                <td>{{ $d($entry->date) }}</td>
                <td>{{ $entry->type == 'invest' ? 'Invest' : 'Withdraw' }}</td>
                <td>{{ $methods[$entry->method] ?? $entry->method }}</td>
                <td>{{ $entry->reference }} {{ $entry->note }}</td>
                <td class="r">@format_currency($capital_amount)</td>
            </tr>
        @empty
            <tr><td colspan="5" class="muted">No capital entries</td></tr>
        @endforelse
        <tr class="total">
            <td colspan="4">Current capital</td>
            <td class="r">@format_currency($balance['capital'])</td>
        </tr>
    </table>
</div>
