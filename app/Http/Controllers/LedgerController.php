<?php

namespace App\Http\Controllers;

use App\Utils\LedgerUtil;
use App\Utils\TransactionUtil;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Accounting menu (double-entry books, Zoho Books style): Update ledger & checks, Chart of accounts, Journals
 * (+ manual journal), General ledger, Trial balance, Balance sheet, Profit & loss.
 * Every report is built as one table (head / rows / foot) that the screen, Print and Excel (CSV) all show.
 */
class LedgerController extends Controller
{
    const DETAIL_TYPES = [
        'asset' => ['cash' => 'Cash', 'bank' => 'Bank', 'accounts_receivable' => 'Accounts receivable', 'stock' => 'Stock',
            'other_current_asset' => 'Other current asset', 'fixed_asset' => 'Fixed asset', 'other_asset' => 'Other asset'],
        'liability' => ['accounts_payable' => 'Accounts payable', 'other_current_liability' => 'Other current liability',
            'long_term_liability' => 'Long term liability', 'other_liability' => 'Other liability'],
        'equity' => ['equity' => 'Equity'],
        'income' => ['income' => 'Income', 'other_income' => 'Other income'],
        'expense' => ['cost_of_goods_sold' => 'Cost of goods sold', 'expense' => 'Expense', 'other_expense' => 'Other expense'],
    ];

    const CLASS_LABELS = ['asset' => 'Assets', 'liability' => 'Liabilities', 'equity' => 'Equity', 'income' => 'Income', 'expense' => 'Expenses'];

    private function businessId(): int
    {
        return (int) request()->session()->get('user.business_id');
    }

    private function authorizeAccess(): void
    {
        if (! auth()->user()->can('account.access')) {
            abort(403, 'Unauthorized action.');
        }
    }

    private function util(): LedgerUtil
    {
        $util = new LedgerUtil($this->businessId());
        $util->loadChart();

        return $util;
    }

    private function ready()
    {
        $this->authorizeAccess();
        if (! LedgerUtil::installed()) {
            return redirect()->action([self::class, 'index']);
        }

        return null;
    }

    private function lastSync(): ?array
    {
        return json_decode((string) DB::table('system')->where('key', 'ledger_last_sync_'.$this->businessId())->value('value'), true) ?: null;
    }

    private function dateOr(Request $request, string $key, string $default): string
    {
        $v = (string) $request->input($key);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : $default;
    }

    // ------------------------------------------------------------------ Update ledger & checks

    public function index()
    {
        $this->authorizeAccess();
        if (! LedgerUtil::installed()) {
            return view('ledger.install');
        }
        $last = $this->lastSync();

        return view('ledger.index', ['last' => $last, 'steps' => LedgerUtil::STEPS]);
    }

    /** One click set-up: the tables (migration) and the chart of accounts (seeder). */
    public function install()
    {
        $this->authorizeAccess();
        foreach (['2026_10_09_100000_create_ledger_tables.php', '2026_10_10_100000_add_accounts_to_investor_money.php'] as $file) {
            Artisan::call('migrate', ['--path' => 'database/migrations/'.$file, '--force' => true]);
        }
        ChartOfAccountsSeeder::seedBusiness($this->businessId());

        return redirect()->action([self::class, 'index'])->with('status', ['success' => 1, 'msg' => 'Chart of accounts ready. Now press Update ledger.']);
    }

    public function syncStart()
    {
        $this->authorizeAccess();
        $totals = $this->util()->totals();

        return ['steps' => collect(LedgerUtil::STEPS)->map(function ($label, $key) use ($totals) {
            return ['key' => $key, 'label' => $label, 'total' => $totals[$key]];
        })->values(), 'total' => array_sum($totals)];
    }

    public function syncStep(Request $request)
    {
        $this->authorizeAccess();
        @set_time_limit(300);
        $step = (string) $request->input('step');
        abort_unless(isset(LedgerUtil::STEPS[$step]), 404);

        return $this->util()->syncChunk($step, (int) $request->input('after'));
    }

    public function syncFinish(Request $request)
    {
        $this->authorizeAccess();
        $issues = array_slice((array) $request->input('issues', []), 0, 200);
        DB::table('system')->updateOrInsert(['key' => 'ledger_last_sync_'.$this->businessId()], ['value' => json_encode([
            'at' => now()->toDateTimeString(), 'by' => auth()->user()->first_name, 'seconds' => (int) $request->input('seconds'),
            'written' => (int) $request->input('written'), 'removed' => (int) $request->input('removed'), 'issues' => $issues,
        ])]);

        return ['ok' => 1];
    }

    // ------------------------------------------------------------------ Cash & bank setup

