@extends('layouts.app')
@section('title', 'Journals')

@section('content')
@include('ledger.partials.styles')
@php
    $L = \App\Http\Controllers\LedgerController::class;
    $fmt = fn ($d) => \Carbon::parse($d)->format(session('business.date_format') ?: 'd-m-Y');
    $m = fn ($v) => (float) $v > 0 ? number_format((float) $v, 2) : '';
@endphp
<section class="content-header no-print">
    <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">Journals <small>every entry, by date (day book)</small></h1>
</section>
<section class="content">
    @include('ledger.partials.nav', ['active' => 'journals'])

    @component('components.filters', ['title' => __('report.filters')])
        <form method="GET" id="journal_filter_form">
            <input type="hidden" name="start_date" id="jf_start" value="{{ $start_date }}">
            <input type="hidden" name="end_date" id="jf_end" value="{{ $end_date }}">
            <div class="col-md-3">
                <div class="form-group">
                    <label for="jf_range">{{ __('report.date_range') }}:</label>
                    <input type="text" id="jf_range" class="form-control" readonly value="{{ $fmt($start_date) }} ~ {{ $fmt($end_date) }}">
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group">
                    <label for="jf_type">Type:</label>
                    {!! Form::select('source_type', $labels, $source_type ?: null, ['class' => 'form-control select2 jf-change', 'style' => 'width:100%', 'id' => 'jf_type', 'placeholder' => __('lang_v1.all')]) !!}
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group">
                    <label for="jf_q">Ref / details:</label>
                    <input type="text" name="q" id="jf_q" class="form-control" value="{{ $q }}" placeholder="Invoice, ref no …">
                </div>
            </div>
        </form>
    @endcomponent

    @component('components.widget')
        <div class="no-print" style="display:flex; gap:8px; align-items:center; margin-bottom:10px;">
            <a href="{{ action([$L, 'createJournal']) }}" class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-primary tw-text-white"><i class="fa fa-plus"></i> Manual journal</a>
            <span class="text-muted small">Journals of POS records follow the records (Update ledger). Use a manual journal for owner's capital, loans, drawings, corrections.</span>
            <span class="text-muted" style="margin-left:auto;">{{ number_format($journals->total()) }} journal(s)</span>
        </div>
        <div class="table-responsive">
            <table class="ledger-table">
                <thead>
                    <tr><th style="width:130px;">Date</th><th>Ref</th><th>Type</th><th>Account</th><th>Customer / supplier</th><th class="right">Debit</th><th class="right">Credit</th><th class="no-print"></th></tr>
                </thead>
                <tbody>
                    @forelse ($journals as $j)
                        @php $jl = $lines[$j->id] ?? collect(); @endphp
                        <tr class="section">
                            <td>{{ \Carbon::parse($j->entry_date)->format((session('business.date_format') ?: 'd-m-Y').' H:i') }}</td>
                            <td>{{ $j->ref_no }}</td>
                            <td>{{ $labels[$j->source_type] ?? $j->source_type }}</td>
                            <td colspan="2" style="font-weight:normal;">{{ $j->memo }} @if ($j->by_name) <span class="muted">· by {{ $j->by_name }}</span> @endif</td>
                            <td class="right">{{ number_format((float) $jl->sum('debit'), 2) }}</td>
                            <td class="right">{{ number_format((float) $jl->sum('credit'), 2) }}</td>
                            <td class="no-print">
                                @if ($j->source_type == 'manual')
                                    <form method="POST" action="{{ action([$L, 'destroyJournal'], [$j->id]) }}" class="delete_journal" style="display:inline;">
                                        @csrf <button class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-error"><i class="fa fa-trash"></i></button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                        @foreach ($jl as $l)
                            <tr class="sub">
                                <td></td><td></td><td></td>
                                <td style="padding-left: {{ $l->credit > 0 ? 30 : 8 }}px;">{{ trim($l->code.' '.$l->type_name) }}@if ($l->account_name) — {{ $l->account_name }}@endif</td>
                                <td>{{ $l->contact }}</td>
                                <td class="right">{{ $m($l->debit) }}</td>
                                <td class="right">{{ $m($l->credit) }}</td>
                                <td class="no-print"></td>
                            </tr>
                        @endforeach
                    @empty
                        <tr><td colspan="8" class="center muted">No journals for these dates. If you have never pressed Update ledger, do that first.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="no-print">{{ $journals->links() }}</div>
    @endcomponent
</section>
@endsection

@section('javascript')
<script>
    $(function () {
        var form = $('#journal_filter_form');
        $('#jf_range').daterangepicker($.extend({}, dateRangeSettings, { startDate: moment('{{ $start_date }}'), endDate: moment('{{ $end_date }}') }),
            function (start, end) { $('#jf_start').val(start.format('YYYY-MM-DD')); $('#jf_end').val(end.format('YYYY-MM-DD')); form.submit(); });
        $('.jf-change').on('change', function () { form.submit(); });
        $('#jf_q').on('keydown', function (e) { if (e.key === 'Enter') { form.submit(); } });
        $('#collapseFilter').collapse('show');
        $(document).on('submit', '.delete_journal', function (e) {
            var f = this;
            if ($(f).data('ok')) { return true; }
            e.preventDefault();
            swal({ title: 'Delete this manual journal?', icon: 'warning', buttons: ['Cancel', 'Delete'], dangerMode: true })
                .then(function (ok) { if (ok) { $(f).data('ok', true); f.submit(); } });
        });
    });
</script>
@endsection
