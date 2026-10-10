@extends('layouts.app')
@section('title', 'Trade scheme report')

@section('content')
@php $q = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ','), '0'), '.'); @endphp
<section class="content-header no-print">
    <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">Trade scheme report
        <small>free goods given on sales, at sale price and at cost</small>
    </h1>
</section>

<section class="content">
    @component('components.filters', ['title' => __('report.filters')])
        <form method="GET" id="ts_filter_form">
            <input type="hidden" name="start_date" id="ts_start" value="{{ $start }}">
            <input type="hidden" name="end_date" id="ts_end" value="{{ $end }}">
            <div class="col-md-3">
                <div class="form-group">
                    {!! Form::label('ts_date_range', __('report.date_range') . ':') !!}
                    {!! Form::text('ts_date_range', null, ['class' => 'form-control', 'id' => 'ts_date_range', 'readonly']) !!}
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group">
                    {!! Form::label('scheme_id', 'Scheme:') !!}
                    {!! Form::select('scheme_id', $schemes, $filters['scheme_id'] ?? null, ['class' => 'form-control select2 ts-filter', 'style' => 'width:100%', 'placeholder' => __('lang_v1.all')]) !!}
                </div>
            </div>
            <div class="col-md-2">
                <div class="form-group">
                    {!! Form::label('funded_by', 'Funded by:') !!}
                    {!! Form::select('funded_by', ['own' => 'Own', 'supplier' => 'Supplier'], $filters['funded_by'] ?? null, ['class' => 'form-control select2 ts-filter', 'style' => 'width:100%', 'placeholder' => __('lang_v1.all')]) !!}
                </div>
            </div>
            <div class="col-md-2">
                <div class="form-group">
                    {!! Form::label('location_id', __('purchase.business_location') . ':') !!}
                    {!! Form::select('location_id', $locations, $filters['location_id'] ?? null, ['class' => 'form-control select2 ts-filter', 'style' => 'width:100%', 'placeholder' => __('lang_v1.all')]) !!}
                </div>
            </div>
            <div class="col-md-2">
                <div class="form-group">
                    {!! Form::label('group_by', 'Show by:') !!}
                    {!! Form::select('group_by', ['scheme' => 'Scheme', 'product' => 'Product', 'customer' => 'Customer', 'invoice' => 'Invoice'], $group_by, ['class' => 'form-control select2 ts-filter', 'style' => 'width:100%']) !!}
                </div>
            </div>
        </form>
    @endcomponent

    <div style="display:grid; gap:12px; grid-template-columns:repeat(auto-fit, minmax(190px, 1fr)); margin-bottom:16px;">
        <div class="box" style="padding:14px 16px; margin:0;"><small class="text-muted">INVOICES WITH SCHEMES</small><br><b style="font-size:20px;">{{ number_format($totals['invoices']) }}</b></div>
        <div class="box" style="padding:14px 16px; margin:0;"><small class="text-muted">FREE GOODS AT SALE PRICE</small><br><b style="font-size:20px;">@format_currency($totals['sale_value'])</b></div>
        <div class="box" style="padding:14px 16px; margin:0;"><small class="text-muted">FREE GOODS AT COST</small><br><b style="font-size:20px;">@format_currency($totals['cost_value'])</b></div>
        <div class="box" style="padding:14px 16px; margin:0;"><small class="text-muted">COST TO CLAIM FROM SUPPLIERS</small><br><b style="font-size:20px; color:#1f7a50;">@format_currency($totals['supplier_cost'])</b>
            <br><a href="{{ action([\App\Http\Controllers\TradeSchemeReportController::class, 'claims'], ['start_date' => $start, 'end_date' => $end]) }}" class="small">Scheme claims →</a></div>
        <div class="box" style="padding:14px 16px; margin:0;"><small class="text-muted">OUR OWN COST</small><br><b style="font-size:20px; color:#dc2626;">@format_currency($totals['own_cost'])</b></div>
    </div>

    @component('components.widget', ['class' => 'box-primary', 'title' => 'Free goods by '.$group_by])
        <div class="table-responsive">
            <table class="table table-bordered table-striped" id="ts_report_table">
                <thead>
                    <tr>
                        <th>{{ ucfirst($group_by) }}</th>
                        <th>Invoices</th>
                        <th>Lines</th>
                        <th>Free qty (base unit)</th>
                        <th>Value at sale price</th>
                        <th>Value at cost</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $r)
                        <tr>
                            <td>{{ $r->label }} @if ($r->sub)<br><small class="text-muted">{{ $r->sub }}</small>@endif</td>
                            <td class="text-center">{{ $r->invoices }}</td>
                            <td class="text-center">{{ $r->lines }}</td>
                            <td class="text-right" data-order="{{ $r->free_qty }}">{{ $q($r->free_qty) }}</td>
                            <td class="text-right" data-order="{{ $r->sale_value }}">@format_currency($r->sale_value)</td>
                            <td class="text-right" data-order="{{ $r->cost_value }}">@format_currency($r->cost_value)</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="bg-gray font-17 text-center footer-total">
                        <td><strong>@lang('sale.total'):</strong></td>
                        <td>{{ $totals['invoices'] }}</td>
                        <td></td>
                        <td class="text-right">{{ $q($totals['free_qty']) }}</td>
                        <td class="text-right">@format_currency($totals['sale_value'])</td>
                        <td class="text-right">@format_currency($totals['cost_value'])</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endcomponent
</section>
@endsection

@section('javascript')
<script type="text/javascript">
    $(document).ready(function () {
        var start = moment('{{ $start }}'), end = moment('{{ $end }}');
        $('#ts_date_range').daterangepicker($.extend({}, dateRangeSettings, { startDate: start, endDate: end }), function (s, e) {
            $('#ts_date_range').val(s.format(moment_date_format) + ' ~ ' + e.format(moment_date_format));
            $('#ts_start').val(s.format('YYYY-MM-DD'));
            $('#ts_end').val(e.format('YYYY-MM-DD'));
            $('#ts_filter_form').submit();
        });
        $('#ts_date_range').val(start.format(moment_date_format) + ' ~ ' + end.format(moment_date_format));
        $('.ts-filter').on('change', function () { $('#ts_filter_form').submit(); });
        $('#ts_report_table').DataTable({ order: [], pageLength: 50 });
    });
</script>
@endsection
