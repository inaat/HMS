<?php

namespace App\Http\Controllers;

use App\Account;
use App\AccountTransaction;
use App\BusinessLocation;
use App\Contact;
use App\Transaction;
use App\Utils\TradeSchemeUtil;
use App\Utils\Util;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Reports > Trade schemes: free goods given (by scheme / product / customer / invoice), and claims of
 * supplier-funded schemes: claim a period from a supplier, print it, then settle it by credit note (a ledger
 * discount on the supplier, which lowers what we owe), cash / bank (a deposit into a payment account) or free stock.
 */
class TradeSchemeReportController extends Controller
{
    protected $util;

    public function __construct(Util $util)
    {
        $this->util = $util;
    }

    private function authorizeView()
    {
        if (! auth()->user()->can('purchase_n_sell_report.view') && ! auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }
    }

    private function period(Request $request): array
    {
        $start = $request->input('start_date') ?: now()->startOfMonth()->format('Y-m-d');
        $end = $request->input('end_date') ?: now()->format('Y-m-d');

        return [$start, $end];
    }

    public function report(Request $request)
    {
        $this->authorizeView();
        $business_id = $request->session()->get('user.business_id');
        [$start, $end] = $this->period($request);
        $filters = $request->only(['scheme_id', 'location_id', 'funded_by', 'supplier_id']);
        $group_by = in_array($request->input('group_by'), ['scheme', 'product', 'customer', 'invoice']) ? $request->input('group_by') : 'scheme';

        $lines = TradeSchemeUtil::freeGoods($business_id, $start, $end, $filters);
        $key = ['scheme' => 'scheme_id', 'product' => 'variation_id', 'customer' => 'contact_id', 'invoice' => 'transaction_id'][$group_by];
        $rows = $lines->groupBy($key)->map(function ($g) use ($group_by) {
            $f = $g->first();
            $label = ['scheme' => $f->code.' — '.$f->scheme_name, 'product' => $f->product, 'customer' => $f->customer ?: '—',
                'invoice' => $f->invoice_no.' · '.\Carbon::parse($f->transaction_date)->format(session('business.date_format'))][$group_by];

            return (object) [
                'label' => $label,
                'sub' => $group_by === 'invoice' ? ($f->customer ?: '') : ($group_by === 'scheme' ? ($f->funded_by === 'supplier' ? 'supplier funded' : 'own') : ''),
                'invoices' => $g->pluck('transaction_id')->unique()->count(),
                'lines' => $g->count(),
                'free_qty' => $g->sum('free_qty'),
                'sale_value' => $g->sum('sale_value'),
                'cost_value' => $g->sum('cost_value'),
            ];
        })->sortByDesc('cost_value')->values();
        $totals = ['invoices' => $lines->pluck('transaction_id')->unique()->count(), 'free_qty' => $lines->sum('free_qty'),
            'sale_value' => $lines->sum('sale_value'), 'cost_value' => $lines->sum('cost_value'),
            'supplier_cost' => $lines->where('funded_by', 'supplier')->sum('cost_value'), 'own_cost' => $lines->where('funded_by', 'own')->sum('cost_value')];

        $schemes = DB::table('trade_schemes')->where('business_id', $business_id)->orderByDesc('id')->get()->mapWithKeys(fn ($s) => [$s->id => $s->code.' — '.$s->name]);
        $locations = BusinessLocation::forDropdown($business_id);
        $suppliers = Contact::suppliersDropdown($business_id, false);

        return view('trade_scheme.report', compact('rows', 'totals', 'start', 'end', 'filters', 'group_by', 'schemes', 'locations', 'suppliers'));
    }

    // ---------------------------------------------------------------- claims

