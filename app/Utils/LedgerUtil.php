<?php

namespace App\Utils;

use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Double-entry books (Accounting menu), Zoho Books style.
 *
 * The chart of accounts is the POS's account types (Payment Accounts > Account Types, filled by ChartOfAccountsSeeder);
 * payment accounts (shafiq, Cash with booker ...) post under "Cash & bank" with their own account_id.
 * Journals are BUILT FROM the POS records (sales, purchases, payments, expenses ...) by the Update ledger screen:
 * nothing in the POS's own saving code changes. A journal is written again only when its POS record changed
 * (fingerprint), and removed when the record is gone. Manual journals (source_type 'manual') are never touched.
 *
 * Every journal balances: a few paisa of difference between a POS total and its parts go to "Rounding differences";
 * bigger ones are listed as issues on the Update ledger screen.
 */
class LedgerUtil extends Util
{
    /** Update steps, in order; each one reads one kind of POS record. */
    const STEPS = [
        'sell' => 'Sales',
        'sell_return' => 'Sale returns',
        'purchase' => 'Purchases',
        'purchase_return' => 'Purchase returns',
        'opening_stock' => 'Opening stock',
        'opening_balance' => 'Opening balances',
        'ledger_discount' => 'Ledger discounts',
        'expense' => 'Expenses',
        'stock_adjustment' => 'Stock adjustments',
        'payment' => 'Payments',
        'account_entry' => 'Account deposits & transfers',
        'investor_capital' => 'Investor capital',
        'investor_settlement' => 'Investor profit shares',
        'investor_payout' => 'Investor payouts',
    ];

    /** Labels for journal source types. */
    const SOURCE_LABELS = self::STEPS + ['manual' => 'Manual journal'];

    const CHUNK = 400;

    protected $business_id;

    protected $acc = [];          // system_key => account_types.id

    protected $pay_type = [];     // payment account id => account_types.id it posts to

    protected $exp_cat = [];      // expense category id => account_types.id

    protected $issues = [];

    protected $agents = [];       // commission agent id => user row (cmmsn_percent, name)

    protected $commission_rules = [];

    protected $commission_cat = null; // expense category "Sales commission" (payouts)

    public static function installed(): bool
    {
        return Schema::hasTable('ledger_journals') && Schema::hasColumn('account_types', 'system_key');
    }

    public function __construct(int $business_id = 0)
    {
        $this->business_id = $business_id;
    }

    /** Chart lookups; adds missing default accounts / new expense categories / new payment accounts first. */
    public function loadChart(): void
    {
        ChartOfAccountsSeeder::seedBusiness($this->business_id);
        $types = DB::table('account_types')->where('business_id', $this->business_id)->get();
        $this->acc = $types->whereNotNull('system_key')->pluck('id', 'system_key')->all();
        $this->exp_cat = $types->whereNotNull('expense_category_id')->pluck('id', 'expense_category_id')->all();
        $asset_types = $types->where('classification', 'asset')->pluck('id')->all();
        foreach (DB::table('accounts')->where('business_id', $this->business_id)->get(['id', 'account_type_id']) as $a) {
            // a payment account always posts to an asset type (its own one, or Cash & bank)
            $this->pay_type[$a->id] = in_array($a->account_type_id, $asset_types) && $a->account_type_id != $this->acc['cash_unassigned']
                ? (int) $a->account_type_id : $this->acc['cash_accounts'];
        }

        // Sales commission agents: commission is booked when earned (sale / return), payouts clear what is owed
        $this->agents = DB::table('users')->where('business_id', $this->business_id)->where('is_cmmsn_agnt', 1)
            ->get(['id', 'cmmsn_percent', 'surname', 'first_name', 'last_name'])->keyBy('id')->all();
        $this->commission_rules = $this->agents && Schema::hasTable('commission_agent_rules')
            ? \App\CommissionAgentRule::forAgents($this->business_id, array_keys($this->agents)) : [];
        $this->commission_cat = DB::table('expense_categories')->where('business_id', $this->business_id)
            ->where('name', 'Sales commission')->whereNull('parent_id')->whereNull('deleted_at')->value('id');
    }

    /** Expense account the agents' commission is booked to. */
    private function commissionAccount(): int
    {
        return (int) ($this->exp_cat[$this->commission_cat] ?? $this->acc['expense_other']);
    }

    /** Same rate rules as the POS commission reports: product rule, else brand rule, else the agent's %. */
    private function commissionOf($agent_id, $product_id, $brand_id, float $qty, float $amount): float
    {
        $agent = $this->agents[$agent_id] ?? null;
        if (empty($agent)) {
            return 0;
        }
        $rule = $this->commission_rules[$agent_id]['p'.$product_id] ?? $this->commission_rules[$agent_id]['b'.$brand_id] ?? null;
        if (empty($rule)) {
            return round($amount * (float) $agent->cmmsn_percent / 100, 4);
        }

        return round($rule->type == 'fixed' ? $qty * (float) $rule->value : $amount * (float) $rule->value / 100, 4);
    }

    /** Commission earned per sale (all its lines as sold, like the POS commission reports). */
    private function saleCommissions($rows): array
    {
        $ids = $rows->whereIn('commission_agent', array_keys($this->agents))->pluck('id')->all();
        if (empty($ids)) {
            return [];
        }
        $out = [];
        $lines = DB::table('transaction_sell_lines as sl')->join('transactions as t', 't.id', '=', 'sl.transaction_id')
            ->join('products as p', 'p.id', '=', 'sl.product_id')->whereIn('sl.transaction_id', $ids)
            ->get(['sl.transaction_id', 't.commission_agent', 'sl.product_id', 'p.brand_id', 'sl.quantity', 'sl.unit_price']);
        foreach ($lines as $l) {
            $out[$l->transaction_id] = ($out[$l->transaction_id] ?? 0)
                + $this->commissionOf($l->commission_agent, $l->product_id, $l->brand_id, (float) $l->quantity, (float) $l->quantity * (float) $l->unit_price);
        }

        return $out;
    }

