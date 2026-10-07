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
        <a href="?kind=order&status={{ $status }}&location_id={{ $location ?: '' }}" class="tw-dw-btn tw-dw-btn-sm {{ $kind == 'order' ? 'tw-dw-btn-primary tw-text-white' : 'tw-dw-btn-outline tw-dw-btn-primary' }}">
            <i class="fa fa-shopping-cart"></i> Orders
            @if (! empty($counts['order'])) <span class="label label-warning">{{ $counts['order'] }}</span> @endif
        </a>
        <a href="?kind=payment&status={{ $status }}&location_id={{ $location ?: '' }}" class="tw-dw-btn tw-dw-btn-sm {{ $kind == 'payment' ? 'tw-dw-btn-primary tw-text-white' : 'tw-dw-btn-outline tw-dw-btn-primary' }}">
            <i class="fa fa-money-bill-wave"></i> Payments
            @if (! empty($counts['payment'])) <span class="label label-warning">{{ $counts['payment'] }}</span> @endif
        </a>
        @can('customer.update')
        <a href="{{ action([\App\Http\Controllers\MobileOrderController::class, 'shopEdits']) }}" class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-outline tw-dw-btn-primary">
            <i class="fa fa-store"></i> Shop edits
            @if (! empty($shop_edits)) <span class="label label-warning">{{ $shop_edits }}</span> @endif
        </a>
        @endcan
        <form method="GET" style="display: flex; gap: 8px; align-items: center; margin-left: 12px;">
            <input type="hidden" name="kind" value="{{ $kind }}">
            @if ($locations->count() > 1)
                <select name="location_id" class="form-control input-sm" onchange="this.form.submit()">
                    <option value="">All my locations</option>
                    @foreach ($locations as $id => $name)
                        <option value="{{ $id }}" @if ($location == $id) selected @endif>{{ $name }}</option>
                    @endforeach
                </select>
            @endif
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
        {{-- Bulk: tick rows, then one button invoices every ticked order / approves every ticked payment. --}}
        <form method="POST" id="bulk-form" action="{{ action([\App\Http\Controllers\MobileOrderController::class, 'bulk']) }}" class="no-print" style="margin-bottom: 10px; display: none;">
            @csrf
            <input type="hidden" name="kind" value="{{ $kind }}">
            <button type="submit" class="tw-dw-btn tw-dw-btn-success tw-text-white" id="bulk-btn">
                <i class="fa {{ $kind == 'order' ? 'fa-file-invoice' : 'fa-check' }}"></i>
                {{ $kind == 'order' ? 'Make invoices for selected' : 'Approve selected payments' }} (<span id="bulk-count">0</span>)
            </button>
        </form>
        <div class="table-responsive">
            <table class="table table-bordered table-hover table-condensed">
                <thead>
                    <tr style="background: #f5f5f5;">
                        <th class="no-print" style="width: 34px;"><input type="checkbox" id="bulk-all" title="Select all"></th>
                        <th>{{ $kind == 'order' ? 'Slip no' : 'Receipt no' }}</th>
                        <th>Date</th>
                        <th>Booker</th>
                        @if ($locations->count() > 1)<th>Location</th>@endif
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
                            <td class="no-print">
                                @if (($r->kind == 'order' && in_array($r->status, ['waiting', 'approved'])) || ($r->kind == 'payment' && $r->status == 'waiting'))
                                    <input type="checkbox" class="bulk-row" value="{{ $r->id }}">
                                @endif
                            </td>
                            <td>
                                <a href="{{ action([\App\Http\Controllers\MobileOrderController::class, 'show'], [$r->id]) }}">{{ $r->number }}</a>
                                @if ($r->short_stock) <span class="label label-danger" title="Booked more than the free stock">Short stock</span> @endif
                            </td>
                            <td>{{ @format_datetime($r->booked_at) }}</td>
                            <td>{{ $r->booker ?: '#'.$r->booker_id }}</td>
                            @if ($locations->count() > 1)<td>{{ $r->location_name }}</td>@endif
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
                                {{-- One click: no extra screens or confirmations. --}}
                                @if ($r->kind == 'order' && in_array($r->status, ['waiting', 'approved']))
                                    <form method="POST" action="{{ action([\App\Http\Controllers\MobileOrderController::class, 'invoice'], [$r->id]) }}" style="display:inline" class="one-click">
                                        @csrf
                                        <button type="submit" class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-success tw-text-white"><i class="fa fa-file-invoice"></i> Make invoice</button>
                                    </form>
                                @elseif ($r->kind == 'payment' && $r->status == 'waiting')
                                    <form method="POST" action="{{ action([\App\Http\Controllers\MobileOrderController::class, 'approve'], [$r->id]) }}" style="display:inline" class="one-click">
                                        @csrf
                                        <button type="submit" class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-success tw-text-white"><i class="fa fa-check"></i> Approve payment</button>
                                    </form>
                                @endif
                                @if ($r->status == 'waiting')
                                    <form method="POST" action="{{ action([\App\Http\Controllers\MobileOrderController::class, 'reject'], [$r->id]) }}" style="display:inline" class="reject-form">
                                        @csrf
                                        <input type="hidden" name="reason" value="">
                                        <button type="submit" class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-outline tw-dw-btn-error"><i class="fa fa-times"></i> Reject</button>
                                    </form>
                                @endif
                                <a href="{{ action([\App\Http\Controllers\MobileOrderController::class, 'show'], [$r->id]) }}" class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-ghost" title="Details">
                                    <i class="fa fa-eye"></i></a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="text-center text-muted">Nothing here.</td></tr>
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
            var waiting = d.mirror_on && d.changes_waiting ? ' · ' + d.changes_waiting + ' new shop changes go up with the next sync' : '';
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
                if (wasRunning && d.waiting_approval != shownWaiting && !(window.__bulkBusy && window.__bulkBusy())) {
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

        // Bulk: show the button with the count of ticked rows; send the ticked ids.
        function bulkRefresh() {
            var n = $('.bulk-row:checked').length;
            $('#bulk-count').text(n);
            $('#bulk-form').toggle(n > 0);
            $('#bulk-all').prop('checked', n > 0 && n === $('.bulk-row').length);
        }
        $(document).on('change', '#bulk-all', function () { $('.bulk-row').prop('checked', this.checked); bulkRefresh(); });
        $(document).on('change', '.bulk-row', bulkRefresh);
        $('#bulk-form').on('submit', function () {
            var form = $(this);
            form.find('input[name="ids[]"]').remove();
            $('.bulk-row:checked').each(function () { form.append('<input type="hidden" name="ids[]" value="' + this.value + '">'); });
            $('#bulk-btn').prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Working on ' + $('.bulk-row:checked').length + '…');
        });
        // A sync must not reload the page while rows are ticked.
        window.__bulkBusy = function () { return $('.bulk-row:checked').length > 0; };

        // One click, but never twice: the button locks while the invoice is made.
        $(document).on('submit', 'form.one-click', function () {
            $(this).find('button').prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Working…');
        });
        // Reject only asks for the reason the booker will see.
        $(document).on('submit', 'form.reject-form', function (e) {
            var reason = prompt('Reason for the booker (e.g. out of stock):', 'Out of stock');
            if (reason === null || !reason.trim()) { e.preventDefault(); return; }
            $(this).find('input[name=reason]').val(reason.trim());
        });
    });
</script>
@endsection
