<?php

namespace App\Http\Controllers;

use App\Account;
use App\BusinessLocation;
use App\CommissionSettlement;
use App\User;
use App\Utils\CommissionPayoutUtil;
use App\Utils\ModuleUtil;
use App\Utils\TransactionUtil;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Commission agent payouts: pay an agent for a date range, which locks that range for the agent,
 * creates the expense, and never lets the same range be paid twice.
 */
class CommissionPayoutController extends Controller
{
    protected $payoutUtil;

    protected $transactionUtil;

    protected $moduleUtil;

    public function __construct(CommissionPayoutUtil $payoutUtil, TransactionUtil $transactionUtil, ModuleUtil $moduleUtil)
    {
        $this->payoutUtil = $payoutUtil;
        $this->transactionUtil = $transactionUtil;
        $this->moduleUtil = $moduleUtil;
    }

    protected function businessId()
    {
        return request()->session()->get('user.business_id');
    }

    /**
     * Date from a request: Y-m-d (from the commission report) or the business date format (date pickers)
     */
    protected function inputDate($value)
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value) ? $value : $this->payoutUtil->uf_date($value);
    }

    /**
     * Requested period, or the agent's next unpaid one: day after the last settlement (or the 1st of the month) to today
     */
    protected function requestPeriod($business_id, $agent_id)
    {
        if (request()->filled('start') && request()->filled('end')) {
            return [$this->inputDate(request()->input('start')), $this->inputDate(request()->input('end'))];
        }
        $last = CommissionSettlement::where('business_id', $business_id)->where('agent_id', $agent_id)->max('period_end');
        $start = $last ? \Carbon::parse($last)->addDay() : \Carbon::now()->startOfMonth();
        $end = \Carbon::today()->lt($start) ? $start->copy() : \Carbon::today();

        return [$start->toDateString(), $end->toDateString()];
    }

    public function index()
    {
        if (! auth()->user()->can('sales_representative.view')) {
            abort(403, 'Unauthorized action.');
        }
        $business_id = $this->businessId();
        $agents = User::saleCommissionAgentsDropdown($business_id, false);
        $agent_id = request()->input('agent_id');

        $settlements = CommissionSettlement::with(['agent', 'lockedBy'])
            ->where('business_id', $business_id)
            ->when(! empty($agent_id), fn ($q) => $q->where('agent_id', $agent_id))
            ->orderByDesc('period_end')->orderBy('agent_id')
            ->get()
            ->each(function ($s) {
                $s->paid = $s->paidAmount();
                $s->due = max((float) $s->payable - $s->paid, 0);
            });
        //latest settlement of each agent: only that one can be unlocked
        $latest_ids = CommissionSettlement::where('business_id', $business_id)->groupBy('agent_id')->selectRaw('MAX(id) as id')->pluck('id')->all();

        return view('commission_payout.index', compact('agents', 'agent_id', 'settlements', 'latest_ids'));
    }

    /**
     * Pay window: agent + period, the calculation, and the payment fields
     */
    public function payForm()
    {
        if (! auth()->user()->can('expense.add')) {
            abort(403, 'Unauthorized action.');
        }
        $business_id = $this->businessId();
        $agents = User::saleCommissionAgentsDropdown($business_id, false);
        $agent = $this->payoutUtil->agent($business_id, request()->input('agent_id') ?: $agents->keys()->first());
        $period = null;
        if (! empty($agent)) {
            [$start, $end] = $this->requestPeriod($business_id, $agent->id);
            $period = $this->payoutUtil->period($business_id, $agent, $start, $end);
        }
        $locations = BusinessLocation::where('business_id', $business_id)->pluck('name', 'id');
        $payment_types = $this->transactionUtil->payment_types(null, false, $business_id);
        $accounts = $this->moduleUtil->isModuleEnabled('account') ? Account::forDropdown($business_id, true, false, true) : [];

        return view('commission_payout.pay', compact('agents', 'agent', 'period', 'locations', 'payment_types', 'accounts'));
    }

    public function store(Request $request)
    {
        if (! auth()->user()->can('expense.add')) {
            abort(403, 'Unauthorized action.');
        }
        $business_id = $this->businessId();
        $agent = $this->payoutUtil->agent($business_id, $request->input('agent_id'));
        if (empty($agent)) {
            return ['success' => false, 'msg' => 'Select a commission agent'];
        }
        if (! $request->filled('start') || ! $request->filled('end')) {
            return ['success' => false, 'msg' => 'Select the period (from / to) you are paying for'];
        }
        if (! $request->filled('location_id')) {
            return ['success' => false, 'msg' => 'Select the location the expense is recorded in'];
        }
        $amount = round((float) $this->payoutUtil->num_uf($request->input('amount')), 2);
        if ($amount <= 0) {
            return ['success' => false, 'msg' => 'Enter an amount greater than 0'];
        }
        [$start, $end] = $this->requestPeriod($business_id, $agent->id);
        $paid_on = $request->filled('paid_on')
            ? $this->payoutUtil->uf_date($request->input('paid_on')).' '.\Carbon::now()->format('H:i:s')
            : \Carbon::now()->toDateTimeString();

        try {
            $result = DB::transaction(function () use ($business_id, $agent, $start, $end, $amount, $request, $paid_on) {
                //one payment at a time per agent, so two clicks / two users can't both pay the same due
                User::where('id', $agent->id)->lockForUpdate()->first();
                $period = $this->payoutUtil->period($business_id, $agent, $start, $end);
                if (! empty($period['error'])) {
                    throw new \RuntimeException($period['error']);
                }
                if ($amount > $period['due'] + 0.009) {
                    throw new \RuntimeException($period['due'] > 0
                        ? 'Only '.$this->payoutUtil->num_f($period['due'], true).' is due for this period'
                        : 'Nothing is due for this period (no commission, or already paid)');
                }
                $locked = false;
                $settlement = $period['settlement'];
                if (empty($settlement)) {
                    $settlement = $this->payoutUtil->lock($business_id, $agent, $start, $end, auth()->id(), 'Locked when paying');
                    $locked = true;
                }
                $expense = $this->payoutUtil->payExpense($settlement, $agent, $amount, [
                    'paid_on' => $paid_on,
                    'location_id' => $request->input('location_id'),
                    'method' => $request->input('method', 'cash'),
                    'account_id' => $request->input('account_id'),
                    'note' => $request->input('note'),
                ], auth()->id());

                return compact('locked', 'expense');
            });
        } catch (\RuntimeException $e) {
            return ['success' => false, 'msg' => $e->getMessage()];
        } catch (\Exception $e) {
            \Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());

            return ['success' => false, 'msg' => __('messages.something_went_wrong')];
        }

        return ['success' => true, 'msg' => 'Commission paid: expense '.$result['expense']->ref_no.' saved'
            .($result['locked'] ? '; '.$this->payoutUtil->format_date($start).' ~ '.$this->payoutUtil->format_date($end).' is now locked for '.$this->payoutUtil->agentName($agent) : ''), ];
    }

    /**
     * One settlement: frozen figures, the sales / returns behind them, and the expenses that paid it
     */
    public function show($id)
    {
        if (! auth()->user()->can('sales_representative.view')) {
            abort(403, 'Unauthorized action.');
        }
        $business_id = $this->businessId();
        $settlement = CommissionSettlement::with(['agent', 'lockedBy'])->where('business_id', $business_id)->findOrFail($id);
        $agent = $settlement->agent;
        $period = $this->payoutUtil->period($business_id, $agent, $settlement->period_start->toDateString(), $settlement->period_end->toDateString());
        $expenses = $settlement->expenses()->with('payment_lines')->orderBy('transaction_date')->get();
        $is_latest = ! CommissionSettlement::where('business_id', $business_id)->where('agent_id', $agent->id)->where('period_start', '>', $settlement->period_start)->exists();

        return view('commission_payout.show', compact('settlement', 'agent', 'period', 'expenses', 'is_latest'));
    }

    /**
     * Unlock = delete the agent's latest settlement, only when nothing is paid on it (delete its expenses first)
     */
    public function unlock(Request $request, $id)
    {
        if (! auth()->user()->can('expense.delete')) {
            abort(403, 'Unauthorized action.');
        }
        $business_id = $this->businessId();
        $settlement = CommissionSettlement::where('business_id', $business_id)->findOrFail($id);
        $later = CommissionSettlement::where('business_id', $business_id)->where('agent_id', $settlement->agent_id)
            ->where('period_start', '>', $settlement->period_start)->exists();
        if ($later) {
            return ['success' => false, 'msg' => 'Only the latest period of this agent can be unlocked'];
        }
        if ($settlement->expenses()->exists()) {
            return ['success' => false, 'msg' => 'This period has commission expenses; delete them in Expenses first'];
        }
        if (! $request->filled('reason')) {
            return ['success' => false, 'msg' => 'Enter the reason for unlocking'];
        }
        \Log::info('Commission settlement '.$settlement->id.' (agent '.$settlement->agent_id.', '.$settlement->period_start->toDateString().' ~ '
            .$settlement->period_end->toDateString().') unlocked by user '.auth()->id().': '.$request->input('reason'));
        DB::table('commission_settlement_payments')->where('settlement_id', $settlement->id)->delete();
        $settlement->delete();

        return ['success' => true, 'msg' => 'Period unlocked; it can be paid again'];
    }
}
