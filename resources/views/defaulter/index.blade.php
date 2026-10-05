@extends('layouts.app')
@section('title', 'Top Defaulters')

@section('content')
<style>
    .df-page .df-counters { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); margin-bottom: 16px; }
    .df-page .df-counter { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 14px 16px; display: flex; align-items: center; gap: 12px; }
    .df-page .df-counter .ic { width: 42px; height: 42px; border-radius: 12px; display: grid; place-items: center; font-size: 18px; flex: none; }
    .df-page .df-counter small { display: block; font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: #6b7280; }
    .df-page .df-counter b { font-size: 20px; color: #111827; font-variant-numeric: tabular-nums; }
    .df-page .ic-red { background: #fee2e2; color: #dc2626; }
    .df-page .ic-amber { background: #fef3c7; color: #d97706; }
    .df-page .ic-blue { background: #dbeafe; color: #2563eb; }
    .df-page .ic-green { background: #dcfce7; color: #16a34a; }
    .df-page .ic-gray { background: #f3f4f6; color: #4b5563; }
    .df-page .df-rank { display: inline-grid; place-items: center; width: 26px; height: 26px; border-radius: 50%; background: #f3f4f6; font-weight: 700; font-size: 12px; }
    .df-page .df-rank.top { background: #fee2e2; color: #dc2626; }
    .df-page .df-due { font-weight: 700; color: #dc2626; white-space: nowrap; }
    .df-page .df-table td { vertical-align: middle !important; }
    .df-page .df-progress { margin-top: 12px; padding: 12px 14px; border: 1px solid #e5e7eb; border-radius: 12px; background: #f9fafb; }
    .df-page .df-progress .progress { height: 10px; border-radius: 999px; margin: 8px 0 0; }
    .df-page .df-progress .progress-bar { border-radius: 999px; transition: width .3s ease; }
    .df-page .df-help code { cursor: pointer; }
</style>

<section class="content-header">
    <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">Top Defaulters</h1>
</section>

<section class="content df-page">

    {{-- counters --}}
    <div class="df-counters">
        <div class="df-counter">
            <div class="ic ic-red"><i class="fa fa-users"></i></div>
            <div><small>Defaulters</small><b>{{ number_format($counters['count']) }}</b></div>
        </div>
        <div class="df-counter">
            <div class="ic ic-amber"><i class="fa fa-money-bill-alt"></i></div>
            <div><small>Total outstanding</small><b>@format_currency($counters['total_due'])</b></div>
        </div>
        <div class="df-counter">
            <div class="ic ic-blue"><i class="fa fa-list-ol"></i></div>
            <div><small>{{ $limit > 0 ? 'Top ' . $limit . ' owe' : 'Shown owe' }}</small><b>@format_currency($counters['top_due'])</b></div>
        </div>
        <div class="df-counter">
            <div class="ic ic-green"><i class="fab fa-whatsapp"></i></div>
            <div><small>Reachable on WhatsApp</small><b>{{ number_format($counters['reachable']) }}</b></div>
        </div>
        <div class="df-counter">
            <div class="ic ic-gray"><i class="fa fa-hourglass-half"></i></div>
            <div><small>No purchase 90+ days</small><b>{{ number_format($counters['over_90']) }}</b></div>
        </div>
    </div>

    {{-- filters --}}
    @component('components.filters', ['title' => __('report.filters')])
        <form method="GET" action="{{ action([\App\Http\Controllers\DefaulterController::class, 'index']) }}">
            <div class="col-md-12">
                <div class="form-group">
                    <label for="search">Search customer</label>
                    <input type="text" name="search" id="search" class="form-control" value="{{ $search }}" placeholder="Name, business name, mobile or contact ID">
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group">
                    <label for="limit">Show</label>
                    <select name="limit" id="limit" class="form-control">
                        @foreach ([10 => 'Top 10', 25 => 'Top 25', 50 => 'Top 50', 100 => 'Top 100', 0 => 'All defaulters'] as $value => $label)
                            <option value="{{ $value }}" @if($limit == $value) selected @endif>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group">
                    <label for="min_due">Minimum due</label>
                    <input type="number" step="any" min="0" name="min_due" id="min_due" class="form-control" value="{{ $min_due ?: '' }}" placeholder="0">
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group">
                    <label for="inactive_days">No purchase in</label>
                    <select name="inactive_days" id="inactive_days" class="form-control">
                        @foreach ([0 => 'Any time', 30 => '30+ days', 60 => '60+ days', 90 => '90+ days', 180 => '180+ days', 365 => '1+ year'] as $value => $label)
                            <option value="{{ $value }}" @if($inactive_days == $value) selected @endif>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="col-md-3">
                <label>&nbsp;</label><br>
                <button type="submit" class="tw-dw-btn tw-dw-btn-primary tw-text-white"><i class="fa fa-filter"></i> @lang('report.apply_filters')</button>
            </div>
        </form>
    @endcomponent

    {{-- WhatsApp message --}}
    @component('components.widget', ['class' => 'box-primary', 'title' => 'WhatsApp reminder message'])
        <textarea id="df_message" class="form-control" rows="6">{{ $default_message }}</textarea>
        <p class="help-block df-help">
            Placeholders (click to insert):
            <code data-ph="{name}">{name}</code>
            <code data-ph="{due}">{due}</code>
            <code data-ph="{last_sale}">{last_sale}</code>
            <code data-ph="{business}">{business}</code>
            &middot; <a href="#" id="df_reset_message">Reset to default</a>
        </p>
    @endcomponent

    {{-- list --}}
    @component('components.widget', ['class' => 'box-primary', 'title' => ($limit > 0 ? 'Top ' . $limit : 'All') . ' defaulters'])
        @slot('tool')
            <div class="box-tools">
                <button type="button" id="df_send_selected" class="tw-dw-btn tw-dw-btn-success tw-text-white" disabled>
                    <i class="fab fa-whatsapp"></i> Send to selected (<span id="df_selected_count">0</span>)
                </button>
            </div>
        @endslot

        <div id="df_progress" class="df-progress" style="display: none;">
            <div class="clearfix">
                <span class="pull-left"><i id="df_progress_icon" class="fa fa-spinner fa-spin text-primary"></i> <b id="df_progress_text"></b></span>
                <b id="df_progress_pct" class="pull-right">0%</b>
            </div>
            <div class="progress">
                <div class="progress-bar progress-bar-success progress-bar-striped active" style="width: 0%;"></div>
            </div>
        </div>
        <br>

        @if (count($defaulters))
            <div class="table-responsive">
                <table class="table table-striped table-bordered df-table">
                    <thead>
                        <tr>
                            <th style="width: 30px;"><input type="checkbox" id="df_check_all" title="Select all with a valid number"></th>
                            <th style="width: 40px;">#</th>
                            <th>@lang('contact.customer')</th>
                            <th>@lang('contact.mobile')</th>
                            <th>@lang('lang_v1.due')</th>
                            <th>Last purchase</th>
                            <th>Status</th>
                            <th>@lang('messages.actions')</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($defaulters as $i => $c)
                            @php
                                $days = $c->max_transaction_date ? \Carbon::parse($c->max_transaction_date)->diffInDays(\Carbon::today()) : null;
                            @endphp
                            <tr data-id="{{ $c->id }}">
                                <td>
                                    @if ($c->whatsapp)
                                        <input type="checkbox" class="df-check" value="{{ $c->id }}">
                                    @endif
                                </td>
                                <td><span class="df-rank {{ $i < 3 ? 'top' : '' }}">{{ $i + 1 }}</span></td>
                                <td>
                                    <a href="{{ action([\App\Http\Controllers\ContactController::class, 'show'], [$c->id]) }}" target="_blank">{{ $c->name }}</a>
                                    @if ($c->supplier_business_name)<br><small class="text-muted">{{ $c->supplier_business_name }}</small>@endif
                                </td>
                                <td>
                                    {{ $c->mobile }}
                                    @if (! $c->whatsapp)<br><small class="text-danger">No valid number</small>@endif
                                </td>
                                <td class="df-due">@format_currency($c->due)</td>
                                <td>
                                    @if ($c->max_transaction_date)
                                        {{ @format_date($c->max_transaction_date) }}<br>
                                        <small class="{{ $days > 90 ? 'text-danger' : 'text-muted' }}">{{ $days }} days ago</small>
                                    @else
                                        <small class="text-muted">Never</small>
                                    @endif
                                </td>
                                <td class="df-status"><span class="text-muted">-</span></td>
                                <td class="text-nowrap">
                                    @if ($c->whatsapp)
                                        <button type="button" class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-success df-send-one" data-id="{{ $c->id }}">
                                            <i class="fab fa-whatsapp"></i> Send</button>
                                    @endif
                                    <a class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-accent"
                                       href="{{ action([\App\Http\Controllers\ContactController::class, 'show'], [$c->id]) }}" target="_blank">
                                        <i class="fas fa-eye"></i> @lang('messages.view')</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="text-center text-muted" style="padding: 24px 0;">
                <i class="fa fa-check-circle fa-2x text-success"></i><br>No customer has an outstanding balance for these filters.
            </div>
        @endif
    @endcomponent
</section>
@endsection

@section('javascript')
<script type="text/javascript">
    $(document).ready(function () {
        var sendUrl = "{{ action([\App\Http\Controllers\DefaulterController::class, 'send']) }}";
        var defaultMessage = @json($default_message);
        var storageKey = 'defaulter_whatsapp_message';
        var $msg = $('#df_message');
        var sending = false;

        // remember the edited message in this browser
        try {
            var saved = localStorage.getItem(storageKey);
            if (saved) { $msg.val(saved); }
        } catch (e) {}
        $msg.on('input', function () {
            try { localStorage.setItem(storageKey, $msg.val()); } catch (e) {}
        });
        $('#df_reset_message').click(function (e) {
            e.preventDefault();
            $msg.val(defaultMessage).trigger('input');
        });
        $('.df-help code').click(function () {
            var el = $msg[0], ph = $(this).data('ph');
            var start = el.selectionStart, end = el.selectionEnd, val = $msg.val();
            $msg.val(val.substring(0, start) + ph + val.substring(end)).trigger('input');
            el.focus();
            el.selectionStart = el.selectionEnd = start + ph.length;
        });

        function refreshSelected() {
            var n = $('.df-check:checked').length;
            $('#df_selected_count').text(n);
            $('#df_send_selected').prop('disabled', n === 0 || sending);
            $('#df_check_all').prop('checked', n > 0 && n === $('.df-check').length);
        }
        $('#df_check_all').change(function () {
            $('.df-check').prop('checked', $(this).is(':checked'));
            refreshSelected();
        });
        $(document).on('change', '.df-check', refreshSelected);

        function setStatus(id, html) {
            $('tr[data-id="' + id + '"] .df-status').html(html);
        }

        function sendOne(id) {
            setStatus(id, '<i class="fa fa-spinner fa-spin text-primary"></i> Sending...');

            return $.ajax({
                method: 'POST',
                url: sendUrl,
                dataType: 'json',
                data: { _token: '{{ csrf_token() }}', contact_id: id, message: $msg.val() }
            }).then(function (res) {
                setStatus(id, res.ok
                    ? '<span class="label label-success"><i class="fa fa-check"></i> Sent</span>'
                    : '<span class="label label-danger" title="' + $('<div>').text(res.message).html() + '">Failed</span><br><small class="text-danger">' + $('<div>').text(res.message).html() + '</small>');
                return res.ok;
            }, function () {
                setStatus(id, '<span class="label label-danger">Failed</span><br><small class="text-danger">Server error</small>');
                return $.Deferred().resolve(false);
            });
        }

        function showProgress(done, total, sent, failed, finished) {
            var pct = total ? Math.round(done * 100 / total) : 0;
            var $box = $('#df_progress').show();
            $box.find('.progress-bar').css('width', pct + '%')
                .toggleClass('progress-bar-striped active', ! finished)
                .toggleClass('progress-bar-danger', finished && sent === 0 && failed > 0)
                .toggleClass('progress-bar-success', ! (finished && sent === 0 && failed > 0));
            $('#df_progress_pct').text(pct + '%');
            $('#df_progress_text').text(finished
                ? 'Done: ' + sent + ' sent' + (failed ? ', ' + failed + ' failed' : '') + '.'
                : 'Sending ' + (done + 1) + ' of ' + total + '... (' + sent + ' sent' + (failed ? ', ' + failed + ' failed' : '') + ')');
            $('#df_progress_icon').attr('class', 'fa ' + (finished ? (failed && ! sent ? 'fa-exclamation-circle text-danger' : 'fa-check-circle text-success') : 'fa-spinner fa-spin text-primary'));
        }

        function lock(on) {
            sending = on;
            $('.df-send-one, #df_check_all, .df-check, #df_message').prop('disabled', on);
            refreshSelected();
        }

        $(document).on('click', '.df-send-one', function () {
            if (sending || ! $.trim($msg.val())) { return; }
            var btn = $(this);
            btn.prop('disabled', true);
            sendOne(btn.data('id')).always(function () { btn.prop('disabled', false); });
        });

        // one request per customer, in order, so the gateway isn't flooded and
        // the bar shows real progress
        $('#df_send_selected').click(function () {
            var ids = $('.df-check:checked').map(function () { return $(this).val(); }).get();
            if (! ids.length || sending) { return; }
            if (! $.trim($msg.val())) { toastr.error('Write the message first.'); return; }

            lock(true);
            var done = 0, sent = 0, failed = 0;
            showProgress(0, ids.length, 0, 0, false);

            (function next() {
                if (done >= ids.length) {
                    showProgress(done, ids.length, sent, failed, true);
                    lock(false);
                    return;
                }
                sendOne(ids[done]).then(function (ok) {
                    ok ? sent++ : failed++;
                    done++;
                    showProgress(done, ids.length, sent, failed, done >= ids.length);
                    // small gap between messages, gentler on the WhatsApp account
                    setTimeout(next, 1500);
                });
            })();
        });
    });
</script>
@endsection
