{{-- Mobile orders / payments > Print: same filters as the list (all pages) or only the ticked rows.
     Orders: load sheet first (products added up, by brand, for the warehouse), then the order list.
     Simple bordered tables like the ledger print. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $only_load ? 'Load sheet' : ($kind == 'order' ? 'Booker orders' : 'Booker payments') }} - {{ $business->name }}</title>
    <style>
        body { font-family: 'Roboto', Arial, sans-serif; font-size: 12px; color: #222; margin: 16px; }
        table { width: 100%; border-collapse: collapse; }
        .head td { padding: 2px 0; vertical-align: top; }
        .title { font-size: 16px; font-weight: bold; }
        h3 { font-size: 14px; margin: 16px 0 6px; }
        .muted { color: #666; font-size: 11px; }
        .right { text-align: right; }
        .list th, .list td { border: 1px solid #555; padding: 5px 6px; vertical-align: top; }
        .list th { background: #e9ecef; font-weight: bold; text-align: center; }
        .amount { text-align: right; white-space: nowrap; }
        .center { text-align: center; }
        .brand td { background: #dbeafe; font-weight: bold; font-size: 13px; }
        .total td { font-weight: bold; background: #f4f4f4; }
        .check { width: 40px; }
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
        $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 4, '.', ','), '0'), '.');
    @endphp
    <div class="noprint">
        <button onclick="window.print()" style="padding:6px 14px;">🖨 Print</button>
        <button onclick="window.close()" style="padding:6px 14px;">Close</button>
    </div>

    <table class="head">
        <tr>
            <td style="width:55%;">
                <div class="title">{{ $only_load ? 'Load sheet (warehouse)' : ($kind == 'order' ? 'Booker orders' : 'Booker payments') }}</div>
                <div class="muted">
                    {{ $grand['count'] }} {{ $kind == 'order' ? 'order(s)' : 'receipt(s)' }}
                    @if ($ids) (ticked) @endif
                    @if ($start_date && $end_date) · {{ $fd($start_date) }} to {{ $fd($end_date) }} @endif
                    @if ($status !== 'all') · {{ $status == 'waiting' ? 'waiting approval' : $status }} @endif
                    @if ($location && isset($locations[$location])) · {{ $locations[$location] }} @endif
                    · printed {{ $fdt(now()) }}
                </div>
            </td>
            <td style="width:45%;" class="right">
                <div class="title">{{ $business->name }}</div>
            </td>
        </tr>
    </table>

    @if ($kind == 'order')
        <h3>Load sheet — products to pack, by brand</h3>
        <table class="list">
            <thead>
                <tr>
                    <th style="width:30px;">#</th>
                    <th>Product</th>
                    <th>SKU</th>
                    <th>Quantity</th>
                    <th>Orders</th>
                    <th>Stock now</th>
                    <th class="check">✓</th>
                </tr>
            </thead>
            <tbody>
                @php $n = 0; @endphp
                @forelse ($load as $brand => $items)
                    <tr class="brand"><td colspan="7">{{ $brand }} <span class="muted">({{ $items->count() }} product(s))</span></td></tr>
                    @foreach ($items as $l)
                        <tr>
                            <td class="center">{{ ++$n }}</td>
                            <td>{{ $l->name }}</td>
                            <td>{{ $l->sku }}</td>
                            <td class="amount">
                                <b>{{ $l->big ?: $qty($l->qty).' '.$l->unit }}</b>
                                @if ($l->big) <div class="muted">= {{ $qty($l->qty) }} {{ $l->unit }}</div> @endif
                            </td>
                            <td class="center">{{ $l->orders }}</td>
                            <td class="amount" @if ($l->stock !== null && (float) $l->stock < $l->qty) style="color:#c00; font-weight:bold;" @endif>
                                {{ $l->stock === null ? '' : $qty($l->stock) }}
                            </td>
                            <td></td>
                        </tr>
                    @endforeach
                @empty
                    <tr><td colspan="7" class="center muted">No products.</td></tr>
                @endforelse
            </tbody>
        </table>
    @endif

    @if (! $only_load)
        <h3>{{ $kind == 'order' ? 'Orders' : 'Payments' }}</h3>
        <table class="list">
            <thead>
                <tr>
                    <th style="width:30px;">#</th>
                    <th>{{ $kind == 'order' ? 'Slip no' : 'Receipt no' }}</th>
                    <th>Date</th>
                    <th>Booker</th>
                    <th>Customer</th>
                    <th>{{ $kind == 'order' ? 'Total' : 'Amount' }}</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $r)
                    <tr>
                        <td class="center">{{ $loop->iteration }}</td>
                        <td>{{ $r->number }}</td>
                        <td>{{ $fdt($r->booked_at) }}</td>
                        <td>{{ $r->booker ?: '#'.$r->booker_id }}</td>
                        <td>{{ $r->customer ?? '—' }}@if ($r->supplier_business_name) <span class="muted">({{ $r->supplier_business_name }})</span>@endif</td>
                        <td class="amount">{{ $money($r->total) }}</td>
                        <td>{{ $r->status == 'waiting' ? 'waiting approval' : $r->status }}</td>
                    </tr>
                @endforeach
                <tr class="total">
                    <td colspan="5" class="right">Total</td>
                    <td class="amount">{{ $money($grand['total']) }}</td>
                    <td></td>
                </tr>
            </tbody>
        </table>

        <h3>By booker</h3>
        <table class="list" style="width:60%;">
            <thead>
                <tr><th>Booker</th><th>{{ $kind == 'order' ? 'Orders' : 'Receipts' }}</th><th>{{ $kind == 'order' ? 'Total' : 'Collected' }}</th></tr>
            </thead>
            <tbody>
                @foreach ($by_booker as $b)
                    <tr><td>{{ $b->booker }}</td><td class="center">{{ $b->count }}</td><td class="amount">{{ $money($b->total) }}</td></tr>
                @endforeach
                <tr class="total"><td>Total</td><td class="center">{{ $grand['count'] }}</td><td class="amount">{{ $money($grand['total']) }}</td></tr>
            </tbody>
        </table>
    @endif
</body>
</html>
