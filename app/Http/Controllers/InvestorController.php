<?php

namespace App\Http\Controllers;

use App\Brands;
use App\BusinessLocation;
use App\Investor;
use App\InvestorCapital;
use App\InvestorDeal;
use App\InvestorPayout;
use App\InvestorSettlementLine;
use App\Product;
use App\Utils\InvestorUtil;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Investors, their capital, deals (what they get a profit share of), payouts and statement.
 * Separate from the Accounts module: nothing here writes account transactions or expenses.
 */
class InvestorController extends Controller
{
    protected $investorUtil;

    public static $payment_methods = ['cash' => 'Cash', 'bank_transfer' => 'Bank transfer', 'cheque' => 'Cheque', 'easypaisa' => 'EasyPaisa / JazzCash', 'other' => 'Other'];

    public function __construct(InvestorUtil $investorUtil)
    {
        $this->investorUtil = $investorUtil;
    }

    protected function businessId()
    {
        return request()->session()->get('user.business_id');
    }

    protected function findInvestor($id)
    {
        return Investor::where('business_id', $this->businessId())->findOrFail($id);
    }

    public function index()
    {
        if (! auth()->user()->can('investor.view')) {
            abort(403, 'Unauthorized action.');
        }
        $business_id = $this->businessId();
        $investors = Investor::where('business_id', $business_id)->withCount(['deals' => fn ($q) => $q->where('is_active', 1)])->orderBy('name')->get();
        $balances = $this->investorUtil->balances($business_id);

        return view('investor.index', compact('investors', 'balances'));
    }

    public function create()
    {
        if (! auth()->user()->can('investor.create')) {
            abort(403, 'Unauthorized action.');
        }
        $investor = null;

        return view('investor.form', compact('investor'));
    }

    public function edit($id)
    {
        if (! auth()->user()->can('investor.update')) {
            abort(403, 'Unauthorized action.');
        }
        $investor = $this->findInvestor($id);

        return view('investor.form', compact('investor'));
    }

    public function store(Request $request)
    {
        if (! auth()->user()->can('investor.create')) {
            abort(403, 'Unauthorized action.');
        }
        $request->validate(['name' => 'required|max:191']);
        Investor::create($request->only(['name', 'mobile', 'notes']) + [
            'business_id' => $this->businessId(),
            'is_active' => $request->boolean('is_active', true),
            'created_by' => auth()->id(),
        ]);

        return ['success' => true, 'msg' => 'Investor added'];
    }

    public function update(Request $request, $id)
    {
        if (! auth()->user()->can('investor.update')) {
            abort(403, 'Unauthorized action.');
        }
        $request->validate(['name' => 'required|max:191']);
        $this->findInvestor($id)->update($request->only(['name', 'mobile', 'notes']) + ['is_active' => $request->boolean('is_active')]);

        return ['success' => true, 'msg' => 'Investor updated'];
    }

    public function destroy($id)
    {
        if (! auth()->user()->can('investor.delete')) {
            abort(403, 'Unauthorized action.');
        }
        $investor = $this->findInvestor($id);
        if (InvestorSettlementLine::where('investor_id', $investor->id)->exists() || $investor->payouts()->exists()) {
            return ['success' => false, 'msg' => 'This investor has settlements or payouts; set them inactive instead'];
        }
        DB::transaction(function () use ($investor) {
            $investor->capitals()->delete();
            $investor->deals()->delete();
            $investor->delete();
        });

        return ['success' => true, 'msg' => 'Investor deleted'];
    }

    //---------- capital ----------

    public function capital($id)
    {
        if (! auth()->user()->can('investor.view')) {
            abort(403, 'Unauthorized action.');
        }
        $investor = $this->findInvestor($id);
        $entries = $investor->capitals()->orderByDesc('date')->orderByDesc('id')->get();
        $methods = static::$payment_methods;

        return view('investor.capital', compact('investor', 'entries', 'methods'));
    }

