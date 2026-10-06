@extends('layouts.app')
@section('title', 'Commission payout')

@section('content')
@php
    $df = session('business.date_format');
    $agent_name = trim($agent->surname.' '.$agent->first_name.' '.$agent->last_name);
    $s_sales = (float) $settlement->sales_amount;
    $s_sales_c = (float) $settlement->sales_commission;
    $s_ret = (float) $settlement->returns_amount;
    $s_ret_c = (float) $settlement->returns_commission;
    $s_bf = (float) $settlement->carry_brought_forward;
    $s_payable = (float) $settlement->payable;
    $s_cf = (float) $settlement->carry_forward;
    $s_paid = $period['paid'];
    $s_due = $period['due'];
@endphp
<section class="content-header no-print">
    <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">Commission payout
        <small>{{ $agent_name }}: {{ $settlement->period_start->format($df) }} ~ {{ $settlement->period_end->format($df) }}</small>
    </h1>
</section>

<section class="content">
    <div class="no-print" style="margin-bottom: 12px; display: flex; flex-wrap: wrap; gap: 8px;">
        <a href="{{ action([\App\Http\Controllers\CommissionPayoutController::class, 'index']) }}" class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-outline"><i class="fa fa-arrow-left"></i> Commission payouts</a>
        <button type="button" class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-primary tw-text-white" onclick="window.print()"><i class="fa fa-print"></i> Print</button>
        @if ($s_due > 0.009)
            @can('expense.add')
                <a class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-success tw-text-white btn-modal" data-container=".cmmsn_pay_modal"
                   data-href="{{ action([\App\Http\Controllers\CommissionPayoutController::class, 'payForm']) }}?agent_id={{ $agent->id }}&start={{ $settlement->period_start->toDateString() }}&end={{ $settlement->period_end->toDateString() }}">
                    <i class="fa fa-money-bill-wave"></i> Pay rest (@format_currency($s_due))</a>
            @endcan
        @endif
        @if ($is_latest)
            @can('expense.delete')
                <button type="button" class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-outline tw-dw-btn-error" id="cpay_unlock"><i class="fa fa-unlock"></i> Unlock</button>
            @endcan
        @endif
    </div>

    @if ($period['changed'])
        <div class="alert alert-info no-print">Sales of this period were changed after it was locked (live commission now
            {{ @num_format($period['live_commission']) }}). The locked figures below stay; returns made later are taken off the next payout.</div>
    @endif

    @component('components.widget')
        <h3 style="margin-top: 0; text-align: center;">{{ session('business.name') }}</h3>
        <h4 style="text-align: center; margin-top: 0;">Commission payout: {{ $agent_name }}, {{ $settlement->period_start->format($df) }} ~ {{ $settlement->period_end->format($df) }}</h4>
        <table class="table table-bordered table-condensed" style="max-width: 700px;">
            <tr><th>Sales in this period (as sold)</th><td class="text-right">@format_currency($s_sales)</td><td class="text-right">@format_currency($s_sales_c)</td></tr>
            <tr><th>Less: returns made in this period</th><td class="text-right">&minus;@format_currency($s_ret)</td><td class="text-right">&minus;@format_currency($s_ret_c)</td></tr>
            @if ($s_bf > 0)
                <tr><th colspan="2">Less: left over from the previous period</th><td class="text-right">&minus;@format_currency($s_bf)</td></tr>
            @endif
            <tr style="background: #eee;"><th colspan="2">Commission</th><td class="text-right"><b>@format_currency($s_payable)</b></td></tr>
            @if ($s_cf > 0)
                <tr><td colspan="2">Taken off the next period</td><td class="text-right">@format_currency($s_cf)</td></tr>
            @endif
            <tr><th colspan="2">Paid</th><td class="text-right">@format_currency($s_paid)</td></tr>
            <tr style="background: #dff0d8;"><th colspan="2">Due</th><td class="text-right"><b>@format_currency($s_due)</b></td></tr>
        </table>
        <p class="text-muted small">Locked {{ optional($settlement->locked_at)->format($df.' H:i') }} by {{ optional($settlement->lockedBy)->first_name }}</p>

        <h4>Payments (expenses)</h4>
        <table class="table table-bordered table-condensed">
            <tr style="background: #f5f5f5;"><th>Date</th><th>Expense ref.</th><th>Method</th><th>Note</th><th class="text-right">Amount</th></tr>
            @forelse ($expenses as $e)
                @php $e_amount = (float) $e->final_total; @endphp
                <tr>
                    <td>{{ \Carbon::parse($e->transaction_date)->format($df) }}</td>
                    <td>{{ $e->ref_no }}</td>
                    <td>{{ $e->payment_lines->pluck('method')->unique()->implode(', ') }}</td>
                    <td>{{ $e->additional_notes }}</td>
                    <td class="text-right">@format_currency($e_amount)</td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-center text-muted">Not paid yet</td></tr>
            @endforelse
        </table>

        <h4>Sales in this period ({{ $period['sales']->pluck('transaction_id')->unique()->count() }} invoices)</h4>
        <div class="table-responsive">
            <table class="table table-bordered table-condensed">
                <tr style="background: #f5f5f5;"><th>Date</th><th>Invoice</th><th>Customer</th><th>Product</th><th>SKU</th><th class="text-right">Qty</th><th class="text-right">Price</th><th class="text-right">Amount</th><th>Rate</th><th class="text-right">Commission</th></tr>
                @forelse ($period['sales'] as $row)
                    @php $r_price = $row->price; $r_amount = $row->amount; $r_c = $row->commission; @endphp
                    <tr>
                        <td style="white-space: nowrap;">{{ \Carbon::parse($row->transaction_date)->format($df) }}</td>
                        <td>{{ $row->invoice_no }}</td>
                        <td>{{ $row->customer }}</td>
                        <td>{{ $row->product }}</td>
                        <td>{{ $row->sku }}</td>
                        <td class="text-right">{{ @format_quantity($row->qty) }} {{ $row->unit }}</td>
                        <td class="text-right">@format_currency($r_price)</td>
                        <td class="text-right">@format_currency($r_amount)</td>
                        <td>{{ $row->rule_text }}</td>
                        <td class="text-right">@format_currency($r_c)</td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="text-center text-muted">No sales</td></tr>
                @endforelse
            </table>
        </div>

        <h4>Returns made in this period ({{ $period['returns']->pluck('return_id')->unique()->count() }})</h4>
        <div class="table-responsive">
            <table class="table table-bordered table-condensed">
                <tr style="background: #f5f5f5;"><th>Return date</th><th>Return no.</th><th>Sold on / invoice</th><th>Customer</th><th>Product</th><th class="text-right">Qty</th><th class="text-right">Amount</th><th>Rate</th><th class="text-right">Commission back</th></tr>
                @forelse ($period['returns'] as $row)
                    @php $r_amount = $row->amount; $r_c = $row->commission; @endphp
                    <tr>
                        <td style="white-space: nowrap;">{{ \Carbon::parse($row->return_date)->format($df) }}</td>
                        <td>{{ $row->return_no }}</td>
                        <td>{{ \Carbon::parse($row->sale_date)->format($df) }} / {{ $row->invoice_no }}</td>
                        <td>{{ $row->customer }}</td>
                        <td>{{ $row->product }}</td>
                        <td class="text-right">{{ @format_quantity($row->qty) }} {{ $row->unit }}</td>
                        <td class="text-right">@format_currency($r_amount)</td>
                        <td>{{ $row->rule_text }}</td>
                        <td class="text-right">@format_currency($r_c)</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center text-muted">No returns</td></tr>
                @endforelse
            </table>
        </div>
    @endcomponent
</section>
<div class="modal fade cmmsn_pay_modal" tabindex="-1" role="dialog"></div>
@endsection

@section('javascript')
<script type="text/javascript">
    $('#cpay_unlock').on('click', function() {
        swal({ title: 'Unlock this period?', text: 'Reason for unlocking:', content: 'input', buttons: true, dangerMode: true }).then(function(reason) {
            if (!reason) {
                return;
            }
            $.ajax({
                method: 'POST',
                url: @json(action([\App\Http\Controllers\CommissionPayoutController::class, 'unlock'], [$settlement->id])),
                data: { _token: '{{ csrf_token() }}', reason: reason },
                dataType: 'json',
                success: function(result) {
                    if (result.success) {
                        toastr.success(result.msg);
                        setTimeout(function() { location.href = @json(action([\App\Http\Controllers\CommissionPayoutController::class, 'index'])); }, 700);
                    } else {
                        toastr.error(result.msg);
                    }
                }
            });
        });
    });
</script>
@endsection
