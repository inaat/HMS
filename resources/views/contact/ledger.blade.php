{{-- Simple ledger: one bordered table, same layout on screen, in PDF and in the WhatsApp PDF --}}
<style>
    .simple-ledger { font-family: 'Roboto', 'DejaVu Sans', sans-serif; font-size: 12px; color: #222; width: 100%; }
    .simple-ledger table { width: 100%; border-collapse: collapse; }
    .simple-ledger .sl-bordered th,
    .simple-ledger .sl-bordered td { border: 1px solid #555; padding: 5px 6px; vertical-align: top; }
    .simple-ledger .sl-bordered th { background: #e9ecef; font-weight: bold; text-align: center; }
    .simple-ledger .sl-head td { padding: 2px 0; vertical-align: top; }
    .simple-ledger .sl-right { text-align: right; }
    .simple-ledger .sl-center { text-align: center; }
    .simple-ledger .sl-amount { text-align: right; white-space: nowrap; }
    .simple-ledger .sl-title { font-size: 16px; font-weight: bold; }
    .simple-ledger .sl-total td { font-weight: bold; background: #f4f4f4; }
    .simple-ledger .sl-due { font-size: 14px; font-weight: bold; }
    .simple-ledger .sl-muted { color: #666; font-size: 11px; }
    /* mPDF has no Roboto and would fall back to a serif font: use its built-in sans font */
    @if(!empty($for_pdf)) .simple-ledger, .simple-ledger table, .simple-ledger td, .simple-ledger th { font-family: dejavusans; }
    .simple-ledger .sl-bordered td, .simple-ledger .sl-bordered th, .simple-ledger .sl-item td, .simple-ledger .sl-item-head td { font-size: 10pt; }
    .simple-ledger .sl-muted { font-size: 8pt; } @endif
</style>

@php
    $is_customer = in_array($contact->type, ['customer', 'both']);
    $is_supplier = in_array($contact->type, ['supplier', 'both']);
    $total_debit = 0;
    $total_credit = 0;
@endphp

<div class="simple-ledger">
    {{-- Header: business (right) and contact (left) --}}
    <table class="sl-head">
        <tr>
            <td style="width: 50%;">
                <div class="sl-title">{{ $contact->name }}</div>
                @if(!empty($contact->supplier_business_name)){{ $contact->supplier_business_name }}<br>@endif
                {!! $contact->contact_address !!}
                @if(!empty($contact->mobile))<br>@lang('contact.mobile'): {{ $contact->mobile }}@endif
                @if(!empty($contact->tax_number))<br>@lang('contact.tax_no'): {{ $contact->tax_number }}@endif
            </td>
            <td style="width: 50%;" class="sl-right">
                <div class="sl-title">{{ $contact->business->name }}</div>
                @if(!empty($location))
                    {!! $location->location_address !!}
                @else
                    {!! $contact->business->business_address !!}
                @endif
            </td>
        </tr>
    </table>

    <br>

    {{-- Account summary --}}
    <table class="sl-bordered">
        <tr>
            <th colspan="2">@lang('lang_v1.account_summary'): {{ $ledger_details['start_date'] }} @lang('lang_v1.to') {{ $ledger_details['end_date'] }}</th>
            <th colspan="2">@lang('lang_v1.overall_summary')</th>
        </tr>
        <tr>
            <td>{{ $is_supplier && ! $is_customer ? __('report.total_purchase') : __('lang_v1.total_invoice') }}</td>
            <td class="sl-amount">@format_currency($is_supplier && ! $is_customer ? $ledger_details['total_purchase'] : $ledger_details['total_invoice'])</td>
            <td>{{ $is_supplier && ! $is_customer ? __('report.total_purchase') : __('lang_v1.total_invoice') }}</td>
            <td class="sl-amount">@format_currency($is_supplier && ! $is_customer ? $ledger_details['all_total_purchase'] : $ledger_details['all_total_invoice'])</td>
        </tr>
        <tr>
            <td>@lang('sale.total_paid')</td>
            <td class="sl-amount">@format_currency($ledger_details['total_paid'])</td>
            <td>@lang('sale.total_paid')</td>
            <td class="sl-amount">@format_currency($is_supplier && ! $is_customer ? $ledger_details['all_purchase_paid'] : $ledger_details['all_invoice_paid'])</td>
        </tr>
        <tr>
            <td>@if($ledger_details['ledger_discount'] > 0) @lang('lang_v1.ledger_discount') @endif</td>
            <td class="sl-amount">@if($ledger_details['ledger_discount'] > 0) @format_currency($ledger_details['ledger_discount']) @endif</td>
            <td class="sl-due">@lang('lang_v1.balance_due')</td>
            <td class="sl-amount sl-due">@format_currency($ledger_details['all_balance_due'])</td>
        </tr>
    </table>

    <br>

    {{-- Ledger table --}}
    <table class="sl-bordered" id="ledger_table">
        <thead>
            <tr>
                <th style="width: 4%;">#</th>
                <th style="width: 14%;">@lang('lang_v1.date')</th>
                <th style="width: 11%;">@lang('purchase.ref_no')</th>
                <th style="width: 11%;">@lang('lang_v1.type')</th>
                <th style="width: 9%;">Method</th>
                <th style="width: 13%;">@lang('account.debit')</th>
                <th style="width: 13%;">@lang('account.credit')</th>
                <th style="width: 14%;">@lang('lang_v1.balance')</th>
                <th style="width: 11%;">@lang('report.others')</th>
            </tr>
        </thead>
        <tbody>
            @foreach($ledger_details['ledger'] as $data)
                @php
                    $total_debit += $data['debit'] !== '' ? (float) $data['debit'] : 0;
                    $total_credit += $data['credit'] !== '' ? (float) $data['credit'] : 0;
                @endphp
                <tr>
                    <td class="sl-center">{{ $loop->iteration }}</td>
                    <td>{{ @format_datetime($data['date']) }}</td>
                    <td>{{ $data['ref_no'] }}</td>
                    <td>{{ $data['type'] }}@if(!empty($data['type_badge']))<br>{!! $data['type_badge'] !!}@endif @if(!empty($data['location']) && empty($location))<br><span class="sl-muted">{{ $data['location'] }}</span>@endif</td>
                    <td>{{ $data['payment_method'] }}</td>
                    <td class="sl-amount">@if($data['debit'] !== '') @format_currency($data['debit']) @endif</td>
                    <td class="sl-amount">@if($data['credit'] !== '') @format_currency($data['credit']) @endif</td>
                    <td class="sl-amount">{{ $data['balance'] }}</td>
                    <td>
                        <span class="sl-muted">{!! $data['others'] !!}</span>
                        @if(empty($for_pdf) && !empty($is_admin) && !empty($data['transaction_id']) && $data['transaction_type'] == 'ledger_discount')
                            <br>
                            <button type="button" class="tw-dw-btn tw-dw-btn-outline tw-dw-btn-xs tw-dw-btn-error delete_ledger_discount" data-href="{{action([\App\Http\Controllers\LedgerDiscountController::class, 'destroy'], ['ledger_discount' => $data['transaction_id']])}}"><i class="fas fa-trash"></i></button>
                            <button type="button" class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-primary btn-modal" data-href="{{action([\App\Http\Controllers\LedgerDiscountController::class, 'edit'], ['ledger_discount' => $data['transaction_id']])}}" data-container="#edit_ledger_discount_modal"><i class="fas fa-edit"></i></button>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr class="sl-total">
                <td colspan="5" class="sl-right">@lang('sale.total')</td>
                <td class="sl-amount">@format_currency($total_debit)</td>
                <td class="sl-amount">@format_currency($total_credit)</td>
                <td colspan="2"></td>
            </tr>
        </tfoot>
    </table>
</div>