    public function claims(Request $request)
    {
        $this->authorizeView();
        $business_id = $request->session()->get('user.business_id');
        [$start, $end] = $this->period($request);
        $supplier_id = $request->input('supplier_id');

        // what could be claimed now: supplier-funded free goods of the period, per supplier
        $open = TradeSchemeUtil::freeGoods($business_id, $start, $end, ['funded_by' => 'supplier', 'supplier_id' => $supplier_id])
            ->groupBy('supplier_id')->map(fn ($g) => (object) ['supplier_id' => $g->first()->supplier_id, 'free_qty' => $g->sum('free_qty'),
                'cost_value' => $g->sum('cost_value'), 'sale_value' => $g->sum('sale_value'), 'invoices' => $g->pluck('transaction_id')->unique()->count()])
            ->values();
        $claims = DB::table('scheme_claims')->where('business_id', $business_id)
            ->when($supplier_id, fn ($q) => $q->where('supplier_id', $supplier_id))->orderByDesc('id')->limit(200)->get();
        $supplier_names = DB::table('contacts')->where('business_id', $business_id)
            ->whereIn('id', $open->pluck('supplier_id')->merge($claims->pluck('supplier_id'))->filter()->unique()->all() ?: [0])
            ->get()->mapWithKeys(fn ($c) => [$c->id => $c->supplier_business_name ?: $c->name]);
        $suppliers = Contact::suppliersDropdown($business_id, false);

        return view('trade_scheme.claims', compact('open', 'claims', 'supplier_names', 'suppliers', 'start', 'end', 'supplier_id'));
    }

