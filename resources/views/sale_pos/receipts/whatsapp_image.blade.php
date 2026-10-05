{{-- Invoice as sent on WhatsApp as an image: same simple bordered style as the ledger --}}
@php
    $r = $receipt_details;
    $lines = $r->lines ?? [];
    $amount = fn ($v) => (empty($v) || $v === 0) ? '' : $v;
    $total_discount = 0;
@endphp
<style>
    .wi { font-family: "Segoe UI", Roboto, Arial, sans-serif; font-size: 15px; color: #222; }
    .wi table { width: 100%; border-collapse: collapse; }
    .wi .b th, .wi .b td { border: 1px solid #555; padding: 6px 8px; vertical-align: top; }
    .wi .b th { background: #e9ecef; font-weight: bold; text-align: center; }
    .wi .r { text-align: right; white-space: nowrap; }
    .wi .c { text-align: center; }
    .wi .title { font-size: 22px; font-weight: bold; }
    .wi .muted { color: #666; font-size: 13px; }
    .wi .head td { vertical-align: top; padding: 0; }
    .wi .doc { font-size: 20px; font-weight: bold; letter-spacing: 1px; }
    .wi .tot td { font-weight: bold; background: #f4f4f4; }
    .wi .due td { font-size: 18px; font-weight: bold; background: #e9ecef; }
</style>
<div class="wi">
    {{-- Business (left) and document title (right) --}}
    <table class="head">
        <tr>
            <td style="width: 60%;">
                @if(! empty($r->logo))<img src="{{ $r->logo }}" style="max-height: 70px; margin-bottom: 6px;"><br>@endif
                <div class="title">{{ $r->display_name ?? '' }}</div>
                @if(! empty($r->address))<div class="muted">{!! $r->address !!}</div>@endif
                @if(! empty($r->contact))<div class="muted">{!! $r->contact !!}</div>@endif
            </td>
            <td style="width: 40%;" class="r">
                <div class="doc">{{ ! empty($r->invoice_heading) ? strip_tags($r->invoice_heading) : 'INVOICE' }}</div>
            </td>
        </tr>
    </table>
    <br>

    {{-- Invoice and customer --}}
    <table class="b">
        <tr>
            <th style="width: 18%; text-align: left;">Invoice No.</th>
            <td style="width: 32%;"><b>{{ $r->invoice_no }}</b></td>
            <th style="width: 18%; text-align: left;">Customer</th>
            <td style="width: 32%;"><b>{{ $r->customer_name ?? '' }}</b></td>
        </tr>
        <tr>
            <th style="text-align: left;">Date</th>
            <td>{{ $r->invoice_date ?? '' }}</td>
            <th style="text-align: left;">Mobile</th>
            <td>{{ $r->customer_mobile ?? '' }}</td>
        </tr>
        @if(! empty($r->sales_person))
            <tr>
                <th style="text-align: left;">Sales Person</th>
                <td colspan="3">{{ $r->sales_person }}</td>
            </tr>
        @endif
    </table>
    <br>

    {{-- Items --}}
    <table class="b">
        <thead>
            <tr>
                <th style="width: 5%;">#</th>
                <th>Product</th>
                <th style="width: 14%;">Qty</th>
                <th style="width: 14%;">Price</th>
                <th style="width: 12%;">Discount</th>
                <th style="width: 16%;">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach($lines as $line)
                @php
                    $qty = (float) ($line['quantity_uf'] ?? 0);
                    $before = (float) ($line['unit_price_before_discount_uf'] ?? 0);
                    $after = (float) ($line['unit_price_inc_tax_uf'] ?? 0);
                    $disc = $before > $after ? ($before - $after) * $qty : 0;
                    $total_discount += $disc;
                @endphp
                <tr>
                    <td class="c">{{ $loop->iteration }}</td>
                    <td>
                        <b>{{ $line['name'] }}</b>
                        @if(! empty($line['product_variation']) || ! empty($line['variation'])) - {{ $line['product_variation'] ?? '' }} {{ $line['variation'] ?? '' }}@endif
                        @if(! empty($line['sub_sku']))<span class="muted">, {{ $line['sub_sku'] }}</span>@endif
                    </td>
                    <td class="r">{{ $line['quantity'] }} {{ $line['units'] ?? '' }}</td>
                    <td class="r">{{ $line['unit_price_before_discount'] ?? $line['unit_price_inc_tax'] }}</td>
                    <td class="r">{{ $disc > 0 ? number_format($disc, 2) : '' }}</td>
                    <td class="r">{{ $line['line_total'] }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr class="tot">
                <td colspan="2" class="r">Total quantity</td>
                <td class="r">{{ $r->total_quantity ?? '' }}</td>
                <td colspan="3"></td>
            </tr>
        </tfoot>
    </table>
    <br>

    {{-- Totals (right) --}}
    <table>
        <tr>
            <td style="width: 45%;"></td>
            <td style="width: 55%;">
                <table class="b">
                    @if(! empty($r->subtotal))<tr><td>Subtotal</td><td class="r">{{ $r->subtotal }}</td></tr>@endif
                    @if(! empty($r->discount))<tr><td>Discount</td><td class="r">(-) {{ $r->discount }}</td></tr>@endif
                    @if(! empty($r->tax))<tr><td>Tax</td><td class="r">(+) {{ $r->tax }}</td></tr>@endif
                    <tr class="tot"><td>Total</td><td class="r">{{ $r->total }}</td></tr>
                    @if(isset($r->total_paid))<tr><td>Paid</td><td class="r">{{ $r->total_paid ?: '0.00' }}</td></tr>@endif
                    @if(! empty($r->total_due))<tr><td>Due on this invoice</td><td class="r">{{ $r->total_due }}</td></tr>@endif
                    @if(! empty($r->previous_due))<tr><td>Previous due</td><td class="r">{{ $r->previous_due }}</td></tr>@endif
                    @if(! empty($r->all_due))<tr class="due"><td>Total due (all sales)</td><td class="r">{{ $r->all_due }}</td></tr>@endif
                </table>
            </td>
        </tr>
    </table>

    @if(! empty($r->additional_notes))
        <p class="muted" style="margin-top: 12px;">{!! nl2br(e($r->additional_notes)) !!}</p>
    @endif
    @if(! empty($r->footer_text))
        <div class="muted c" style="margin-top: 14px;">{!! $r->footer_text !!}</div>
    @endif
</div>
