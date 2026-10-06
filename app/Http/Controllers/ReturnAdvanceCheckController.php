<?php

namespace App\Http\Controllers;

use App\Events\TransactionPaymentDeleted;
use App\Transaction;
use App\TransactionPayment;
use App\Utils\TransactionUtil;
use DB;
use Illuminate\Http\Request;

/**
 * Return & advance check: finds sell returns whose credit was never used on the customer's unpaid
 * invoices, and returns "settled" with fake money movements (a payment out on the return plus the same
 * amount paid back in, which turns the return into advance). Every row has its own button; nothing
 * changes until it is clicked. The customer's net balance stays the same, only the records are fixed.
 */
class ReturnAdvanceCheckController extends Controller
{
    protected $transactionUtil;

    public function __construct(TransactionUtil $transactionUtil)
    {
        $this->transactionUtil = $transactionUtil;
    }

    public function index(Request $request)
    {
        if (! auth()->user()->can('customer.update')) {
            abort(403, 'Unauthorized action.');
        }
        $business_id = $request->session()->get('user.business_id');

        return view('report.return_advance_check', [
            'unsettled' => $this->unsettledReturns($business_id),
            //Only returns that left the customer with advance: those must become 0. Pairs whose money was
            //fully used on invoices leave no advance and a correct balance, so they are not listed.
            'fake_pairs' => collect($this->fakeRefundPairs($business_id))->filter(fn ($p) => $p->advance_left >= 0.005)->values()->all(),
            'payment_types' => $this->transactionUtil->payment_types(null, true, $business_id),
        ]);
    }

    /**
     * Repairs every case where a return left advance: all that advance goes to 0
     */
    public function repairAll(Request $request)
    {
        if (! auth()->user()->can('customer.update')) {
            abort(403, 'Unauthorized action.');
        }
        $business_id = $request->session()->get('user.business_id');
        $pairs = collect($this->fakeRefundPairs($business_id))->filter(fn ($p) => $p->advance_left >= 0.005);

        $done = 0;
        $advance = 0;
        foreach ($pairs as $pair) {
            $request->merge(['out_id' => $pair->out_id, 'in_id' => $pair->in_id]);
            $result = $this->repair($request);
            if (! empty($result['success'])) {
                $done++;
                $advance += $pair->advance_left;
            }
        }

        return ['success' => $done > 0 ? 1 : 0, 'msg' => $done > 0
            ? "Repaired $done case(s); advance removed: ".$this->transactionUtil->num_f($advance, true)
            : 'Nothing to repair.'];
    }