    public function storeCapital(Request $request, $id)
    {
        if (! auth()->user()->can('investor.update')) {
            abort(403, 'Unauthorized action.');
        }
        $investor = $this->findInvestor($id);
        $request->validate(['type' => 'required|in:invest,withdraw', 'amount' => 'required', 'date' => 'required']);
        $amount = $this->investorUtil->num_uf($request->input('amount'));
        if ($amount <= 0) {
            return ['success' => false, 'msg' => 'Enter an amount greater than 0'];
        }
        $date = $this->investorUtil->uf_date($request->input('date'));

        //capital decides capital-based shares: never change it inside a period that is already locked
        $locked_end = $this->lastLockedEnd($investor->business_id);
        if (! empty($locked_end) && $date <= $locked_end) {
            return ['success' => false, 'msg' => 'Profit is locked up to '.$this->investorUtil->format_date($locked_end).'; use a date after it'];
        }
        if ($request->input('type') == 'withdraw') {
            $signed = DB::raw("IF(type = 'invest', amount, -amount)");
            $at_date = (float) $investor->capitals()->whereDate('date', '<=', $date)->sum($signed);
            $now = (float) $investor->capitals()->sum($signed);
            $available = min($at_date, $now);
            if ($amount > $available + 0.0001) {
                return ['success' => false, 'msg' => 'Only '.$this->investorUtil->num_f(max($available, 0), true).' capital can be withdrawn'];
            }
        }

        $investor->capitals()->create([
            'business_id' => $investor->business_id,
            'date' => $date,
            'type' => $request->input('type'),
            'amount' => $amount,
            'method' => $request->input('method'),
            'reference' => $request->input('reference'),
            'note' => $request->input('note'),
            'created_by' => auth()->id(),
        ]);

        return ['success' => true, 'msg' => $request->input('type') == 'invest' ? 'Capital added' : 'Withdrawal saved'];
    }

    public function deleteCapital($id, $capital_id)
    {
        if (! auth()->user()->can('investor.update')) {
            abort(403, 'Unauthorized action.');
        }
        $investor = $this->findInvestor($id);
        $entry = $investor->capitals()->findOrFail($capital_id);
        //capital inside a locked period was used for a capital-based share; keep it
        $locked = \App\InvestorSettlement::where('business_id', $investor->business_id)->where('status', 'locked')
            ->whereDate('period_end', '>=', $entry->date)->exists();
        if ($locked) {
            return ['success' => false, 'msg' => 'This entry is inside or before a locked settlement and cannot be deleted'];
        }
        $entry->delete();

        return ['success' => true, 'msg' => 'Capital entry deleted'];
    }

    //---------- deals ----------

    public function deals($id)
    {
        if (! auth()->user()->can('investor.view')) {
            abort(403, 'Unauthorized action.');
        }
        $business_id = $this->businessId();
        $investor = $this->findInvestor($id);
        $deals = $investor->deals()->orderByDesc('is_active')->orderBy('id')->get();
        $used = InvestorSettlementLine::whereIn('deal_id', $deals->pluck('id'))->distinct()->pluck('deal_id')->all();
        //Brand: name + how many products it has; product: name - SKU (brand), so both can be found by name or SKU
        $brands = Brands::where('brands.business_id', $business_id)
            ->leftJoin('products as p', 'p.brand_id', '=', 'brands.id')
            ->groupBy('brands.id', 'brands.name')
            ->orderBy('brands.name')
            ->select('brands.id', DB::raw("CONCAT(brands.name, ' (', COUNT(p.id), ' products)') as label"))
            ->pluck('label', 'brands.id');
        $products = Product::where('products.business_id', $business_id)
            ->leftJoin('brands as b', 'b.id', '=', 'products.brand_id')
            ->orderBy('products.name')
            ->select('products.id', DB::raw("CONCAT(products.name, IF(products.sku IS NULL OR products.sku = '', '', CONCAT(' - SKU: ', products.sku)), IF(b.name IS NULL, '', CONCAT(' (', b.name, ')'))) as label"))
            ->pluck('label', 'products.id');
        $locations = BusinessLocation::forDropdown($business_id);

        return view('investor.deals', compact('investor', 'deals', 'used', 'brands', 'products', 'locations'));
    }

