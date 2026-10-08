{{-- Zakat slip: given from the POS (printed like a receipt) or opened from Reports > Zakat. --}}
@php $categories = \App\Utils\ZakatUtil::CATEGORIES; @endphp
@if ($standalone)
<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Zakat slip #{{ $payment->id }}</title></head>
<body style="margin:0;">
<div style="padding:10px;"><button onclick="window.print()" class="no-print-btn" style="padding:6px 14px;">🖨 Print</button></div>
<style>@media print { .no-print-btn { display:none; } }</style>
@endif
<div style="font-family: Arial, sans-serif; font-size: 13px; color: #111; max-width: 360px; margin: 0 auto; padding: 6px;">
    <div style="text-align:center;">
        <div style="font-size:16px; font-weight:bold;">{{ $business->name }}</div>
        @if ($location)<div>{{ $location->name }}</div>@endif
        <div style="font-size:18px; font-weight:bold; margin-top:6px; border-top:1px dashed #333; border-bottom:1px dashed #333; padding:4px 0;">ZAKAT — not a sale</div>
    </div>
    <table style="width:100%; margin-top:6px; border-collapse:collapse;">
        <tr><td>Slip no</td><td style="text-align:right;">Z-{{ str_pad($payment->id, 5, '0', STR_PAD_LEFT) }}</td></tr>
        <tr><td>Date</td><td style="text-align:right;">{{ \Carbon::parse($payment->paid_on)->format(session('business.date_format', 'd-m-Y').' H:i') }}<br><small>{{ \App\Utils\ZakatUtil::hijri($payment->paid_on) }}</small></td></tr>
        <tr><td>Given to</td><td style="text-align:right;"><b>{{ $payment->recipient_name }}</b>@if ($payment->recipient_mobile)<br>{{ $payment->recipient_mobile }}@endif</td></tr>
        @if ($payment->category)<tr><td>Category</td><td style="text-align:right;">{{ $categories[$payment->category] ?? $payment->category }}</td></tr>@endif
        <tr><td>Given as</td><td style="text-align:right;">{{ $payment->kind == 'goods' ? 'Products' : 'Cash' }}</td></tr>
    </table>
    @if ($lines->count())
        <table style="width:100%; margin-top:6px; border-collapse:collapse; border-top:1px dashed #333;">
            <tr><th style="text-align:left;">Item</th><th style="text-align:right;">Qty</th></tr>
            @foreach ($lines as $l)
                <tr><td>{{ $l->name }}</td><td style="text-align:right; white-space:nowrap;">{{ @format_quantity($l->quantity) }} {{ $l->unit }}</td></tr>
            @endforeach
        </table>
    @endif
    <div style="border-top:1px dashed #333; margin-top:6px; padding-top:6px; font-size:16px; font-weight:bold; display:flex; justify-content:space-between;">
        <span>Zakat value</span><span>@format_currency($payment->amount_value)</span>
    </div>
    @if ($payment->note)<div style="margin-top:4px;">{{ $payment->note }}</div>@endif
    <div style="text-align:center; margin-top:10px; font-size:12px;">May Allah accept it. جزاك الله خيرا</div>
</div>
@if ($standalone)
<script>window.onload = function () { window.print(); };</script>
</body></html>
@endif
