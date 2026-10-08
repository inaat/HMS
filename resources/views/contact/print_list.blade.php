{{-- Contacts > Customers > Print: every customer matching the list's search / filters / sort, in the same simple
     bordered table style as the ledger, plus an empty Remarks column to write on. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Customers - {{ $business->name }}</title>
    <style>
        body { font-family: 'Roboto', Arial, sans-serif; font-size: 12px; color: #222; margin: 16px; }
        table { width: 100%; border-collapse: collapse; }
        .head td { padding: 2px 0; vertical-align: top; }
        .title { font-size: 16px; font-weight: bold; }
        .muted { color: #666; font-size: 11px; }
        .right { text-align: right; }
        .list th, .list td { border: 1px solid #555; padding: 5px 6px; vertical-align: top; }
        .list th { background: #e9ecef; font-weight: bold; text-align: center; }
        .amount { text-align: right; white-space: nowrap; }
        .center { text-align: center; }
        .remarks { width: 20%; }
        .total td { font-weight: bold; background: #f4f4f4; }
        tr { page-break-inside: avoid; }
        thead { display: table-header-group; }
        .noprint { margin-bottom: 10px; }
        @media print { .noprint { display: none; } body { margin: 0; } @page { size: A4 landscape; margin: 10mm; } }
    </style>
</head>
<body>
    <div class="noprint">
        <button onclick="window.print()" style="padding:6px 14px;">🖨 Print</button>
        <button onclick="window.close()" style="padding:6px 14px;">Close</button>
    </div>
    @php
        $phone = fn ($m) => in_array(trim((string) $m), ['', '0', '-'], true) ? '' : $m;
        $total_due = 0;
    @endphp

    <table class="head">
        <tr>
            <td style="width:50%;">
                <div class="title">Customers</div>
                <div class="muted">{{ $customers->count() }} customer(s) · printed {{ @format_datetime(now()) }}</div>
            </td>
            <td style="width:50%;" class="right">
                <div class="title">{{ $business->name }}</div>
                {!! $business->business_address ?? '' !!}
            </td>
        </tr>
    </table>
    <br>

    <table class="list">
        <thead>
            <tr>
                <th style="width:30px;">#</th>
                <th>Contact ID</th>
                <th>Name</th>
                <th>Mobile</th>
                <th>Total Sale Due</th>
                <th>Address</th>
                <th>Customer Group</th>
                <th class="remarks">Remarks</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($customers as $c)
                @php
                    $due = ($c->total_invoice - $c->invoice_received) + ($c->opening_balance - $c->opening_balance_paid);
                    $total_due += $due;
                @endphp
                <tr>
                    <td class="center">{{ $loop->iteration }}</td>
                    <td>{{ $c->contact_id }}</td>
                    <td><b>{{ $c->name }}</b>@if ($c->supplier_business_name)<br><span class="muted">{{ $c->supplier_business_name }}</span>@endif</td>
                    <td>{{ $phone($c->mobile) }}</td>
                    <td class="amount">@format_currency($due)</td>
                    <td>{{ implode(', ', array_filter([$c->address_line_1, $c->address_line_2, $c->city, $c->state])) }}</td>
                    <td>{{ $c->customer_group }}</td>
                    <td class="remarks"></td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr class="total">
                <td colspan="4" class="right">Total</td>
                <td class="amount">@format_currency($total_due)</td>
                <td colspan="3"></td>
            </tr>
        </tfoot>
    </table>
    <script>window.onload = function () { window.print(); };</script>
</body>
</html>