    public function saveDeals(Request $request, $id)
    {
        if (! auth()->user()->can('investor.update')) {
            abort(403, 'Unauthorized action.');
        }
        $investor = $this->findInvestor($id);
        $rows = [];
        foreach ((array) $request->input('deals', []) as $row) {
            $scope = in_array($row['scope'] ?? '', ['overall', 'brand', 'product']) ? $row['scope'] : 'overall';
            if ($scope == 'brand' && empty($row['brand_id'])) {
                return ['success' => false, 'msg' => 'Select a brand for every brand deal'];
            }
            if ($scope == 'product' && empty($row['product_id'])) {
                return ['success' => false, 'msg' => 'Select a product for every product deal'];
            }
            if (empty($row['start_date'])) {
                return ['success' => false, 'msg' => 'Every deal needs a start date'];
            }
            $share_type = ($row['share_type'] ?? '') == 'capital' ? 'capital' : 'percentage';
            $percent = $this->investorUtil->num_uf($row['share_percent'] ?? 0);
            $pool = $this->investorUtil->num_uf($row['pool_capital'] ?? 0);
            if ($share_type == 'percentage' && ($percent <= 0 || $percent > 100)) {
                return ['success' => false, 'msg' => 'Share % must be between 0 and 100'];
            }
            if ($share_type == 'capital' && $pool <= 0) {
                return ['success' => false, 'msg' => 'Capital-based deals need the total (pool) capital'];
            }
            $rows[] = [
                'id' => ! empty($row['id']) ? (int) $row['id'] : null,
                'scope' => $scope,
                'brand_id' => $scope == 'brand' ? $row['brand_id'] : null,
                'product_id' => $scope == 'product' ? $row['product_id'] : null,
                'location_id' => ! empty($row['location_id']) ? $row['location_id'] : null,
                'share_type' => $share_type,
                'share_percent' => $share_type == 'percentage' ? $percent : 0,
                'pool_capital' => $share_type == 'capital' ? $pool : 0,
                'start_date' => $this->investorUtil->uf_date($row['start_date']),
                'end_date' => ! empty($row['end_date']) ? $this->investorUtil->uf_date($row['end_date']) : null,
                'is_active' => ! empty($row['is_active']) ? 1 : 0,
            ];
        }

        //Locked periods must keep their figures: a deal can't start inside them, and a deal already settled
        //keeps what / where it shares (end it and add a new deal to change that)
        $locked_end = $this->lastLockedEnd($investor->business_id);
        foreach ($rows as $row) {
            $label = ucfirst($row['scope']).' deal';
            if (! empty($row['end_date']) && $row['end_date'] < $row['start_date']) {
                return ['success' => false, 'msg' => $label.': end date is before the start date'];
            }
            $existing = ! empty($row['id']) ? $investor->deals()->find($row['id']) : null;
            $settled = $existing && InvestorSettlementLine::where('deal_id', $existing->id)->exists();
            if ($settled) {
                foreach (['scope', 'brand_id', 'product_id', 'location_id'] as $field) {
                    if ((string) $existing->$field !== (string) $row[$field]) {
                        return ['success' => false, 'msg' => $existing->scopeLabel().' is in a locked settlement: its brand / product / location cannot change. Set an end date and add a new deal.'];
                    }
                }
                if (\Carbon::parse($existing->start_date)->toDateString() != $row['start_date']) {
                    return ['success' => false, 'msg' => $existing->scopeLabel().' is in a locked settlement: its start date cannot change'];
                }
                if (! empty($row['end_date']) && ! empty($locked_end) && $row['end_date'] < $locked_end) {
                    return ['success' => false, 'msg' => $existing->scopeLabel().': end date must be after '.$this->investorUtil->format_date($locked_end).' (profit is locked up to it)'];
                }
            } elseif (! empty($locked_end) && $row['start_date'] <= $locked_end && $row['is_active']
                && (! $existing || \Carbon::parse($existing->start_date)->toDateString() != $row['start_date'] || ! $existing->is_active)) {
                return ['success' => false, 'msg' => $label.': profit is locked up to '.$this->investorUtil->format_date($locked_end).', so the start date must be after it'];
            }
        }

        DB::transaction(function () use ($investor, $rows) {
            $kept = [];
            foreach ($rows as $row) {
                $data = collect($row)->except('id')->all() + ['business_id' => $investor->business_id];
                $deal = ! empty($row['id']) ? $investor->deals()->find($row['id']) : null;
                if ($deal) {
                    $deal->update($data);
                } else {
                    $deal = $investor->deals()->create($data);
                }
                $kept[] = $deal->id;
            }
            //removed rows: deals used in a settlement are only switched off, so locked figures keep their deal
            foreach ($investor->deals()->whereNotIn('id', $kept)->get() as $deal) {
                if (InvestorSettlementLine::where('deal_id', $deal->id)->exists()) {
                    $deal->update(['is_active' => 0]);
                } else {
                    $deal->delete();
                }
            }
        });

        return ['success' => true, 'msg' => 'Deals saved'];
    }

