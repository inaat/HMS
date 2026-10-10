@extends('layouts.app')
@section('title', 'Manual journal')

@section('content')
@include('ledger.partials.styles')
@php $L = \App\Http\Controllers\LedgerController::class; @endphp
<section class="content-header no-print">
    <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">Manual journal <small>debits must equal credits</small></h1>
</section>
<section class="content">
    @include('ledger.partials.nav', ['active' => 'journals'])
    <form method="POST" action="{{ action([$L, 'storeJournal']) }}" id="journal_form">
        @csrf
        @component('components.widget')
            <div class="row">
                <div class="col-sm-3 form-group">
                    <label for="jv_date">Date *</label>
                    <div class="input-group">
                        <span class="input-group-addon"><i class="fa fa-calendar"></i></span>
                        <input type="text" name="entry_date" id="jv_date" class="form-control" readonly required>
                    </div>
                </div>
                <div class="col-sm-3 form-group">
                    <label for="jv_ref">Ref no</label>
                    <input type="text" name="ref_no" id="jv_ref" class="form-control" placeholder="Leave empty for automatic" value="{{ old('ref_no') }}">
                </div>
                @if (count($locations) > 1)
                    <div class="col-sm-3 form-group">
                        <label for="jv_location">{{ __('purchase.business_location') }}</label>
                        {!! Form::select('location_id', $locations, old('location_id'), ['class' => 'form-control select2', 'style' => 'width:100%', 'id' => 'jv_location', 'placeholder' => __('lang_v1.all')]) !!}
                    </div>
                @endif
                <div class="col-sm-12 form-group">
                    <label for="jv_memo">Details</label>
                    <input type="text" name="memo" id="jv_memo" class="form-control" maxlength="250" placeholder="e.g. Owner put money into the business" value="{{ old('memo') }}">
                </div>
            </div>
            <table class="ledger-table" id="jv_lines">
                <thead><tr><th style="width:34%;">Account</th><th style="width:22%;">Customer / supplier (optional)</th><th class="right" style="width:13%;">Debit</th><th class="right" style="width:13%;">Credit</th><th>Note</th><th style="width:40px;"></th></tr></thead>
                <tbody></tbody>
                <tfoot>
                    <tr class="total"><td colspan="2" class="right">Total</td><td class="right" id="jv_dr">0.00</td><td class="right" id="jv_cr">0.00</td><td colspan="2" id="jv_diff"></td></tr>
                </tfoot>
            </table>
            <div style="margin-top:10px; display:flex; gap:8px;">
                <button type="button" class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-outline tw-dw-btn-primary" id="jv_add"><i class="fa fa-plus"></i> Add line</button>
                <button type="submit" class="tw-dw-btn tw-dw-btn-primary tw-text-white" id="jv_save" style="margin-left:auto;">Save journal</button>
            </div>
            <p class="text-muted small" style="margin-top:10px;">Examples — owner puts money in: <b>Debit</b> shafiq (payment account), <b>Credit</b> Owner's capital.
                Owner takes money out: Debit Drawings, Credit shafiq. Loan received: Debit bank, Credit Loans.</p>
        @endcomponent
    </form>
</section>

<template id="jv_row">
    <tr>
        <td>{!! Form::select('lines[__i][account]', $options, null, ['class' => 'form-control jv-account', 'style' => 'width:100%', 'placeholder' => 'Choose account', 'required']) !!}</td>
        <td><select name="lines[__i][contact_id]" class="form-control jv-contact" style="width:100%;"></select></td>
        <td><input type="text" name="lines[__i][debit]" class="form-control input_number jv-dr text-right"></td>
        <td><input type="text" name="lines[__i][credit]" class="form-control input_number jv-cr text-right"></td>
        <td><input type="text" name="lines[__i][note]" class="form-control" maxlength="190"></td>
        <td><button type="button" class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-error jv-remove"><i class="fa fa-times"></i></button></td>
    </tr>
</template>
@endsection

@section('javascript')
<script>
    $(function () {
        var n = 0;
        $('#jv_date').datepicker({ autoclose: true, format: datepicker_date_format }).datepicker('setDate', new Date());
        function addRow() {
            var row = $($('#jv_row').html().replace(/__i/g, n++));
            $('#jv_lines tbody').append(row);
            row.find('.jv-account').select2();
            row.find('.jv-contact').select2({
                allowClear: true, placeholder: '—', minimumInputLength: 1,
                ajax: { url: '{{ action([$L, 'contactSearch']) }}', dataType: 'json', delay: 250,
                    data: function (p) { return { q: p.term }; }, processResults: function (d) { return { results: d }; } }
            });
        }
        function totals() {
            var dr = 0, cr = 0;
            $('.jv-dr').each(function () { dr += __read_number($(this)) || 0; });
            $('.jv-cr').each(function () { cr += __read_number($(this)) || 0; });
            $('#jv_dr').text(__number_f(dr)); $('#jv_cr').text(__number_f(cr));
            var diff = Math.round((dr - cr) * 100) / 100;
            $('#jv_diff').html(diff === 0 && dr > 0 ? '<span class="ledger-ok"><i class="fa fa-check"></i> balanced</span>'
                : '<span class="ledger-bad">difference ' + __number_f(diff) + '</span>');
            $('#jv_save').prop('disabled', !(diff === 0 && dr > 0));
        }
        addRow(); addRow();
        totals();
        $('#jv_add').on('click', addRow);
        $(document).on('click', '.jv-remove', function () { $(this).closest('tr').remove(); totals(); });
        $(document).on('input', '.jv-dr, .jv-cr', function () {
            // a line is either a debit or a credit
            if ($(this).val() !== '') { $(this).closest('tr').find($(this).hasClass('jv-dr') ? '.jv-cr' : '.jv-dr').val(''); }
            totals();
        });
    });
</script>
@endsection
