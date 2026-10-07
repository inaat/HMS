@extends('layouts.app')
@section('title', 'Mobile orders')

@section('content')
@php
    $badge = ['waiting' => 'label-warning', 'approved' => 'label-info', 'invoiced' => 'label-success', 'rejected' => 'label-danger'];
@endphp
<section class="content-header no-print">
    <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">Mobile orders
        <small>orders and collections from order bookers; approve to bring them into the POS</small>
    </h1>
</section>

<section class="content">
    <div class="no-print" style="margin-bottom: 12px; display: flex; flex-wrap: wrap; gap: 8px; align-items: center;">
        <a href="?kind=order&status={{ $status }}" class="tw-dw-btn tw-dw-btn-sm {{ $kind == 'order' ? 'tw-dw-btn-primary tw-text-white' : 'tw-dw-btn-outline tw-dw-btn-primary' }}">
            <i class="fa fa-shopping-cart"></i> Orders
            @if (! empty($counts['order'])) <span class="label label-warning">{{ $counts['order'] }}</span> @endif
        </a>
        <a href="?kind=payment&status={{ $status }}" class="tw-dw-btn tw-dw-btn-sm {{ $kind == 'payment' ? 'tw-dw-btn-primary tw-text-white' : 'tw-dw-btn-outline tw-dw-btn-primary' }}">
            <i class="fa fa-money-bill-wave"></i> Payments
            @if (! empty($counts['payment'])) <span class="label label-warning">{{ $counts['payment'] }}</span> @endif
        </a>
        <form method="GET" style="display: flex; gap: 8px; align-items: center; margin-left: 12px;">
            <input type="hidden" name="kind" value="{{ $kind }}">
            <select name="status" class="form-control input-sm" onchange="this.form.submit()">
                @foreach (['waiting' => 'Waiting approval', 'approved' => 'Approved', 'invoiced' => 'Invoiced', 'rejected' => 'Rejected', 'all' => 'All'] as $k => $label)
                    @if ($kind == 'payment' && $k == 'invoiced') @continue @endif
                    <option value="{{ $k }}" @if ($status == $k) selected @endif>{{ $label }}</option>
                @endforeach
            </select>
        </form>
        <span style="margin-left: auto;" class="text-muted">
            Last sync:
            @if (empty($last_run))
                <span class="label label-default">never</span>
            @else
                {{ @format_datetime($last_run['at']) }}
                <span class="label {{ $last_run['ok'] ? 'label-success' : 'label-danger' }}">{{ $last_run['ok'] ? 'OK' : 'failed' }}</span>
            @endif
        </span>
    </div>

    @component('components.widget')
        <div class="table-responsive">
            <table class="table table-bordered table-hover table-condensed">
                <thead>
                    <tr style="background: #f5f5f5;">
                        <th>{{ $kind == 'order' ? 'Slip no' : 'Receipt no' }}</th>
                        <th>Date</th>
                        <th>Booker</th>
                        <th>Customer</th>
                        <th class="text-right">{{ $kind == 'order' ? 'Total' : 'Amount' }}</th>
                        <th>Status</th>
                        <th>{{ $kind == 'order' ? 'Sales order / invoice' : 'Payment ref' }}</th>
                        <th class="no-print"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $r)
                        <tr>
                            <td>
                                <a href="{{ action([\App\Http\Controllers\MobileOrderController::class, 'show'], [$r->id]) }}">{{ $r->number }}</a>
                                @if ($r->short_stock) <span class="label label-danger" title="Booked more than the free stock">Short stock</span> @endif
                            </td>
                            <td>{{ @format_datetime($r->booked_at) }}</td>
                            <td>{{ $r->booker ?: '#'.$r->booker_id }}</td>
                            <td>
                                {{ $r->customer ?? '—' }}
                                @if ($r->supplier_business_name) <br><small class="text-muted">{{ $r->supplier_business_name }}</small> @endif
                                @if ($r->customer_uuid) <span class="label label-default" title="Added by the booker">new</span> @endif
                            </td>
                            <td class="text-right"><span class="display_currency" data-currency_symbol="true">{{ $r->total }}</span></td>
                            <td>
                                <span class="label {{ $badge[$r->status] ?? 'label-default' }}">{{ $r->status == 'waiting' ? 'waiting approval' : $r->status }}</span>
                                @if ($r->status == 'rejected') <br><small>{{ $r->reject_reason }}</small> @endif
                                @if ($r->decided_by_name) <br><small class="text-muted">by {{ $r->decided_by_name }}</small> @endif
                            </td>
                            <td>
                                {{ $r->ref }}
                                @if ($r->invoice_no) <br><small>Invoice {{ $r->invoice_no }}</small> @endif
                            </td>
                            <td class="no-print" style="white-space: nowrap;">
                                <a href="{{ action([\App\Http\Controllers\MobileOrderController::class, 'show'], [$r->id]) }}" class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-primary">
                                    <i class="fa fa-eye"></i> {{ $r->status == 'waiting' ? 'Check & approve' : 'View' }}</a>
                                @if ($r->kind == 'order' && $r->status == 'approved')
                                    <a href="{{ action([\App\Http\Controllers\SellController::class, 'create']) }}?mobile_so={{ $r->transaction_id }}" class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-success tw-text-white">
                                        <i class="fa fa-file-invoice"></i> Make invoice</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-muted">Nothing here.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $rows->links() }}
    @endcomponent
</section>
@endsection

@section('javascript')
<script>
    $(document).ready(function () { __currency_convert_recursively($('.content')); });
</script>
@endsection