    /**
     * Delete one deal right away (trash button in the Deals window). A deal used in a locked settlement is only
     * switched off, so the locked figures keep their deal.
     */
    public function deleteDeal($id, $deal_id)
    {
        if (! auth()->user()->can('investor.update')) {
            abort(403, 'Unauthorized action.');
        }
        $deal = $this->findInvestor($id)->deals()->findOrFail($deal_id);
        if (InvestorSettlementLine::where('deal_id', $deal->id)->exists()) {
            $deal->update(['is_active' => 0]);

            return ['success' => true, 'msg' => 'This deal is used in a locked settlement, so it was switched off instead of deleted'];
        }
        $deal->delete();

        return ['success' => true, 'msg' => 'Deal deleted'];
    }

    /**
     * Last day of the latest locked settlement (null = nothing locked yet)
     */
    protected function lastLockedEnd($business_id)
    {
        $end = \App\InvestorSettlement::where('business_id', $business_id)->where('status', 'locked')->max('period_end');

        return empty($end) ? null : \Carbon::parse($end)->toDateString();
    }

    //---------- payouts ----------

    public function pay($id)
    {
        if (! auth()->user()->can('investor.payout')) {
            abort(403, 'Unauthorized action.');
        }
        $investor = $this->findInvestor($id);
        $balance = $this->investorUtil->balances($investor->business_id)[$investor->id] ?? ['balance' => 0];
        $methods = static::$payment_methods;

        //a payout is always for a date range (default: the next period not settled yet)
        [$start, $end] = $this->statementPeriod($investor->business_id);
        $period = $this->periodPayable($investor, $start, $end);
        $report = $this->investorUtil->report($investor->business_id, $investor->id, $start, $end);

        return view('investor.pay', compact('investor', 'balance', 'methods', 'period', 'report'));
    }

    /**
     * What an investor gets for a date range: locked figures when the range is already settled, else the live calculation.
     * due = payable - already paid against that range.
     */
    protected function periodPayable($investor, $start, $end)
    {
        $settlement = \App\InvestorSettlement::where('business_id', $investor->business_id)
            ->whereDate('period_start', $start)->whereDate('period_end', $end)->where('status', 'locked')->first();
        $payable = $settlement
            ? (float) $settlement->lines()->where('investor_id', $investor->id)->sum('payable')
            : (float) $this->investorUtil->calculate($investor->business_id, $start, $end, $investor->id)->sum('payable');
        $paid = $settlement ? (float) $investor->payouts()->where('settlement_id', $settlement->id)->sum('amount') : 0;

        return [
            'start' => $start,
            'end' => $end,
            'settlement' => $settlement,
            'error' => $settlement ? null : $this->investorUtil->periodError($investor->business_id, $start, $end),
            'payable' => round($payable, 4),
            'paid' => round($paid, 4),
            'due' => round(max($payable - $paid, 0), 4),
        ];
    }

