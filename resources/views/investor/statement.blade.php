@extends('layouts.app')
@section('title', 'Investor statement: '.$investor->name)

@section('content')
<section class="content-header no-print">
    <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">Investor statement
        <small>{{ $investor->name }}</small>
    </h1>
</section>

@php
    $range = ['start' => \Carbon::parse($start)->format(session('business.date_format')), 'end' => \Carbon::parse($end)->format(session('business.date_format'))];
@endphp
<section class="content">
    <form method="GET" class="no-print" style="margin-bottom: 12px; display: flex; flex-wrap: wrap; gap: 8px; align-items: flex-end;">
        <div>
            <label style="display: block; margin-bottom: 2px;">From</label>
            <input type="text" name="start" class="form-control input-sm investor-date" value="{{ $range['start'] }}" readonly style="width: 130px; background: #fff;">
        </div>
        <div>
            <label style="display: block; margin-bottom: 2px;">To</label>
            <input type="text" name="end" class="form-control input-sm investor-date" value="{{ $range['end'] }}" readonly style="width: 130px; background: #fff;">
        </div>
        <button type="submit" class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-primary tw-text-white"><i class="fa fa-filter"></i> Show</button>
        <span class="text-muted" style="padding-bottom: 6px;">Sales, purchase cost, profit and the investor's share for this date range</span>
    </form>

    <div class="no-print" style="margin-bottom: 12px; display: flex; flex-wrap: wrap; gap: 8px;">
        <a href="{{ action([\App\Http\Controllers\InvestorController::class, 'index']) }}" class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-outline"><i class="fa fa-arrow-left"></i> Investors</a>
        <button type="button" class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-primary tw-text-white" onclick="window.print()"><i class="fa fa-print"></i> Print</button>
        <a href="{{ action([\App\Http\Controllers\InvestorController::class, 'statementPdf'], [$investor->id] + $range) }}" target="_blank" class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-outline tw-dw-btn-primary"><i class="fa fa-file-pdf"></i> PDF</a>
        <button type="button" class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-success tw-text-white" id="send_investor_statement"><i class="fab fa-whatsapp"></i> Send on WhatsApp</button>
        @can('investor.payout')
            @if ($period['due'] > 0 && empty($period['error']))
                <a class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-success tw-text-white btn-modal" data-href="{{ action([\App\Http\Controllers\InvestorController::class, 'pay'], [$investor->id] + $range) }}" data-container=".investor_modal"><i class="fa fa-money-bill-wave"></i> Pay for this period (@format_currency($period['due']))</a>
            @elseif ($period['paid'] > 0)
                <span class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-disabled"><i class="fa fa-check"></i> This period is paid</span>
            @else
                <span class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-disabled" title="{{ $period['error'] }}"><i class="fa fa-ban"></i> Nothing to pay for this period</span>
            @endif
        @endcan
        <a class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-outline tw-dw-btn-info btn-modal" data-href="{{ action([\App\Http\Controllers\InvestorController::class, 'capital'], [$investor->id]) }}" data-container=".investor_modal"><i class="fa fa-piggy-bank"></i> Capital</a>
        <a class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-outline tw-dw-btn-primary btn-modal" data-href="{{ action([\App\Http\Controllers\InvestorController::class, 'deals'], [$investor->id]) }}" data-container=".investor_modal"><i class="fa fa-handshake"></i> Deals</a>
    </div>

    @component('components.widget')
        @include('investor.partials.statement_body')
    @endcomponent

    <h4 class="no-print" style="margin-top: 4px;">What was sold: {{ $range['start'] }} ~ {{ $range['end'] }}</h4>
    @include('investor.partials.sales_tabs')
</section>
<div class="modal fade investor_modal no-print" tabindex="-1" role="dialog"></div>
<div class="modal fade view_modal no-print" tabindex="-1" role="dialog"></div>
@endsection

@section('javascript')
    @include('investor.partials.js')
    <script type="text/javascript">
        $('form .investor-date').datepicker({ autoclose: true, format: datepicker_date_format });

        //quick search inside a tab's table
        $('.inv-search').on('keyup', function() {
            var term = $(this).val().toLowerCase();
            var $rows = $($(this).data('table')).find('tbody tr');
            $rows.each(function() {
                var $tr = $(this);
                if ($tr.hasClass('inv-day')) {
                    $tr.toggle(term == '');
                    return;
                }
                $tr.toggle(term == '' || $tr.text().toLowerCase().indexOf(term) > -1);
            });
        });
        $('#send_investor_statement').on('click', function() {
            var $btn = $(this);
            var html = $btn.html();
            $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Sending...');
            $.ajax({
                method: 'POST',
                url: "{{ action([\App\Http\Controllers\InvestorController::class, 'sendStatement'], [$investor->id]) }}",
                data: { _token: '{{ csrf_token() }}', start: @json($range['start']), end: @json($range['end']) },
                dataType: 'json',
                success: function(result) {
                    result.success ? toastr.success(result.msg) : toastr.error(result.msg);
                },
                error: function() {
                    toastr.error(@json(__('messages.something_went_wrong')));
                },
                complete: function() {
                    $btn.prop('disabled', false).html(html);
                }
            });
        });
    </script>
@endsection
