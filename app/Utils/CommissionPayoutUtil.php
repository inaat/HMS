<?php

namespace App\Utils;

use App\CommissionAgentRule;
use App\CommissionSettlement;
use App\ExpenseCategory;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Commission agent payouts: what an agent earns for a period, locking it, and paying it as an expense.
 *
 * Sales count in the period they are made (full quantity as sold). A return counts in the period it is
 * MADE, whatever the sale date, so a return of a sale whose commission was already paid is taken off the
 * next payout instead of silently changing a period that is already locked and paid.
 * Same rate rules as the Commission Agent Report: product rule, else brand rule, else the agent's %.
 */
class CommissionPayoutUtil extends Util
{
    protected $transactionUtil;

    public function __construct(TransactionUtil $transactionUtil)
    {
        $this->transactionUtil = $transactionUtil;
    }

    public function agent($business_id, $agent_id)
    {
        return User::where('business_id', $business_id)->where('is_cmmsn_agnt', 1)->find($agent_id);
    }

    public function agentName($agent)
    {
        return trim(implode(' ', array_filter([$agent->surname, $agent->first_name, $agent->last_name])));
    }

    /**
     * Commission of a row (qty, amount, product_id, brand_id) for an agent
     */
    protected function applyRule($row, $agent, $rules)
    {
        $rule = $rules[$agent->id]['p'.$row->product_id] ?? $rules[$agent->id]['b'.$row->brand_id] ?? null;
        if (empty($rule)) {
            $row->commission = $row->amount * (float) $agent->cmmsn_percent / 100;
            $row->rule_text = $this->num_f($agent->cmmsn_percent).'%';
        } elseif ($rule->type == 'fixed') {
            $row->commission = $row->qty * (float) $rule->value;
            $row->rule_text = $this->num_f($rule->value).'/unit';
        } else {
            $row->commission = $row->amount * (float) $rule->value / 100;
            $row->rule_text = $this->num_f($rule->value).'%';
        }
        $row->commission = round($row->commission, 4);

        return $row;
    }

    /**
     * Lines sold by the agent in the period, full quantity as sold (returns are handled on their own date)
     */
    /**
     * Optional location / user filters (Profit / Loss report) on the sale
     */
    protected function applyFilters($query, $filters, $alias = 't')
    {
        if (! empty($filters['location_id'])) {
            $query->where("$alias.location_id", $filters['location_id']);
        }
        if (! empty($filters['permitted_locations']) && $filters['permitted_locations'] != 'all') {
            $query->whereIn("$alias.location_id", $filters['permitted_locations']);
        }
        if (! empty($filters['user_id'])) {
            $query->where("$alias.created_by", $filters['user_id']);
        }

        return $query;
    }

    public function salesLines($business_id, $agent, $start, $end, $filters = [])
    {
        $rules = CommissionAgentRule::forAgents($business_id, [$agent->id]);

        return DB::table('transaction_sell_lines as sl')
            ->join('transactions as t', 't.id', '=', 'sl.transaction_id')
            ->join('products as p', 'p.id', '=', 'sl.product_id')
            ->leftJoin('brands as b', 'b.id', '=', 'p.brand_id')
            ->leftJoin('units as u', 'u.id', '=', 'p.unit_id')
            ->leftJoin('contacts as ct', 'ct.id', '=', 't.contact_id')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->where('t.commission_agent', $agent->id)
            ->whereDate('t.transaction_date', '>=', $start)
            ->whereDate('t.transaction_date', '<=', $end)
            ->tap(fn ($q) => $this->applyFilters($q, $filters))
            ->select(
                't.id as transaction_id', 't.invoice_no', 't.transaction_date',
                DB::raw("COALESCE(NULLIF(ct.supplier_business_name, ''), ct.name) as customer"),
                'p.id as product_id', 'p.name as product', 'p.sku', 'b.id as brand_id', 'b.name as brand', 'u.short_name as unit',
                'sl.unit_price as price',
                DB::raw('sl.quantity as qty'),
                DB::raw('sl.quantity * sl.unit_price as amount')
            )
            ->orderBy('t.transaction_date')->orderBy('sl.id')
            ->get()
            ->map(function ($row) use ($agent, $rules) {
                $row->qty = (float) $row->qty;
                $row->amount = (float) $row->amount;

                return $this->applyRule($row, $agent, $rules);
            });
    }

