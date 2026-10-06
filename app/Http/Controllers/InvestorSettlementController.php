<?php

namespace App\Http\Controllers;

use App\InvestorSettlement;
use App\Utils\InvestorUtil;
use Illuminate\Http\Request;

/**
 * Investor settlements: preview a period (live, nothing saved), lock it (figures frozen), view, unlock the latest.
 */
class InvestorSettlementController extends Controller
{
    protected $investorUtil;

    public function __construct(InvestorUtil $investorUtil)
    {
        $this->investorUtil = $investorUtil;
    }

    protected function businessId()
    {
        return request()->session()->get('user.business_id');
    }

    public function index()
    {
        if (! auth()->user()->can('investor.view')) {
            abort(403, 'Unauthorized action.');
        }
        $business_id = $this->businessId();
        $settlements = InvestorSettlement::where('business_id', $business_id)
            ->withSum('lines', 'payable')->withSum('lines', 'share_amount')->withCount('payouts')
            ->orderByDesc('period_start')->get();
        $last = $settlements->first();
        //suggest the month after the last settlement (or the current month)
        $next_start = $last ? \Carbon::parse($last->period_end)->addDay() : \Carbon::now()->startOfMonth();
        $next_end = $next_start->copy()->endOfMonth();

        return view('investor.settlements.index', compact('settlements', 'next_start', 'next_end'));
    }

    /**
     * Live calculation for a period, nothing saved
     */
    public function preview(Request $request)
    {
        if (! auth()->user()->can('investor.view')) {
            abort(403, 'Unauthorized action.');
        }
        $business_id = $this->businessId();
        $start = $this->investorUtil->uf_date($request->input('start'));
        $end = $this->investorUtil->uf_date($request->input('end'));
        $error = $this->investorUtil->periodError($business_id, $start, $end);
        $lines = $this->investorUtil->calculate($business_id, $start, $end);

        return view('investor.settlements.preview', compact('lines', 'start', 'end', 'error'));
    }

    public function lock(Request $request)
    {
        if (! auth()->user()->can('investor.settle')) {
            abort(403, 'Unauthorized action.');
        }
        $business_id = $this->businessId();
        $start = $this->investorUtil->uf_date($request->input('start'));
        $end = $this->investorUtil->uf_date($request->input('end'));
        $error = $this->investorUtil->periodError($business_id, $start, $end);
        if (! empty($error)) {
            return ['success' => false, 'msg' => $error];
        }
        $settlement = $this->investorUtil->lock($business_id, $start, $end, auth()->id(), $request->input('note'));

        return ['success' => true, 'msg' => 'Settlement locked', 'redirect' => action([static::class, 'show'], [$settlement->id])];
    }

    public function show($id)
    {
        if (! auth()->user()->can('investor.view')) {
            abort(403, 'Unauthorized action.');
        }
        $business_id = $this->businessId();
        $settlement = InvestorSettlement::where('business_id', $business_id)->with(['lines.investor'])->findOrFail($id);

        //live recalculation: warn when sales of this period changed after locking (locked figures stay as they are)
        $live = $this->investorUtil->calculate($business_id, $settlement->period_start, $settlement->period_end)->keyBy('deal_id');
        $changed = [];
        foreach ($settlement->lines as $line) {
            $now = $live[$line->deal_id]['profit_base'] ?? null;
            if ($now !== null && abs($now - (float) $line->profit_base) > 0.5) {
                $changed[$line->deal_id] = $now;
            }
        }
        $is_latest = ! InvestorSettlement::where('business_id', $business_id)->whereDate('period_start', '>', $settlement->period_start)->exists();

        return view('investor.settlements.show', compact('settlement', 'changed', 'is_latest'));
    }

    /**
     * Only the latest locked settlement without payouts can be unlocked (deleted), with a reason
     */
    public function unlock(Request $request, $id)
    {
        if (! auth()->user()->can('investor.settle')) {
            abort(403, 'Unauthorized action.');
        }
        $business_id = $this->businessId();
        $settlement = InvestorSettlement::where('business_id', $business_id)->findOrFail($id);
        if (InvestorSettlement::where('business_id', $business_id)->whereDate('period_start', '>', $settlement->period_start)->exists()) {
            return ['success' => false, 'msg' => 'Only the latest settlement can be unlocked'];
        }
        if ($settlement->payouts()->exists()) {
            return ['success' => false, 'msg' => 'This settlement has payouts linked to it'];
        }
        if (trim((string) $request->input('reason')) === '') {
            return ['success' => false, 'msg' => 'Enter a reason for unlocking'];
        }
        \Log::info('Investor settlement unlocked', [
            'business_id' => $business_id, 'settlement_id' => $settlement->id, 'user_id' => auth()->id(),
            'period' => $settlement->period_start.' - '.$settlement->period_end, 'reason' => $request->input('reason'),
        ]);
        $settlement->lines()->delete();
        $settlement->delete();

        return ['success' => true, 'msg' => 'Settlement unlocked; you can now settle this period again'];
    }
}
