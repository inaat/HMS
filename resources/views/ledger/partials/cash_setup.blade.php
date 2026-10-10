{{-- Accounts > Update ledger: Cash & bank setup (loaded by ajax). Payments with no account: stop new ones, settle the old
     money with a cash count, link the ones after the count date. --}}
@php
    $L = \App\Http\Controllers\LedgerController::class;
    $fmt = fn ($d) => \Carbon::parse($d)->format(session('business.date_format') ?: 'd-m-Y');
    $m = fn ($v) => number_format((float) $v, 2);
    $no_account = $locations->contains(function ($l) {
        $d = json_decode((string) $l->default_payment_accounts, true) ?: [];

        return empty($d['cash']['account']);
    });
@endphp
@if ($accounts->isEmpty())
    <p class="text-muted">Add your cash and bank accounts first in <a href="{{ action([\App\Http\Controllers\AccountController::class, 'index']) }}">Payment accounts</a>
        (e.g. "Shop cash" with type Cash accounts, "HBL" with type Bank accounts).</p>
@else
    {{-- Step 1 --}}
    <h5><b>1. Where new payments go</b>
        @if ($no_account) <span class="ledger-bad">— not set: every cash sale is saved without an account</span>
        @else <span class="ledger-ok"><i class="fa fa-check"></i> set</span> @endif
    </h5>
    <form method="POST" action="{{ action([$L, 'saveDefaultAccounts']) }}">
        @csrf
        <table class="ledger-table" style="width:auto; min-width:60%;">
            <thead><tr><th>Location</th><th>Payment method</th><th style="min-width:240px;">Goes into account</th></tr></thead>
            <tbody>
                @foreach ($locations as $l)
                    @php $d = json_decode((string) $l->default_payment_accounts, true) ?: []; @endphp
                    @foreach ($methods[$l->id] as $key => $label)
                        @if (in_array($key, ['cash', 'card', 'cheque', 'bank_transfer']) || ! empty($d[$key]['account']))
                            <tr>
                                <td>{{ $loop->first ? $l->name : '' }}</td>
                                <td>{{ $label }}</td>
                                <td>{!! Form::select('defaults['.$l->id.']['.$key.']', $accounts, $d[$key]['account'] ?? null,
                                    ['class' => 'form-control input-sm cs-select2', 'style' => 'width:100%', 'placeholder' => 'No account']) !!}</td>
                            </tr>
                        @endif
                    @endforeach
                @endforeach
            </tbody>
        </table>
        <button type="submit" class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-primary tw-text-white" style="margin-top:8px;">Save default accounts</button>
        <span class="text-muted small">Cash → your shop cash account; Card / Cheque / Bank transfer → your bank account.</span>
    </form>

    {{-- Step 2 --}}
    <h5 style="margin-top:20px;"><b>2. Cash count: settle the old money that has no account</b></h5>
    @if (empty($last))
        <p class="text-muted">Press <b>Update ledger</b> first.</p>
    @else
        <p class="text-muted small" style="margin-bottom:6px;">
            Count the real cash in each box and check each bank balance on the date below, and enter the amounts.
            Each account is set to what you counted, "Cash in hand (no account)" is cleared, and the difference
            (money taken out or spent without being entered) is booked once to the account you choose.
        </p>
        <form method="POST" action="{{ action([$L, 'saveCashCount']) }}" id="cash_count_form">
            @csrf
            <input type="hidden" name="count_date" id="cc_date_value" value="{{ $date }}">
            <div style="display:flex; gap:12px; align-items:center; margin-bottom:8px;">
                <label style="margin:0;">Count date:</label>
                <div class="input-group" style="width:180px;">
                    <span class="input-group-addon"><i class="fa fa-calendar"></i></span>
                    <input type="text" id="cc_date" class="form-control input-sm" readonly value="{{ $fmt($date) }}">
                </div>
                <span>Cash with no account on this date in the books: <b>{{ $m($unassigned) }}</b></span>
            </div>
            <table class="ledger-table" style="width:auto; min-width:60%;">
                <thead><tr><th>Account</th><th class="right">In the books on {{ $fmt($date) }}</th><th class="right" style="width:180px;">Real amount counted</th></tr></thead>
                <tbody>
                    @foreach ($accounts as $id => $name)
                        <tr>
                            <td>{{ $name }}</td>
                            <td class="right">{{ $m($account_balances[$id] ?? 0) }}</td>
                            <td><input type="text" name="counted[{{ $id }}]" class="form-control input-sm input_number text-right cc-counted" placeholder="leave empty = no change"></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div style="display:flex; gap:10px; align-items:center; margin-top:8px; flex-wrap:wrap;">
                <label style="margin:0;">Difference goes to:</label>
                <div style="width:280px;">{!! Form::select('difference_account', $diff_accounts, $drawings, ['class' => 'form-control input-sm cs-select2', 'style' => 'width:100%']) !!}</div>
                <button type="submit" class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-primary tw-text-white">Save cash count</button>
            </div>
        </form>
    @endif

    {{-- Step 3 --}}
    <h5 style="margin-top:20px;"><b>3. Link payments that have no account</b>
        <small>({{ number_format($unlinked->n) }} payment(s) without account{{ $unlinked->n ? ', '.$fmt($unlinked->first).' to '.$fmt($unlinked->last) : '' }})</small>
    </h5>
    <p class="text-muted small" style="margin-bottom:6px;">
        Old payments: choose <b>All</b> and the account the cash went to (e.g. your shop cash), then do the <b>cash count</b> (step 2)
        of that account, so it shows the real cash and the extra goes once to Drawings.
    </p>
    <form method="POST" action="{{ action([$L, 'linkPayments']) }}" id="link_form" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
        @csrf
        <input type="hidden" name="from_date" value="{{ $link_from }}">
        <input type="hidden" name="method" value="cash">
        <span>Cash payments:</span>
        <div style="width:330px;">{!! Form::select('range', array_filter([
            'all' => 'All without account, any date ('.number_format($unlinked->n).')',
            'after' => $last_count || $unlinked_after ? 'After '.$fmt($link_from).($last_count ? ' (last cash count)' : '').' ('.number_format($unlinked_after).')' : null,
        ]), 'all', ['class' => 'form-control input-sm cs-select2', 'style' => 'width:100%']) !!}</div>
        <span>go into</span>
        <div style="width:240px;">{!! Form::select('account_id', $accounts, null, ['class' => 'form-control input-sm cs-select2', 'style' => 'width:100%', 'placeholder' => 'Choose account', 'required']) !!}</div>
        <button type="submit" class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-outline tw-dw-btn-primary">Link payments</button>
    </form>
