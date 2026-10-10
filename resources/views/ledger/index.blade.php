@extends('layouts.app')
@section('title', 'Accounting')

@section('content')
@include('ledger.partials.styles')
<section class="content-header no-print">
    <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">Accounting <small>double-entry books from your POS records</small></h1>
</section>
<section class="content">
    @include('ledger.partials.nav', ['active' => 'index'])

    @component('components.widget')
        <div style="display:flex; flex-wrap:wrap; gap:12px; align-items:center;">
            <div>
                <h4 style="margin:0 0 4px;">Update ledger</h4>
                <div class="text-muted" id="ledger_last">
                    @if ($last)
                        Last updated {{ \Carbon::parse($last['at'])->diffForHumans() }} ({{ \Carbon::parse($last['at'])->format((session('business.date_format') ?: 'd-m-Y').' H:i') }})
                        by {{ $last['by'] ?? '' }} · {{ $last['seconds'] ?? 0 }}s · {{ number_format($last['written'] ?? 0) }} journal(s) written
                    @else
                        Never updated: press the button to write the journals of all your records.
                    @endif
                </div>
                <div class="text-muted small">Reads sales, returns, purchases, payments, expenses, opening stock / balances and account transfers,
                    and writes only what changed since the last update. Your POS records are not changed.</div>
            </div>
            <div style="margin-left:auto;">
                <button type="button" id="ledger_update" class="tw-dw-btn tw-dw-btn-primary tw-text-white"><i class="fa fa-sync"></i> Update ledger</button>
            </div>
        </div>
        <div id="ledger_progress" style="display:none; margin-top:14px;">
            <div style="display:flex; justify-content:space-between;"><b id="ledger_step">Starting…</b><span id="ledger_count" class="text-muted"></span></div>
            <div class="progress" style="height:22px; margin:6px 0 0;">
                <div class="progress-bar progress-bar-success progress-bar-striped active" id="ledger_bar" role="progressbar" style="width:0%; min-width:2em; line-height:22px;">0%</div>
            </div>
            <div class="text-muted small" style="margin-top:4px;">Keep this page open until it finishes.</div>
        </div>
    @endcomponent

    @component('components.widget')
        <h4 style="margin-top:0;">Does the ledger match the POS?</h4>
        <div id="ledger_checks"><i class="fa fa-spinner fa-spin"></i> Checking…</div>
    @endcomponent

    @component('components.widget')
        <h4 style="margin-top:0;">Cash & bank setup <small>payments without an account</small></h4>
        <div id="cash_setup"><i class="fa fa-spinner fa-spin"></i> Loading…</div>
    @endcomponent
</section>
@endsection

@section('javascript')
<script>
    $(function () {
        var urls = {
            start: '{{ action([\App\Http\Controllers\LedgerController::class, 'syncStart']) }}',
            step: '{{ action([\App\Http\Controllers\LedgerController::class, 'syncStep']) }}',
            finish: '{{ action([\App\Http\Controllers\LedgerController::class, 'syncFinish']) }}',
            checks: '{{ action([\App\Http\Controllers\LedgerController::class, 'checks']) }}'
        };
        function loadChecks() {
            $('#ledger_checks').load(urls.checks);
            $('#cash_setup').load('{{ action([\App\Http\Controllers\LedgerController::class, 'cashSetup']) }}');
        }
        loadChecks();

        function post(url, data) {
            return $.ajax({ url: url, method: 'POST', data: data, dataType: 'json', timeout: 300000 });
        }

        $('#ledger_update').on('click', function () {
            var btn = $(this).prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Updating…');
            var t0 = Date.now(), done = 0, written = 0, removed = 0, issues = [];
            $('#ledger_progress').show();
            post(urls.start, {}).done(function (plan) {
                var steps = plan.steps, total = Math.max(1, plan.total), i = 0;
                function setBar() {
                    var pct = Math.min(100, Math.round(done * 100 / total));
                    $('#ledger_bar').css('width', pct + '%').text(pct + '%');
                }
                function next(after) {
                    if (i >= steps.length) {
                        return finish();
                    }
                    var s = steps[i];
                    $('#ledger_step').text(s.label + '…');
                    post(urls.step, { step: s.key, after: after }).done(function (r) {
                        done += r.done; written += r.written; removed += r.removed;
                        issues = issues.concat(r.issues || []);
                        s.read = (s.read || 0) + r.done;
                        $('#ledger_count').text(s.label + ' ' + s.read.toLocaleString() + ' / ' + s.total.toLocaleString());
                        setBar();
                        if (r.finished) { i++; next(0); } else { next(r.last_id); }
                    }).fail(fail);
                }
                function finish() {
                    $('#ledger_bar').css('width', '100%').text('100%').removeClass('active');
                    $('#ledger_step').text('Done');
                    var seconds = Math.round((Date.now() - t0) / 1000);
                    post(urls.finish, { seconds: seconds, written: written, removed: removed, issues: issues }).always(function () {
                        $('#ledger_last').text('Updated just now · ' + seconds + 's · ' + written.toLocaleString() + ' journal(s) written, ' + removed + ' removed');
                        btn.prop('disabled', false).html('<i class="fa fa-sync"></i> Update ledger');
                        toastr.success('Ledger updated');
                        loadChecks();
                    });
                }
                next(0);
            }).fail(fail);

            function fail(x) {
                btn.prop('disabled', false).html('<i class="fa fa-sync"></i> Update ledger');
                $('#ledger_step').text('Stopped — press Update ledger again to continue');
                toastr.error((x && x.responseJSON && x.responseJSON.message) || 'Update stopped (connection or server error)');
            }
        });
    });
</script>
@endsection
