@php
    $commission_of = fn ($r) => $r->commission;
    $date_format = session('business.date_format');
    $period = \Carbon::parse($filters['start_date'])->format($date_format).' ~ '.\Carbon::parse($filters['end_date'])->format($date_format);
    $product_groups = $products->groupBy(fn ($r) => $r->product_id.'_'.$r->sub_sku)->sortByDesc(fn ($rows) => $rows->sum('net_amount'));
    $brand_groups = $brands->groupBy('brand')->sortByDesc(fn ($rows) => $rows->sum('net_amount'));
@endphp
<style>
    body { font-family: sans-serif; font-size: 10px; color: #111; }
    h2 { margin: 0; font-size: 16px; text-align: center; }
    h3 { margin: 2px 0 8px; font-size: 13px; text-align: center; font-weight: normal; }
    h4 { margin: 14px 0 4px; font-size: 12px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #999; padding: 3px 5px; }
    th { background: #eee; text-align: left; }
    .r { text-align: right; }
    .total td { background: #ddd; font-weight: bold; }
    .sub td { background: #f2f2f2; font-weight: bold; }
    .summary th { width: 22%; }
    .payable th, .payable td { font-size: 13px; font-weight: bold; background: #ddd; }
    .filters { text-align: center; margin-bottom: 8px; color: #444; }
</style>

<h2>{{ session('business.name') }}</h2>
<h3>Commission Agent Report</h3>
<div class="filters">
    @if($location_name) Location: <b>{{ $location_name }}</b> &nbsp; @endif
    @if($category_name) Category: <b>{{ $category_name }}</b> &nbsp; @endif
    @if($brand_name) Brand: <b>{{ $brand_name }}</b> &nbsp; @endif
    @if($product_name) Product: <b>{{ $product_name }}</b> @endif
</div>

<table class="summary">
    <tr>
        <th>Commission agent</th><td><b>{{ $agent_name }}</b></td>
        <th>Period</th><td>{{ $period }}</td>
    </tr>
    <tr>
        <th>Invoices</th><td>{{ number_format($agents->sum('invoice_count')) }}</td>
        <th>Commission</th><td>{{ $agents->isNotEmpty() ? @num_format($agents->first()->cmmsn_percent) : '0' }}% @if($agents->isNotEmpty() && $agents->first()->rule_count) (+ brand/product rules below) @endif</td>
    </tr>
    <tr>
        <th>Gross sale</th><td>@format_currency($agents->sum('gross_amount'))</td>
        <th>Qty sold / returned</th><td>{{ @format_quantity($agents->sum('qty_sold')) }} / {{ @format_quantity($agents->sum('qty_returned')) }}</td>
    </tr>
    <tr>
        <th>Returns</th><td>@format_currency($agents->sum('gross_amount') - $agents->sum('net_amount'))</td>
        <th>Net sale</th><td><b>@format_currency($agents->sum('net_amount'))</b></td>
    </tr>
    @if($calculation_type == 'payment_received')
        <tr>
            <th>Payment received</th><td>@format_currency($agents->sum('payment_received'))</td>
            <th>Commission basis</th><td>Payments received</td>
        </tr>
    @endif
    <tr class="payable">
        <th colspan="3" class="r">Commission payable</th>
        <td>@format_currency($total_commission)</td>
    </tr>
</table>

@if($agents->isEmpty())
    <p style="text-align: center; margin-top: 20px;">No sales for this period.</p>
@else
    <h4>Brand wise summary</h4>
    <table>
        <tr>
            <th>Brand</th>
            <th class="r">Qty sold</th>
            <th class="r">Qty returned</th>
            <th class="r">Net sale</th>
            <th class="r">Commission</th>
        </tr>
        @foreach($brand_groups as $rows)
            <tr>
                <td>{{ $rows->first()->brand ?: '(No brand)' }}</td>
                <td class="r">{{ @format_quantity($rows->sum('qty_sold')) }}</td>
                <td class="r">{{ @format_quantity($rows->sum('qty_returned')) }}</td>
                <td class="r">@format_currency($rows->sum('net_amount'))</td>
                <td class="r">@format_currency($rows->sum('commission'))</td>
            </tr>
        @endforeach
        <tr class="total">
            <td>Total</td>
            <td class="r">{{ @format_quantity($brands->sum('qty_sold')) }}</td>
            <td class="r">{{ @format_quantity($brands->sum('qty_returned')) }}</td>
            <td class="r">@format_currency($brands->sum('net_amount'))</td>
            <td class="r">@format_currency($brands->sum('commission'))</td>
        </tr>
    </table>

    <h4>Product wise summary</h4>
    <table>
        <tr>
            <th>#</th>
            <th>Product</th>
            <th>SKU</th>
            <th>Brand</th>
            <th class="r">Qty sold</th>
            <th class="r">Qty returned</th>
            <th class="r">Net sale</th>
            <th>Rate</th>
            <th class="r">Commission</th>
        </tr>
        @foreach($product_groups as $rows)
            @php $first = $rows->first(); @endphp
            <tr>
                <td>{{ $loop->iteration }}</td>
                <td>{{ $first->product }}@if($first->product_type == 'variable') - {{ $first->variation }}@endif</td>
                <td>{{ $first->sub_sku }}</td>
                <td>{{ $first->brand }}</td>
                <td class="r">{{ @format_quantity($rows->sum('qty_sold')) }} {{ $first->unit }}</td>
                <td class="r">{{ @format_quantity($rows->sum('qty_returned')) }}</td>
                <td class="r">@format_currency($rows->sum('net_amount'))</td>
                <td>{{ $first->rule_text }}</td>
                <td class="r">@format_currency($rows->sum($commission_of))</td>
            </tr>
        @endforeach
        <tr class="total">
            <td colspan="4">Total ({{ $product_groups->count() }} products)</td>
            <td class="r">{{ @format_quantity($products->sum('qty_sold')) }}</td>
            <td class="r">{{ @format_quantity($products->sum('qty_returned')) }}</td>
            <td class="r">@format_currency($products->sum('net_amount'))</td>
            <td></td>
            <td class="r">@format_currency($products->sum($commission_of))</td>
        </tr>
    </table>

    <h4>Sales detail</h4>
    <table>
        <tr>
            <th>Date</th>
            <th>Invoice no.</th>
            <th>Customer</th>
            <th>Product</th>
            <th class="r">Qty</th>
            <th class="r">Price</th>
            <th class="r">Amount</th>
            <th class="r">Commission</th>
        </tr>
        @foreach($lines->groupBy(fn ($r) => substr($r->transaction_date, 0, 10)) as $day => $day_lines)
            @foreach($day_lines as $row)
                <tr>
                    <td>{{ \Carbon::parse($row->transaction_date)->format($date_format) }}</td>
                    <td>{{ $row->invoice_no }}</td>
                    <td>{{ $row->customer }}</td>
                    <td>{{ $row->product }}@if($row->product_type == 'variable') - {{ $row->variation }}@endif</td>
                    <td class="r">{{ @format_quantity($row->qty_sold - $row->qty_returned) }}</td>
                    <td class="r">@format_currency($row->unit_price)</td>
                    <td class="r">@format_currency($row->net_amount)</td>
                    <td class="r">@format_currency($commission_of($row)) <span style="color: #666;">({{ $row->rule_text }})</span></td>
                </tr>
            @endforeach
            <tr class="sub">
                <td colspan="4" class="r">{{ \Carbon::parse($day)->format($date_format) }} total</td>
                <td class="r">{{ @format_quantity($day_lines->sum('qty_sold') - $day_lines->sum('qty_returned')) }}</td>
                <td></td>
                <td class="r">@format_currency($day_lines->sum('net_amount'))</td>
                <td class="r">@format_currency($day_lines->sum($commission_of))</td>
            </tr>
        @endforeach
        <tr class="total">
            <td colspan="4">Grand total ({{ number_format($lines->pluck('transaction_id')->unique()->count()) }} invoices)</td>
            <td class="r">{{ @format_quantity($lines->sum('qty_sold') - $lines->sum('qty_returned')) }}</td>
            <td></td>
            <td class="r">@format_currency($lines->sum('net_amount'))</td>
            <td class="r">@format_currency($lines->sum($commission_of))</td>
        </tr>
    </table>
@endif