@endif

<script>
    $(function () {
        var box = $('#cash_setup');
        box.find('.cs-select2').select2();
        $('#cc_date').datepicker({ autoclose: true, format: datepicker_date_format }).on('changeDate', function (e) {
            $('#cash_setup').load('{{ action([$L, 'cashSetup']) }}?count_date=' + moment(e.date).format('YYYY-MM-DD'));
        });
        $('#cash_count_form').on('submit', function (e) {
            var f = this;
            if ($(f).data('ok')) { return true; }
            e.preventDefault();
            if (!$(f).find('.cc-counted').filter(function () { return $(this).val() !== ''; }).length) {
                toastr.error('Enter at least one counted amount');
                return;
            }
            swal({ title: 'Save cash count?', text: 'Accounts are set to the counted amounts on {{ $fmt($date) }}. You can undo it by deleting the journal.',
                icon: 'warning', buttons: ['Cancel', 'Save'] }).then(function (ok) { if (ok) { $(f).data('ok', true); f.submit(); } });
        });
        $('#link_form').on('submit', function (e) {
            var f = this;
            if ($(f).data('ok')) { return true; }
            e.preventDefault();
            if (!$(f).find('[name=account_id]').val()) { toastr.error('Choose the account'); return; }
            $.post($(f).attr('action'), $(f).serialize() + '&preview=1', function (r) {
                if (!r.count) { toastr.info('No payments to link for this choice'); return; }
                swal({ title: 'Link ' + r.count.toLocaleString() + ' payment(s)?',
                    text: 'They go into ' + $(f).find('[name=account_id] option:selected').text() + ' (payments in and out, ' + __number_f(r.total) + ' in all). Then press Update ledger and do the cash count of that account.',
                    icon: 'warning', buttons: ['Cancel', 'Link'] }).then(function (ok) {
                        if (ok) {
                            $(f).data('ok', true);
                            $(f).find('button[type=submit]').prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Linking…');
                            f.submit();
                        }
                    });
            });
        });
    });
</script>
