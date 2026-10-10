@extends('layouts.app')
@section('title', 'Claim '.$claim->claim_no)

@section('content')
@php
    $q = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ','), '0'), '.');
    $fd = fn ($d) => \Carbon::parse($d)->format(session('business.date_format'));
    $settled = ['credit_note' => 'Credit note (lowers what we owe the supplier)', 'cash' => 'Cash / bank received', 'stock' => 'Free stock received'];
@endphp
<section class="content-header no-print">
    <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">Claim {{ $claim->claim_no }}
        <span class="label {{ $claim->status === 'received' ? 'label-success' : 'label-warning' }}" style="font-size:13px; vertical-align:middle;">{{ $claim->status }}</span>
    </h1>
</section>

<section class="content">
    <div class="no-print" style="margin-bottom:12px; display:flex; gap:8px; flex-wrap:wrap;">
        <a href="{{ action([\App\Http\Controllers\TradeSchemeReportController::class, 'claims']) }}" class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-outline tw-dw-btn-primary"><i class="fa fa-arrow-left"></i> All claims</a>
        <button type="button" onclick="window.print()" class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-primary tw-text-white"><i class="fa fa-print"></i> Print claim</button>
    </div>

    {{-- the claim letter (printed) --}}
    <div class="box" style="padding:24px 28px;">
        <table style="width:100%; margin-bottom:14px;">
            <tr>
                <td style="vertical-align:top;">
                    <h3 style="margin:0 0 4px;"><b>Scheme claim</b></h3>
                    <div>No. <b>{{ $claim->claim_no }}</b> · {{ $fd($claim->created_at) }}</div>
                    <div>Period: <b>{{ $fd($claim->period_start) }} – {{ $fd($claim->period_end) }}</b></div>
                </td>
                <td style="vertical-align:top; text-align:right;">
                    <h3 style="margin:0 0 4px;"><b>{{ $business->name }}</b></h3>
                    <div>To: <b>{{ $supplier->supplier_business_name ?: $supplier->name }}</b></div>
                    @if ($supplier->mobile)<div>{{ $supplier->mobile }}</div>@endif
                </td>
            </tr>
        </table>
        <p>Free goods given to customers under your trade schemes during the period, claimed at cost:</p>
        <table class="table table-bordered" style="margin-bottom:8px;">
            <thead>
                <tr style="background:#eef6f1;"><th>#</th><th>Scheme</th><th>Product</th><th class="text-right">Free qty</th><th class="text-right">At sale price</th><th class="text-right">Claim amount</th></tr>
            </thead>
            <tbody>
                @foreach ($lines as $l)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td>{{ $l->code }} <small class="text-muted">{{ $l->scheme_name }}</small></td>
                        <td>{{ $l->product_name }}@if ($l->product_type === 'variable') - {{ $l->variation_name }}@endif</td>
                        <td class="text-right">{{ $q($l->free_qty) }} {{ $l->unit }}</td>
                        <td class="text-right">@format_currency($l->sale_value)</td>
                        <td class="text-right">@format_currency($l->cost_value)</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr style="font-weight:bold; background:#f7faf8;">
                    <td colspan="4" class="text-right">Total</td>
                    <td class="text-right">@format_currency($claim->sale_value)</td>
                    <td class="text-right">@format_currency($claim->total_value)</td>
                </tr>
            </tfoot>
        </table>
        <p style="margin-top:40px; display:flex; justify-content:space-between;">
            <span style="border-top:1px solid #333; padding-top:4px; min-width:200px; text-align:center;">Prepared by</span>
            <span style="border-top:1px solid #333; padding-top:4px; min-width:200px; text-align:center;">Received by (supplier)</span>
        </p>
    </div>

    {{-- settle (not printed) --}}
    <div class="no-print">
        @if ($claim->status === 'received')
            @component('components.widget', ['class' => 'box-success', 'title' => 'Settled'])
                <p><b>{{ $settled[$claim->settled_by] ?? $claim->settled_by }}</b>: @format_currency($claim->received_amount) on {{ $fd($claim->settled_at) }}
                    @if ($claim->settlement_ref) · ref {{ $claim->settlement_ref }}@endif</p>
                @if ($purchase)
                    <p>Stock received as purchase <b>{{ $purchase->ref_no }}</b> (@format_currency($purchase->final_total)), paid by this claim —
                        <a href="{{ action([\App\Http\Controllers\PurchaseController::class, 'show'], [$purchase->id]) }}" class="btn-modal" data-container=".view_modal">view purchase</a></p>
                @endif
                @php $not_approved = round((float) $claim->total_value - (float) $claim->received_amount, 2); @endphp
                @if ($not_approved > 0)
                    <p class="text-danger">Not approved by the supplier: <b>@format_currency($not_approved)</b> — stays our own scheme cost.</p>
                @endif
                {!! Form::open(['url' => action([\App\Http\Controllers\TradeSchemeReportController::class, 'destroyClaim'], [$claim->id]), 'method' => 'delete',
                    'onsubmit' => "return confirm('Undo this settlement? Its credit note / deposit is removed and the claim is open again.')"]) !!}
                    <button type="submit" class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-outline tw-dw-btn-error"><i class="fa fa-undo"></i> Undo settlement</button>
                {!! Form::close() !!}
            @endcomponent
        @else
            @component('components.widget', ['class' => 'box-primary', 'title' => 'Mark as received'])
                {!! Form::open(['url' => action([\App\Http\Controllers\TradeSchemeReportController::class, 'settleClaim'], [$claim->id]), 'method' => 'post']) !!}
                <div class="row">
                    <div class="col-md-3">
                        <div class="form-group">
                            {!! Form::label('settled_by', 'Settled by:*') !!}
                            {!! Form::select('settled_by', $settled, $default_settle,['class' => 'form-control select2', 'style' => 'width:100%', 'id' => 'settled_by']) !!}
                        </div>
                    </div>
                    <div class="col-md-2" id="amount_box">
                        <div class="form-group">
                            {!! Form::label('amount', 'Amount approved:*') !!}
                            {!! Form::text('amount', number_format((float) $claim->total_value, 2, '.', ''), ['class' => 'form-control input_number', 'required']) !!}
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            {!! Form::label('settled_at', __('messages.date') . ':*') !!}
                            <div class="input-group">
                                <span class="input-group-addon"><i class="fa fa-calendar"></i></span>
                                {!! Form::text('settled_at', now()->format(session('business.date_format')), ['class' => 'form-control', 'id' => 'settled_at', 'readonly', 'required']) !!}
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3" id="account_box">
                        <div class="form-group">
                            {!! Form::label('account_id', 'Into account:') !!}
                            {!! Form::select('account_id', $accounts, null, ['class' => 'form-control select2', 'style' => 'width:100%']) !!}
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            {!! Form::label('settlement_ref', 'Reference:') !!}
                            {!! Form::text('settlement_ref', null, ['class' => 'form-control', 'placeholder' => 'credit note / cheque / GRN no']) !!}
                        </div>
                    </div>
                </div>
                {{-- supplier sends goods instead of money: received qty per product, in the scheme unit --}}
                <div id="stock_box" style="display:none;">
                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-group">
                                {!! Form::label('location_id', 'Stock received at:*') !!}
                                {!! Form::select('location_id', $locations, array_key_first($locations->toArray()), ['class' => 'form-control select2', 'style' => 'width:100%']) !!}
                            </div>
                        </div>
                    </div>
                    <table class="table table-bordered" style="max-width:900px;">
                        <thead><tr style="background:#eef6f1;"><th>Product</th><th class="text-right">Claimed</th><th style="width:170px;">Received qty</th><th style="width:170px;">Cost per unit</th><th class="text-right">Value</th></tr></thead>
                        <tbody>
                            @foreach ($lines as $l)
                                <tr class="stock_row">
                                    <td>{{ $l->product_name }}@if ($l->product_type === 'variable') - {{ $l->variation_name }}@endif <small class="text-muted">({{ $l->code }})</small></td>
                                    <td class="text-right">{{ $q($l->stock_qty) }} {{ $l->stock_unit }}</td>
                                    <td><div class="input-group"><input type="text" name="stock_qty[{{ $l->id }}]" class="form-control input-sm input_number stock_qty" value="{{ $l->stock_qty + 0 }}"><span class="input-group-addon">{{ $l->stock_unit }}</span></div></td>
                                    <td><input type="text" name="stock_cost[{{ $l->id }}]" class="form-control input-sm input_number stock_cost" value="{{ round($l->stock_cost, 2) }}"></td>
                                    <td class="text-right stock_value"></td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot><tr style="font-weight:bold;"><td colspan="4" class="text-right">Stock value received</td><td class="text-right" id="stock_total"></td></tr></tfoot>
                    </table>
                </div>
                <p class="help-block" id="settle_help"></p>
                <button type="submit" class="tw-dw-btn tw-dw-btn-success tw-text-white"><i class="fa fa-check"></i> Mark as received</button>
                {!! Form::close() !!}
                <hr>
                {!! Form::open(['url' => action([\App\Http\Controllers\TradeSchemeReportController::class, 'destroyClaim'], [$claim->id]), 'method' => 'delete',
                    'onsubmit' => "return confirm('Delete claim ".e($claim->claim_no)."?')"]) !!}
                    <button type="submit" class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-error"><i class="fa fa-trash"></i> Delete claim</button>
                {!! Form::close() !!}
            @endcomponent
        @endif
    </div>