    public function storePayout(Request $request, $id)
    {
        if (! auth()->user()->can('investor.payout')) {
            abort(403, 'Unauthorized action.');
        }
        $investor = $this->findInvestor($id);
        $amount = $this->investorUtil->num_uf($request->input('amount'));
        if ($amount <= 0) {
            return ['success' => false, 'msg' => 'Enter an amount greater than 0'];
        }

        //Pay for a date range: the range is locked first (if not yet), so the paid profit can never change afterwards
        if (! $request->filled('start') || ! $request->filled('end')) {
            return ['success' => false, 'msg' => 'Select the period (from / to) you are paying for'];
        }
        $period = $this->periodPayable($investor, ...$this->statementPeriod($investor->business_id));
        if (! empty($period['error'])) {
            return ['success' => false, 'msg' => $period['error']];
        }
        if (empty($period['settlement']) && ! auth()->user()->can('investor.settle')) {
            return ['success' => false, 'msg' => 'This period is not locked yet and you have no permission to lock settlements'];
        }
        //never pay the same period twice
        if ($amount > $period['due'] + 0.0001) {
            return ['success' => false, 'msg' => $period['due'] > 0
                ? 'Only '.$this->investorUtil->num_f($period['due'], true).' is due for this period'
                : 'Nothing is due for this period (no profit share, or already paid)'];
        }

        $locked = false;
        try {
            $this->savePayout($request, $investor, $amount, $period, $locked);
        } catch (\RuntimeException $e) {
            return ['success' => false, 'msg' => $e->getMessage()];
        }

        return ['success' => true, 'msg' => 'Payout saved'.($locked ? ' and the period '.$this->investorUtil->format_date($period['start']).' ~ '.$this->investorUtil->format_date($period['end']).' is now locked' : '')];
    }

    /**
     * Lock the period (when paying for one) and save the payout, all or nothing
     */
    protected function savePayout($request, $investor, $amount, $period, &$locked)
    {
        DB::transaction(function () use ($request, $investor, $amount, $period, &$locked) {
            $settlement_id = null;
            if (! empty($period)) {
                $settlement = $period['settlement'];
                if (empty($settlement)) {
                    $settlement = $this->investorUtil->lock($investor->business_id, $period['start'], $period['end'], auth()->id(), 'Locked when paying '.$investor->name);
                    $locked = true;
                }
                $settlement_id = $settlement->id;

                //older payouts not tied to a period also count: never pay more than the investor's total balance
                $balance = $this->investorUtil->balances($investor->business_id)[$investor->id]['balance'] ?? 0;
                if ($amount > $balance + 0.0001) {
                    throw new \RuntimeException('Only '.$this->investorUtil->num_f(max($balance, 0), true).' is still due to '.$investor->name.' (earlier payouts included)');
                }
            }
            InvestorPayout::create([
                'business_id' => $investor->business_id,
                'investor_id' => $investor->id,
                'settlement_id' => $settlement_id,
                'paid_on' => $this->investorUtil->uf_date($request->input('paid_on')),
                'amount' => $amount,
                'method' => $request->input('method'),
                'reference' => $request->input('reference'),
                'note' => $request->input('note'),
                'created_by' => auth()->id(),
            ]);
        });
    }

    public function deletePayout($id, $payout_id)
    {
        if (! auth()->user()->can('investor.payout')) {
            abort(403, 'Unauthorized action.');
        }
        $this->findInvestor($id)->payouts()->findOrFail($payout_id)->delete();

        return ['success' => true, 'msg' => 'Payout deleted'];
    }

    //---------- statement ----------

    /**
     * Period of the statement: ?start / ?end (business date format); default = day after the last settlement
     * (or the 1st of this month) up to today
     */
    protected function statementPeriod($business_id)
    {
        if (request()->filled('start') && request()->filled('end')) {
            return [$this->investorUtil->uf_date(request()->input('start')), $this->investorUtil->uf_date(request()->input('end'))];
        }
        $last = \App\InvestorSettlement::where('business_id', $business_id)->orderByDesc('period_end')->first();
        $start = $last ? \Carbon::parse($last->period_end)->addDay() : \Carbon::now()->startOfMonth();
        $end = \Carbon::today()->lt($start) ? $start->copy() : \Carbon::today();

        return [$start->toDateString(), $end->toDateString()];
    }