    /**
     * Section 1 button: the return pays the customer's unpaid invoices
     */
    public function settle(Request $request, $id)
    {
        if (! auth()->user()->can('customer.update')) {
            abort(403, 'Unauthorized action.');
        }
        $business_id = $request->session()->get('user.business_id');
        $sell_return = Transaction::where('business_id', $business_id)->where('type', 'sell_return')->findOrFail($id);

        DB::beginTransaction();
        try {
            $left = $this->transactionUtil->settleSellReturn($sell_return);
            DB::commit();

            return ['success' => 1, 'msg' => 'Return '.$sell_return->invoice_no.' settled. Credit left: '.$this->transactionUtil->num_f($left, true)];
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());

            return ['success' => 0, 'msg' => __('messages.something_went_wrong')];
        }
    }

    /**
     * Section 2 button: removes the fake payment out on the return and the fake payment in (with the
     * invoices it paid and the advance it created), then the return settles the invoices for real
     */
    public function repair(Request $request)
    {
        if (! auth()->user()->can('customer.update')) {
            abort(403, 'Unauthorized action.');
        }
        $business_id = $request->session()->get('user.business_id');
        $pair = collect($this->fakeRefundPairs($business_id))
                    ->first(fn ($p) => $p->out_id == $request->input('out_id') && $p->in_id == $request->input('in_id'));
        if (empty($pair)) {
            return ['success' => 0, 'msg' => 'This case was already repaired or changed.'];
        }

        DB::beginTransaction();
        try {
            //Payment in (advance): same steps as deleting it on the Payments tab
            $in = TransactionPayment::findOrFail($pair->in_id);
            $children = TransactionPayment::where('parent_id', $in->id)->get();
            $unused = $in->amount - $children->sum('amount');
            if ($unused > 0) {
                $this->transactionUtil->updateContactBalance($in->payment_for, $unused, 'deduct');
            }
            foreach ($children as $child) {
                $child->parent_id = null;
                TransactionPayment::deletePayment($child);
            }
            TransactionPayment::deletePayment($in);

            //Payment out on the return
            TransactionPayment::deletePayment(TransactionPayment::findOrFail($pair->out_id));

            //Now the return pays the invoices itself
            $left = $this->transactionUtil->settleSellReturn(Transaction::find($pair->return_id));
            DB::commit();

            return ['success' => 1, 'msg' => 'Repaired '.$pair->return_no.'. The return now settles the invoices; credit left: '.$this->transactionUtil->num_f($left, true)];
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());

            return ['success' => 0, 'msg' => __('messages.something_went_wrong')];
        }
    }

    /**
     * Returns with credit not paid out and not used, while the same customer has unpaid invoices
     */
    private function unsettledReturns($business_id)
    {
        $paid = '(SELECT COALESCE(SUM(p.amount), 0) FROM transaction_payments p WHERE p.transaction_id = r.id)';

        return DB::table('transactions as r')
                ->join('contacts as c', 'c.id', '=', 'r.contact_id')
                ->where('r.business_id', $business_id)
                ->where('r.type', 'sell_return')
                ->where('r.status', 'final')
                ->whereRaw("r.final_total - $paid > 0.01")
                ->whereExists(fn ($q) => $q->from('transactions as s')->whereColumn('s.contact_id', 'r.contact_id')
                    ->where('s.type', 'sell')->where('s.status', 'final')->where('s.payment_status', '!=', 'paid'))
                ->select('r.id', 'r.invoice_no', 'r.transaction_date', 'r.final_total', 'c.id as contact_id', 'c.name as contact',
                    DB::raw("r.final_total - $paid as credit"),
                    DB::raw("(SELECT COALESCE(SUM(s.final_total - (SELECT COALESCE(SUM(IF(sp.is_return = 1, -sp.amount, sp.amount)), 0) FROM transaction_payments sp WHERE sp.transaction_id = s.id)), 0)
                        FROM transactions s WHERE s.contact_id = r.contact_id AND s.type = 'sell' AND s.status = 'final' AND s.payment_status != 'paid') as customer_due"))
                ->orderBy('r.transaction_date')
                ->get();
    }

    /**
     * Payment out on a return + payment in (advance, no invoice) of the same customer, same method,
     * same amount, within a day: the return was "refunded" and the money "received back"
     */
    private function fakeRefundPairs($business_id)
    {
        $outs = DB::table('transaction_payments as o')
                ->join('transactions as r', 'r.id', '=', 'o.transaction_id')
                ->join('contacts as c', 'c.id', '=', 'r.contact_id')
                ->where('r.business_id', $business_id)
                ->where('r.type', 'sell_return')
                ->where('o.method', '!=', TransactionUtil::RETURN_ADJUSTMENT_METHOD)
                ->whereNull('o.parent_id')
                ->select('o.id as out_id', 'o.payment_ref_no as out_ref', 'o.amount', 'o.method', 'o.paid_on', 'r.id as return_id', 'r.invoice_no as return_no', 'r.final_total as return_total', 'c.id as contact_id', 'c.name as contact')
                ->orderBy('o.paid_on')
                ->get();
        if ($outs->isEmpty()) {
            return [];
        }

        //All advance payments (money in, no invoice) of these contacts in one query, matched in PHP
        $ins = DB::table('transaction_payments as i')
                ->where('i.business_id', $business_id)
                ->whereIn('i.payment_for', $outs->pluck('contact_id')->unique())
                ->where('i.is_advance', 1)
                ->whereNull('i.transaction_id')
                ->whereNull('i.parent_id')
                ->where(fn ($q) => $q->whereNull('i.payment_type')->orWhere('i.payment_type', 'credit'))
                ->select('i.id', 'i.payment_ref_no', 'i.payment_for', 'i.method', 'i.amount', 'i.paid_on')
                ->get()
                ->groupBy('payment_for');

        $pairs = [];
        $used_in = [];
        foreach ($outs as $out) {
            $out_time = strtotime($out->paid_on);
            $in = collect($ins[$out->contact_id] ?? [])
                    ->filter(fn ($i) => ! isset($used_in[$i->id]) && $i->method == $out->method
                        && abs($i->amount - $out->amount) < 0.01 && abs(strtotime($i->paid_on) - $out_time) <= 86400)
                    ->sortBy(fn ($i) => abs(strtotime($i->paid_on) - $out_time))
                    ->first();
            if (empty($in)) {
                continue;
            }
            $used_in[$in->id] = true;
            $out->in_id = $in->id;
            $out->in_ref = $in->payment_ref_no;
            $out->in_paid_on = $in->paid_on;
            $out->in_amount = $in->amount;
            $pairs[] = $out;
        }

        //Invoices each payment in was used for, in one query
        $children = DB::table('transaction_payments as ch')
                        ->join('transactions as t', 't.id', '=', 'ch.transaction_id')
                        ->whereIn('ch.parent_id', array_column($pairs, 'in_id'))
                        ->select('ch.parent_id', 't.invoice_no', 'ch.amount')
                        ->get()
                        ->groupBy('parent_id');
        foreach ($pairs as $pair) {
            $pair->used_for = collect($children[$pair->in_id] ?? []);
            $pair->advance_left = $pair->in_amount - $pair->used_for->sum('amount');
        }

        return $pairs;
    }
}
