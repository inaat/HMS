{{-- Payment Accounts page, under the money accounts: the business's other accounts from the chart (receivable,
     payable, stock, capital ...) with their balance and account book. They are not payment accounts, so they never
     show in the payment dropdowns of sales / purchases / expenses. --}}
@if (\App\Utils\LedgerUtil::installed())
    @php
        $b = session('user.business_id');
        $L = \App\Http\Controllers\LedgerController::class;
        $types = \DB::table('account_types')->where('business_id', $b)->get()->keyBy('id');
        $balances = \DB::table('ledger_lines')->where('business_id', $b)->groupBy('account_type_id')
            ->selectRaw('account_type_id, SUM(debit - credit) as net')->pluck('net', 'account_type_id');
        $order = ['asset' => 1, 'liability' => 2, 'equity' => 3];
        $rows = $types->filter(function ($t) use ($balances, $order) {
            // balance-sheet accounts (not cash & bank, which are the payment accounts above), used or posted to
            // with a balance; receivable, payable and stock always
            return $t->parent_account_type_id && isset($order[$t->classification])
                && ! in_array($t->detail_type, ['cash', 'bank'])
                && (abs((float) ($balances[$t->id] ?? 0)) >= 0.005 || in_array($t->system_key, ['receivable', 'payable', 'inventory']));
        })->sortBy(fn ($t) => $order[$t->classification].'|'.($t->code ?? 'zzzz'));
        $labels = collect(\App\Http\Controllers\LedgerController::DETAIL_TYPES)->collapse();
        $last = json_decode((string) \DB::table('system')->where('key', 'ledger_last_sync_'.$b)->value('value'), true);
    @endphp
    <div style="margin-top:24px;">
        <h4 style="margin-bottom:4px;"><b>Business accounts</b>
            <small>customers, suppliers, stock, capital … (from the chart of accounts; not used for payments)</small></h4>
        <p class="text-muted small" style="margin:0 0 8px;">
            Balances as of the last ledger update{{ $last ? ': '.\Carbon::parse($last['at'])->diffForHumans() : ' (never — press Update ledger)' }}.
            <a href="{{ action([$L, 'index']) }}">Update ledger</a> · <a href="{{ action([$L, 'chart']) }}">Full chart of accounts</a>
        </p>
        <div class="table-responsive">
            <table class="table table-bordered table-striped" style="width:100%;">
                <thead>
                    <tr><th style="width:70px;">Code</th><th>Account</th><th>Type</th><th class="text-right">Balance</th><th style="width:130px;">Action</th></tr>
                </thead>
                <tbody>
                    @foreach ($rows as $t)
                        @php
                            $net = (float) ($balances[$t->id] ?? 0);
                            $amount = ($t->debit_increases ?? ($t->classification === 'asset' ? 1 : 0)) ? $net : -$net;
                        @endphp
                        <tr>
                            <td>{{ $t->code }}</td>
                            <td>{{ $t->name }}</td>
                            <td>{{ $labels[$t->detail_type] ?? ucfirst($t->classification) }}</td>
                            <td class="text-right">@format_currency($amount)</td>
                            <td>
                                <a href="{{ action([$L, 'generalLedger'], ['account' => 't'.$t->id]) }}" class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-warning">
                                    <i class="fa fa-book"></i> Account Book</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
