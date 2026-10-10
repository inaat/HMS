@extends('layouts.app')
@section('title', $title)

@section('content')
@include('ledger.partials.styles')
@php
    $L = \App\Http\Controllers\LedgerController::class;
    $active = request()->route()->getActionMethod();
    $fmt = fn ($d) => \Carbon::parse($d)->format(session('business.date_format') ?: 'd-m-Y');
@endphp
<section class="content-header no-print">
    <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">{{ $title }} <small>{{ $subtitle }}</small></h1>
</section>
<section class="content">
    @include('ledger.partials.nav', ['active' => $active])

    @component('components.filters', ['title' => __('report.filters')])
        <form method="GET" id="ledger_filter_form">
            @if ($filter == 'date')
                <input type="hidden" name="date" id="lf_date_value" value="{{ $date }}">
                <div class="col-md-3">
                    <div class="form-group">
                        <label for="lf_date">As of date:</label>
                        <div class="input-group">
                            <span class="input-group-addon"><i class="fa fa-calendar"></i></span>
                            <input type="text" id="lf_date" class="form-control" readonly value="{{ $fmt($date) }}">
                        </div>
                    </div>
                </div>
            @else
                <input type="hidden" name="start_date" id="lf_start" value="{{ $start_date }}">
                <input type="hidden" name="end_date" id="lf_end" value="{{ $end_date }}">
                @if ($filter == 'ledger')
                    <div class="col-md-4">
                        <div class="form-group">
                            <label for="lf_account">Account:</label>
                            {!! Form::select('account', $options, $account, ['class' => 'form-control select2 lf-change', 'style' => 'width:100%', 'id' => 'lf_account']) !!}
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label for="lf_contact">Customer / supplier:</label>
                            {!! Form::select('contact_id', $contacts, $contact_id, ['class' => 'form-control lf-change', 'style' => 'width:100%', 'id' => 'lf_contact', 'placeholder' => __('lang_v1.all')]) !!}
                        </div>
                    </div>
                @endif
                <div class="col-md-3">
                    <div class="form-group">
                        <label for="lf_range">{{ __('report.date_range') }}:</label>
                        <input type="text" id="lf_range" class="form-control" readonly value="{{ $fmt($start_date) }} ~ {{ $fmt($end_date) }}">
                    </div>
                </div>
                @if (! empty($locations) && count($locations) > 1)
                    <div class="col-md-3">
                        <div class="form-group">
                            <label for="lf_location">{{ __('purchase.business_location') }}:</label>
                            {!! Form::select('location_id', $locations, $location_id, ['class' => 'form-control select2 lf-change', 'style' => 'width:100%', 'id' => 'lf_location', 'placeholder' => __('lang_v1.all')]) !!}
                        </div>
                    </div>
                @endif
            @endif
        </form>
    @endcomponent

    @component('components.widget')
        <div class="no-print" style="display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-bottom:10px;">
            @isset($balanced)
                @if ($balanced)
                    <span class="ledger-ok"><i class="fa fa-check-circle"></i> {{ $filter == 'date' && $active == 'balanceSheet' ? 'Assets = Liabilities + Equity' : 'Debits = Credits' }}</span>
                @else
                    <span class="ledger-bad"><i class="fa fa-times-circle"></i> Not balanced — press Update ledger on the Accounting page</span>
                @endif
            @endisset
            @if (isset($pos_net) && $pos_net !== null)
                <span class="text-muted">POS Profit / Loss report for these dates: <b>{{ number_format($pos_net, 2) }}</b>
                    @if (abs($pos_net - $net) >= 1)
                        (difference {{ number_format($net - $pos_net, 2) }}: paisa rounding — see "Rounding differences" — and zakat,
                        which the POS report keeps out of profit while the books show it as an expense)
                    @endif
                </span>
            @endif
            @if (! empty($too_many))
                <span class="ledger-bad">Only the first 5,000 lines are shown — choose fewer dates</span>
            @endif
            <div style="margin-left:auto; display:flex; gap:6px;">
                <a href="{{ request()->fullUrlWithQuery(['print' => 1]) }}" target="_blank" class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-primary tw-text-white"><i class="fa fa-print"></i> Print</a>
                <a href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}" class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-outline tw-dw-btn-success"><i class="fa fa-file-excel"></i> Excel</a>
            </div>
        </div>
        <div class="table-responsive">
            @include('ledger.partials.table')
        </div>
    @endcomponent
</section>
@endsection

@section('javascript')
<script>
    $(function () {
        var form = $('#ledger_filter_form');
        @if ($filter == 'date')
            $('#lf_date').datepicker({ autoclose: true, format: datepicker_date_format }).on('changeDate', function (e) {
                $('#lf_date_value').val(moment(e.date).format('YYYY-MM-DD'));
                form.submit();
            });
        @else
            $('#lf_range').daterangepicker($.extend({}, dateRangeSettings, {
                startDate: moment('{{ $start_date }}'), endDate: moment('{{ $end_date }}')
            }), function (start, end) {
                $('#lf_start').val(start.format('YYYY-MM-DD'));
                $('#lf_end').val(end.format('YYYY-MM-DD'));
                form.submit();
            });
        @endif
        $('.lf-change').on('change', function () { form.submit(); });
        $('#lf_contact').select2({
            allowClear: true, placeholder: '{{ __('lang_v1.all') }}', minimumInputLength: 1,
            ajax: { url: '{{ action([$L, 'contactSearch']) }}', dataType: 'json', delay: 250,
                data: function (p) { return { q: p.term }; }, processResults: function (d) { return { results: d }; } }
        });
        $('#collapseFilter').collapse('show');
    });
</script>
@endsection
