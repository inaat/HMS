<?php

namespace App\Http\Controllers;

use App\Business;
use App\Utils\Util;
use App\Utils\ZakatUtil;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Reports > Zakat: the year's calculation (cash + stock + dues - payables vs nisab), zakat given in cash or in goods
 * (the goods mostly from the POS screen's Zakat button) and the slip. Rules: Settings > Business Settings > Zakat.
 */
class ZakatController extends Controller
{
    private function authorizeZakat(): void
    {
        $user = auth()->user();
        if (! $user->can('zakat.manage') && ! (new Util())->is_admin($user)) {
            abort(403, 'Unauthorized action.');
        }
    }

    private function businessId(): int
    {
        return (int) request()->session()->get('user.business_id');
    }

    public function index(Request $request)
    {
        $this->authorizeZakat();
        $business_id = $this->businessId();
        $settings = ZakatUtil::settings($business_id);

        $years = DB::table('zakat_years')->where('business_id', $business_id)->orderByDesc('zakat_date')->orderByDesc('id')->get();
        $year = $request->input('year_id') ? $years->firstWhere('id', (int) $request->input('year_id')) : $years->first();

        // Calculation: a locked year shows its saved numbers; an open one is worked out live for its date.
        $date = $year ? $year->zakat_date : ($settings['zakat_date'] ?: date('Y-m-d'));
        $manual = $year ? (json_decode($year->manual_lines ?? '[]', true) ?: []) : [];
        $calc = $year && $year->status === 'locked'
            ? (array) $year + ['reaches_nisab' => $year->net_wealth >= $year->nisab_value, 'receivables_skipped' => null, 'manual' => array_sum(array_column($manual, 'amount'))]
            : (new ZakatUtil())->calculate($business_id, $date, $settings, $manual);

        $payments = $year ? DB::table('zakat_payments as zp')
            ->leftJoin('accounts as a', 'a.id', '=', 'zp.account_id')
            ->leftJoin('transactions as t', 't.id', '=', 'zp.transaction_id')
            ->leftJoin('users as u', 'u.id', '=', 'zp.created_by')
            ->where('zp.business_id', $business_id)->where('zp.zakat_year_id', $year->id)
            ->select('zp.*', 'a.name as account_name', 't.ref_no',
                DB::raw("TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) as given_by"))
            ->orderByDesc('zp.paid_on')->get() : collect();

        $accounts = DB::table('accounts')->where('business_id', $business_id)->where('is_closed', 0)->whereNull('deleted_at')->pluck('name', 'id');

        return view('zakat.index', compact('settings', 'years', 'year', 'date', 'calc', 'manual', 'payments', 'accounts'));
    }

    /** Save the calculation for a zakat date (new year, or update the open one) with hand-added lines. */
    public function saveYear(Request $request)
    {
        $this->authorizeZakat();
        $business_id = $this->businessId();
        $settings = ZakatUtil::settings($business_id);
        $util = new Util();

        $date = $request->input('zakat_date') ? $util->uf_date($request->input('zakat_date')) : date('Y-m-d');
        $manual = [];
        foreach ((array) $request->input('manual_label', []) as $i => $label) {
            $amount = $util->num_uf($request->input('manual_amount.'.$i));
            if (trim((string) $label) !== '' && $amount != 0) {
                $manual[] = ['label' => trim($label), 'amount' => $amount];
            }
        }
        $calc = (new ZakatUtil())->calculate($business_id, $date, $settings, $manual);
        $data = [
            'maslak' => $settings['maslak'], 'zakat_date' => $date, 'hijri_label' => ZakatUtil::hijri($date),
            'settings' => json_encode($settings), 'cash' => $calc['cash'], 'stock_value' => $calc['stock_value'],
            'receivables' => $calc['receivables'], 'payables' => $calc['payables'], 'manual_lines' => json_encode($manual),
            'net_wealth' => $calc['net_wealth'], 'nisab_value' => $calc['nisab_value'], 'rate' => $calc['rate'],
            'zakat_due' => $calc['zakat_due'], 'updated_at' => now(),
        ];

        $year_id = (int) $request->input('year_id');
        $open = $year_id ? DB::table('zakat_years')->where('business_id', $business_id)->where('id', $year_id)->where('status', 'open')->first() : null;
        if ($open) {
            DB::table('zakat_years')->where('id', $open->id)->update($data);
        } else {
            $year_id = DB::table('zakat_years')->insertGetId($data + ['business_id' => $business_id, 'status' => 'open',
                'created_by' => auth()->id(), 'created_at' => now()]);
        }

        return redirect()->action([self::class, 'index'], ['year_id' => $year_id])->with('status', ['success' => 1, 'msg' => 'Zakat calculation saved']);
    }

    /** Lock a year: its numbers are frozen as saved; a new year opens with the next payment or calculation. */
    public function lockYear($id)
    {
        $this->authorizeZakat();
        DB::table('zakat_years')->where('business_id', $this->businessId())->where('id', $id)->update(['status' => 'locked', 'updated_at' => now()]);

        return redirect()->action([self::class, 'index'], ['year_id' => $id])->with('status', ['success' => 1, 'msg' => 'Zakat year locked']);
    }

    public function payCash(Request $request)
    {
        $this->authorizeZakat();
        $util = new Util();
        $amount = $util->num_uf($request->input('amount'));
        if ($amount <= 0) {
            return redirect()->back()->with('status', ['success' => 0, 'msg' => 'Enter the amount']);
        }
        // "Products — already given before": counts toward the year, no account and no stock change now
        $earlier = $request->input('given_as') === 'earlier_goods';
        $id = (new ZakatUtil())->giveCash($this->businessId(), auth()->id(), [
            'kind' => $earlier ? 'goods' : 'cash',
            'amount' => $amount,
            'account_id' => $earlier ? null : ($request->input('account_id') ?: null),
            'name' => $request->input('recipient_name'),
            'mobile' => $request->input('recipient_mobile'),
            'category' => $request->input('category'),
            'note' => trim(($earlier ? 'Given before — recorded later (stock not changed). ' : '').$request->input('note')),
            'paid_on' => $request->input('paid_on') ? $util->uf_date($request->input('paid_on')).' '.date('H:i:s') : now(),
        ]);

        return redirect()->back()->with('status', ['success' => 1, 'msg' => 'Zakat payment saved'])->with('zakat_slip', $id);
    }

    /**
     * POS screen > Zakat: the cart's products are given as zakat. Stock goes down, no invoice / payment / due;
     * the value counted is what the cashier sees (unit price inc. tax x quantity).
     */
    public function storeFromPos(Request $request)
    {
        try {
            $this->authorizeZakat();
            $business_id = $this->businessId();
            $settings = ZakatUtil::settings($business_id);
            if (! ZakatUtil::goodsAllowed($settings)) {
                return ['success' => 0, 'msg' => 'Giving zakat in products is turned off in Settings > Zakat'];
            }
            $util = new Util();

            $lines = [];
            foreach ((array) $request->input('products', []) as $p) {
                if (empty($p['variation_id']) || empty($p['product_id'])) {
                    continue;
                }
                $qty = (float) $util->num_uf($p['quantity'] ?? 0);
                if ($qty <= 0) {
                    continue;
                }
                $multiplier = ! empty($p['base_unit_multiplier']) ? (float) $util->num_uf($p['base_unit_multiplier']) : 1;
                $price = (float) $util->num_uf($p['unit_price_inc_tax'] ?? ($p['unit_price'] ?? 0));
                $lines[] = [
                    'product_id' => (int) $p['product_id'],
                    'variation_id' => (int) $p['variation_id'],
                    'quantity' => $qty * ($multiplier ?: 1),
                    'value' => $qty * $price,
                ];
            }
            if (empty($lines)) {
                return ['success' => 0, 'msg' => 'Add products first'];
            }

            $contact = DB::table('contacts')->where('business_id', $business_id)->where('id', (int) $request->input('contact_id'))->first();
            $walk_in = empty($contact) || ! empty($contact->is_default);
            $name = trim((string) $request->input('zakat_name')) ?: ($walk_in ? '' : $contact->name);
            if ($name === '') {
                return ['success' => 0, 'msg' => 'Enter the name of the person receiving the zakat'];
            }

            $payment_id = (new ZakatUtil())->giveGoods($business_id, (int) $request->input('location_id'), auth()->id(), $lines, [
                'name' => $name,
                'mobile' => trim((string) $request->input('zakat_mobile')) ?: ($walk_in ? null : $contact->mobile),
                'contact_id' => $walk_in ? null : $contact->id,
                'category' => $request->input('zakat_category'),
                'note' => $request->input('zakat_note'),
            ], (string) $request->session()->get('business.accounting_method'));

            return [
                'success' => 1,
                'msg' => 'Zakat given — stock updated',
                'receipt' => ['is_enabled' => true, 'print_type' => 'browser', 'html_content' => $this->slipHtml($payment_id)],
            ];
        } catch (\Throwable $e) {
            \Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());

            return ['success' => 0, 'msg' => get_class($e) == \App\Exceptions\PurchaseSellMismatch::class ? $e->getMessage() : __('messages.something_went_wrong')];
        }
    }

    public function slip($id)
    {
        $this->authorizeZakat();

        return $this->slipHtml((int) $id, true);
    }

    private function slipHtml(int $payment_id, bool $standalone = false): string
    {
        $business_id = $this->businessId();
        $payment = DB::table('zakat_payments')->where('business_id', $business_id)->where('id', $payment_id)->first();
        abort_if(empty($payment), 404);
        $lines = $payment->transaction_id ? DB::table('stock_adjustment_lines as l')
            ->join('products as p', 'p.id', '=', 'l.product_id')
            ->join('variations as v', 'v.id', '=', 'l.variation_id')
            ->leftJoin('units as u', 'u.id', '=', 'p.unit_id')
            ->where('l.transaction_id', $payment->transaction_id)
            ->select('p.name', 'v.sub_sku', 'l.quantity', 'u.short_name as unit')->get() : collect();
        $business = Business::find($business_id);
        $location = $payment->location_id ? DB::table('business_locations')->find($payment->location_id) : null;

        return view('zakat.slip', compact('payment', 'lines', 'business', 'location', 'standalone'))->render();
    }
}
