{{-- Statement page only: what was sold in the period for this investor's deals, like the Commission Agent Report tabs --}}
@php
    $all_sales = $report->flatMap(function ($r) {
        return $r->sales->map(function ($s) use ($r) {
            $s = clone $s;
            $s->deal_label = $r->line->scope_label;
            $s->is_overall = $r->deal->scope == 'overall';

            return $s;
        });
    });
    $all_returns = $report->flatMap(function ($r) {
        return $r->returns->map(function ($s) use ($r) {
            $s = clone $s;
            $s->deal_label = $r->line->scope_label;

            return $s;
        });
    });
    $many_deals = $report->count() > 1;
    $sum_of = function ($rows, $key) {
        return $rows->sum(fn ($x) => (float) $x->$key);
    };
    $share_of = function ($rows) {
        return $rows->contains(fn ($x) => $x->share === null) ? null : $rows->sum(fn ($x) => (float) $x->share);
    };
    $group = function ($key_fn) use ($all_sales, $sum_of, $share_of) {
        return $all_sales->groupBy($key_fn)->map(function ($rows) use ($sum_of, $share_of) {
            $first = $rows->first();

            return (object) [
                'deal_label' => $first->deal_label,
                'product' => $first->product,
                'sku' => $first->sku,
                'brand' => $first->brand ?: '(No brand)',
                'unit' => $first->unit,
                'invoice_no' => $first->invoice_no,
                'transaction_id' => $first->transaction_id,
                'transaction_date' => $first->transaction_date,
                'customer' => $first->customer,
                'final_total' => $first->final_total,
                'payment_status' => $first->payment_status,
                'invoices' => $rows->pluck('transaction_id')->unique()->count(),
                'products' => $rows->pluck('product_id')->unique()->count(),
                'qty' => $sum_of($rows, 'qty'),
                'sales' => $sum_of($rows, 'sales'),
                'cost' => $sum_of($rows, 'cost'),
                'profit' => $sum_of($rows, 'profit'),
                'share' => $share_of($rows),
            ];
        })->sortByDesc('sales')->values();
    };
    $by_product = $group(fn ($x) => $x->deal_label.'|'.$x->product_id);
    $by_brand = $group(fn ($x) => $x->deal_label.'|'.$x->brand);
    $by_invoice = $group(fn ($x) => $x->deal_label.'|'.$x->transaction_id)->sortBy('transaction_date')->values();
    $t_qty = $sum_of($all_sales, 'qty');
    $t_sales = $sum_of($all_sales, 'sales');
    $t_cost = $sum_of($all_sales, 'cost');
    $t_profit = $sum_of($all_sales, 'profit');
    $t_share = $share_of($all_sales);
    $rt_qty = $sum_of($all_returns, 'qty');
    $rt_value = $sum_of($all_returns, 'value');
    $rt_cost = $sum_of($all_returns, 'cost');
    $rt_profit = $sum_of($all_returns, 'profit');
    $rt_share = $share_of($all_returns);
    //returns of each sold line made in this period, to mark them on the sales detail
    $returned_in_period = $all_returns->groupBy(fn ($x) => $x->transaction_id.'|'.$x->product_id)->map(fn ($rows) => $rows->sum('qty'));
    $date_format = session('business.date_format');
@endphp
<style>
    .inv-tabs .inv-toolbar { display: flex; gap: 8px; margin-bottom: 8px; flex-wrap: wrap; }
    .inv-tabs .inv-toolbar input { max-width: 300px; }
    .inv-tabs table td.r, .inv-tabs table th.r { text-align: right; white-space: nowrap; }
    .inv-tabs tfoot td { background: #eee; font-weight: bold; }
    .inv-tabs .inv-day td { background: #f6f6f6; font-weight: bold; }
</style>

<div class="nav-tabs-custom inv-tabs no-print">
    <ul class="nav nav-tabs">
        <li class="active"><a href="#inv_tab_lines" data-toggle="tab"><i class="fa fa-list"></i> Sales detail</a></li>
        <li><a href="#inv_tab_products" data-toggle="tab"><i class="fa fa-cubes"></i> Product wise</a></li>
        <li><a href="#inv_tab_brands" data-toggle="tab"><i class="fa fa-tags"></i> Brand wise</a></li>
        <li><a href="#inv_tab_invoices" data-toggle="tab"><i class="fa fa-file-alt"></i> Invoices</a></li>
        <li><a href="#inv_tab_returns" data-toggle="tab"><i class="fa fa-undo"></i> Returns ({{ $all_returns->count() }})</a></li>
    </ul>
    <div class="tab-content">

        {{-- ============ Sales detail ============ --}}
        <div class="tab-pane active" id="inv_tab_lines">
            <div class="inv-toolbar">
                <input type="text" class="form-control input-sm inv-search" data-table="#inv_lines_table" placeholder="Search invoice, customer, product, SKU...">
            </div>
            <div class="table-responsive">
                <table class="table table-bordered table-hover table-condensed" id="inv_lines_table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Invoice no.</th>
                            <th>Customer</th>
                            @if ($many_deals)<th>Deal</th>@endif
                            <th>Product</th>
                            <th>SKU</th>
                            <th>Brand</th>
                            <th class="r">Qty</th>
                            <th class="r">Price</th>
                            <th class="r">Sale</th>
                            <th class="r">Purchase cost</th>
                            <th class="r">Profit</th>
                            <th class="r">Investor share</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($all_sales->groupBy(fn ($x) => substr($x->transaction_date, 0, 10)) as $day => $day_rows)
                            @foreach ($day_rows as $row)
                                @php
                                    $r_qty = (float) $row->qty;
                                    $r_price = (float) $row->unit_price;
                                    $r_sales = (float) $row->sales;
                                    $r_cost = (float) $row->cost;
                                    $r_profit = (float) $row->profit;
                                    $r_share = $row->share;
                                @endphp
                                <tr class="inv-row">
                                    <td style="white-space: nowrap;">{{ \Carbon::parse($row->transaction_date)->format($date_format) }}</td>
                                    <td><a href="#" data-href="{{ action([\App\Http\Controllers\SellController::class, 'show'], [$row->transaction_id]) }}" class="btn-modal" data-container=".view_modal">{{ $row->invoice_no }}</a></td>
                                    <td>{{ $row->customer }}</td>
                                    @if ($many_deals)<td>{{ $row->deal_label }}</td>@endif
                                    <td>{{ $row->product }}</td>
                                    <td>{{ $row->sku }}</td>
                                    <td>{{ $row->brand }}</td>
                                    @php $r_returned = (float) ($returned_in_period[$row->transaction_id.'|'.$row->product_id] ?? 0); @endphp
                                    <td class="r">{{ @format_quantity($r_qty) }} {{ $row->unit }}
                                        @if ($r_returned > 0)<br><span class="label label-danger">returned {{ @format_quantity($r_returned) }} in this period</span>
                                        @elseif ($row->qty_returned > 0)<br><span class="label label-warning">returned {{ @format_quantity($row->qty_returned) }} later</span>@endif
                                    </td>
                                    <td class="r">@format_currency($r_price)</td>
                                    <td class="r">@format_currency($r_sales)</td>
                                    <td class="r">@format_currency($r_cost)</td>
                                    <td class="r">@format_currency($r_profit)</td>
                                    <td class="r">@if ($r_share !== null) @format_currency($r_share) @else <small class="text-muted">net profit deal</small> @endif</td>
                                </tr>
                            @endforeach
                            @php
                                $d_qty = $sum_of($day_rows, 'qty');
                                $d_sales = $sum_of($day_rows, 'sales');
                                $d_cost = $sum_of($day_rows, 'cost');
                                $d_profit = $sum_of($day_rows, 'profit');
                                $d_share = $share_of($day_rows);
                            @endphp
                            <tr class="inv-day">
                                <td colspan="{{ $many_deals ? 7 : 6 }}" class="r">{{ \Carbon::parse($day)->format($date_format) }} total ({{ $day_rows->pluck('transaction_id')->unique()->count() }} invoices)</td>
                                <td class="r">{{ @format_quantity($d_qty) }}</td>
                                <td></td>
                                <td class="r">@format_currency($d_sales)</td>
                                <td class="r">@format_currency($d_cost)</td>
                                <td class="r">@format_currency($d_profit)</td>
                                <td class="r">@if ($d_share !== null) @format_currency($d_share) @endif</td>
                            </tr>
                        @empty
                            <tr><td colspan="13" class="text-center text-muted">No sales in this period</td></tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="{{ $many_deals ? 7 : 6 }}">Grand total ({{ $all_sales->pluck('transaction_id')->unique()->count() }} invoices, {{ $all_sales->count() }} lines)</td>
                            <td class="r">{{ @format_quantity($t_qty) }}</td>
                            <td></td>
                            <td class="r">@format_currency($t_sales)</td>
                            <td class="r">@format_currency($t_cost)</td>
                            <td class="r">@format_currency($t_profit)</td>
                            <td class="r">@if ($t_share !== null) @format_currency($t_share) @endif</td>
                        </tr>
                        @include('investor.partials.net_rows', ['span' => $many_deals ? 7 : 6])
                    </tfoot>
                </table>
            </div>
        </div>

        {{-- ============ Product wise / Brand wise ============ --}}
        @foreach (['products' => [$by_product, 'Product'], 'brands' => [$by_brand, 'Brand']] as $tab => [$rows, $title])
            <div class="tab-pane" id="inv_tab_{{ $tab }}">
                <div class="inv-toolbar">
                    <input type="text" class="form-control input-sm inv-search" data-table="#inv_{{ $tab }}_table" placeholder="Search {{ strtolower($title) }}...">
                </div>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover table-condensed" id="inv_{{ $tab }}_table">
                        <thead>
                            <tr>
                                <th>#</th>
                                @if ($many_deals)<th>Deal</th>@endif
                                @if ($tab == 'products')
                                    <th>Product</th><th>SKU</th><th>Brand</th>
                                @else
                                    <th>Brand</th><th class="r">Products</th>
                                @endif
                                <th class="r">Invoices</th>
                                <th class="r">Qty sold</th>
                                <th class="r">Avg. price</th>
                                <th class="r">Sale</th>
                                <th class="r">Purchase cost</th>
                                <th class="r">Profit</th>
                                <th class="r">Investor share</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($rows as $i => $row)
                                @php
                                    $g_qty = $row->qty;
                                    $g_avg = $row->qty != 0 ? $row->sales / $row->qty : 0;
                                    $g_sales = $row->sales;
                                    $g_cost = $row->cost;
                                    $g_profit = $row->profit;
                                    $g_share = $row->share;
                                @endphp
                                <tr class="inv-row">
                                    <td>{{ $i + 1 }}</td>
                                    @if ($many_deals)<td>{{ $row->deal_label }}</td>@endif
                                    @if ($tab == 'products')
                                        <td><b>{{ $row->product }}</b></td><td>{{ $row->sku }}</td><td>{{ $row->brand }}</td>
                                    @else
                                        <td><b>{{ $row->brand }}</b></td><td class="r">{{ $row->products }}</td>
                                    @endif
                                    <td class="r">{{ $row->invoices }}</td>
                                    <td class="r">{{ @format_quantity($g_qty) }} @if ($tab == 'products') {{ $row->unit }} @endif</td>
                                    <td class="r">@if ($tab == 'products') @format_currency($g_avg) @endif</td>
                                    <td class="r">@format_currency($g_sales)</td>
                                    <td class="r">@format_currency($g_cost)</td>
                                    <td class="r">@format_currency($g_profit)</td>
                                    <td class="r">@if ($g_share !== null) @format_currency($g_share) @else <small class="text-muted">net profit deal</small> @endif</td>
                                </tr>
                            @empty
                                <tr><td colspan="13" class="text-center text-muted">No sales in this period</td></tr>
                            @endforelse
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="{{ ($many_deals ? 1 : 0) + ($tab == 'products' ? 5 : 4) }}">Total ({{ $rows->count() }} {{ strtolower($title) }}s)</td>
                                <td class="r">{{ @format_quantity($t_qty) }}</td>
                                <td></td>
                                <td class="r">@format_currency($t_sales)</td>
                                <td class="r">@format_currency($t_cost)</td>
                                <td class="r">@format_currency($t_profit)</td>
                                <td class="r">@if ($t_share !== null) @format_currency($t_share) @endif</td>
                            </tr>
                            @include('investor.partials.net_rows', ['span' => ($many_deals ? 1 : 0) + ($tab == 'products' ? 5 : 4)])
                        </tfoot>
                    </table>
                </div>
            </div>
        @endforeach

        {{-- ============ Invoices ============ --}}
        <div class="tab-pane" id="inv_tab_invoices">
            <div class="inv-toolbar">
                <input type="text" class="form-control input-sm inv-search" data-table="#inv_invoices_table" placeholder="Search invoice no. or customer...">
            </div>
            <div class="table-responsive">
                <table class="table table-bordered table-hover table-condensed" id="inv_invoices_table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Invoice no.</th>
                            <th>Customer</th>
                            @if ($many_deals)<th>Deal</th>@endif
                            <th>Payment</th>
                            <th class="r">Qty</th>
                            <th class="r">Invoice total</th>
                            <th class="r">Sale of these items</th>
                            <th class="r">Purchase cost</th>
                            <th class="r">Profit</th>
                            <th class="r">Investor share</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($by_invoice as $row)
                            @php
                                $v_qty = $row->qty;
                                $v_total = (float) $row->final_total;
                                $v_sales = $row->sales;
                                $v_cost = $row->cost;
                                $v_profit = $row->profit;
                                $v_share = $row->share;
                            @endphp
                            <tr class="inv-row">
                                <td style="white-space: nowrap;">{{ \Carbon::parse($row->transaction_date)->format($date_format) }}</td>
                                <td><a href="#" data-href="{{ action([\App\Http\Controllers\SellController::class, 'show'], [$row->transaction_id]) }}" class="btn-modal" data-container=".view_modal">{{ $row->invoice_no }}</a></td>
                                <td>{{ $row->customer }}</td>
                                @if ($many_deals)<td>{{ $row->deal_label }}</td>@endif
                                <td><span class="label @payment_status($row->payment_status)">{{ __('lang_v1.'.$row->payment_status) }}</span></td>
                                <td class="r">{{ @format_quantity($v_qty) }}</td>
                                <td class="r">@format_currency($v_total)</td>
                                <td class="r">@format_currency($v_sales)</td>
                                <td class="r">@format_currency($v_cost)</td>
                                <td class="r">@format_currency($v_profit)</td>
                                <td class="r">@if ($v_share !== null) @format_currency($v_share) @endif</td>
                            </tr>
                        @empty
                            <tr><td colspan="11" class="text-center text-muted">No invoices in this period</td></tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="{{ $many_deals ? 5 : 4 }}">Total ({{ $by_invoice->pluck('transaction_id')->unique()->count() }} invoices)</td>
                            <td class="r">{{ @format_quantity($t_qty) }}</td>
                            <td></td>
                            <td class="r">@format_currency($t_sales)</td>
                            <td class="r">@format_currency($t_cost)</td>
                            <td class="r">@format_currency($t_profit)</td>
                            <td class="r">@if ($t_share !== null) @format_currency($t_share) @endif</td>
                        </tr>
                        @include('investor.partials.net_rows', ['span' => $many_deals ? 5 : 4])
                    </tfoot>
                </table>
            </div>
        </div>

        {{-- ============ Returns made in the period ============ --}}
        <div class="tab-pane" id="inv_tab_returns">
            <div class="inv-toolbar">
                <input type="text" class="form-control input-sm inv-search" data-table="#inv_returns_table" placeholder="Search return no., invoice, customer, product...">
            </div>
            <p class="text-muted small">A return is taken off the period in which it is made (its return date), even when the sale was in an
                earlier period that is already locked and paid. Includes returns made without an invoice.</p>
            <div class="table-responsive">
                <table class="table table-bordered table-hover table-condensed" id="inv_returns_table">
                    <thead>
                        <tr>
                            <th>Return date</th>
                            <th>Return no.</th>
                            <th>Sold on</th>
                            <th>Sale invoice</th>
                            <th>Customer</th>
                            @if ($many_deals)<th>Deal</th>@endif
                            <th>Product</th>
                            <th>SKU</th>
                            <th class="r">Qty</th>
                            <th class="r">Refund value</th>
                            <th class="r">Cost back</th>
                            <th class="r">Profit removed</th>
                            <th class="r">Share removed</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($all_returns as $row)
                            @php
                                $x_qty = $row->qty;
                                $x_value = $row->value;
                                $x_cost = $row->cost;
                                $x_profit = $row->profit;
                                $x_share = $row->share;
                            @endphp
                            <tr class="inv-row">
                                <td style="white-space: nowrap;">{{ \Carbon::parse($row->return_date)->format($date_format) }}</td>
                                <td><a href="#" data-href="{{ action([$row->return_parent_id ? \App\Http\Controllers\SellReturnController::class : \App\Http\Controllers\WithOutSellReturnController::class, 'show'], [$row->return_id]) }}" class="btn-modal" data-container=".view_modal">{{ $row->return_no }}</a></td>
                                <td style="white-space: nowrap;">{{ \Carbon::parse($row->sale_date)->format($date_format) }}</td>
                                <td><a href="#" data-href="{{ action([\App\Http\Controllers\SellController::class, 'show'], [$row->transaction_id]) }}" class="btn-modal" data-container=".view_modal">{{ $row->invoice_no }}</a></td>
                                <td>{{ $row->customer }}</td>
                                @if ($many_deals)<td>{{ $row->deal_label }}</td>@endif
                                <td>{{ $row->product }}</td>
                                <td>{{ $row->sku }}</td>
                                <td class="r">{{ @format_quantity($x_qty) }} {{ $row->unit }}</td>
                                <td class="r">@format_currency($x_value)</td>
                                <td class="r">@format_currency($x_cost)</td>
                                <td class="r">@format_currency($x_profit)</td>
                                <td class="r">@if ($x_share !== null) @format_currency($x_share) @endif</td>
                            </tr>
                        @empty
                            <tr><td colspan="13" class="text-center text-muted">No returns in this period</td></tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="{{ $many_deals ? 8 : 7 }}">Total ({{ $all_returns->pluck('return_id')->unique()->count() }} returns)</td>
                            <td class="r">{{ @format_quantity($rt_qty) }}</td>
                            <td class="r">@format_currency($rt_value)</td>
                            <td class="r">@format_currency($rt_cost)</td>
                            <td class="r">@format_currency($rt_profit)</td>
                            <td class="r">@if ($rt_share !== null) @format_currency($rt_share) @endif</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>