    /**
     * Returns MADE in the period of the agent's sales (any sale date): against the invoice and without invoice.
     * Valued at the sale price of the line, like the Commission Agent Report.
     */
    public function returnLines($business_id, $agent, $start, $end, $filters = [])
    {
        $rules = CommissionAgentRule::forAgents($business_id, [$agent->id]);
        $without_invoice_qty = '(SELECT COALESCE(SUM(r.quantity), 0) FROM return_sell_lines AS r WHERE r.transaction_sell_id = sl.id)';

        $base = function ($query) use ($business_id, $agent, $start, $end, $filters) {
            $this->applyFilters($query, $filters, 'ret');

            return $query->join('transactions as t', 't.id', '=', 'sl.transaction_id')
                ->join('products as p', 'p.id', '=', 'sl.product_id')
                ->leftJoin('brands as b', 'b.id', '=', 'p.brand_id')
                ->leftJoin('units as u', 'u.id', '=', 'p.unit_id')
                ->leftJoin('contacts as ct', 'ct.id', '=', 'ret.contact_id')
                ->where('ret.business_id', $business_id)
                ->where('ret.type', 'sell_return')
                ->where('ret.status', 'final')
                ->where('t.type', 'sell')
                ->where('t.status', 'final')
                ->where('t.commission_agent', $agent->id)
                ->whereDate('ret.transaction_date', '>=', $start)
                ->whereDate('ret.transaction_date', '<=', $end);
        };
        $columns = fn ($qty) => [
            'ret.id as return_id', 'ret.return_parent_id', 'ret.invoice_no as return_no', 'ret.transaction_date as return_date',
            't.id as transaction_id', 't.invoice_no', 't.transaction_date as sale_date',
            DB::raw("COALESCE(NULLIF(ct.supplier_business_name, ''), ct.name) as customer"),
            'p.id as product_id', 'p.name as product', 'p.sku', 'b.id as brand_id', 'b.name as brand', 'u.short_name as unit',
            'sl.unit_price as price',
            DB::raw("$qty as qty"),
            DB::raw("$qty * sl.unit_price as amount"),
        ];

        $with_invoice = $base(DB::table('transaction_sell_lines as sl')
            ->join('transactions as ret', 'ret.return_parent_id', '=', 'sl.transaction_id'))
            ->whereRaw("sl.quantity_returned - $without_invoice_qty > 0")
            ->select($columns("(sl.quantity_returned - $without_invoice_qty)"))
            ->get();

        $without_invoice = $base(DB::table('return_sell_lines as rsl')
            ->join('transactions as ret', 'ret.id', '=', 'rsl.return_transaction_id')
            ->join('transaction_sell_lines as sl', 'sl.id', '=', 'rsl.transaction_sell_id'))
            ->select($columns('rsl.quantity'))
            ->get();

        return $with_invoice->concat($without_invoice)
            ->map(function ($row) use ($agent, $rules) {
                $row->qty = (float) $row->qty;
                $row->amount = (float) $row->amount;

                return $this->applyRule($row, $agent, $rules);
            })
            ->sortBy('return_date')->values();
    }

