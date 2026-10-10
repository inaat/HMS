@extends('layouts.app')
@section('title', 'Scheme claims')

@section('content')
@php
    $q = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ','), '0'), '.');
    $settled = ['credit_note' => 'Credit note', 'cash' => 'Cash / bank', 'stock' => 'Free stock'];
@endphp
<section class="content-header no-print">
    <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">Scheme claims
        <small>claim supplier-funded free goods back from the supplier (e.g. Hilal)</small>
    </h1>
</section>

<section class="content">
    @component('components.filters', ['title' => __('report.filters')])
        <form method="GET" id="cl_filter_form">
            <input type="hidden" name="start_date" id="cl_start" value="{{ $start }}">
            <input type="hidden" name="end_date" id="cl_end" value="{{ $end }}">
            <div class="col-md-3">
                <div class="form-group">
                    {!! Form::label('cl_date_range', 'Claim period:') !!}
                    {!! Form::text('cl_date_range', null, ['class' => 'form-control', 'id' => 'cl_date_range', 'readonly']) !!}
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group">
                    {!! Form::label('supplier_id', 'Supplier:') !!}
                    {!! Form::select('supplier_id', $suppliers, $supplier_id, ['class' => 'form-control select2', 'style' => 'width:100%', 'placeholder' => __('lang_v1.all'), 'id' => 'cl_supplier']) !!}
                </div>
            </div>
        </form>
    @endcomponent

    @component('components.widget', ['class' => 'box-primary', 'title' => 'Ready to claim — '.\Carbon::parse($start)->format(session('business.date_format')).' to '.\Carbon::parse($end)->format(session('business.date_format'))])
        <table class="table table-bordered table-striped">
            <thead><tr><th>Supplier</th><th>Invoices</th><th>Free qty (base)</th><th>At sale price</th><th>Claim (at cost)</th><th></th></tr></thead>
            <tbody>
                @forelse ($open as $o)
                    <tr>
                        <td><b>{{ $supplier_names[$o->supplier_id] ?? 'No supplier set on the scheme' }}</b></td>
                        <td class="text-center">{{ $o->invoices }}</td>
                        <td class="text-right">{{ $q($o->free_qty) }}</td>
                        <td class="text-right">@format_currency($o->sale_value)</td>
                        <td class="text-right"><b>@format_currency($o->cost_value)</b></td>
                        <td>
                            @if ($o->supplier_id)
                                {!! Form::open(['url' => action([\App\Http\Controllers\TradeSchemeReportController::class, 'storeClaim']), 'method' => 'post', 'style' => 'display:inline;']) !!}
                                    <input type="hidden" name="supplier_id" value="{{ $o->supplier_id }}">
                                    <input type="hidden" name="start_date" value="{{ $start }}">
                                    <input type="hidden" name="end_date" value="{{ $end }}">
                                    <button type="submit" class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-success tw-text-white"><i class="fa fa-file-invoice"></i> Create claim</button>
                                {!! Form::close() !!}
                            @else
                                <small class="text-danger">set the supplier on the scheme first</small>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted">No supplier-funded free goods in this period.</td></tr>
                @endforelse
            </tbody>
        </table>
    @endcomponent

    @component('components.widget', ['class' => 'box-primary', 'title' => 'Claims'])
        <div class="table-responsive">
            <table class="table table-bordered table-striped" id="claims_table">
                <thead>
                    <tr><th>Claim</th><th>Supplier</th><th>Period</th><th>Claimed</th><th>Status</th><th>Settled</th><th>@lang('messages.action')</th></tr>
                </thead>
                <tbody>
                    @foreach ($claims as $c)
                        <tr>
                            <td><b>{{ $c->claim_no }}</b></td>
                            <td>{{ $supplier_names[$c->supplier_id] ?? '#'.$c->supplier_id }}</td>
                            <td>{{ \Carbon::parse($c->period_start)->format(session('business.date_format')) }} – {{ \Carbon::parse($c->period_end)->format(session('business.date_format')) }}</td>
                            <td class="text-right" data-order="{{ $c->total_value }}">@format_currency($c->total_value)</td>
                            <td><span class="label {{ $c->status === 'received' ? 'label-success' : 'label-warning' }}">{{ $c->status }}</span></td>
                            <td>
                                @if ($c->status === 'received')
                                    {{ $settled[$c->settled_by] ?? '' }} · @format_currency($c->received_amount)
                                    <br><small class="text-muted">{{ \Carbon::parse($c->settled_at)->format(session('business.date_format')) }} {{ $c->settlement_ref }}</small>
                                    @if ((float) $c->total_value - (float) $c->received_amount > 0.004)
                                        <br><small class="text-danger">not approved: @format_currency((float) $c->total_value - (float) $c->received_amount)</small>
                                    @endif
                                @endif
                            </td>
                            <td style="white-space:nowrap;">
                                <a href="{{ action([\App\Http\Controllers\TradeSchemeReportController::class, 'showClaim'], [$c->id]) }}" class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-primary"><i class="fa fa-eye"></i> Open</a>
                                @if ($c->status === 'claimed')
                                    {{-- a settled claim is undone first (Open → Undo settlement), so its credit note / deposit / stock is reversed --}}
                                    {!! Form::open(['url' => action([\App\Http\Controllers\TradeSchemeReportController::class, 'destroyClaim'], [$c->id]), 'method' => 'delete', 'style' => 'display:inline;',
                                        'onsubmit' => "return confirm('Delete claim ".e($c->claim_no)."? The free goods can be claimed again afterwards.')"]) !!}
                                        <button type="submit" class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-error"><i class="fa fa-trash"></i> Delete</button>
                                    {!! Form::close() !!}
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endcomponent
</section>
@endsection

@section('javascript')
<script type="text/javascript">
    $(document).ready(function () {
        var start = moment('{{ $start }}'), end = moment('{{ $end }}');
        $('#cl_date_range').daterangepicker($.extend({}, dateRangeSettings, { startDate: start, endDate: end }), function (s, e) {
            $('#cl_start').val(s.format('YYYY-MM-DD'));
            $('#cl_end').val(e.format('YYYY-MM-DD'));
            $('#cl_filter_form').submit();
        });
        $('#cl_date_range').val(start.format(moment_date_format) + ' ~ ' + end.format(moment_date_format));
        $('#cl_supplier').on('change', function () { $('#cl_filter_form').submit(); });
        $('#claims_table').DataTable({ order: [], pageLength: 25 });
    });
</script>
@endsection
