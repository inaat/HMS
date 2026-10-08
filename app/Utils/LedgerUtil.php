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
 * Journals are BUILT FROM the POS records (sales, purchases, payments, expenses ...) by sync(): nothing in the POS's
 * own saving code changes. A journal is written again only when its POS record changed (fingerprint), and removed when
 * the record is gone. Manual journals (source_type 'manual') are never touched by sync.
 *
 * Every journal balances: a few paisa of difference between a POS total and its parts go to "Rounding differences";
 * bigger ones are listed as issues on the Update ledger screen.
 */
class LedgerUtil extends Util
{
    /** Sync steps, in order; each one reads one kind of POS record. */
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
    ];

    const CHUNK = 400;

    /** Labels for journal source types. */
    const SOURCE_LABELS = self::STEPS + ['manual' => 'Manual journal'];

    protected $business_id;

    protected $acc = [];          // system_key => account_types.id

    protected $pay_type = [];     // payment account id => account_types.id it posts to

    protected $exp_cat = [];      // expense category id => account_types.id

    protected $issues = [];

    public static function installed(): bool
    {
        return Schema::hasTable('ledger_journals') && Schema::hasColumn('account_types', 'system_key');
    }

    public function __construct(int $business_id = 0)
    {
        $this->business_id = $business_id;
    }

    /** Chart lookups; adds missing default accounts / new expense categories first. */
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
                ? (int) $a->account_type_id : $this->acc['cash_bank'];
        }
    }

    // ------------------------------------------------------------------ sync

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
     * Returns ['done' => n records read, 'written' => journals (re)written, 'last_id' => .., 'finished' => bool].
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
                ->select('tp.*', 't.type as t_type', 't.contact_id as t_contact', 't.location_id', 't.ref_no as t_ref', 't.invoice_no as t_invoice');
        }
        if ($step === 'account_entry') {
            return DB::table('account_transactions as at')->join('accounts as a', 'a.id', '=', 'at.account_id')
                ->where('a.business_id', $b)->whereNull('at.deleted_at')->whereNull('at.transaction_payment_id')
                ->select('at.*');
        }
        $q = DB::table('transactions as t')->where('t.business_id', $b)->select('t.*');
        switch ($step) {
            case 'sell':
                return $q->where('t.type', 'sell')->where('t.status', 'final');
            case 'sell_return':
                return $q->where('t.type', 'sell_return')->where('t.status', 'final');
            case 'purchase':
                return $q->where('t.type', 'purchase');
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
            ->whereIn('source_id', array_keys($journals))->pluck('fingerprint', 'source_id')->all();
        $old_ids = DB::table('ledger_journals')->where('business_id', $this->business_id)->where('source_type', $step)
            ->whereIn('source_id', array_keys($journals))->pluck('id', 'source_id')->all();

        $written = 0;
        $now = now();
        foreach ($journals as $source_id => $j) {
            $j['lines'] = $this->balance($j['lines'], $step, $source_id, $j['ref_no']);
            if (empty($j['lines'])) {
                if (isset($old_ids[$source_id])) {
                    $this->deleteJournals([$old_ids[$source_id]]);
                }

                continue;
            }
            $fp = md5(json_encode([$j['date'], $j['location_id'], $j['ref_no'], $j['memo'], $j['lines']]));
            if (($existing[$source_id] ?? null) === $fp) {
                continue;
            }
            DB::transaction(function () use ($step, $source_id, $j, $fp, $now, $old_ids) {
                if (isset($old_ids[$source_id])) {
                    $this->deleteJournals([$old_ids[$source_id]]);
                }
                $id = DB::table('ledger_journals')->insertGetId([
                    'business_id' => $this->business_id, 'location_id' => $j['location_id'], 'entry_date' => $j['date'],
                    'source_type' => $step, 'source_id' => $source_id, 'transaction_id' => $j['transaction_id'],
                    'ref_no' => $j['ref_no'], 'memo' => mb_substr((string) $j['memo'], 0, 250), 'fingerprint' => $fp,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                DB::table('ledger_lines')->insert(array_map(function ($l) use ($id) {
                    return ['journal_id' => $id, 'business_id' => $this->business_id, 'account_type_id' => $l[0],
                        'account_id' => $l[1], 'contact_id' => $l[2], 'debit' => $l[3], 'credit' => $l[4], 'note' => $l[5]];
                }, $j['lines']));
            });
            $written++;
        }

        return $written;
    }

    /** Drops zero lines, merges nothing, and puts any difference into Rounding (bigger ones become issues). */
    private function balance(array $lines, string $step, int $source_id, $ref): array
    {
        $out = [];
        foreach ($lines as $l) {
            $d = round((float) $l[3], 4);
            $c = round((float) $l[4], 4);
            if ($d < 0 || $c < 0) { // a negative amount goes on the other side
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

    /** Cost of the sold units per sale (same rule as the POS profit: FIFO purchase price, else the default price). */
    private function saleCosts(array $sale_ids, bool $returned = false): array
    {
        $qty_mapped = $returned ? 'tspl.qty_returned' : 'tspl.quantity';
        $qty_line = $returned ? 'tsl.quantity_returned' : 'tsl.quantity';

        return DB::table('transaction_sell_lines as tsl')
            ->join('products as p', 'p.id', '=', 'tsl.product_id')
            ->join('variations as v', 'v.id', '=', 'tsl.variation_id')
            ->leftJoin('transaction_sell_lines_purchase_lines as tspl', 'tspl.sell_line_id', '=', 'tsl.id')
            ->leftJoin('purchase_lines as pl', 'pl.id', '=', 'tspl.purchase_line_id')
            ->whereIn('tsl.transaction_id', $sale_ids)
            ->groupBy('tsl.transaction_id')
            ->selectRaw("tsl.transaction_id, SUM(IF(p.enable_stock = 0, 0, IF(tspl.id IS NULL,
                IF(p.type = 'combo', 0, {$qty_line} * COALESCE(v.default_purchase_price, 0)),
                {$qty_mapped} * COALESCE(pl.purchase_price, v.default_purchase_price, 0)))) as cost")
            ->pluck('cost', 'transaction_id')->all();
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
            $cost = (float) ($costs[$t->id] ?? 0);
            if ($cost != 0) {
                $lines[] = $this->line('cogs', $cost, 0, null, null, 'Cost of goods sold');
                $lines[] = $this->line('inventory', 0, $cost, null, null, 'Cost of goods sold');
            }
            $out[$t->id] = $this->journal($t, (string) $t->invoice_no, 'Sale '.$t->invoice_no, $lines);
        }

        return $out;
    }

    private function buildSellReturn($rows): array
    {
        $costs = $this->saleCosts($rows->pluck('return_parent_id')->filter()->all(), true);
        $out = [];
        foreach ($rows as $t) {
            $tax = (float) $t->tax_amount;
            $lines = [
                $this->line('receivable', 0, $t->final_total, $t->contact_id),
                $this->line('sales_returns', (float) $t->final_total - $tax, 0),
                $this->line('tax_output', $tax, 0),
            ];
            $cost = (float) ($costs[$t->return_parent_id] ?? 0);
            if ($cost != 0) {
                $lines[] = $this->line('inventory', $cost, 0, null, null, 'Returned to stock');
                $lines[] = $this->line('cogs', 0, $cost, null, null, 'Returned to stock');
            }
            $ref = (string) ($t->invoice_no ?: $t->ref_no);
            $out[$t->id] = $this->journal($t, $ref, 'Sale return '.$ref, $lines);
        }

        return $out;
    }

    private function purchaseLineSums(array $ids, string $qty = 'quantity'): array
    {
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

    private function contactTypes($rows, string $column = 'contact_id'): array
    {
        return DB::table('contacts')->whereIn('id', $rows->pluck($column)->filter()->unique()->all())->pluck('type', 'id')->all();
    }

    private function buildOpeningBalance($rows): array
    {
        $types = $this->contactTypes($rows);
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
            $amount = (float) $t->final_total;
            $lines = [$this->line($account, $amount, 0), $this->line('expenses_payable', 0, $amount)];
            if ($t->type === 'expense_refund') {
                $lines = [$this->line('expenses_payable', $amount, 0), $this->line($account, 0, $amount)];
            }
            $memo = trim(($t->type === 'expense_refund' ? 'Expense refund ' : 'Expense ').$t->ref_no.' '.mb_substr((string) $t->additional_notes, 0, 120));
            $out[$t->id] = $this->journal($t, (string) $t->ref_no, $memo, $lines);
        }

        return $out;
    }

    private function buildStockAdjustment($rows): array
    {
        $ids = $rows->pluck('id')->all();
        $costs = DB::table('stock_adjustment_lines as sal')
            ->leftJoin('transaction_sell_lines_purchase_lines as tspl', 'tspl.stock_adjustment_line_id', '=', 'sal.id')
            ->leftJoin('purchase_lines as pl', 'pl.id', '=', 'tspl.purchase_line_id')
            ->whereIn('sal.transaction_id', $ids)->groupBy('sal.transaction_id')
            ->selectRaw('sal.transaction_id, SUM(IF(tspl.id IS NULL, sal.quantity * sal.unit_price, tspl.quantity * COALESCE(pl.purchase_price, sal.unit_price))) as cost')
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
            if (in_array($type, ['opening_balance', null], true)) {
                $type = ($contact_types[$contact] ?? 'customer') === 'supplier' ? 'purchase' : 'sell';
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
        $zakat = Schema::hasColumn('zakat_payments', 'account_transaction_id')
            ? DB::table('zakat_payments')->whereIn('account_transaction_id', $rows->pluck('id')->all())->pluck('id', 'account_transaction_id')->all() : [];

        $out = [];
        foreach ($rows as $a) {
            if (! empty($a->transaction_id)) {
                continue; // belongs to a POS record that has its own journal
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

    // ------------------------------------------------------------------ reading the books

    /**
     * Balance per account type (and per payment account) as Σdebit − Σcredit, between dates (null = from the start).
     * Returns rows: account_type_id, account_id, debit, credit, net.
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

    /** The chart: main types with their accounts (and payment accounts under the cash & bank types). */
    public function chart()
    {
        $types = DB::table('account_types')->where('business_id', $this->business_id)->orderByRaw('code IS NULL, code')->orderBy('name')->get();
        $accounts = DB::table('accounts')->where('business_id', $this->business_id)->whereNull('deleted_at')->orderBy('name')->get(['id', 'name', 'account_type_id', 'is_closed']);

        return [$types, $accounts];
    }

    /** "+" side of an account for showing balances: debit-normal => Σdr−Σcr, credit-normal => Σcr−Σdr. */
    public static function signed($type, float $net): float
    {
        $debit = $type->debit_increases ?? (in_array($type->classification, ['asset', 'expense']) ? 1 : 0);

        return $debit ? $net : -$net;
    }

    /** Checks: does the ledger match the POS's own numbers? */
    public function checks(): array
    {
        $this->loadChart();
        $bal = $this->balances(null, null);
        $sum = function ($key) use ($bal) {
            return round((float) $bal->where('account_type_id', $this->acc[$key])->sum('net'), 2);
        };
        $checks = [];

        $tb = DB::table('ledger_lines')->where('business_id', $this->business_id)->selectRaw('SUM(debit) d, SUM(credit) c')->first();
        $checks[] = ['label' => 'Trial balance: debits = credits', 'ledger' => round((float) $tb->d, 2), 'pos' => round((float) $tb->c, 2),
            'note' => 'Every journal balances, so the totals must be equal.'];

        $contactUtil = new ContactUtil();
        $customers = $contactUtil->getContactQuery($this->business_id, 'customer')->get();
        $pos_ar = round((float) $customers->sum('for_ordering_total_due'), 2);
        $checks[] = ['label' => 'Accounts receivable = customers\' total due', 'ledger' => $sum('receivable'), 'pos' => $pos_ar,
            'note' => 'POS number: Contacts > Customers, total sale due. Sale returns, ledger discounts and advance payments are in the ledger but not in that column.'];

        $suppliers = $contactUtil->getContactQuery($this->business_id, 'supplier')->get();
        $checks[] = ['label' => 'Accounts payable = suppliers\' total due', 'ledger' => -$sum('payable'),
            'pos' => round((float) $suppliers->sum('display_due'), 2),
            'note' => 'POS number: Contacts > Suppliers, total purchase due. Supplier opening balances and purchase returns are in the ledger.'];

        $stock = round((float) (new TransactionUtil())->getOpeningClosingStock($this->business_id, date('Y-m-d'), null, false, false), 2);
        $checks[] = ['label' => 'Inventory = stock value (by purchase price)', 'ledger' => $sum('inventory'), 'pos' => $stock,
            'note' => 'POS number: Reports > Stock value. Items sold before their purchase was entered are costed at the default purchase price until a purchase covers them.'];

        foreach (DB::table('accounts')->where('business_id', $this->business_id)->whereNull('deleted_at')->get(['id', 'name']) as $a) {
            $pos = (float) DB::table('account_transactions')->where('account_id', $a->id)->whereNull('deleted_at')
                ->selectRaw("SUM(IF(type = 'credit', amount, -amount)) as b")->value('b');
            $checks[] = ['label' => 'Payment account: '.$a->name, 'ledger' => round((float) $bal->where('account_id', $a->id)->sum('net'), 2),
                'pos' => round($pos, 2), 'note' => 'POS number: Payment Accounts balance.'];
        }

        $checks[] = ['label' => 'Rounding differences (should be near 0)', 'ledger' => -$sum('rounding'), 'pos' => 0,
            'note' => 'Small differences between POS totals and their parts. Big ones are listed below.', 'tolerance' => 100];

        foreach ($checks as &$c) {
            $c['ok'] = abs($c['ledger'] - $c['pos']) <= ($c['tolerance'] ?? 1);
        }

        return $checks;
    }
}
