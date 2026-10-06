@extends('layouts.app')
@section('title', 'Commission payouts')

@section('content')
@php
    $df = session('business.date_format');
    $t_sales = $settlements->sum('sales_amount');
    $t_returns = $settlements->sum('returns_amount');
    $t_payable = $settlements->sum('payable');
    $t_paid = $settlements->sum('paid');
    $t_due = $settlements->sum('due');
@endphp
<section class="content-header no-print">
    <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">Commission payouts
        <small>pay agents per period; a paid period is locked</small>
    </h1>
</section>

<section class="content">
    <div class="no-print" style="margin-bottom: 12px; display: flex; flex-wrap: wrap; gap: 8px; align-items: flex-end;">
        <form method="GET" style="display: flex; gap: 8px; align-items: flex-end;">
            <div>
                <label style="display: block; margin-bottom: 2px;">Commission agent</label>
                <select name="agent_id" class="form-control input-sm" onchange="this.form.submit()" style="min-width: 220px;">
                    <option value="">All agents</option>
                    @foreach ($agents as $id => $name)
                        <option value="{{ $id }}" @if ($agent_id == $id) selected @endif>{{ trim($name) }}</option>
                    @endforeach
                </select>
            </div>
        </form>
        @can('expense.add')
            <a class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-success tw-text-white btn-modal" data-container=".cmmsn_pay_modal"
               data-href="{{ action([\App\Http\Controllers\CommissionPayoutController::class, 'payForm']) }}{{ $agent_id ? '?agent_id='.$agent_id : '' }}">
                <i class="fa fa-money-bill-wave"></i> Pay commission</a>
        @endcan
        <a href="{{ action([\App\Http\Controllers\ReportController::class, 'getCommissionAgentReport']) }}" class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-outline tw-dw-btn-primary">
            <i class="fa fa-chart-bar"></i> Commission Agent Report</a>
    </div>

    @component('components.widget')
        <div class="table-responsive">
            <table class="table table-bordered table-hover table-condensed">
                <thead>
                    <tr style="background: #f5f5f5;">
                        <th>Agent</th>
                        <th>Period</th>
                        <th class="text-right">Sales</th>
                        <th class="text-right">Returns</th>
                        <th class="text-right">Commission</th>
                        <th class="text-right">Paid</th>
                        <th class="text-right">Due</th>
                        <th>Locked</th>
                        <th class="no-print"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($settlements as $s)
                        @php
                            $s_sales = (float) $s->sales_amount;
                            $s_returns = (float) $s->returns_amount;
                            $s_payable = (float) $s->payable;
                            $s_paid = $s->paid;
                            $s_due = $s->due;
                        @endphp
                        <tr>
                            <td><b>{{ $s->agent ? trim($s->agent->surname.' '.$s->agent->first_name.' '.$s->agent->last_name) : '#'.$s->agent_id }}</b></td>
                            <td style="white-space: nowrap;">{{ $s->period_start->format($df) }} ~ {{ $s->period_end->format($df) }}</td>
                            <td class="text-right">@format_currency($s_sales)</td>
                            <td class="text-right">@if ($s_returns > 0) &minus;@format_currency($s_returns) @endif</td>
                            <td class="text-right">@format_currency($s_payable)
                                @if ($s->carry_forward > 0)<br><small class="text-muted">{{ @num_format($s->carry_forward) }} to next period</small>@endif</td>
                            <td class="text-right">@format_currency($s_paid)</td>
                            <td class="text-right">
                                @if ($s_due > 0.009)
                                    <b style="color: #c0392b;">@format_currency($s_due)</b>
                                @else
                                    <span class="label label-success">Paid</span>
                                @endif
                            </td>
                            <td><small>{{ optional($s->locked_at)->format($df.' H:i') }}<br>{{ optional($s->lockedBy)->first_name }}</small></td>
                            <td class="no-print" style="white-space: nowrap;">
                                <a href="{{ action([\App\Http\Controllers\CommissionPayoutController::class, 'show'], [$s->id]) }}" class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-primary"><i class="fa fa-eye"></i> View</a>
                                @if ($s_due > 0.009)
                                    @can('expense.add')
                                        <a class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-success tw-text-white btn-modal" data-container=".cmmsn_pay_modal"
                                           data-href="{{ action([\App\Http\Controllers\CommissionPayoutController::class, 'payForm']) }}?agent_id={{ $s->agent_id }}&start={{ $s->period_start->toDateString() }}&end={{ $s->period_end->toDateString() }}">
                                            <i class="fa fa-money-bill-wave"></i> Pay rest</a>
                                    @endcan
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="text-center text-muted">No commission paid yet. Use "Pay commission".</td></tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr style="background: #eee; font-weight: bold;">
                        <td colspan="2">Total</td>
                        <td class="text-right">@format_currency($t_sales)</td>
                        <td class="text-right">&minus;@format_currency($t_returns)</td>
                        <td class="text-right">@format_currency($t_payable)</td>
                        <td class="text-right">@format_currency($t_paid)</td>
                        <td class="text-right">@format_currency($t_due)</td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <p class="text-muted small" style="margin: 0;">
            Commission = sales of the period (as sold) &minus; returns made in the period (any sale date), with the agent's % and brand / product rules.
            A sale returned after its period was paid is taken off the agent's next payout. Payments are expenses (category "Sales commission"):
            deleting one in Expenses makes that amount due again.
        </p>
    @endcomponent
</section>
<div class="modal fade cmmsn_pay_modal" tabindex="-1" role="dialog"></div>
@endsection
