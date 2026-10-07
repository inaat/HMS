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
    </div>

    {{-- Cloud sync: status, Sync now and progress bar; layouts/partials/mobile_autosync also syncs every 2 minutes. --}}
    <div id="cloud-sync" class="no-print" style="background: #fff; border: 1px solid #e3e7ed; border-radius: 12px; padding: 12px 16px; margin-bottom: 12px;">
        <div style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center;">
            <span id="cs-icon" style="font-size: 22px;">☁️</span>
            <div style="flex: 1; min-width: 220px;">
                <b id="cs-title">Cloud sync</b>
                <div id="cs-detail" class="text-muted" style="font-size: 13px;">Loading…</div>
            </div>
            <button type="button" id="cs-now" class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-primary tw-text-white"><i class="fa fa-sync"></i> Sync now</button>
        </div>
        <div id="cs-bar-wrap" class="progress" style="margin: 10px 0 0; height: 18px; display: none;">
            <div id="cs-bar" class="progress-bar progress-bar-striped active" role="progressbar" style="width: 0%; min-width: 2em;">0%</div>
        </div>
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
    $(document).ready(function () {
        __currency_convert_recursively($('.content'));

        var statusUrl = '{{ action([\App\Http\Controllers\MobileOrderController::class, 'syncStatus']) }}';
        var startUrl = '{{ action([\App\Http\Controllers\MobileOrderController::class, 'syncNow']) }}';
        var timer = null, wasRunning = false, shownWaiting = {{ (int) $counts->sum() }};

        function ago(t) {
            if (!t) return 'never';
            var s = Math.max(0, (Date.now() - new Date(t.replace(' ', 'T')).getTime()) / 1000);
            if (s < 60) return 'just now';
            if (s < 3600) return Math.round(s / 60) + ' min ago';
            if (s < 86400) return Math.round(s / 3600) + ' hours ago';
            return Math.round(s / 86400) + ' days ago';
        }

        function show(d) {
            var p = d.progress || {}, run = d.last_run || {};
            var waiting = d.mirror_on && d.changes_waiting ? ' · ' + d.changes_waiting + ' shop changes waiting to go up' : '';
            if (p.running) {
                $('#cs-icon').text('🔄');
                $('#cs-title').text('Syncing… ' + (p.step || ''));
                $('#cs-detail').text((p.detail ? p.detail + ' · ' : '') + 'you can keep working');
                $('#cs-bar-wrap').show();
                $('#cs-bar').css('width', (p.percent || 1) + '%').text((p.percent || 1) + '%');
                $('#cs-now').hide();
            } else {
                $('#cs-bar-wrap').hide();
                $('#cs-now').show().prop('disabled', false);
                if (!run.at) {
                    $('#cs-icon').text('☁️'); $('#cs-title').text('Cloud sync has not run yet');
                    $('#cs-detail').text('Press Sync now, or keep the POS open: it syncs by itself every 2 minutes.');
                } else if (run.ok) {
                    $('#cs-icon').text('✅'); $('#cs-title').text('Up to date');
                    $('#cs-detail').text('Last sync ' + ago(run.at) + waiting + ' · syncs by itself every 2 minutes');
                } else if (run.offline) {
                    $('#cs-icon').text('📴'); $('#cs-title').text('No internet');
                    $('#cs-detail').text('Last try ' + ago(run.at) + waiting + '. Nothing is lost: everything goes up by itself when the internet is back.');
                } else {
                    $('#cs-icon').text('⚠️'); $('#cs-title').text('Last sync had a problem');
                    $('#cs-detail').text(ago(run.at) + ': ' + (run.error || p.message || '') + waiting);
                }
                if (wasRunning && d.waiting_approval != shownWaiting) {
                    // A sync brought new orders or payments: show them.
                    location.reload();
                }
            }
            wasRunning = !!p.running;
            clearTimeout(timer);
            timer = setTimeout(poll, p.running ? 1500 : 20000);
        }

        function poll() { $.getJSON(statusUrl, show); }

        $('#cs-now').on('click', function () {
            $(this).prop('disabled', true);
            $.post(startUrl, {_token: $('meta[name="csrf-token"]').attr('content')}, show, 'json');
        });

        poll();
    });
</script>
@endsection