    /**
     * Commission for the Profit / Loss report, by the dates of the sales and returns (accrual):
     *   earned   = commission on sales in the dates - commission on returns made in the dates (all agents)
     *   expensed = commission payout expenses dated in the dates (left out of "Expenses" so nothing counts twice)
     *   due      = earned - expensed (earned, not paid yet)
     */
    public function profitLoss($business_id, $start, $end, $location_id = null, $user_id = null, $permitted_locations = null)
    {
        $filters = compact('location_id', 'user_id', 'permitted_locations');
        $out = ['earned' => 0, 'expensed' => 0, 'due' => 0, 'agents' => collect()];

        $agent_ids = User::where('business_id', $business_id)->where('is_cmmsn_agnt', 1)->pluck('id');
        if ($agent_ids->isEmpty()) {
            return $out;
        }

        $out['expensed'] = (float) $this->applyFilters(
            DB::table('transactions as t')->where('t.business_id', $business_id)->where('t.type', 'expense')
                ->whereIn('t.expense_for', $agent_ids)
                ->where('t.expense_category_id', $this->expenseCategory($business_id)->id)
                ->whereDate('t.transaction_date', '>=', $start)->whereDate('t.transaction_date', '<=', $end),
            $filters
        )->sum('t.final_total');

        foreach (User::whereIn('id', $agent_ids)->get() as $agent) {
            $sales = $this->salesLines($business_id, $agent, $start, $end, $filters);
            $returns = $this->returnLines($business_id, $agent, $start, $end, $filters);
            $earned = $sales->sum('commission') - $returns->sum('commission');
            if ($sales->isEmpty() && $returns->isEmpty()) {
                continue;
            }
            $out['agents']->push((object) ['agent_id' => $agent->id, 'agent' => $this->agentName($agent), 'earned' => round($earned, 4)]);
            $out['earned'] += $earned;
        }
        $out['earned'] = round($out['earned'], 4);
        $out['due'] = round($out['earned'] - $out['expensed'], 4);

        return $out;
    }

    /**
     * Latest locked period of the agent that ends before a date (for the carry forward)
     */
    public function previous($business_id, $agent_id, $before)
    {
        return CommissionSettlement::where('business_id', $business_id)->where('agent_id', $agent_id)
            ->whereDate('period_end', '<', $before)->orderByDesc('period_end')->first();
    }

    /**
     * Live figures of a period (nothing saved)
     */
    public function calculate($business_id, $agent, $start, $end)
    {
        $sales = $this->salesLines($business_id, $agent, $start, $end);
        $returns = $this->returnLines($business_id, $agent, $start, $end);
        $previous = $this->previous($business_id, $agent->id, $start);

        $out = [
            'sales' => $sales,
            'returns' => $returns,
            'sales_amount' => round($sales->sum('amount'), 4),
            'sales_commission' => round($sales->sum('commission'), 4),
            'returns_amount' => round($returns->sum('amount'), 4),
            'returns_commission' => round($returns->sum('commission'), 4),
            'carry_brought_forward' => $previous ? (float) $previous->carry_forward : 0,
        ];
        $net = $out['sales_commission'] - $out['returns_commission'] - $out['carry_brought_forward'];
        $out['commission'] = round($net, 4);
        $out['payable'] = $net > 0 ? round($net, 4) : 0;
        $out['carry_forward'] = $net < 0 ? round(-$net, 4) : 0;

        return $out;
    }

    /**
     * Why a period of an agent cannot be locked (null = ok)
     */
    public function periodError($business_id, $agent_id, $start, $end)
    {
        if ($end < $start) {
            return 'The end date is before the start date';
        }
        $settlements = CommissionSettlement::where('business_id', $business_id)->where('agent_id', $agent_id);
        $overlap = (clone $settlements)->whereDate('period_start', '<=', $end)->whereDate('period_end', '>=', $start)->first();
        if ($overlap) {
            return 'This agent is already settled for '.$this->format_date($overlap->period_start).' to '.$this->format_date($overlap->period_end).' (it overlaps)';
        }
        if ((clone $settlements)->whereDate('period_start', '>', $end)->exists()) {
            return 'A later period of this agent is already settled; periods must be paid in date order';
        }

        return null;
    }