    protected function statementData($id, $with_sales = false)
    {
        $investor = $this->findInvestor($id);
        [$start, $end] = $this->statementPeriod($investor->business_id);

        //breakdown of the period + is it already locked, and how much of it is paid
        $report = $this->investorUtil->report($investor->business_id, $investor->id, $start, $end, $with_sales);
        $period = $this->periodPayable($investor, $start, $end);
        $capitals = $investor->capitals()->orderBy('date')->orderBy('id')->get();
        $lines = InvestorSettlementLine::with('settlement')
            ->join('investor_settlements as s', 's.id', '=', 'investor_settlement_lines.settlement_id')
            ->where('investor_settlement_lines.investor_id', $investor->id)->where('s.status', 'locked')
            ->orderBy('s.period_start')->select('investor_settlement_lines.*')->get();
        $payouts = $investor->payouts()->orderBy('paid_on')->orderBy('id')->get();
        $balance = $this->investorUtil->balances($investor->business_id)[$investor->id];
        $deals = $investor->deals()->with(['brand', 'product', 'location'])->where('is_active', 1)->get();
        $methods = static::$payment_methods;
        $business = \App\Business::find($investor->business_id);

        return compact('investor', 'capitals', 'lines', 'payouts', 'balance', 'deals', 'methods', 'business',
            'start', 'end', 'report', 'period');
    }

    public function statement($id)
    {
        if (! auth()->user()->can('investor.view')) {
            abort(403, 'Unauthorized action.');
        }

        return view('investor.statement', $this->statementData($id, true));
    }

    protected function statementPdfFile($id)
    {
        $data = $this->statementData($id) + ['for_pdf' => true];
        $mpdf = $this->getMpdf();
        $mpdf->WriteHTML(view('investor.statement_pdf', $data)->render());
        $path = config('constants.mpdf_temp_path');
        if (! file_exists($path)) {
            mkdir($path, 0777, true);
        }
        $file = $path.'/'.time().'_investor_'.$id.'.pdf';
        $mpdf->Output($file, 'F');

        return [$file, $data];
    }

    public function statementPdf($id)
    {
        if (! auth()->user()->can('investor.view')) {
            abort(403, 'Unauthorized action.');
        }
        [$file, $data] = $this->statementPdfFile($id);

        return response()->file($file, ['Content-Type' => 'application/pdf'])->deleteFileAfterSend(true);
    }

    public function sendStatement($id)
    {
        if (! auth()->user()->can('investor.view')) {
            abort(403, 'Unauthorized action.');
        }
        $investor = $this->findInvestor($id);
        $number = DefaulterController::whatsappNumber($investor->mobile);
        if (empty($number)) {
            return ['success' => false, 'msg' => 'No valid mobile number for '.$investor->name];
        }
        $file = null;
        try {
            [$file, $data] = $this->statementPdfFile($id);
            $caption = 'Dear '.$investor->name.",\nYour investment statement.\n"
                .'Period: '.$this->investorUtil->format_date($data['start']).' ~ '.$this->investorUtil->format_date($data['end'])."\n"
                .'Profit share for this period: '.$this->investorUtil->num_f($data['period']['payable'], true)."\n"
                .'Capital: '.$this->investorUtil->num_f($data['balance']['capital'], true)."\n"
                .'Profit earned: '.$this->investorUtil->num_f($data['balance']['earned'], true)."\n"
                .'Paid: '.$this->investorUtil->num_f($data['balance']['paid'], true)."\n"
                .'Balance due: '.$this->investorUtil->num_f($data['balance']['balance'], true)."\n\n".optional($data['business'])->name;
            $response = (new \App\Services\WhatsappApiService())->sendDocument(\App\WhatsappDevice::instanceFor($investor->business_id), $file, $number, 'Investor-statement-'.str_replace(' ', '-', $investor->name).'.pdf', $caption);
            $output = (empty($response) || ! empty($response['error']))
                ? ['success' => false, 'msg' => 'WhatsApp: '.($response['message'] ?? 'sending failed')]
                : ['success' => true, 'msg' => 'Statement sent on WhatsApp to '.$investor->name];
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $output = ['success' => false, 'msg' => 'WhatsApp server is not responding'];
        } catch (\Exception $e) {
            \Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());
            $output = ['success' => false, 'msg' => __('messages.something_went_wrong')];
        }
        if (! empty($file) && file_exists($file)) {
            unlink($file);
        }

        return $output;
    }
}