    /** Commission taken back per return (the returned quantity at the sale price; with and without invoice). */
    private function returnCommissions($rows): array
    {
        if (empty($this->agents)) {
            return [];
        }
        $agent_ids = array_keys($this->agents);
        $without_qty = Schema::hasTable('return_sell_lines')
            ? '(SELECT COALESCE(SUM(r.quantity), 0) FROM return_sell_lines AS r WHERE r.transaction_sell_id = sl.id)' : '0';
        $out = [];
        $with = DB::table('transaction_sell_lines as sl')->join('transactions as t', 't.id', '=', 'sl.transaction_id')
            ->join('transactions as ret', 'ret.return_parent_id', '=', 't.id')->join('products as p', 'p.id', '=', 'sl.product_id')
            ->whereIn('ret.id', $rows->pluck('id')->all())->whereIn('t.commission_agent', $agent_ids)
            ->whereRaw("sl.quantity_returned - {$without_qty} > 0")
            ->get(['ret.id as return_id', 't.commission_agent', 'sl.product_id', 'p.brand_id', 'sl.unit_price', DB::raw("sl.quantity_returned - {$without_qty} as qty")]);
        $without = Schema::hasTable('return_sell_lines') ? DB::table('return_sell_lines as rsl')
            ->join('transaction_sell_lines as sl', 'sl.id', '=', 'rsl.transaction_sell_id')->join('transactions as t', 't.id', '=', 'sl.transaction_id')
            ->join('products as p', 'p.id', '=', 'sl.product_id')
            ->whereIn('rsl.return_transaction_id', $rows->pluck('id')->all())->whereIn('t.commission_agent', $agent_ids)
            ->get(['rsl.return_transaction_id as return_id', 't.commission_agent', 'sl.product_id', 'p.brand_id', 'sl.unit_price', 'rsl.quantity as qty']) : collect();
        foreach ($with->concat($without) as $l) {
            $out[$l->return_id] = ($out[$l->return_id] ?? 0)
                + $this->commissionOf($l->commission_agent, $l->product_id, $l->brand_id, (float) $l->qty, (float) $l->qty * (float) $l->unit_price);
        }

        return $out;
    }

    public function accountId(string $key): int
    {
        return (int) $this->acc[$key];
    }

    // ------------------------------------------------------------------ update (sync)

    /** Counts per step, for the progress bar. */
    public function totals(): array
    {
        $out = [];
        foreach (array_keys(self::STEPS) as $step) {
            $out[$step] = (clone $this->sourceQuery($step))->count();
        }

        return $out;
    }

    /**
     * One batch of one step: builds the journals of the next CHUNK records after $after_id.
     * Returns ['done' => records read, 'written' => journals (re)written, 'removed' => .., 'last_id' => .., 'finished' => bool].
     */
    public function syncChunk(string $step, int $after_id): array
    {
        $rows = (clone $this->sourceQuery($step))->where($this->idColumn($step), '>', $after_id)
            ->orderBy($this->idColumn($step))->limit(self::CHUNK)->get();
        $finished = $rows->count() < self::CHUNK;
        $last_id = $rows->isEmpty() ? $after_id : (int) $rows->last()->id;

        $journals = $rows->isEmpty() ? [] : $this->{'build'.str_replace('_', '', ucwords($step, '_'))}($rows);
        $written = $this->store($step, $journals);

        // Journals of records that are gone / no longer count (deleted, made draft ...) in the range just read
        $range = DB::table('ledger_journals')->where('business_id', $this->business_id)->where('source_type', $step)
            ->where('source_id', '>', $after_id);
        if (! $finished) {
            $range->where('source_id', '<=', $last_id);
        }
        $stale = $range->whereNotIn('source_id', array_keys($journals) ?: [0])->pluck('id');
        $this->deleteJournals($stale->all());

        return ['done' => $rows->count(), 'written' => $written, 'removed' => $stale->count(), 'last_id' => $last_id,
            'finished' => $finished, 'issues' => $this->issues];
    }

    private function idColumn(string $step): string
    {
        return $step === 'payment' ? 'tp.id' : ($step === 'account_entry' ? 'at.id' : 't.id');
    }

    /** The POS records each step reads (business scoped). */
    private function sourceQuery(string $step)
    {
        $b = $this->business_id;
        if ($step === 'payment') {
            return DB::table('transaction_payments as tp')
                ->leftJoin('transactions as t', 't.id', '=', 'tp.transaction_id')
                ->where('tp.business_id', $b)->whereNull('tp.parent_id')
                // like the POS dues: payments of draft / quotation sales do not count
                ->where(function ($q) {
                    $q->whereNull('t.id')->orWhereNotIn('t.type', ['sell', 'sell_return'])->orWhere('t.status', 'final');
                })
                ->select('tp.*', 't.type as t_type', 't.contact_id as t_contact', 't.location_id', 't.ref_no as t_ref', 't.invoice_no as t_invoice');
        }
        if ($step === 'account_entry') {
            return DB::table('account_transactions as at')->join('accounts as a', 'a.id', '=', 'at.account_id')
                ->where('a.business_id', $b)->whereNull('at.deleted_at')->whereNull('at.transaction_payment_id')
                ->select('at.*');
        }
        if (str_starts_with($step, 'investor_')) {
            // investor module (may not be set up on this install)
            $table = ['investor_capital' => 'investor_capitals', 'investor_settlement' => 'investor_settlement_lines', 'investor_payout' => 'investor_payouts'][$step];
            if (! Schema::hasTable($table)) {
                return DB::table('transactions as t')->whereRaw('1 = 0')->select('t.*');
            }
            if ($step === 'investor_settlement') {
                return DB::table('investor_settlement_lines as t')->join('investor_settlements as s', 's.id', '=', 't.settlement_id')
                    ->leftJoin('investors as i', 'i.id', '=', 't.investor_id')
                    ->where('s.business_id', $b)->where('s.status', 'locked')
                    ->select('t.*', 's.period_start', 's.period_end', 'i.name as investor');
            }

            return DB::table($table.' as t')->leftJoin('investors as i', 'i.id', '=', 't.investor_id')
                ->where('t.business_id', $b)->select('t.*', 'i.name as investor');
        }
        $q = DB::table('transactions as t')->where('t.business_id', $b)->select('t.*');
        switch ($step) {
            case 'sell':
                return $q->where('t.type', 'sell')->where('t.status', 'final');
            case 'sell_return':
                return $q->where('t.type', 'sell_return')->where('t.status', 'final');
            case 'expense':
                return $q->whereIn('t.type', ['expense', 'expense_refund']);
            default:
                return $q->where('t.type', $step);
        }
    }