    /**
     * The period as it stands: locked settlement (frozen figures) or the live calculation, paid and due
     */
    public function period($business_id, $agent, $start, $end)
    {
        $settlement = CommissionSettlement::where('business_id', $business_id)->where('agent_id', $agent->id)
            ->whereDate('period_start', $start)->whereDate('period_end', $end)->first();
        $live = $this->calculate($business_id, $agent, $start, $end);
        $figures = $settlement ? $settlement->only(['sales_amount', 'sales_commission', 'returns_amount', 'returns_commission',
            'carry_brought_forward', 'commission', 'payable', 'carry_forward', ]) : collect($live)->except(['sales', 'returns'])->all();
        $figures = array_map('floatval', $figures);
        $paid = $settlement ? $settlement->paidAmount() : 0;

        return $figures + [
            'start' => $start,
            'end' => $end,
            'settlement' => $settlement,
            'sales' => $live['sales'],
            'returns' => $live['returns'],
            //a locked period whose sales were edited later: shown, the locked figures stay
            'changed' => $settlement && abs($live['commission'] - (float) $settlement->commission) > 0.01,
            'live_commission' => $live['commission'],
            'error' => $settlement ? null : $this->periodError($business_id, $agent->id, $start, $end),
            'paid' => round($paid, 4),
            'due' => round(max((float) $figures['payable'] - $paid, 0), 4),
        ];
    }

    public function lock($business_id, $agent, $start, $end, $user_id, $note = null)
    {
        $c = $this->calculate($business_id, $agent, $start, $end);

        return CommissionSettlement::create(collect($c)->except(['sales', 'returns'])->all() + [
            'business_id' => $business_id,
            'agent_id' => $agent->id,
            'period_start' => $start,
            'period_end' => $end,
            'locked_by' => $user_id,
            'locked_at' => \Carbon::now(),
            'note' => $note,
        ]);
    }

    /**
     * Expense category for commission payouts (made once per business)
     */
    public function expenseCategory($business_id)
    {
        return ExpenseCategory::firstOrCreate(
            ['business_id' => $business_id, 'name' => 'Sales commission', 'parent_id' => null],
            ['code' => 'COMMISSION']
        );
    }

    /**
     * Pay a settlement: a normal expense (category Sales commission, expense for = the agent) with its payment,
     * so it shows in Expenses, Profit / Loss and the payment account, and is linked to the settlement
     */
    public function payExpense(CommissionSettlement $settlement, $agent, $amount, $data, $user_id)
    {
        $note = 'Commission '.$this->agentName($agent).' '.$this->format_date($settlement->period_start).' ~ '.$this->format_date($settlement->period_end)
            .(! empty($data['note']) ? ' - '.$data['note'] : '');
        //$data['paid_on'] is Y-m-d H:i:s, $amount a plain number: nothing is converted from the business format
        //the expense belongs to the month of the sales (period end); the payment keeps the real pay day
        $request = new Request([
            'transaction_date' => $settlement->period_end->toDateString().' 23:59:00',
            'location_id' => $data['location_id'],
            'final_total' => $amount,
            'expense_for' => $agent->id,
            'expense_category_id' => $this->expenseCategory($settlement->business_id)->id,
            'additional_notes' => $note,
        ]);
        $expense = $this->transactionUtil->createExpense($request, $settlement->business_id, $user_id, false);
        $this->transactionUtil->createOrUpdatePaymentLines($expense, [[
            'amount' => $amount,
            'method' => $data['method'] ?? 'cash',
            'paid_on' => $data['paid_on'],
            'account_id' => $data['account_id'] ?? null,
            'note' => $note,
        ]], $settlement->business_id, $user_id, false);
        $this->transactionUtil->updatePaymentStatus($expense->id, $expense->final_total);
        DB::table('commission_settlement_payments')->insert([
            'settlement_id' => $settlement->id,
            'transaction_id' => $expense->id,
            'created_at' => \Carbon::now(),
            'updated_at' => \Carbon::now(),
        ]);
        $this->transactionUtil->activityLog($expense, 'added');
        //same as an expense added from the Expenses screen (modules like accounting listen to it)
        event(new \App\Events\ExpenseCreatedOrModified($expense));

        return $expense;
    }
}