</section>
@endsection

@section('javascript')
<script type="text/javascript">
    $(document).ready(function () {
        $('#settled_at').datepicker({ autoclose: true, format: datepicker_date_format });
        var help = {
            credit_note: 'Enter the amount the supplier APPROVED on its credit note. The supplier ledger gets a "Scheme claim credit note" for it: what we owe them goes down. Anything not approved stays our own cost.',
            cash: 'The money is added to the chosen payment account as a deposit.',
            stock: 'The supplier sent goods instead of money. Enter what came in: it is added to stock at cost as a purchase "paid by the claim" — no money, the supplier balance does not change. Less than claimed = the rest stays our own cost.'
        };
        $('#settled_by').on('change', function () {
            var by = $(this).val();
            $('#account_box').toggle(by === 'cash');
            $('#amount_box').toggle(by !== 'stock');
            $('#stock_box').toggle(by === 'stock');
            $('#settle_help').text(help[by] || '');
        }).trigger('change');
        function stockTotals() {
            var total = 0;
            $('.stock_row').each(function () {
                var v = __read_number($(this).find('.stock_qty')) * __read_number($(this).find('.stock_cost'));
                total += v;
                $(this).find('.stock_value').text(__currency_trans_from_en(v, true));
            });
            $('#stock_total').text(__currency_trans_from_en(total, true));
        }
        $(document).on('input change', '.stock_qty, .stock_cost', stockTotals);
        stockTotals();
    });
</script>
@endsection
