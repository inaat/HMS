{{-- All sales > tick rows > Delivery challan: one line per invoice, empty remark column to write on,
     receiver / driver signatures at the bottom. Same plain bordered style as the load sheet print. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Delivery challan - {{ $business->name }}</title>
    <style>
        body { font-family: 'Roboto', Arial, sans-serif; font-size: 12px; color: #222; margin: 16px; }
        table { width: 100%; border-collapse: collapse; }
        .head td { padding: 2px 0; vertical-align: top; }
        .title { font-size: 16px; font-weight: bold; }
        .muted { color: #666; font-size: 11px; }
        .right { text-align: right; }
        .center { text-align: center; }
        .list { margin-top: 10px; }
        .list th, .list td { border: 1px solid #555; padding: 6px; vertical-align: top; }
        .list th { background: #e9ecef; font-weight: bold; text-align: center; }
        .amount { text-align: right; white-space: nowrap; }
        .remark { width: 28%; }
        .total td { font-weight: bold; background: #f4f4f4; }
        .subtotal td { font-weight: bold; background: #fafafa; }
        .sign { margin-top: 50px; }
        .sign td { width: 33%; padding: 0 12px; text-align: center; }
        .sign span { display: block; border-top: 1px solid #333; padding-top: 4px; }
        tr { page-break-inside: avoid; }
        thead { display: table-header-group; }
        .noprint { margin-bottom: 10px; }
        @media print { .noprint { display: none; } body { margin: 0; } @page { size: A4; margin: 10mm; } }
    </style>
</head>
<body>
    @php
        $fd = fn ($d) => \Carbon::parse($d)->format(session('business.date_format'));
        $fdt = fn ($d) => \Carbon::parse($d)->format(session('business.date_format').' '.(session('business.time_format') == 12 ? 'h:i A' : 'H:i'));
        $money = fn ($v) => number_format((float) $v, 2);
    @endphp
    <div class="noprint">
        <button onclick="window.print()" style="padding:6px 14px;">🖨 Print</button>
        <button onclick="window.close()" style="padding:6px 14px;">Close</button>
    </div>

    <table class="head">
        <tr>
            <td style="width:55%;">
                <div class="title">Delivery Challan</div>
                <div class="muted">{{ $rows->count() }} invoice(s) · {{ $rows->groupBy('contact_id')->count() }} customer(s) · printed {{ $fdt(now()) }}</div>
            </td>
            <td style="width:45%;" class="right">
                <div class="title">{{ $business->name }}</div>
            </td>
        </tr>
    </table>

    <table class="list">
        <thead>
            <tr>
                <th style="width:30px;">#</th>
                <th>Customer name</th>
                <th>Invoice no</th>
                <th>Date</th>
                <th>Amount</th>
                <th class="remark">Remark</th>
            </tr>
        </thead>
        <tbody>
            {{-- One block per customer: name cell spans all of that customer's invoices, subtotal when more than one --}}
            @php $n = 0; @endphp
            @forelse ($rows->groupBy('contact_id') as $invoices)
                @php
                    $c = $invoices->first();
                    $place = trim(implode(', ', array_filter([$c->address_line_1, $c->city])));
                    $span = $invoices->count() + ($invoices->count() > 1 ? 1 : 0);
                @endphp
                @foreach ($invoices as $r)
                    <tr>
                        @if ($loop->first)
                            <td class="center" rowspan="{{ $span }}">{{ ++$n }}</td>
                            <td rowspan="{{ $span }}">
                                <b>{{ $c->supplier_business_name ?: $c->customer }}</b>
                                @if ($c->supplier_business_name && $c->customer && $c->customer != $c->supplier_business_name)
                                    <span class="muted">({{ $c->customer }})</span>
                                @endif
                                @if ($place || $c->mobile)
                                    <div class="muted">{{ $place }}@if ($place && $c->mobile) · @endif{{ $c->mobile }}</div>
                                @endif
                                @if ($invoices->count() > 1)
                                    <div class="muted">{{ $invoices->count() }} invoices</div>
                                @endif
                            </td>
                        @endif
                        <td class="center">{{ $r->invoice_no }}</td>
                        <td class="center" style="white-space:nowrap;">{{ $fd($r->transaction_date) }}</td>
                        <td class="amount">{{ $money($r->final_total) }}</td>
                        @if ($loop->first)
                            <td rowspan="{{ $span }}"></td>
                        @endif
                    </tr>
                @endforeach
                @if ($invoices->count() > 1)
                    <tr class="subtotal">
                        <td colspan="2" class="right">Customer total</td>
                        <td class="amount">{{ $money($invoices->sum('final_total')) }}</td>
                    </tr>
                @endif
            @empty
                <tr><td colspan="6" class="center muted">No sales selected.</td></tr>
            @endforelse
            <tr class="total">
                <td colspan="4" class="right">Total</td>
                <td class="amount">{{ $money($rows->sum('final_total')) }}</td>
                <td></td>
            </tr>
        </tbody>
    </table>

    <table class="sign">
        <tr>
            <td><span>Prepared by</span></td>
            <td><span>Driver / Salesman</span></td>
            <td><span>Received by</span></td>
        </tr>
    </table>
</body>
</html>