    public function storeClaim(Request $request)
    {
        $this->authorizeView();
        $business_id = $request->session()->get('user.business_id');
        $request->validate(['supplier_id' => 'required|integer', 'start_date' => 'required|date', 'end_date' => 'required|date']);
        $start = $request->input('start_date');
        $end = $request->input('end_date');
        $supplier_id = (int) $request->input('supplier_id');

        $overlap = DB::table('scheme_claims')->where('business_id', $business_id)->where('supplier_id', $supplier_id)
            ->where('period_start', '<=', $end)->where('period_end', '>=', $start)->value('claim_no');
        if ($overlap) {
            return back()->with('status', ['success' => 0, 'msg' => 'Claim '.$overlap.' already covers part of this period for this supplier']);
        }
        $lines = TradeSchemeUtil::freeGoods($business_id, $start, $end, ['funded_by' => 'supplier', 'supplier_id' => $supplier_id]);
        if ($lines->isEmpty()) {
            return back()->with('status', ['success' => 0, 'msg' => 'No supplier-funded free goods in this period']);
        }

        $id = DB::transaction(function () use ($business_id, $supplier_id, $start, $end, $lines) {
            $next = (int) DB::table('scheme_claims')->where('business_id', $business_id)->max('id') + 1;
            $id = DB::table('scheme_claims')->insertGetId([
                'business_id' => $business_id, 'claim_no' => 'CLM-'.str_pad((string) $next, 4, '0', STR_PAD_LEFT), 'supplier_id' => $supplier_id,
                'period_start' => $start, 'period_end' => $end, 'total_value' => round($lines->sum('cost_value'), 2),
                'sale_value' => round($lines->sum('sale_value'), 2), 'status' => 'claimed', 'created_by' => auth()->id(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($lines->groupBy(fn ($l) => $l->scheme_id.'|'.$l->variation_id) as $g) {
                DB::table('scheme_claim_lines')->insert(['scheme_claim_id' => $id, 'trade_scheme_id' => $g->first()->scheme_id,
                    'variation_id' => $g->first()->variation_id, 'free_qty' => $g->sum('free_qty'),
                    'cost_value' => round($g->sum('cost_value'), 4), 'sale_value' => round($g->sum('sale_value'), 4)]);
            }

            return $id;
        });

        return redirect()->action([self::class, 'showClaim'], [$id])->with('status', ['success' => 1, 'msg' => 'Claim created — print it and send it to the supplier']);
    }

    private function claimOrFail(Request $request, $id)
    {
        return DB::table('scheme_claims')->where('business_id', $request->session()->get('user.business_id'))->where('id', $id)->first() ?? abort(404);
    }

    /** Claim page: printable claim letter, and the settle form for staff. */
    public function showClaim(Request $request, $id)
    {
        $this->authorizeView();
        $business_id = $request->session()->get('user.business_id');
        $claim = $this->claimOrFail($request, $id);
        $lines = DB::table('scheme_claim_lines as cl')->join('trade_schemes as s', 's.id', '=', 'cl.trade_scheme_id')
            ->leftJoin('variations as v', 'v.id', '=', 'cl.variation_id')->leftJoin('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoin('units as u', 'u.id', '=', 'p.unit_id')
            ->where('cl.scheme_claim_id', $id)->orderBy('s.code')
            ->get(['cl.*', 's.code', 's.name as scheme_name', 's.free_mode', 's.unit_id as scheme_unit_id', 's.free_unit_id', 's.claim_type',
                'p.name as product_name', 'p.type as product_type', 'v.name as variation_name', 'u.short_name as unit']);
        // stock settlement is entered in the scheme's free unit (e.g. CTN), at the claim's cost per unit
        $unit_names = DB::table('units')->where('business_id', $business_id)->pluck('short_name', 'id');
        foreach ($lines as $l) {
            $free_unit = $l->free_mode === 'same' ? ($l->free_unit_id ?: $l->scheme_unit_id) : $l->free_unit_id;
            $l->stock_unit_mult = TradeSchemeUtil::multiplier($free_unit);
            $l->stock_unit = $free_unit ? ($unit_names[$free_unit] ?? $l->unit) : $l->unit;
            $l->stock_qty = round((float) $l->free_qty / $l->stock_unit_mult, 4);
            $l->stock_cost = (float) $l->free_qty > 0 ? round((float) $l->cost_value / (float) $l->free_qty * $l->stock_unit_mult, 4) : 0;
        }
        $supplier = DB::table('contacts')->where('id', $claim->supplier_id)->first();
        $business = \App\Business::find($business_id);
        $accounts = Account::forDropdown($business_id, true, false, false);
        $locations = BusinessLocation::forDropdown($business_id);
        $purchase = ! empty($claim->purchase_transaction_id) ? DB::table('transactions')->where('id', $claim->purchase_transaction_id)->first(['id', 'ref_no', 'final_total']) : null;
        // how the supplier pays these schemes back (scheme "Claim paid back as"); the most common one is preselected
        $default_settle = $lines->pluck('claim_type')->filter()->countBy()->sortDesc()->keys()->first() ?: 'credit_note';

        return view('trade_scheme.claim_show', compact('claim', 'lines', 'supplier', 'business', 'accounts', 'locations', 'purchase', 'default_settle'));
    }

    /**
     * Settlement in stock: the supplier sent goods for the claim. They come in as a purchase at the claim's cost (so
     * stock value and later profit are right), received at the chosen location, and paid by the claim (a payment
     * "Settled by scheme claim", no money, no account) — the supplier balance does not change.
     *
     * @return float value of the stock received
     */
    private function stockSettlement(Request $request, $claim, $date, string $note): array
    {
        $qty = (array) $request->input('stock_qty');
        $cost = (array) $request->input('stock_cost');
        $location_id = (int) $request->input('location_id');
        $lines = DB::table('scheme_claim_lines as cl')->join('trade_schemes as s', 's.id', '=', 'cl.trade_scheme_id')
            ->join('variations as v', 'v.id', '=', 'cl.variation_id')
            ->where('cl.scheme_claim_id', $claim->id)->get(['cl.*', 'v.product_id', 's.free_mode', 's.unit_id as scheme_unit_id', 's.free_unit_id']);

        $rows = [];
        $total = 0;
        foreach ($lines as $l) {
            $free_unit = $l->free_mode === 'same' ? ($l->free_unit_id ?: $l->scheme_unit_id) : $l->free_unit_id;
            $m = TradeSchemeUtil::multiplier($free_unit);
            $q = (float) $this->util->num_uf((string) ($qty[$l->id] ?? 0)) * $m;      // base units
            $c = (float) $this->util->num_uf((string) ($cost[$l->id] ?? 0)) / $m;     // per base unit
            if ($q <= 0) {
                continue;
            }
            $rows[] = ['product_id' => $l->product_id, 'variation_id' => $l->variation_id, 'quantity' => $q, 'product_unit_id' => null,
                'pp_without_discount' => $c, 'discount_percent' => 0, 'purchase_price' => $c, 'purchase_price_inc_tax' => $c,
                'item_tax' => 0, 'purchase_line_tax_id' => null];
            $total += $q * $c;
        }
        if (empty($rows)) {
            throw new \Exception('Enter the quantity the supplier sent');
        }
        if (! $location_id) {
            throw new \Exception('Choose the location the stock came into');
        }

        $purchase = Transaction::create([
            'business_id' => $claim->business_id, 'location_id' => $location_id, 'type' => 'purchase', 'status' => 'received',
            'contact_id' => $claim->supplier_id, 'ref_no' => $claim->claim_no, 'transaction_date' => $date.' '.now()->format('H:i:s'),
            'total_before_tax' => round($total, 4), 'final_total' => round($total, 4), 'payment_status' => 'due', 'exchange_rate' => 1,
            'additional_notes' => 'Free stock for '.$note, 'created_by' => auth()->id(),
        ]);
        $currency = (object) ['thousand_separator' => '', 'decimal_separator' => '.'];
        (new \App\Utils\ProductUtil())->createOrUpdatePurchaseLines($purchase, $rows, $currency, 0, null);

        // paid by the claim, not with money: purchase credit and this payment cancel out in the supplier ledger
        $tu = new \App\Utils\TransactionUtil();
        $ref_count = $tu->setAndGetReferenceCount('purchase_payment', $claim->business_id);
        \App\TransactionPayment::create([
            'transaction_id' => $purchase->id, 'business_id' => $claim->business_id, 'amount' => round($total, 4), 'method' => 'other',
            'paid_on' => $date.' '.now()->format('H:i:s'), 'created_by' => auth()->id(), 'payment_for' => $claim->supplier_id,
            'payment_ref_no' => $tu->generateReferenceNumber('purchase_payment', $ref_count, $claim->business_id),
            'note' => 'Settled by scheme claim '.$claim->claim_no,
        ]);
        $tu->updatePaymentStatus($purchase->id, $purchase->final_total);

        return [$purchase->id, round($total, 2)];
    }

    public function settleClaim(Request $request, $id)
    {
        $this->authorizeView();
        $claim = $this->claimOrFail($request, $id);
        if ($claim->status === 'received') {
            return back()->with('status', ['success' => 0, 'msg' => 'This claim is already settled']);
        }
        $request->validate(['settled_by' => 'required|in:credit_note,cash,stock', 'settled_at' => 'required']);
        $amount = (float) $this->util->num_uf($request->input('amount'));
        $date = $this->util->uf_date($request->input('settled_at'));
        $note = 'Scheme claim '.$claim->claim_no.' ('.$claim->period_start.' – '.$claim->period_end.')'.($request->input('settlement_ref') ? ' ref '.$request->input('settlement_ref') : '');
        if ($request->input('settled_by') === 'cash' && ! $request->input('account_id')) {
            return back()->with('status', ['success' => 0, 'msg' => 'Choose the account the money came into']);
        }

        try {
            DB::transaction(function () use ($request, $claim, $amount, $date, $note) {
            $update = ['status' => 'received', 'settled_by' => $request->input('settled_by'), 'received_amount' => $amount, 'settled_at' => $date,
                'settlement_ref' => $request->input('settlement_ref'), 'updated_at' => now()];
            if ($request->input('settled_by') === 'stock') {
                [$purchase_id, $value] = $this->stockSettlement($request, $claim, $date, $note);
                $update['purchase_transaction_id'] = $purchase_id;
                $update['received_amount'] = $value;
            } elseif ($request->input('settled_by') === 'credit_note' && $amount > 0) {
                // a ledger discount on the supplier lowers what we owe them (same as Contacts > ledger discount)
                // ref_no = claim no: the supplier ledger shows it as "Scheme claim credit note" (TransactionUtil::getLedgerDetails)
                $t = Transaction::create(['business_id' => $claim->business_id, 'final_total' => $amount, 'total_before_tax' => $amount, 'status' => 'final',
                    'ref_no' => $claim->claim_no,
                    'type' => 'ledger_discount', 'sub_type' => 'purchase_discount', 'contact_id' => $claim->supplier_id, 'created_by' => auth()->id(),
                    'additional_notes' => $note, 'transaction_date' => $date.' '.now()->format('H:i:s')]);
                $update['ledger_transaction_id'] = $t->id;
            } elseif ($request->input('settled_by') === 'cash' && $amount > 0) {
                $a = AccountTransaction::createAccountTransaction(['amount' => $amount, 'account_id' => $request->input('account_id'), 'type' => 'credit',
                    'sub_type' => 'deposit', 'operation_date' => $date.' '.now()->format('H:i:s'), 'created_by' => auth()->id(), 'note' => $note]);
                $update['account_transaction_id'] = $a->id;
            }
            DB::table('scheme_claims')->where('id', $claim->id)->update($update);
            });
        } catch (\Exception $e) {
            return back()->with('status', ['success' => 0, 'msg' => $e->getMessage()]);
        }

        return back()->with('status', ['success' => 1, 'msg' => 'Claim '.$claim->claim_no.' marked as received']);
    }

    /** Undo a settlement (its credit note / deposit is removed) or delete a claim that is not settled. */
    public function destroyClaim(Request $request, $id)
    {
        $this->authorizeView();
        $claim = $this->claimOrFail($request, $id);
        // stock received for the claim can be taken back only while none of it has been sold / returned / adjusted
        if (! empty($claim->purchase_transaction_id)
            && DB::table('purchase_lines')->where('transaction_id', $claim->purchase_transaction_id)
                ->where(fn ($q) => $q->where('quantity_sold', '>', 0)->orWhere('quantity_returned', '>', 0)->orWhere('quantity_adjusted', '>', 0))->exists()) {
            return back()->with('status', ['success' => 0, 'msg' => 'Some of the free stock of this claim is already sold, so the settlement cannot be undone']);
        }
        DB::transaction(function () use ($claim) {
            if (! empty($claim->purchase_transaction_id)) {
                $pu = new \App\Utils\ProductUtil();
                $purchase = DB::table('transactions')->where('id', $claim->purchase_transaction_id)->where('type', 'purchase')->first();
                if ($purchase) {
                    foreach (DB::table('purchase_lines')->where('transaction_id', $purchase->id)->get() as $pl) {
                        $pu->decreaseProductQuantity($pl->product_id, $pl->variation_id, $purchase->location_id, $pl->quantity);
                    }
                    DB::table('transaction_payments')->where('transaction_id', $purchase->id)->delete();
                    DB::table('purchase_lines')->where('transaction_id', $purchase->id)->delete();
                    DB::table('transactions')->where('id', $purchase->id)->delete();
                }
            }
            if ($claim->ledger_transaction_id) {
                Transaction::where('id', $claim->ledger_transaction_id)->where('type', 'ledger_discount')->delete();
            }
            if ($claim->account_transaction_id) {
                AccountTransaction::where('id', $claim->account_transaction_id)->delete();
            }
            if ($claim->status === 'received') {
                DB::table('scheme_claims')->where('id', $claim->id)->update(['status' => 'claimed', 'settled_by' => null, 'received_amount' => null,
                    'settled_at' => null, 'settlement_ref' => null, 'ledger_transaction_id' => null, 'account_transaction_id' => null,
                    'purchase_transaction_id' => null, 'updated_at' => now()]);
            } else {
                DB::table('scheme_claim_lines')->where('scheme_claim_id', $claim->id)->delete();
                DB::table('scheme_claims')->where('id', $claim->id)->delete();
            }
        });

        return $claim->status === 'received'
            ? back()->with('status', ['success' => 1, 'msg' => 'Settlement of '.$claim->claim_no.' undone'])
            : redirect()->action([self::class, 'claims'])->with('status', ['success' => 1, 'msg' => 'Claim '.$claim->claim_no.' deleted']);
    }
}