    /**
     * Writes the built journals; one whose fingerprint did not change is left alone.
     * $journals: source_id => [date, location_id, transaction_id, ref_no, memo, lines => [[type, account, contact, debit, credit, note]]]
     */
    private function store(string $step, array $journals): int
    {
        if (empty($journals)) {
            return 0;
        }
        $existing = DB::table('ledger_journals')->where('business_id', $this->business_id)->where('source_type', $step)
            ->whereIn('source_id', array_keys($journals))->get(['id', 'source_id', 'fingerprint'])->keyBy('source_id');

        $written = 0;
        $now = now();
        foreach ($journals as $source_id => $j) {
            $j['lines'] = $this->balance($j['lines'], $step, $source_id, $j['ref_no']);
            $old = $existing[$source_id] ?? null;
            if (empty($j['lines'])) {
                if ($old) {
                    $this->deleteJournals([$old->id]);
                }

                continue;
            }
            $fp = md5(json_encode([$j['date'], $j['location_id'], $j['ref_no'], $j['memo'], $j['lines']]));
            if ($old && $old->fingerprint === $fp) {
                continue;
            }
            DB::transaction(function () use ($step, $source_id, $j, $fp, $now, $old) {
                if ($old) {
                    $this->deleteJournals([$old->id]);
                }
                $id = DB::table('ledger_journals')->insertGetId([
                    'business_id' => $this->business_id, 'location_id' => $j['location_id'], 'entry_date' => $j['date'],
                    'source_type' => $step, 'source_id' => $source_id, 'transaction_id' => $j['transaction_id'],
                    'ref_no' => $j['ref_no'], 'memo' => mb_substr((string) $j['memo'], 0, 250), 'fingerprint' => $fp,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                $this->insertLines($id, $j['lines']);
            });
            $written++;
        }

        return $written;
    }

    public function insertLines(int $journal_id, array $lines): void
    {
        DB::table('ledger_lines')->insert(array_map(function ($l) use ($journal_id) {
            return ['journal_id' => $journal_id, 'business_id' => $this->business_id, 'account_type_id' => $l[0],
                'account_id' => $l[1], 'contact_id' => $l[2], 'debit' => $l[3], 'credit' => $l[4], 'note' => $l[5],
                'account_transaction_id' => $l[6] ?? null];
        }, $lines));
    }

    /** Drops zero lines, moves negative amounts to the other side, and puts any difference into Rounding. */
    private function balance(array $lines, string $step, int $source_id, $ref): array
    {
        $out = [];
        foreach ($lines as $l) {
            $d = round((float) $l[3], 4);
            $c = round((float) $l[4], 4);
            if ($d < 0 || $c < 0) {
                [$d, $c] = [max(0, $d) + max(0, -$c), max(0, $c) + max(0, -$d)];
            }
            if (abs($d) < 0.00005 && abs($c) < 0.00005) {
                continue;
            }
            $out[] = [(int) $l[0], $l[1] ? (int) $l[1] : null, $l[2] ? (int) $l[2] : null, $d, $c, $l[5] ?? null];
        }
        $diff = round(array_sum(array_column($out, 3)) - array_sum(array_column($out, 4)), 4);
        if (abs($diff) >= 0.00005 && ! empty($out)) {
            $out[] = [$this->acc['rounding'], null, null, $diff < 0 ? -$diff : 0, $diff > 0 ? $diff : 0, 'Rounding'];
            if (abs($diff) >= 1) {
                $this->issues[] = ['step' => $step, 'id' => $source_id, 'ref' => $ref, 'diff' => $diff];
            }
        }

        return $out;
    }

    public function deleteJournals(array $ids): void
    {
        if (! empty($ids)) {
            DB::table('ledger_lines')->whereIn('journal_id', $ids)->delete();
            DB::table('ledger_journals')->whereIn('id', $ids)->delete();
        }
    }

    // ------------------------------------------------------------------ builders

    private function line($key, $debit, $credit, $contact = null, $account = null, $note = null): array
    {
        return [is_int($key) ? $key : $this->acc[$key], $account, $contact, $debit, $credit, $note];
    }

    private function discountAmount($t): float
    {
        $amount = (float) $t->discount_amount;

        return $t->discount_type === 'percentage' ? (float) $t->total_before_tax * $amount / 100 : $amount;
    }

    private function journal($t, string $ref, string $memo, array $lines, $date = null): array
    {
        return ['date' => $date ?: $t->transaction_date, 'location_id' => $t->location_id ?? null, 'transaction_id' => $t->id,
            'ref_no' => $ref, 'memo' => $memo, 'lines' => $lines];
    }

    /**
     * Cost of the sold units per sale, sold less returned (same rule as the POS profit and stock value: FIFO purchase
     * price). 'cost' = from purchases (Inventory); 'unmatched' = sold while the stock was 0, at the default purchase price.
     */
    private function saleCosts(array $sale_ids): array
    {
        if (empty($sale_ids)) {
            return [];
        }

        return DB::table('transaction_sell_lines as tsl')
            ->join('products as p', 'p.id', '=', 'tsl.product_id')
            ->join('variations as v', 'v.id', '=', 'tsl.variation_id')
            ->leftJoin('transaction_sell_lines_purchase_lines as tspl', 'tspl.sell_line_id', '=', 'tsl.id')
            ->leftJoin('purchase_lines as pl', 'pl.id', '=', 'tspl.purchase_line_id')
            ->whereIn('tsl.transaction_id', $sale_ids)
            ->where('p.enable_stock', 1)
            ->groupBy('tsl.transaction_id')
            ->selectRaw("tsl.transaction_id,
                SUM(IF(pl.id IS NOT NULL, (tspl.quantity - tspl.qty_returned) * pl.purchase_price, 0)) as cost,
                SUM(IF(pl.id IS NOT NULL, 0, IF(tspl.id IS NULL, IF(p.type = 'combo', 0, tsl.quantity - tsl.quantity_returned),
                    tspl.quantity - tspl.qty_returned) * COALESCE(v.default_purchase_price, 0))) as unmatched")
            ->get()->keyBy('transaction_id')->all();
    }

    /** Cost lines of a sale: Cost of goods sold against Inventory / Stock sold before purchase entered. */
    private function costLines($c): array
    {
        $lines = [];
        foreach (['inventory' => (float) ($c->cost ?? 0), 'inventory_unmatched' => (float) ($c->unmatched ?? 0)] as $stock => $amount) {
            if ($amount != 0) {
                $lines[] = $this->line('cogs', $amount, 0, null, null, 'Cost of goods sold');
                $lines[] = $this->line($stock, 0, $amount, null, null, 'Cost of goods sold');
            }
        }

        return $lines;
    }

    private function buildSell($rows): array
    {
        $ids = $rows->pluck('id')->all();
        $sums = DB::table('transaction_sell_lines')->whereIn('transaction_id', $ids)
            ->where(function ($q) {
                $q->whereNull('children_type')->orWhere('children_type', '!=', 'combo');
            })
            ->groupBy('transaction_id')
            ->selectRaw('transaction_id, SUM((unit_price_inc_tax - COALESCE(item_tax, 0)) * quantity) as sales, SUM(COALESCE(item_tax, 0) * quantity) as tax')
            ->get()->keyBy('transaction_id');
        $costs = $this->saleCosts($ids);
        $commissions = $this->saleCommissions($rows);

        $out = [];
        foreach ($rows as $t) {
            $s = $sums[$t->id] ?? null;
            $charges = (float) $t->shipping_charges + (float) $t->additional_expense_value_1 + (float) $t->additional_expense_value_2
                + (float) $t->additional_expense_value_3 + (float) $t->additional_expense_value_4 + (float) ($t->packing_charge ?? 0);
            $lines = [
                $this->line('receivable', $t->final_total, 0, $t->contact_id),
                $this->line('sales', 0, $s->sales ?? 0),
                $this->line('tax_output', 0, (float) ($s->tax ?? 0) + (float) $t->tax_amount),
                $this->line('sales_discount', $this->discountAmount($t), 0),
                $this->line('shipping_income', 0, $charges),
            ];
            $lines = array_merge($lines, $this->costLines($costs[$t->id] ?? null));
            // the agent earned commission on this sale: owed to the agent until paid
            if (! empty($commissions[$t->id])) {
                $lines[] = $this->line($this->commissionAccount(), $commissions[$t->id], 0, null, null, 'Commission '.$this->agentName($t->commission_agent));
                $lines[] = $this->line('commission_payable', 0, $commissions[$t->id], null, null, 'Commission '.$this->agentName($t->commission_agent));
            }
            $out[$t->id] = $this->journal($t, (string) $t->invoice_no, 'Sale '.$t->invoice_no, $lines);
        }

        return $out;
    }

    private function buildSellReturn($rows): array
    {
        // The returned items' cost comes off their sale's own cost (saleCosts: sold less returned), as in the POS profit
        $commissions = $this->returnCommissions($rows);
        $out = [];
        foreach ($rows as $t) {
            $tax = (float) $t->tax_amount;
            $lines = [
                $this->line('receivable', 0, $t->final_total, $t->contact_id),
                $this->line('sales_returns', (float) $t->final_total - $tax, 0),
                $this->line('tax_output', $tax, 0),
            ];
            // commission on the returned goods is taken back, on the return's date (as in the POS)
            if (! empty($commissions[$t->id])) {
                $lines[] = $this->line('commission_payable', $commissions[$t->id], 0, null, null, 'Commission on returned goods');
                $lines[] = $this->line($this->commissionAccount(), 0, $commissions[$t->id], null, null, 'Commission on returned goods');
            }
            $ref = (string) ($t->invoice_no ?: $t->ref_no);
            $out[$t->id] = $this->journal($t, $ref, 'Sale return '.$ref, $lines);
        }

        return $out;
    }

    private function purchaseLineSums(array $ids, string $qty = 'quantity'): array
    {
        if (empty($ids)) {
            return [];
        }

        return DB::table('purchase_lines')->whereIn('transaction_id', $ids)->groupBy('transaction_id')
            ->selectRaw("transaction_id, SUM({$qty} * purchase_price) as cost, SUM({$qty} * COALESCE(item_tax, 0)) as tax")
            ->get()->keyBy('transaction_id')->all();
    }

    private function buildPurchase($rows): array
    {
        $sums = $this->purchaseLineSums($rows->pluck('id')->all());
        $out = [];
        foreach ($rows as $t) {
            $s = $sums[$t->id] ?? null;
            $charges = (float) $t->shipping_charges + (float) $t->additional_expense_value_1 + (float) $t->additional_expense_value_2
                + (float) $t->additional_expense_value_3 + (float) $t->additional_expense_value_4;
            $lines = [
                $this->line($t->status === 'received' ? 'inventory' : 'inventory_ordered', $s->cost ?? 0, 0),
                $this->line('tax_input', (float) ($s->tax ?? 0) + (float) $t->tax_amount, 0),
                $this->line('purchase_expenses', $charges, 0),
                $this->line('purchase_discount', 0, $this->discountAmount($t)),
                $this->line('payable', 0, $t->final_total, $t->contact_id),
            ];
            $out[$t->id] = $this->journal($t, (string) $t->ref_no, 'Purchase '.$t->ref_no, $lines);
        }

        return $out;
    }

    private function buildPurchaseReturn($rows): array
    {
        $parent = $this->purchaseLineSums($rows->pluck('return_parent_id')->filter()->all(), 'quantity_returned');
        $own = $this->purchaseLineSums($rows->pluck('id')->all(), 'quantity_returned');
        $out = [];
        foreach ($rows as $t) {
            $s = $t->return_parent_id ? ($parent[$t->return_parent_id] ?? null) : ($own[$t->id] ?? null);
            $cost = (float) ($s->cost ?? 0);
            $tax = (float) ($s->tax ?? 0) + (float) $t->tax_amount;
            // a return priced differently from the cost: the difference is a gain / loss
            $lines = [
                $this->line('payable', $t->final_total, 0, $t->contact_id),
                $this->line('inventory', 0, $cost),
                $this->line('tax_input', 0, $tax),
                $this->line('other_income', 0, (float) $t->final_total - $cost - $tax),
            ];
            $out[$t->id] = $this->journal($t, (string) $t->ref_no, 'Purchase return '.$t->ref_no, $lines);
        }

        return $out;
    }

    private function buildOpeningStock($rows): array
    {
        $sums = $this->purchaseLineSums($rows->pluck('id')->all());
        $out = [];
        foreach ($rows as $t) {
            $cost = (float) ($sums[$t->id]->cost ?? 0);
            $out[$t->id] = $this->journal($t, (string) $t->ref_no, 'Opening stock', [
                $this->line('inventory', $cost, 0),
                $this->line('opening_equity', 0, $cost),
            ]);
        }

        return $out;
    }

    private function buildOpeningBalance($rows): array
    {
        $types = DB::table('contacts')->whereIn('id', $rows->pluck('contact_id')->filter()->unique()->all())->pluck('type', 'id')->all();
        $out = [];
        foreach ($rows as $t) {
            $amount = (float) $t->final_total;
            $lines = ($types[$t->contact_id] ?? 'customer') === 'supplier'
                ? [$this->line('opening_equity', $amount, 0), $this->line('payable', 0, $amount, $t->contact_id)]
                : [$this->line('receivable', $amount, 0, $t->contact_id), $this->line('opening_equity', 0, $amount)];
            $out[$t->id] = $this->journal($t, (string) $t->ref_no, 'Opening balance', $lines);
        }

        return $out;
    }

    private function buildLedgerDiscount($rows): array
    {
        $out = [];
        foreach ($rows as $t) {
            $amount = (float) $t->final_total;
            $lines = $t->sub_type === 'purchase_discount'
                ? [$this->line('payable', $amount, 0, $t->contact_id), $this->line('purchase_discount', 0, $amount)]
                : [$this->line('sales_discount', $amount, 0), $this->line('receivable', 0, $amount, $t->contact_id)];
            $out[$t->id] = $this->journal($t, (string) $t->ref_no, 'Ledger discount', $lines);
        }

        return $out;
    }

    private function buildExpense($rows): array
    {
        $out = [];
        foreach ($rows as $t) {
            $account = $this->exp_cat[$t->expense_category_id] ?? $this->acc['expense_other'];
            // a commission payout pays what the agent earned (already booked on the sales): it clears Commission payable
            if ($this->commission_cat && $t->expense_category_id == $this->commission_cat && isset($this->agents[$t->expense_for])) {
                $account = $this->acc['commission_payable'];
            }
            $amount = (float) $t->final_total;
            $lines = $t->type === 'expense_refund'
                ? [$this->line('expenses_payable', $amount, 0), $this->line($account, 0, $amount)]
                : [$this->line($account, $amount, 0), $this->line('expenses_payable', 0, $amount)];
            $memo = trim(($t->type === 'expense_refund' ? 'Expense refund ' : 'Expense ').$t->ref_no.' '.mb_substr((string) $t->additional_notes, 0, 120));
            $out[$t->id] = $this->journal($t, (string) $t->ref_no, $memo, $lines);
        }

        return $out;
    }

    private function buildStockAdjustment($rows): array
    {
        $costs = DB::table('stock_adjustment_lines as sal')
            ->leftJoin('transaction_sell_lines_purchase_lines as tspl', 'tspl.stock_adjustment_line_id', '=', 'sal.id')
            ->leftJoin('purchase_lines as pl', 'pl.id', '=', 'tspl.purchase_line_id')
            ->whereIn('sal.transaction_id', $rows->pluck('id')->all())->groupBy('sal.transaction_id')
            ->selectRaw('sal.transaction_id, SUM(IF(pl.id IS NULL, IF(tspl.id IS NULL, sal.quantity, tspl.quantity) * sal.unit_price, tspl.quantity * pl.purchase_price)) as cost')
            ->pluck('cost', 'transaction_id')->all();
        $out = [];
        foreach ($rows as $t) {
            $cost = (float) ($costs[$t->id] ?? $t->final_total);
            $zakat = ! empty($t->is_zakat);
            $lines = [
                $this->line($zakat ? 'zakat' : 'stock_loss', $cost, 0),
                $this->line('inventory', 0, $cost),
            ];
            $recovered = (float) ($t->total_amount_recovered ?? 0);
            if ($recovered > 0) {
                $lines[] = $this->line('cash_unassigned', $recovered, 0, null, null, 'Amount recovered');
                $lines[] = $this->line('other_income', 0, $recovered, null, null, 'Amount recovered');
            }
            $out[$t->id] = $this->journal($t, (string) $t->ref_no, ($zakat ? 'Zakat given in goods ' : 'Stock adjustment ').$t->ref_no, $lines);
        }

        return $out;
    }

    /** Where a payment's money is: its payment account, or "Cash in hand (no account)". */
    private function cashLine($account_id, $debit, $credit): array
    {
        if ($account_id && isset($this->pay_type[$account_id])) {
            return $this->line($this->pay_type[$account_id], $debit, $credit, null, (int) $account_id);
        }

        return $this->line('cash_unassigned', $debit, $credit);
    }

    private function buildPayment($rows): array
    {
        // Payments made from the contact's page (no invoice of their own): what they paid decides the side
        $parents = $rows->whereNull('transaction_id')->pluck('id')->all();
        $child_types = $parents ? DB::table('transaction_payments as tp')->join('transactions as t', 't.id', '=', 'tp.transaction_id')
            ->whereIn('tp.parent_id', $parents)->groupBy('tp.parent_id')->selectRaw('tp.parent_id, MIN(t.type) as type')
            ->pluck('type', 'parent_id')->all() : [];
        $contact_ids = $rows->map(function ($p) {
            return $p->payment_for ?: $p->t_contact;
        })->filter()->unique()->all();
        $contact_types = DB::table('contacts')->whereIn('id', $contact_ids)->pluck('type', 'id')->all();

        $out = [];
        foreach ($rows as $p) {
            if ($p->method === 'advance') {
                continue; // paid from the customer's own advance: no money moved
            }
            $contact = $p->payment_for ?: $p->t_contact;
            $type = $p->t_type ?: ($child_types[$p->id] ?? null);
            $supplier = ($contact_types[$contact] ?? 'customer') === 'supplier';
            if (! $p->t_type && in_array($p->payment_type, ['credit', 'debit'])) {
                // paid from the contact's page: the POS marks money in (credit) / out (debit), e.g. advance paid back
                $type = $supplier ? ($p->payment_type === 'debit' ? 'purchase' : 'purchase_return') : ($p->payment_type === 'credit' ? 'sell' : 'sell_return');
            } elseif (in_array($type, ['opening_balance', null], true)) {
                $type = $supplier ? 'purchase' : 'sell';
            }
            $amount = (float) $p->amount;
            switch ($type) {
                case 'sell':
                    $lines = [$this->cashLine($p->account_id, $amount, 0), $this->line('receivable', 0, $amount, $contact)];
                    break;
                case 'sell_return':
                    $lines = [$this->line('receivable', $amount, 0, $contact), $this->cashLine($p->account_id, 0, $amount)];
                    break;
                case 'purchase':
                    $lines = [$this->line('payable', $amount, 0, $contact), $this->cashLine($p->account_id, 0, $amount)];
                    break;
                case 'purchase_return':
                    $lines = [$this->cashLine($p->account_id, $amount, 0), $this->line('payable', 0, $amount, $contact)];
                    break;
                case 'expense':
                    $lines = [$this->line('expenses_payable', $amount, 0), $this->cashLine($p->account_id, 0, $amount)];
                    break;
                case 'expense_refund':
                    $lines = [$this->cashLine($p->account_id, $amount, 0), $this->line('expenses_payable', 0, $amount)];
                    break;
                default:
                    $lines = [$this->line('expense_other', $amount, 0), $this->cashLine($p->account_id, 0, $amount)];
            }
            if (! empty($p->is_return)) { // change given back: the other way round
                $lines = array_map(function ($l) {
                    [$l[3], $l[4]] = [$l[4], $l[3]];

                    return $l;
                }, $lines);
            }
            $ref = (string) $p->payment_ref_no;
            $for = $p->t_invoice ?: $p->t_ref;
            $out[$p->id] = ['date' => $p->paid_on, 'location_id' => $p->location_id, 'transaction_id' => $p->transaction_id,
                'ref_no' => $ref, 'memo' => trim(($p->is_return ? 'Change returned ' : 'Payment ').$ref.($for ? ' for '.$for : '')
                    .($p->note ? ' — '.mb_substr($p->note, 0, 100) : '')), 'lines' => $lines];
        }

        return $out;
    }

    private function buildAccountEntry($rows): array
    {
        // Fund transfers come as a pair (money out of one account, into another): one journal, on the money-out row
        $pairs = DB::table('account_transactions')->whereNull('deleted_at')
            ->where(function ($q) use ($rows) {
                $q->whereIn('id', $rows->pluck('transfer_transaction_id')->filter()->all() ?: [0])
                    ->orWhereIn('transfer_transaction_id', $rows->pluck('id')->all());
            })->get();
        $zakat = Schema::hasTable('zakat_payments') && Schema::hasColumn('zakat_payments', 'account_transaction_id')
            ? DB::table('zakat_payments')->whereIn('account_transaction_id', $rows->pluck('id')->all())->pluck('id', 'account_transaction_id')->all() : [];

        // entries made by manual journals / investor capital & payouts (they have their own journal)
        $ids = $rows->pluck('id')->all();
        $manual = DB::table('ledger_lines')->whereIn('account_transaction_id', $ids)->pluck('account_transaction_id');
        foreach (['investor_capitals', 'investor_payouts'] as $table) {
            if (Schema::hasColumn($table, 'account_transaction_id')) {
                $manual = $manual->merge(DB::table($table)->whereIn('account_transaction_id', $ids)->pluck('account_transaction_id'));
            }
        }
        $manual = $manual->flip();

        $out = [];
        foreach ($rows as $a) {
            if (! empty($a->transaction_id) || isset($manual[$a->id])) {
                continue; // belongs to a POS record / manual journal that has its own journal
            }
            $pair = $pairs->first(function ($x) use ($a) {
                return $x->id == $a->transfer_transaction_id || $x->transfer_transaction_id == $a->id;
            });
            $amount = (float) $a->amount;
            $note = $a->note ? mb_substr($a->note, 0, 120) : null;
            if ($pair) {
                if ($a->type !== 'debit') {
                    continue; // the money-out row writes the transfer
                }
                $lines = [$this->cashLine($pair->account_id, $amount, 0), $this->cashLine($a->account_id, 0, $amount)];
                $memo = 'Fund transfer';
            } elseif (isset($zakat[$a->id])) {
                $lines = [$this->line('zakat', $amount, 0), $this->cashLine($a->account_id, 0, $amount)];
                $memo = 'Zakat paid in cash';
            } elseif ($a->sub_type === 'opening_balance') {
                $lines = $a->type === 'credit'
                    ? [$this->cashLine($a->account_id, $amount, 0), $this->line('opening_equity', 0, $amount)]
                    : [$this->line('opening_equity', $amount, 0), $this->cashLine($a->account_id, 0, $amount)];
                $memo = 'Account opening balance';
            } elseif ($a->type === 'credit') {
                $lines = [$this->cashLine($a->account_id, $amount, 0), $this->line('capital', 0, $amount)];
                $memo = 'Deposit';
            } else {
                $lines = [$this->line('drawings', $amount, 0), $this->cashLine($a->account_id, 0, $amount)];
                $memo = 'Withdrawal';
            }
            $out[$a->id] = ['date' => $a->operation_date, 'location_id' => null, 'transaction_id' => null,
                'ref_no' => $a->reff_no, 'memo' => trim($memo.($note ? ' — '.$note : '')), 'lines' => $lines];
        }

        return $out;
    }

    private function agentName($agent_id): string
    {
        $a = $this->agents[$agent_id] ?? null;

        return $a ? trim(implode(' ', array_filter([$a->surname, $a->first_name, $a->last_name]))) : '#'.$agent_id;
    }

    /** Investor puts money in (capital) or takes it back. */
    private function buildInvestorCapital($rows): array
    {
        $out = [];
        foreach ($rows as $c) {
            $amount = (float) $c->amount;
            $account = $c->account_id ?? null;
            $lines = $c->type === 'invest'
                ? [$this->cashLine($account, $amount, 0), $this->line('investor_capital', 0, $amount)]
                : [$this->line('investor_capital', $amount, 0), $this->cashLine($account, 0, $amount)];
            $out[$c->id] = ['date' => $c->date.' 12:00:00', 'location_id' => null, 'transaction_id' => null, 'ref_no' => $c->reference,
                'memo' => ($c->type === 'invest' ? 'Investor capital from ' : 'Capital returned to ').$c->investor, 'lines' => $lines];
        }

        return $out;
    }

    /** Locked settlement: each investor's share of the profit, owed to them until paid. */
    private function buildInvestorSettlement($rows): array
    {
        $out = [];
        foreach ($rows as $s) {
            $amount = (float) $s->payable;
            $out[$s->id] = ['date' => $s->period_end.' 23:59:00', 'location_id' => null, 'transaction_id' => null,
                'ref_no' => 'IS-'.$s->settlement_id, 'memo' => 'Profit share '.$s->investor.' ('.$s->scope_label.') '.$s->period_start.' to '.$s->period_end,
                'lines' => [$this->line('investor_share', $amount, 0), $this->line('investor_payable', 0, $amount)]];
        }

        return $out;
    }

    private function buildInvestorPayout($rows): array
    {
        $out = [];
        foreach ($rows as $p) {
            $amount = (float) $p->amount;
            $out[$p->id] = ['date' => $p->paid_on.' 12:00:00', 'location_id' => null, 'transaction_id' => null, 'ref_no' => $p->reference,
                'memo' => 'Profit share paid to '.$p->investor,
                'lines' => [$this->line('investor_payable', $amount, 0), $this->cashLine($p->account_id ?? null, 0, $amount)]];
        }

        return $out;
    }

    // ------------------------------------------------------------------ reading the books

    /**
     * Totals per account type (and per payment account) between dates (null = from the start).
     * Rows: account_type_id, account_id, debit, credit, net (= debit − credit).
     */
    public function balances(?string $from, ?string $to, ?int $location_id = null)
    {
        $q = DB::table('ledger_lines as l')->join('ledger_journals as j', 'j.id', '=', 'l.journal_id')
            ->where('l.business_id', $this->business_id);
        if ($from) {
            $q->where('j.entry_date', '>=', $from.' 00:00:00');
        }
        if ($to) {
            $q->where('j.entry_date', '<=', $to.' 23:59:59');
        }
        if ($location_id) {
            $q->where('j.location_id', $location_id);
        }

        return $q->groupBy('l.account_type_id', 'l.account_id')
            ->selectRaw('l.account_type_id, l.account_id, SUM(l.debit) as debit, SUM(l.credit) as credit, SUM(l.debit - l.credit) as net')
            ->get();
    }

    /** "+" side of an account: debit-normal => Σdr−Σcr, credit-normal => Σcr−Σdr. */
    public static function signed($type, float $net): float
    {
        $debit = $type->debit_increases ?? (in_array($type->classification, ['asset', 'expense']) ? 1 : 0);

        return $debit ? $net : -$net;
    }

    /** Checks: does the ledger match the POS's own numbers? */
    public function checks(): array
    {
        $this->loadChart();
        $b = $this->business_id;
        $bal = $this->balances(null, null);
        $sum = function ($key) use ($bal) {
            return round((float) $bal->where('account_type_id', $this->acc[$key])->sum('net'), 2);
        };
        $checks = [];

        $tb = DB::table('ledger_lines')->where('business_id', $b)->selectRaw('SUM(debit) d, SUM(credit) c')->first();
        $checks[] = ['label' => 'Trial balance: debits = credits', 'ledger' => round((float) $tb->d, 2), 'pos' => round((float) $tb->c, 2),
            'note' => 'Every journal balances, so total debits and total credits must be equal.'];

        // POS side, from the POS's own lists plus what those columns leave out
        $contactUtil = new ContactUtil();
        $customers_due = (float) $contactUtil->getContactQuery($b, 'customer')->get()->sum('for_ordering_total_due');
        $suppliers_due = (float) $contactUtil->getContactQuery($b, 'supplier')->get()->sum('display_due');
        $by_type = function ($type, $supplier) use ($b) {
            return (float) DB::table('transactions as t')->join('contacts as c', 'c.id', '=', 't.contact_id')
                ->where('t.business_id', $b)->where('t.type', $type)
                ->where('c.type', $supplier ? '=' : '!=', 'supplier')->sum('t.final_total');
        };
        $paid_on = function ($type, $supplier) use ($b) {
            return (float) DB::table('transaction_payments as tp')->join('transactions as t', 't.id', '=', 'tp.transaction_id')
                ->join('contacts as c', 'c.id', '=', 't.contact_id')->where('t.business_id', $b)->where('t.type', $type)
                ->where('c.type', $supplier ? '=' : '!=', 'supplier')->sum(DB::raw('IF(tp.is_return = 1, -tp.amount, tp.amount)'));
        };
        // payments made from a contact's page that are not yet matched to any invoice / bill (advance)
        $unallocated = function ($supplier) use ($b) {
            return (float) DB::table('transaction_payments as p')->join('contacts as c', 'c.id', '=', 'p.payment_for')
                ->where('p.business_id', $b)->whereNull('p.parent_id')->whereNull('p.transaction_id')->where('p.method', '!=', 'advance')
                ->where('c.type', $supplier ? '=' : '!=', 'supplier')
                ->sum(DB::raw('p.amount - COALESCE((SELECT SUM(ch.amount) FROM transaction_payments ch WHERE ch.parent_id = p.id), 0)'));
        };
        $returns = $by_type('sell_return', false) - $paid_on('sell_return', false);
        $sell_discounts = (float) DB::table('transactions')->where('business_id', $b)->where('type', 'ledger_discount')
            ->where(function ($q) {
                $q->whereNull('sub_type')->orWhere('sub_type', '!=', 'purchase_discount');
            })->sum('final_total');
        $cust_adv = $unallocated(false);
        $checks[] = ['label' => 'Accounts receivable = customers\' total due', 'ledger' => $sum('receivable'),
            'pos' => round($customers_due - $returns - $sell_discounts - $cust_adv, 2),
            'note' => 'POS: Contacts > Customers total due '.number_format($customers_due, 2).' − sale returns not refunded '.number_format($returns, 2)
                .($sell_discounts ? ' − ledger discounts '.number_format($sell_discounts, 2) : '').' − advance payments not yet matched '.number_format($cust_adv, 2)];

        $sup_open = $by_type('opening_balance', true) - $paid_on('opening_balance', true);
        $purchase_returns = $by_type('purchase_return', true) - $paid_on('purchase_return', true);
        $sup_adv = $unallocated(true);
        $checks[] = ['label' => 'Accounts payable = suppliers\' total due', 'ledger' => -$sum('payable'),
            'pos' => round($suppliers_due + $sup_open - $purchase_returns - $sup_adv, 2),
            'note' => 'POS: Contacts > Suppliers total due '.number_format($suppliers_due, 2).' + opening balances still due '.number_format($sup_open, 2)
                .($purchase_returns ? ' − purchase returns '.number_format($purchase_returns, 2) : '').' − advance paid to suppliers, not yet matched '.number_format($sup_adv, 2)];

        $stock = round((float) (new TransactionUtil())->getOpeningClosingStock($b, date('Y-m-d'), 0, false, false), 2);
        $checks[] = ['label' => 'Inventory = stock value (by purchase price)', 'ledger' => $sum('inventory'), 'pos' => $stock,
            'note' => 'POS: Reports > Stock value (by purchase price).'];
        $unmatched = $sum('inventory_unmatched');
        if (abs($unmatched) >= 0.01) {
            $checks[] = ['label' => 'Stock sold before purchase entered', 'ledger' => $unmatched, 'pos' => $unmatched, 'info' => true,
                'note' => 'Cost (at the default purchase price) of items sold while their stock was 0. It moves into Inventory when the missing purchases are entered.'];
        }

        if (! empty($this->agents)) {
            $cp = (new CommissionPayoutUtil(new TransactionUtil()))->profitLoss($b, '2000-01-01', date('Y-m-d'));
            $checks[] = ['label' => 'Commission payable = earned − paid', 'ledger' => -$sum('commission_payable'),
                'pos' => round((float) $cp['earned'] - (float) $cp['expensed'], 2),
                'note' => 'POS: commission earned on all sales less returns '.number_format($cp['earned'], 2).' − commission payouts '.number_format($cp['expensed'], 2)];
        }
        if (Schema::hasTable('investor_capitals')) {
            $inv = (new InvestorUtil(new TransactionUtil()))->balances($b);
            $checks[] = ['label' => 'Investors\' capital', 'ledger' => -$sum('investor_capital'), 'pos' => round(array_sum(array_column($inv, 'capital')), 2),
                'note' => 'POS: Investors, total capital (money in − money back).'];
            $checks[] = ['label' => 'Investor profit payable = earned − paid', 'ledger' => -$sum('investor_payable'), 'pos' => round(array_sum(array_column($inv, 'balance')), 2),
                'note' => 'POS: Investors, profit share of locked settlements − payouts.'];
        }

        foreach (DB::table('accounts')->where('business_id', $b)->whereNull('deleted_at')->get(['id', 'name']) as $a) {
            $pos = (float) DB::table('account_transactions')->where('account_id', $a->id)->whereNull('deleted_at')
                ->selectRaw("SUM(IF(type = 'credit', amount, -amount)) as b")->value('b');
            $checks[] = ['label' => 'Payment account: '.$a->name, 'ledger' => round((float) $bal->where('account_id', $a->id)->sum('net'), 2),
                'pos' => round($pos, 2), 'note' => 'POS: Payment Accounts balance.'];
        }

        $checks[] = ['label' => 'Rounding differences (should be near 0)', 'ledger' => -$sum('rounding'), 'pos' => 0, 'tolerance' => 500,
            'note' => 'Small differences between POS totals and their parts (paisa rounding). Bigger ones are listed below.'];

        foreach ($checks as &$c) {
            $c['ok'] = abs($c['ledger'] - $c['pos']) <= ($c['tolerance'] ?? 1);
        }

        return $checks;
    }
}