    /**
     * Update ledger screen > Cash & bank setup: default account per payment method (new payments link themselves),
     * cash count on a cut-off date (old money without an account), and linking the payments after that date.
     */
    public function cashSetup(Request $request)
    {
        $this->authorizeAccess();
        if (! LedgerUtil::installed()) {
            return '';
        }
        $b = $this->businessId();
        $util = $this->util();
        $accounts = DB::table('accounts')->where('business_id', $b)->whereNull('deleted_at')->where('is_closed', 0)->orderBy('name')->pluck('name', 'id');
        $locations = DB::table('business_locations')->where('business_id', $b)->whereNull('deleted_at')->get(['id', 'name', 'default_payment_accounts']);
        $methods = [];
        foreach ($locations as $l) {
            $methods[$l->id] = $util->payment_types($l->id, false, $b);
        }
        $unlinked = DB::table('transaction_payments')->where('business_id', $b)->whereNull('parent_id')->whereNull('account_id')
            ->where('method', '!=', 'advance')->selectRaw('COUNT(*) as n, MIN(paid_on) as first, MAX(paid_on) as last')->first();

        $date = $this->dateOr($request, 'count_date', date('Y-m-d'));
        $bal = $util->balances(null, $date);
        $unassigned = round((float) $bal->where('account_type_id', $util->accountId('cash_unassigned'))->sum('net'), 2);
        $account_balances = $bal->whereNotNull('account_id')->pluck('net', 'account_id')->map(function ($v) {
            return round((float) $v, 2);
        });
        $types = $this->types();
        $diff_accounts = $types->filter(function ($t) {
            return ! $t->is_main && in_array($t->cls, ['equity', 'expense']);
        })->mapWithKeys(function ($t) {
            return [$t->id => trim($t->code.' '.$t->name)];
        });

        // payments to link are the ones after the latest cash count (its date settled everything before)
        $last_count = DB::table('ledger_journals')->where('business_id', $b)->where('source_type', 'manual')->where('ref_no', 'like', 'CC-%')
            ->max('entry_date');
        $link_from = $last_count ? substr($last_count, 0, 10) : $date;
        $unlinked_after = DB::table('transaction_payments')->where('business_id', $b)->whereNull('parent_id')->whereNull('account_id')
            ->where('method', 'cash')->where('paid_on', '>', $link_from.' 23:59:59')->count();

        return view('ledger.partials.cash_setup', compact('accounts', 'locations', 'methods', 'unlinked', 'date', 'unassigned',
            'account_balances', 'diff_accounts', 'last_count', 'link_from', 'unlinked_after') + ['drawings' => $util->accountId('drawings'), 'last' => $this->lastSync()]);
    }

    /** Step 1: the account each payment method goes to, per location (same setting as Business Locations > edit). */
    public function saveDefaultAccounts(Request $request)
    {
        $this->authorizeAccess();
        $b = $this->businessId();
        $valid = DB::table('accounts')->where('business_id', $b)->pluck('id')->flip();
        foreach ((array) $request->input('defaults', []) as $location_id => $by_method) {
            $location = DB::table('business_locations')->where('business_id', $b)->where('id', (int) $location_id)->first();
            if (empty($location)) {
                continue;
            }
            $current = json_decode((string) $location->default_payment_accounts, true) ?: [];
            foreach ((array) $by_method as $method => $account_id) {
                $current[$method] = ['is_enabled' => $current[$method]['is_enabled'] ?? '1',
                    'account' => $account_id && isset($valid[(int) $account_id]) ? (string) (int) $account_id : null];
            }
            DB::table('business_locations')->where('id', $location->id)->update(['default_payment_accounts' => json_encode($current)]);
        }

        return redirect()->back()->with('status', ['success' => 1, 'msg' => 'Default accounts saved: new payments go into them automatically']);
    }

