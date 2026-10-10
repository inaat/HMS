{{-- Payment Accounts page, under the money accounts: every balance-sheet account of the chart (receivable, payable,
     stock, capital ...) grouped as assets / liabilities / equity, with balance and account book. Cash & bank are the
     payment accounts above, so they are one summary line here; profit to date (income − expenses) closes into equity,
     so the totals check (assets = liabilities + equity). These accounts never show in the payment dropdowns. --}}
@if (\App\Utils\LedgerUtil::installed())
    @php
        $b = session('user.business_id');
        $L = \App\Http\Controllers\LedgerController::class;
        $types = \DB::table('account_types')->where('business_id', $b)->get()->keyBy('id');
        $balances = \DB::table('ledger_lines')->where('business_id', $b)->groupBy('account_type_id')
            ->selectRaw('account_type_id, SUM(debit - credit) as net')->pluck('net', 'account_type_id');
        $order = ['asset' => 1, 'liability' => 2, 'equity' => 3];
        $sections = ['asset' => 'Assets', 'liability' => 'Liabilities', 'equity' => 'Equity'];
        // shown amount: assets positive when debit, liabilities / equity positive when credit
        $amountOf = function ($t) use ($balances) {
            $net = (float) ($balances[$t->id] ?? 0);

            return ($t->debit_increases ?? ($t->classification === 'asset' ? 1 : 0)) ? $net : -$net;
        };
        $cash_ids = $types->filter(fn ($t) => in_array($t->detail_type, ['cash', 'bank']))->keys();
        $cash = $cash_ids->sum(fn ($id) => (float) ($balances[$id] ?? 0));
        $profit = -$types->filter(fn ($t) => in_array($t->classification, ['income', 'expense']))
            ->keys()->sum(fn ($id) => (float) ($balances[$id] ?? 0));
        $rows = $types->filter(function ($t) use ($order) {
            return $t->parent_account_type_id && isset($order[$t->classification]) && ! in_array($t->detail_type, ['cash', 'bank']);
        })->sortBy(fn ($t) => $order[$t->classification].'|'.($t->code ?? 'zzzz'))->groupBy('classification');
        $totals = [];
        foreach ($sections as $cls => $title) {
            // totals from debit − credit, so contra accounts (drawings, accumulated depreciation) reduce their section
            $net = collect($rows[$cls] ?? [])->sum(fn ($t) => (float) ($balances[$t->id] ?? 0));
            $totals[$cls] = ($cls === 'asset' ? $net + $cash : -$net) + ($cls === 'equity' ? $profit : 0);
        }
        $diff = round($totals['asset'] - $totals['liability'] - $totals['equity'], 2);
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
            <table class="table table-bordered" style="width:100%;">
                <thead>
                    <tr><th style="width:70px;">Code</th><th>Account</th><th>Type</th><th class="text-right">Balance</th><th style="width:130px;">Action</th></tr>
                </thead>
                <tbody>
                    @foreach ($sections as $cls => $title)
                        <tr style="background:#eef6f1;"><td colspan="5"><b>{{ $title }}</b></td></tr>
                        @if ($cls === 'asset')
                            <tr>
                                <td></td>
                                <td>Cash &amp; bank <small class="text-muted">(the payment accounts above)</small></td>
                                <td>Cash / Bank</td>
                                <td class="text-right">@format_currency($cash)</td>
                                <td></td>
                            </tr>
                        @endif
                        @foreach ($rows[$cls] ?? [] as $t)
                            @php $amount = $amountOf($t); $zero = abs($amount) < 0.005; @endphp
                            <tr @if ($zero) style="color:#9aa8a0;" @endif>
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
                        @if ($cls === 'equity')
                            <tr>
                                <td></td>
                                <td>Profit / loss to date <small class="text-muted">(income − expenses, not yet closed to retained earnings)</small></td>
                                <td>Equity</td>
                                <td class="text-right" style="color:{{ $profit < 0 ? '#dc2626' : '#1f7a50' }};">@format_currency($profit)</td>
                                <td>
                                    <a href="{{ action([$L, 'profitLoss']) }}" class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-warning">
                                        <i class="fa fa-chart-line"></i> Profit &amp; Loss</a>
                                </td>
                            </tr>
                        @endif
                        <tr style="font-weight:bold; background:#f7faf8;">
                            <td></td><td colspan="2">Total {{ strtolower($title) }}</td>
                            <td class="text-right">@format_currency($totals[$cls])</td><td></td>
                        </tr>
                    @endforeach
                    <tr style="font-weight:bold; background:{{ abs($diff) < 1 ? '#e8f6ef' : '#fdecec' }};">
                        <td></td>
                        <td colspan="2">Liabilities + equity
                            <small style="font-weight:normal;">{{ abs($diff) < 1 ? '✓ equal to total assets' : 'differs from total assets by '.number_format($diff, 2).' — press Update ledger' }}</small></td>
                        <td class="text-right">@format_currency($totals['liability'] + $totals['equity'])</td><td></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
@endif