    /**
     * Step 2: real cash / bank counted on a date. Each payment account is set to the counted amount, "Cash in hand
     * (no account)" is cleared, and the difference goes once to the chosen account (e.g. Drawings). One manual journal;
     * the Payment Accounts entries it makes go with it if the journal is deleted.
     */
    public function saveCashCount(Request $request)
    {
        $this->authorizeAccess();
        $b = $this->businessId();
        $util = $this->util();
        if (empty($this->lastSync())) {
            return redirect()->back()->with('status', ['success' => 0, 'msg' => 'Press Update ledger first']);
        }
        $date = $this->dateOr($request, 'count_date', date('Y-m-d'));
        $types = $this->types();
        $diff_type = $types[(int) $request->input('difference_account')] ?? null;
        if (empty($diff_type) || $diff_type->is_main) {
            return redirect()->back()->with('status', ['success' => 0, 'msg' => 'Choose where the difference goes']);
        }
        $bal = $util->balances(null, $date);
        $unassigned = round((float) $bal->where('account_type_id', $util->accountId('cash_unassigned'))->sum('net'), 4);
        $accounts = $this->paymentAccounts()->keyBy('id');

        $lines = [];
        $total_in = 0;
        foreach ((array) $request->input('counted', []) as $account_id => $value) {
            $a = $accounts[(int) $account_id] ?? null;
            if (empty($a) || $value === null || $value === '') {
                continue;
            }
            $adjust = round((float) $util->num_uf($value) - (float) $bal->where('account_id', $a->id)->sum('net'), 4);
            if (abs($adjust) < 0.005) {
                continue;
            }
            $type_id = isset($types[$a->account_type_id]) && $types[$a->account_type_id]->cls === 'asset' ? $a->account_type_id : $util->accountId('cash_accounts');
            $lines[] = [(int) $type_id, (int) $a->id, null, $adjust > 0 ? $adjust : 0, $adjust < 0 ? -$adjust : 0, 'Cash count'];
            $total_in += $adjust;
        }
        if (abs($unassigned) >= 0.005) {
            $lines[] = [$util->accountId('cash_unassigned'), null, null, $unassigned < 0 ? -$unassigned : 0, $unassigned > 0 ? $unassigned : 0, 'Cleared by cash count'];
        }
        $difference = round($unassigned - $total_in, 4); // book cash not found in any account
        if (abs($difference) >= 0.005) {
            $lines[] = [(int) $diff_type->id, null, null, $difference > 0 ? $difference : 0, $difference < 0 ? -$difference : 0, 'Cash count difference'];
        }
        if (count($lines) < 2) {
            return redirect()->back()->with('status', ['success' => 0, 'msg' => 'Nothing to change: the accounts already show these amounts']);
        }

        $ref = 'CC-'.str_replace('-', '', $date);
        $memo = 'Cash count on '.$this->fd($date).': accounts set to the real amounts, cash without account cleared';
        DB::transaction(function () use ($lines, $date, $ref, $memo, $util, $b) {
            foreach ($lines as &$l) {
                if ($l[1]) { // the Payment Accounts balance shows the same amount
                    $l[6] = \App\AccountTransaction::createAccountTransaction([
                        'amount' => $l[3] > 0 ? $l[3] : $l[4], 'account_id' => $l[1], 'type' => $l[3] > 0 ? 'credit' : 'debit',
                        'operation_date' => $date.' 23:59:00', 'created_by' => auth()->id(), 'note' => 'Cash count '.$ref,
                    ])->id;
                }
            }
            unset($l);
            $id = DB::table('ledger_journals')->insertGetId([
                'business_id' => $b, 'location_id' => null, 'entry_date' => $date.' 23:59:00', 'source_type' => 'manual', 'source_id' => null,
                'ref_no' => $ref, 'memo' => $memo, 'created_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $util->insertLines($id, $lines);
        });

        return redirect()->back()->with('status', ['success' => 1, 'msg' => 'Cash count saved ('.$ref.'). It is in Journals; delete it there to undo.']);
    }

    /** Step 3: payments with no account after a date go into the chosen account (with their Payment Accounts entry). */
    public function linkPayments(Request $request)
    {
        $this->authorizeAccess();
        $b = $this->businessId();
        $account = DB::table('accounts')->where('business_id', $b)->where('id', (int) $request->input('account_id'))->first();
        $all = $request->input('range') === 'all'; // every payment without an account, any date
        $from = $this->dateOr($request, 'from_date', '');
        if (empty($account) || (! $all && $from === '')) {
            return redirect()->back()->with('status', ['success' => 0, 'msg' => 'Choose the account']);
        }
        $method = (string) $request->input('method', 'cash');
        $payments = DB::table('transaction_payments as tp')->leftJoin('transactions as t', 't.id', '=', 'tp.transaction_id')
            ->leftJoin('contacts as c', 'c.id', '=', 'tp.payment_for')
            ->where('tp.business_id', $b)->whereNull('tp.parent_id')->whereNull('tp.account_id')->where('tp.method', $method)
            ->when(! $all, function ($q) use ($from) {
                $q->where('tp.paid_on', '>', $from.' 23:59:59');
            })
            ->get(['tp.id', 'tp.transaction_id', 'tp.amount', 'tp.is_return', 'tp.paid_on', 'tp.created_by', 'tp.payment_type', 't.type as t_type', 'c.type as c_type']);
        if ($request->input('preview')) {
            return ['count' => $payments->count(), 'total' => round((float) $payments->sum('amount'), 2)];
        }
        @set_time_limit(600);
        $money_in = ['sell', 'purchase_return', 'expense_refund'];
        $now = now();
        $user = auth()->id();
        // in batches: ~30,000 payments link in seconds; all or nothing
        DB::transaction(function () use ($payments, $account, $money_in, $now, $user) {
            foreach ($payments->chunk(1000) as $chunk) {
                $rows = [];
                foreach ($chunk as $p) {
                    if (in_array($p->payment_type, ['credit', 'debit'])) {
                        $in = $p->payment_type === 'credit'; // the POS marked it itself (e.g. advance paid back = debit)
                    } else {
                        $type = $p->t_type ?: ($p->c_type === 'supplier' ? 'purchase' : 'sell');
                        $in = in_array($type, $money_in) || ($type === 'opening_balance' && $p->c_type !== 'supplier');
                        if ($p->is_return) {
                            $in = ! $in;
                        }
                    }
                    $rows[] = ['amount' => $p->amount, 'account_id' => $account->id, 'type' => $in ? 'credit' : 'debit',
                        'operation_date' => $p->paid_on, 'created_by' => $p->created_by ?: $user,
                        'transaction_id' => $p->transaction_id, 'transaction_payment_id' => $p->id,
                        'created_at' => $now, 'updated_at' => $now];
                }
                DB::table('transaction_payments')->whereIn('id', $chunk->pluck('id')->all())->update(['account_id' => $account->id]);
                DB::table('account_transactions')->insert($rows);
            }
        });

        return redirect()->back()->with('status', ['success' => 1, 'msg' => number_format($payments->count()).' payment(s) linked to '.$account->name
            .'. Now press Update ledger'.($all ? ', then do the cash count of '.$account->name.' (step 2)' : '').'.']);
    }

    public function checks()
    {
        $this->authorizeAccess();
        if (! LedgerUtil::installed()) {
            return '';
        }
        if (empty($this->lastSync())) {
            return '<p class="text-muted">Press <b>Update ledger</b> first; the checks show after it.</p>';
        }

        return view('ledger.partials.checks', ['checks' => $this->util()->checks(), 'last' => $this->lastSync()]);
    }

    // ------------------------------------------------------------------ chart helpers

    /** All account types with their main class / normal side worked out (custom ones inherit from their parent). */
    private function types()
    {
        $types = DB::table('account_types')->where('business_id', $this->businessId())
            ->orderByRaw('code IS NULL, code')->orderBy('name')->get()->keyBy('id');
        foreach ($types as $t) {
            $parent = $t->parent_account_type_id ? ($types[$t->parent_account_type_id] ?? null) : null;
            $t->cls = $t->classification ?: ($parent->classification ?? 'asset');
            $t->debit_side = $t->debit_increases !== null ? (int) $t->debit_increases : (in_array($t->cls, ['asset', 'expense']) ? 1 : 0);
            $t->detail = $t->detail_type ?: ($parent ? ($parent->detail_type ?? null) : null);
            $t->is_main = empty($t->parent_account_type_id);
        }

        return $types;
    }

    private function paymentAccounts()
    {
        return DB::table('accounts')->where('business_id', $this->businessId())->whereNull('deleted_at')->orderBy('name')
            ->get(['id', 'name', 'account_type_id', 'is_closed']);
    }

    /** Accounts to post to / pick in reports: [ 't12' => '1200 Accounts receivable', 'a3' => 'shafiq (Cash & bank)' ] */
    private function accountOptions($types, bool $with_main = false): array
    {
        $opts = [];
        $util = $this->util();
        foreach ($types as $t) {
            if ($t->is_main && ! $with_main) {
                continue;
            }
            $opts['t'.$t->id] = trim($t->code.' '.$t->name);
        }
        foreach ($this->paymentAccounts() as $a) {
            $opts['a'.$a->id] = $a->name.' (payment account)';
        }

        return $opts;
    }

    private function m($v): string
    {
        return number_format((float) $v, 2);
    }

    /** Show a report table: screen, ?print=1 or ?export=csv. */
    private function output(Request $request, string $view, string $title, string $subtitle, array $table, array $extra = [])
    {
        if ($request->input('export') === 'csv') {
            $name = preg_replace('/[^a-z0-9]+/i', '-', strtolower($title)).'-'.date('Y-m-d').'.csv';

            return response()->streamDownload(function () use ($table, $title, $subtitle) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, [$title]);
                fputcsv($out, [$subtitle]);
                fputcsv($out, array_column($table['head'], 0));
                foreach (array_merge($table['rows'], $table['foot'] ?? []) as $r) {
                    fputcsv($out, array_map(function ($c) {
                        $c = is_array($c) ? '' : strip_tags((string) $c);

                        // plain numbers, so Excel can add them up
                        return preg_match('/^-?[\d,]+\.\d+$/', $c) ? str_replace(',', '', $c) : $c;
                    }, array_filter($r['cells'], function ($c) {
                        return ! is_array($c);
                    })));
                }
                fclose($out);
            }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }
        $business = DB::table('business')->where('id', $this->businessId())->first();
        $data = compact('title', 'subtitle', 'table', 'business') + $extra;
        if ($request->input('print')) {
            return view('ledger.print', $data);
        }

        return view($view, $data);
    }

    private function row(array $cells, string $class = '', int $indent = 0, ?string $link = null): array
    {
        return ['cells' => $cells, 'class' => $class, 'indent' => $indent, 'link' => $link];
    }

    // ------------------------------------------------------------------ Chart of accounts

    public function chart(Request $request)
    {
        if ($r = $this->ready()) {
            return $r;
        }
        $date = $this->dateOr($request, 'date', date('Y-m-d'));
        $types = $this->types();
        $bal = $this->util()->balances(null, $date);
        $by_type = $bal->groupBy('account_type_id');
        $by_acc = $bal->whereNotNull('account_id')->keyBy('account_id');
        $accounts = $this->paymentAccounts()->groupBy('account_type_id');
        $used = DB::table('ledger_lines')->where('business_id', $this->businessId())->distinct()->pluck('account_type_id')->flip();

        $rows = [];
        $labels = collect(self::DETAIL_TYPES)->collapse();
        foreach ($types->where('is_main', true) as $main) {
            $children = $types->where('parent_account_type_id', $main->id);
            $total = 0;
            $child_rows = [];
            foreach ($children as $t) {
                $amount = LedgerUtil::signed((object) ['debit_increases' => $t->debit_side], (float) ($by_type[$t->id] ?? collect())->sum('net'));
                $total += $main->debit_side == $t->debit_side ? $amount : -$amount;
                $link = action([self::class, 'generalLedger'], ['account' => 't'.$t->id]);
                $child_rows[] = $this->row([$t->code, $t->name, $labels[$t->detail] ?? '', $t->debit_side ? 'Debit' : 'Credit', $this->m($amount),
                    ['id' => $t->id, 'name' => $t->name, 'code' => $t->code, 'detail' => $t->detail, 'contra' => (int) ($t->debit_side != $main->debit_side),
                        'parent' => $t->parent_account_type_id, 'fixed' => ! empty($t->system_key), 'can_delete' => empty($t->system_key) && empty($t->expense_category_id) && ! isset($used[$t->id]) && empty($accounts[$t->id])]], '', 1, $link);
                foreach ($accounts[$t->id] ?? [] as $a) {
                    $amt = (float) ($by_acc[$a->id]->net ?? 0);
                    $child_rows[] = $this->row(['', $a->name.($a->is_closed ? ' (closed)' : ''), 'Payment account', 'Debit', $this->m($amt), null], 'sub', 2,
                        action([self::class, 'generalLedger'], ['account' => 'a'.$a->id]));
                }
            }
            $rows[] = $this->row([$main->code, $main->name, self::CLASS_LABELS[$main->cls] ?? '', $main->debit_side ? 'Debit' : 'Credit', $this->m($total), null], 'group');
            $rows = array_merge($rows, $child_rows);
        }
        $table = ['head' => [['Code', ''], ['Account', ''], ['Type', ''], ['Normal side', ''], ['Balance', 'right'], ['', 'no-print']], 'rows' => $rows, 'foot' => [], 'name_col' => 1];
        $mains = $types->where('is_main', true)->mapWithKeys(function ($t) {
            return [$t->id => trim($t->code.' '.$t->name)];
        });
        $main_class = $types->where('is_main', true)->pluck('cls', 'id');

        return $this->output($request, 'ledger.chart', 'Chart of accounts', 'Balances on '.$this->fd($date), $table,
            ['date' => $date, 'mains' => $mains, 'main_class' => $main_class, 'detail_types' => self::DETAIL_TYPES]);
    }

    private function fd($date): string
    {
        return \Carbon::parse($date)->format(session('business.date_format') ?: 'd-m-Y');
    }

    public function storeAccount(Request $request)
    {
        $this->authorizeAccess();
        $request->validate(['name' => 'required|string|max:191', 'parent_id' => 'required|integer']);
        $types = $this->types();
        $parent = $types[(int) $request->input('parent_id')] ?? null;
        abort_if(empty($parent) || ! $parent->is_main, 422, 'Choose the main group');
        $data = $this->accountData($request, $parent);
        $id = (int) $request->input('id');
        if ($id) {
            $type = $types[$id] ?? null;
            abort_if(empty($type) || $type->is_main, 404);
            if (! empty($type->system_key)) { // fixed accounts: name / code only
                $data = ['name' => $data['name'], 'code' => $data['code']];
            }
            DB::table('account_types')->where('id', $id)->update($data + ['updated_at' => now()]);
            $msg = 'Account saved';
        } else {
            DB::table('account_types')->insert($data + ['business_id' => $this->businessId(), 'created_at' => now(), 'updated_at' => now()]);
            $msg = 'Account added';
        }

        return redirect()->back()->with('status', ['success' => 1, 'msg' => $msg]);
    }

    private function accountData(Request $request, $parent): array
    {
        return self::chartFields($request, $parent);
    }

    /**
     * Fields of an account under a main group (Assets, Liabilities ...): its class, Zoho-style type and normal side.
     * Also used by Payment Accounts > Account Types (AccountTypeController), so both screens save the same way.
     */
    public static function chartFields(Request $request, $parent): array
    {
        $cls = $parent->classification ?: 'asset';
        $detail = (string) $request->input('detail_type');
        if (! isset(self::DETAIL_TYPES[$cls][$detail])) {
            $detail = array_key_first(self::DETAIL_TYPES[$cls]);
        }
        $debit = $parent->debit_increases !== null ? (int) $parent->debit_increases : (in_array($cls, ['asset', 'expense']) ? 1 : 0);
        if ($request->input('contra')) { // e.g. Sales returns under Income, Drawings under Equity
            $debit = 1 - $debit;
        }

        return ['name' => trim($request->input('name')), 'code' => trim((string) $request->input('code')) ?: null,
            'parent_account_type_id' => $parent->id, 'classification' => $cls, 'detail_type' => $detail,
            'debit_increases' => $debit, 'credit_increases' => 1 - $debit];
    }

    public function destroyAccount($id)
    {
        $this->authorizeAccess();
        $type = DB::table('account_types')->where('business_id', $this->businessId())->where('id', $id)->first();
        $in_use = empty($type) || ! empty($type->system_key) || ! empty($type->expense_category_id) || empty($type->parent_account_type_id)
            || DB::table('ledger_lines')->where('account_type_id', $id)->exists()
            || DB::table('accounts')->where('account_type_id', $id)->exists();
        if ($in_use) {
            return redirect()->back()->with('status', ['success' => 0, 'msg' => 'This account is in use and cannot be deleted']);
        }
        DB::table('account_types')->where('id', $id)->delete();

        return redirect()->back()->with('status', ['success' => 1, 'msg' => 'Account deleted']);
    }

    // ------------------------------------------------------------------ Trial balance

    public function trialBalance(Request $request)
    {
        if ($r = $this->ready()) {
            return $r;
        }
        $date = $this->dateOr($request, 'date', date('Y-m-d'));
        $types = $this->types();
        $bal = $this->util()->balances(null, $date);
        $acc_names = $this->paymentAccounts()->pluck('name', 'id');
        $rows = [];
        $td = 0;
        $tc = 0;
        foreach ($types as $t) {
            foreach ($bal->where('account_type_id', $t->id) as $b) {
                $net = round((float) $b->net, 2);
                if (abs($net) < 0.005) {
                    continue;
                }
                $name = $b->account_id ? ($acc_names[$b->account_id] ?? 'Account #'.$b->account_id) : $t->name;
                $td += $net > 0 ? $net : 0;
                $tc += $net < 0 ? -$net : 0;
                $rows[] = $this->row([$b->account_id ? '' : $t->code, $name, self::CLASS_LABELS[$t->cls] ?? '',
                    $net > 0 ? $this->m($net) : '', $net < 0 ? $this->m(-$net) : ''], '', 0,
                    action([self::class, 'generalLedger'], ['account' => $b->account_id ? 'a'.$b->account_id : 't'.$t->id, 'end_date' => $date]));
            }
        }
        $table = ['head' => [['Code', ''], ['Account', ''], ['Type', ''], ['Debit', 'right'], ['Credit', 'right']], 'rows' => $rows, 'name_col' => 1,
            'foot' => [$this->row(['', 'Total', '', $this->m($td), $this->m($tc)], 'total')]];

        return $this->output($request, 'ledger.report', 'Trial balance', 'As of '.$this->fd($date), $table,
            ['filter' => 'date', 'date' => $date, 'balanced' => abs($td - $tc) < 0.01]);
    }

    // ------------------------------------------------------------------ Balance sheet

    public function balanceSheet(Request $request)
    {
        if ($r = $this->ready()) {
            return $r;
        }
        $date = $this->dateOr($request, 'date', date('Y-m-d'));
        $types = $this->types();
        $bal = $this->util()->balances(null, $date);
        $acc_names = $this->paymentAccounts()->pluck('name', 'id');

        $groups = [
            'asset' => ['Current assets' => ['cash', 'bank', 'accounts_receivable', 'stock', 'other_current_asset', null], 'Fixed assets' => ['fixed_asset'], 'Other assets' => ['other_asset']],
            'liability' => ['Current liabilities' => ['accounts_payable', 'other_current_liability', null], 'Long term liabilities' => ['long_term_liability'], 'Other liabilities' => ['other_liability']],
            'equity' => ['Equity' => ['equity', null]],
        ];
        // Profit (income − expenses) up to the date belongs to the owner: shown inside equity
        $profit = 0;
        foreach ($types->whereIn('cls', ['income', 'expense']) as $t) {
            $profit -= (float) $bal->where('account_type_id', $t->id)->sum('net');
        }

        $rows = [];
        $totals = [];
        foreach ($groups as $cls => $sections) {
            $rows[] = $this->row([self::CLASS_LABELS[$cls], ''], 'group');
            $cls_total = 0;
            foreach ($sections as $section => $details) {
                $section_rows = [];
                $section_total = 0;
                foreach ($types->where('cls', $cls)->where('is_main', false) as $t) {
                    if (! in_array($t->detail, $details, true)) {
                        continue;
                    }
                    $lines = $bal->where('account_type_id', $t->id);
                    $amount = ($cls === 'asset' ? 1 : -1) * (float) $lines->sum('net');
                    if (abs($amount) < 0.005) {
                        continue;
                    }
                    $section_total += $amount;
                    $link = action([self::class, 'generalLedger'], ['account' => 't'.$t->id, 'end_date' => $date]);
                    $section_rows[] = $this->row([$t->name, $this->m($amount)], '', 2, $link);
                    foreach ($lines->whereNotNull('account_id') as $b) {
                        if (abs((float) $b->net) >= 0.005) {
                            $section_rows[] = $this->row([$acc_names[$b->account_id] ?? 'Account #'.$b->account_id, $this->m($b->net)], 'sub', 3,
                                action([self::class, 'generalLedger'], ['account' => 'a'.$b->account_id, 'end_date' => $date]));
                        }
                    }
                }
                if ($cls === 'equity') {
                    $section_total += $profit;
                    $section_rows[] = $this->row(['Profit / loss to date (income − expenses)', $this->m($profit)], '', 2,
                        action([self::class, 'profitLoss'], ['end_date' => $date]));
                }
                if (empty($section_rows)) {
                    continue;
                }
                $rows[] = $this->row([$section, ''], 'section', 1);
                $rows = array_merge($rows, $section_rows);
                $rows[] = $this->row(['Total '.strtolower($section), $this->m($section_total)], 'subtotal', 1);
                $cls_total += $section_total;
            }
            $totals[$cls] = $cls_total;
            $rows[] = $this->row(['Total '.strtolower(self::CLASS_LABELS[$cls]), $this->m($cls_total)], 'total');
        }
        $le = $totals['liability'] + $totals['equity'];
        $table = ['head' => [['Account', ''], ['Amount', 'right']], 'rows' => $rows,
            'foot' => [$this->row(['Total liabilities + equity', $this->m($le)], 'total')]];

        return $this->output($request, 'ledger.report', 'Balance sheet', 'As of '.$this->fd($date), $table,
            ['filter' => 'date', 'date' => $date, 'balanced' => abs($totals['asset'] - $le) < 0.01]);
    }

    // ------------------------------------------------------------------ Profit & loss

    public function profitLoss(Request $request)
    {
        if ($r = $this->ready()) {
            return $r;
        }
        $start = $this->dateOr($request, 'start_date', date('Y-m-01'));
        $end = $this->dateOr($request, 'end_date', date('Y-m-d'));
        $location_id = (int) $request->input('location_id') ?: null;
        $types = $this->types();
        $bal = $this->util()->balances($start, $end, $location_id);

        $sections = [
            ['Operating income', ['income'], -1],
            ['Cost of goods sold', ['cost_of_goods_sold'], 1],
            ['Operating expenses', ['expense'], 1],
            ['Other income', ['other_income'], -1],
            ['Other expenses', ['other_expense'], 1],
        ];
        $amounts = [];
        $rows = [];
        foreach ($sections as [$label, $details, $sign]) {
            $section_rows = [];
            $total = 0;
            foreach ($types->whereIn('cls', ['income', 'expense'])->where('is_main', false) as $t) {
                if (! in_array($t->detail ?: ($t->cls === 'income' ? 'income' : 'expense'), $details, true)) {
                    continue;
                }
                $amount = $sign * (float) $bal->where('account_type_id', $t->id)->sum('net');
                if (abs($amount) < 0.005) {
                    continue;
                }
                $total += $amount;
                $section_rows[] = $this->row([$t->name, $this->m($amount)], '', 2,
                    action([self::class, 'generalLedger'], ['account' => 't'.$t->id, 'start_date' => $start, 'end_date' => $end]));
            }
            $amounts[$label] = $total;
            $rows[] = $this->row([$label, ''], 'section', 1);
            $rows = array_merge($rows, $section_rows);
            $rows[] = $this->row(['Total '.strtolower($label), $this->m($total)], 'subtotal', 1);
            if ($label === 'Cost of goods sold') {
                $rows[] = $this->row(['Gross profit', $this->m($amounts['Operating income'] - $total)], 'total');
            }
            if ($label === 'Operating expenses') {
                $rows[] = $this->row(['Operating profit', $this->m($amounts['Operating income'] - $amounts['Cost of goods sold'] - $total)], 'total');
            }
        }
        $net = $amounts['Operating income'] - $amounts['Cost of goods sold'] - $amounts['Operating expenses'] + $amounts['Other income'] - $amounts['Other expenses'];
        $table = ['head' => [['Account', ''], ['Amount', 'right']], 'rows' => $rows,
            'foot' => [$this->row(['Net profit / loss', $this->m($net)], 'total')]];

        // POS Profit / Loss report for the same dates, to compare
        $pos_net = null;
        try {
            $pos = (new TransactionUtil())->getProfitLossDetails($this->businessId(), $location_id, $start, $end, null, auth()->user()->permitted_locations());
            $pos_net = (float) ($pos['net_profit'] ?? 0);
        } catch (\Throwable $e) {
            \Log::info('Ledger P&L compare: '.$e->getMessage());
        }
        $locations = \App\BusinessLocation::forDropdown($this->businessId());

        return $this->output($request, 'ledger.report', 'Profit & loss', $this->fd($start).' to '.$this->fd($end), $table,
            ['filter' => 'range', 'start_date' => $start, 'end_date' => $end, 'pos_net' => $pos_net, 'net' => $net,
                'locations' => $locations, 'location_id' => $location_id]);
    }

    // ------------------------------------------------------------------ General ledger

    public function generalLedger(Request $request)
    {
        if ($r = $this->ready()) {
            return $r;
        }
        $types = $this->types();
        $options = $this->accountOptions($types);
        $account = (string) $request->input('account', array_key_first($options));
        $start = $this->dateOr($request, 'start_date', date('Y-m-01'));
        $end = $this->dateOr($request, 'end_date', date('Y-m-d'));
        $contact_id = (int) $request->input('contact_id') ?: null;

        $q = DB::table('ledger_lines as l')->join('ledger_journals as j', 'j.id', '=', 'l.journal_id')
            ->leftJoin('contacts as c', 'c.id', '=', 'l.contact_id')
            ->where('l.business_id', $this->businessId());
        $debit_side = 1;
        $name = '';
        if (str_starts_with($account, 'a')) {
            $q->where('l.account_id', (int) substr($account, 1));
            $name = DB::table('accounts')->where('id', (int) substr($account, 1))->value('name');
        } else {
            $t = $types[(int) substr($account, 1)] ?? null;
            abort_if(empty($t), 404);
            $ids = $t->is_main ? $types->where('parent_account_type_id', $t->id)->pluck('id')->push($t->id)->all() : [$t->id];
            $q->whereIn('l.account_type_id', $ids);
            $debit_side = $t->debit_side;
            $name = trim($t->code.' '.$t->name);
        }
        if ($contact_id) {
            $q->where('l.contact_id', $contact_id);
            $name .= ' — '.DB::table('contacts')->where('id', $contact_id)->value('name');
        }
        $sign = $debit_side ? 1 : -1;
        $opening = $sign * (float) (clone $q)->where('j.entry_date', '<', $start.' 00:00:00')->sum(DB::raw('l.debit - l.credit'));
        $lines = (clone $q)->whereBetween('j.entry_date', [$start.' 00:00:00', $end.' 23:59:59'])
            ->orderBy('j.entry_date')->orderBy('j.id')
            ->limit(5001)->get(['j.entry_date', 'j.ref_no', 'j.memo', 'j.source_type', 'c.name as contact', 'l.debit', 'l.credit', 'l.note']);
        $too_many = $lines->count() > 5000;

        $running = $opening;
        $rows = [$this->row(['', '', 'Opening balance', '', '', '', $this->m($opening)], 'section')];
        $td = 0;
        $tc = 0;
        foreach ($lines->take(5000) as $l) {
            $running += $sign * ((float) $l->debit - (float) $l->credit);
            $td += $l->debit;
            $tc += $l->credit;
            $rows[] = $this->row([\Carbon::parse($l->entry_date)->format((session('business.date_format') ?: 'd-m-Y').' H:i'), $l->ref_no,
                trim($l->memo.($l->note && $l->note !== 'Rounding' ? '' : '')), $l->contact,
                $l->debit > 0 ? $this->m($l->debit) : '', $l->credit > 0 ? $this->m($l->credit) : '', $this->m($running)]);
        }
        $table = ['head' => [['Date', ''], ['Ref', ''], ['Details', ''], ['Customer / supplier', ''], ['Debit', 'right'], ['Credit', 'right'], ['Balance', 'right']], 'name_col' => 2,
            'rows' => $rows, 'foot' => [$this->row(['', '', 'Totals / closing balance', '', $this->m($td), $this->m($tc), $this->m($running)], 'total')]];

        $contacts = [];
        if ($contact_id) {
            $contacts = DB::table('contacts')->where('id', $contact_id)->pluck('name', 'id')->all();
        }

        return $this->output($request, 'ledger.report', 'General ledger: '.$name, $this->fd($start).' to '.$this->fd($end), $table,
            ['filter' => 'ledger', 'start_date' => $start, 'end_date' => $end, 'account' => $account, 'options' => $options,
                'contact_id' => $contact_id, 'contacts' => $contacts, 'too_many' => $too_many]);
    }

    /** select2 search for customers / suppliers (general ledger, manual journal). */
    public function contactSearch(Request $request)
    {
        $this->authorizeAccess();
        $term = trim((string) $request->input('q'));

        return DB::table('contacts')->where('business_id', $this->businessId())->whereNull('deleted_at')
            ->when($term !== '', function ($q) use ($term) {
                $q->where(function ($w) use ($term) {
                    $w->where('name', 'like', "%{$term}%")->orWhere('supplier_business_name', 'like', "%{$term}%")
                        ->orWhere('contact_id', 'like', "%{$term}%")->orWhere('mobile', 'like', "%{$term}%");
                });
            })
            ->orderBy('name')->limit(30)->get(['id', 'name', 'supplier_business_name', 'contact_id', 'type'])
            ->map(function ($c) {
                return ['id' => $c->id, 'text' => $c->name.($c->supplier_business_name ? ' ('.$c->supplier_business_name.')' : '').' — '.$c->contact_id.' · '.$c->type];
            });
    }

    // ------------------------------------------------------------------ Journals

    public function journals(Request $request)
    {
        if ($r = $this->ready()) {
            return $r;
        }
        $start = $this->dateOr($request, 'start_date', date('Y-m-01'));
        $end = $this->dateOr($request, 'end_date', date('Y-m-d'));
        $type = (string) $request->input('source_type');
        $search = trim((string) $request->input('q'));

        $q = DB::table('ledger_journals as j')->leftJoin('users as u', 'u.id', '=', 'j.created_by')
            ->where('j.business_id', $this->businessId())
            ->whereBetween('j.entry_date', [$start.' 00:00:00', $end.' 23:59:59'])
            ->when(isset(LedgerUtil::SOURCE_LABELS[$type]), function ($q) use ($type) {
                $q->where('j.source_type', $type);
            })
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($w) use ($search) {
                    $w->where('j.ref_no', 'like', "%{$search}%")->orWhere('j.memo', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('j.entry_date')->orderByDesc('j.id')
            ->select('j.*', 'u.first_name as by_name');
        $journals = $q->paginate(50)->withQueryString();
        $lines = DB::table('ledger_lines as l')->leftJoin('account_types as t', 't.id', '=', 'l.account_type_id')
            ->leftJoin('accounts as a', 'a.id', '=', 'l.account_id')->leftJoin('contacts as c', 'c.id', '=', 'l.contact_id')
            ->whereIn('l.journal_id', $journals->pluck('id')->all() ?: [0])
            ->orderBy('l.id')
            ->get(['l.journal_id', 'l.debit', 'l.credit', 'l.note', 't.code', 't.name as type_name', 'a.name as account_name', 'c.name as contact'])
            ->groupBy('journal_id');

        return view('ledger.journals', ['journals' => $journals, 'lines' => $lines, 'start_date' => $start, 'end_date' => $end,
            'source_type' => $type, 'q' => $search, 'labels' => LedgerUtil::SOURCE_LABELS]);
    }

    public function createJournal()
    {
        if ($r = $this->ready()) {
            return $r;
        }
        $types = $this->types();

        return view('ledger.journal_create', ['options' => $this->accountOptions($types),
            'locations' => \App\BusinessLocation::forDropdown($this->businessId())]);
    }

    public function storeJournal(Request $request)
    {
        $this->authorizeAccess();
        $util = $this->util();
        $types = $this->types();
        $accounts = $this->paymentAccounts()->keyBy('id');
        $lines = [];
        foreach ((array) $request->input('lines', []) as $l) {
            $debit = round((float) $util->num_uf($l['debit'] ?? 0), 4);
            $credit = round((float) $util->num_uf($l['credit'] ?? 0), 4);
            $key = (string) ($l['account'] ?? '');
            if (($debit == 0 && $credit == 0) || $key === '') {
                continue;
            }
            if (str_starts_with($key, 'a')) {
                $a = $accounts[(int) substr($key, 1)] ?? null;
                if (empty($a)) {
                    continue;
                }
                $type_id = isset($types[$a->account_type_id]) && $types[$a->account_type_id]->cls === 'asset' ? $a->account_type_id : $util->accountId('cash_accounts');
                $lines[] = [(int) $type_id, (int) $a->id, null, $debit, $credit, $l['note'] ?? null];
            } else {
                $t = $types[(int) substr($key, 1)] ?? null;
                if (empty($t) || $t->is_main) {
                    continue;
                }
                $lines[] = [(int) $t->id, null, ! empty($l['contact_id']) ? (int) $l['contact_id'] : null, $debit, $credit, $l['note'] ?? null];
            }
        }
        $dr = round(array_sum(array_column($lines, 3)), 2);
        $cr = round(array_sum(array_column($lines, 4)), 2);
        if (count($lines) < 2 || $dr != $cr || $dr == 0) {
            return redirect()->back()->withInput()->with('status', ['success' => 0,
                'msg' => 'Debits ('.number_format($dr, 2).') and credits ('.number_format($cr, 2).') must be equal, with at least two lines']);
        }
        $date = $request->input('entry_date') ? $util->uf_date($request->input('entry_date')).' '.date('H:i:s') : now()->toDateTimeString();
        $ref = trim((string) $request->input('ref_no')) ?: 'JV-'.date('ymdHis');
        $memo = mb_substr(trim((string) $request->input('memo')), 0, 250);
        DB::transaction(function () use ($request, $lines, $date, $util, $ref, $memo) {
            // a line on a payment account (cash / bank) also goes on the Payment Accounts screen, so both balances agree
            foreach ($lines as &$l) {
                if ($l[1]) {
                    $l[6] = \App\AccountTransaction::createAccountTransaction([
                        'amount' => $l[3] > 0 ? $l[3] : $l[4], 'account_id' => $l[1], 'type' => $l[3] > 0 ? 'credit' : 'debit',
                        'operation_date' => $date, 'created_by' => auth()->id(), 'note' => trim('Manual journal '.$ref.($memo ? ' - '.$memo : '')),
                    ])->id;
                }
            }
            unset($l);
            $id = DB::table('ledger_journals')->insertGetId([
                'business_id' => $this->businessId(), 'location_id' => $request->input('location_id') ?: null,
                'entry_date' => $date, 'source_type' => 'manual', 'source_id' => null,
                'ref_no' => $ref, 'memo' => $memo, 'created_by' => auth()->id(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $util->insertLines($id, $lines);
        });

        return redirect()->action([self::class, 'journals'], ['source_type' => 'manual', 'start_date' => \Carbon::parse($date)->format('Y-m-d'), 'end_date' => date('Y-m-d') > \Carbon::parse($date)->format('Y-m-d') ? date('Y-m-d') : \Carbon::parse($date)->format('Y-m-d')])
            ->with('status', ['success' => 1, 'msg' => 'Journal saved']);
    }

    public function destroyJournal($id)
    {
        $this->authorizeAccess();
        $j = DB::table('ledger_journals')->where('business_id', $this->businessId())->where('id', $id)->where('source_type', 'manual')->first();
        if (empty($j)) {
            return redirect()->back()->with('status', ['success' => 0, 'msg' => 'Only manual journals can be deleted; the others follow the POS records']);
        }
        DB::transaction(function () use ($j) {
            // its Payment Accounts entries go with it
            $at_ids = DB::table('ledger_lines')->where('journal_id', $j->id)->whereNotNull('account_transaction_id')->pluck('account_transaction_id')->all();
            \App\AccountTransaction::whereIn('id', $at_ids)->delete();
            (new LedgerUtil($this->businessId()))->deleteJournals([$j->id]);
        });

        return redirect()->back()->with('status', ['success' => 1, 'msg' => 'Journal deleted']);
    }
}
