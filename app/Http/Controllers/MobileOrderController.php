<?php

namespace App\Http\Controllers;

use App\Services\MobileSync\MobileInbox;
use App\Services\MobileSync\SyncStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Sell > Mobile orders: orders and collections order bookers sent from the app (see config/mobile_sync.php),
 * waiting for staff to approve or reject. An approved order becomes a Sales Order; Make invoice opens Add Sale
 * with that sales order picked.
 */
class MobileOrderController extends Controller
{
    private function authorizeAccess()
    {
        if (! auth()->user()->can('sell.create') && ! auth()->user()->can('so.create') && ! auth()->user()->can('direct_sell.access')) {
            abort(403, 'Unauthorized action.');
        }
    }

    /** Staff may only act on orders of the locations they can access in the POS. */
    private function authorizeRow($id): void
    {
        $location = DB::table('mobile_inbox')->where('id', $id)->value('location_id') ?: (int) config('mobile_sync.location_id');
        $permitted = auth()->user()->permitted_locations();
        if ($permitted !== 'all' && ! in_array($location, (array) $permitted)) {
            abort(403, 'Unauthorized action.');
        }
    }

    private function inbox(): MobileInbox
    {
        return new MobileInbox(request()->session()->get('user.business_id'), config('mobile_sync.location_id'));
    }

    public function index(Request $request)
    {
        $this->authorizeAccess();

        $kind = $request->input('kind') === 'payment' ? 'payment' : 'order';
        $status = $request->input('status', 'waiting');

        // Several locations: staff see the orders of the locations they may access in the POS.
        $default = (int) config('mobile_sync.location_id');
        $locations = \App\BusinessLocation::forDropdown(request()->session()->get('user.business_id'));
        $location = (int) $request->input('location_id');
        if (! $locations->has($location)) {
            $location = 0;
        }

        $query = DB::table('mobile_inbox as m')
            ->leftJoin('contacts as c', 'c.id', '=', 'm.contact_id')
            ->leftJoin('users as u', 'u.id', '=', 'm.booker_id')
            ->leftJoin('users as d', 'd.id', '=', 'm.decided_by')
            ->leftJoin('business_locations as bl', 'bl.id', '=', DB::raw("COALESCE(m.location_id, {$default})"))
            ->where('m.kind', $kind)
            ->whereIn(DB::raw("COALESCE(m.location_id, {$default})"), $location ? [$location] : array_keys($locations->toArray()))
            ->select('m.*', 'bl.name as location_name', 'c.name as customer', 'c.supplier_business_name', 'c.mobile as customer_mobile',
                DB::raw("TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) as booker"),
                DB::raw("TRIM(CONCAT(COALESCE(d.first_name, ''), ' ', COALESCE(d.last_name, ''))) as decided_by_name"))
            ->orderByDesc('m.id');
        if ($status !== 'all') {
            $query->where('m.status', $status);
        }

        // Booker and date (booked on the phone) filters.
        $booker = (int) $request->input('booker_id');
        if ($booker) {
            $query->where('m.booker_id', $booker);
        }
        $start_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->input('start_date')) ? $request->input('start_date') : null;
        $end_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->input('end_date')) ? $request->input('end_date') : null;
        if ($start_date && $end_date) {
            $query->whereDate('m.booked_at', '>=', $start_date)->whereDate('m.booked_at', '<=', $end_date);
        }
        // Totals of everything the filters match (all pages): grand total and per booker
        $by_booker = (clone $query)->reorder()->groupBy('m.booker_id', 'u.first_name', 'u.last_name')
            ->select('m.booker_id', DB::raw("TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) as booker"),
                DB::raw('COUNT(*) as count'), DB::raw('SUM(m.total) as total'))
            ->orderByDesc('total')->get();
        $grand = ['count' => $by_booker->sum('count'), 'total' => $by_booker->sum('total')];

        if ($request->input('print')) {
            return $this->printList($request, $query, $kind, $status, $start_date, $end_date, $locations, $location);
        }
        $rows = $query->paginate(50)->withQueryString();

        // Everyone who ever sent something from the app (also bookers who left).
        $bookers = DB::table('mobile_inbox as m')->join('users as u', 'u.id', '=', 'm.booker_id')
            ->where('u.business_id', request()->session()->get('user.business_id'))
            ->selectRaw("u.id, TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) as name")
            ->distinct()->orderBy('name')->pluck('name', 'u.id');
        $filters = array_filter(['status' => $status, 'location_id' => $location ?: null, 'booker_id' => $booker ?: null,
            'start_date' => $start_date, 'end_date' => $end_date]);

        $counts = DB::table('mobile_inbox')->where('status', 'waiting')->groupBy('kind')->selectRaw('kind, COUNT(*) as c')->pluck('c', 'kind');
        $last_run = json_decode((string) DB::table('system')->where('key', 'mobile_sync_last_run')->value('value'), true);

        $shop_edits = DB::table('booker_customer_updates')->where('business_id', request()->session()->get('user.business_id'))->where('status', 'waiting')->count();

        return view('mobile_order.index', compact('rows', 'kind', 'status', 'counts', 'last_run', 'locations', 'location', 'shop_edits',
            'bookers', 'booker', 'start_date', 'end_date', 'filters', 'by_booker', 'grand'));
    }

    /**
     * Print of the list (same filters, all pages; or only the ticked rows). Orders also get a load sheet for the
     * warehouse: every product of these orders added up, grouped by brand, with the quantity in its biggest unit.
     */
    private function printList(Request $request, $query, $kind, $status, $start_date, $end_date, $locations, $location)
    {
        $business_id = request()->session()->get('user.business_id');
        $ids = array_filter(array_map('intval', explode(',', (string) $request->input('ids'))));
        if ($ids) {
            $query->whereIn('m.id', $ids);
        }
        $rows = $query->get();
        $by_booker = $rows->groupBy('booker_id')->map(function ($g) {
            return (object) ['booker' => $g->first()->booker ?: '#'.$g->first()->booker_id, 'count' => $g->count(), 'total' => $g->sum('total')];
        })->sortByDesc('total')->values();
        $grand = ['count' => $rows->count(), 'total' => $rows->sum('total')];

        $load = collect();
        if ($kind === 'order') {
            $qty = [];
            $orders = [];
            foreach ($rows as $r) {
                foreach (json_decode($r->data, true)['lines'] ?? [] as $l) {
                    $vid = (int) ($l['variation_id'] ?? 0);
                    $qty[$vid] = ($qty[$vid] ?? 0) + (float) ($l['quantity'] ?? 0);
                    $orders[$vid][$r->id] = true;
                }
            }
            $location_id = $location ?: (int) config('mobile_sync.location_id');
            $products = DB::table('variations as v')
                ->join('products as p', 'p.id', '=', 'v.product_id')
                ->leftJoin('brands as b', 'b.id', '=', 'p.brand_id')
                ->leftJoin('units as u', 'u.id', '=', 'p.unit_id')
                ->leftJoin('product_variations as pv', 'pv.id', '=', 'v.product_variation_id')
                ->leftJoin('variation_location_details as vld', function ($join) use ($location_id) {
                    $join->on('vld.variation_id', '=', 'v.id')->where('vld.location_id', $location_id);
                })
                ->whereIn('v.id', array_keys($qty) ?: [0])
                ->select('v.id', 'p.name', 'p.type', 'v.name as variation', 'pv.name as variation_group', 'v.sub_sku',
                    'p.sub_unit_ids', 'u.short_name as unit', 'b.name as brand', 'vld.qty_available')
                ->get()->keyBy('id');
            $productUtil = new \App\Utils\ProductUtil();
            foreach ($qty as $vid => $q) {
                $p = $products[$vid] ?? null;
                $load->push((object) [
                    'brand' => $p && $p->brand ? $p->brand : 'No brand',
                    'name' => $p ? $p->name.($p->type == 'variable' ? ' - '.$p->variation : '') : 'Product (deleted) #'.$vid,
                    'sku' => $p->sub_sku ?? '',
                    'qty' => $q,
                    'unit' => $p->unit ?? '',
                    'big' => $p ? $productUtil->stockInBiggestSubUnit($business_id, $q, $p->sub_unit_ids, $p->unit) : null,
                    'stock' => $p->qty_available ?? null,
                    'orders' => count($orders[$vid] ?? []),
                ]);
            }
            $load = $load->sortBy(function ($l) {
                return ($l->brand === 'No brand' ? 'zzzz' : strtolower($l->brand)).'|'.strtolower($l->name);
            })->groupBy('brand');
        }
        $business = \App\Business::find($business_id);
        $only_load = (bool) $request->input('load');

        return view('mobile_order.print', compact('rows', 'kind', 'status', 'by_booker', 'grand', 'start_date', 'end_date',
            'business', 'locations', 'location', 'load', 'only_load', 'ids'));
    }

    public function show($id)
    {
        $this->authorizeAccess();
        $this->authorizeRow($id);

        $row = DB::table('mobile_inbox as m')
            ->leftJoin('contacts as c', 'c.id', '=', 'm.contact_id')
            ->leftJoin('users as u', 'u.id', '=', 'm.booker_id')
            ->where('m.id', $id)
            ->whereIn('m.kind', ['order', 'payment'])
            ->select('m.*', 'c.name as customer', 'c.supplier_business_name', 'c.mobile as customer_mobile',
                DB::raw("TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) as booker"))
            ->first();
        abort_if(empty($row), 404);
        $data = json_decode($row->data, true);

        $lines = [];
        $invoices = collect();
        if ($row->kind === 'order') {
            $location_id = $row->location_id ?: config('mobile_sync.location_id');
            foreach ($data['lines'] ?? [] as $l) {
                $v = DB::table('variations as v')
                    ->join('products as p', 'p.id', '=', 'v.product_id')
                    ->leftJoin('variation_location_details as vld', function ($join) use ($location_id) {
                        $join->on('vld.variation_id', '=', 'v.id')->where('vld.location_id', $location_id);
                    })
                    ->where('v.id', $l['variation_id'])
                    ->first(['p.name', 'v.sub_sku', 'v.sell_price_inc_tax', 'vld.qty_available', 'p.enable_stock']);
                $lines[] = $l + [
                    'name' => $v->name ?? 'Product #'.$l['product_id'].' (deleted)',
                    'sku' => $v->sub_sku ?? '',
                    'unit_name' => DB::table('units')->where('id', $l['sub_unit_id'])->value('actual_name'),
                    'current_price' => isset($v->sell_price_inc_tax) ? $v->sell_price_inc_tax * $l['multiplier'] : null,
                    'stock' => $v->qty_available ?? 0,
                    'enable_stock' => $v->enable_stock ?? 0,
                ];
            }
        } else {
            $ids = array_column($data['allocations'] ?? [], 'invoice_id');
            $invoices = DB::table('transactions')->whereIn('id', $ids)->pluck('invoice_no', 'id');
        }

        return view('mobile_order.show', compact('row', 'data', 'lines', 'invoices'));
    }

    public function approve($id)
    {
        $this->authorizeAccess();
        $this->authorizeRow($id);

        try {
            $row = DB::table('mobile_inbox')->find($id);
            if (! empty($row) && $row->kind === 'payment') {
                $payment = $this->inbox()->approvePayment($id, auth()->id());
                $msg = 'Payment posted: '.$payment->payment_ref_no;
            } else {
                $so = $this->inbox()->approveOrder($id, auth()->id());
                $msg = 'Sales order created: '.$so->invoice_no;
            }
            $output = ['success' => 1, 'msg' => $msg];
        } catch (\Exception $e) {
            \Log::emergency('Mobile approve: File:'.$e->getFile().' Line:'.$e->getLine().' Message:'.$e->getMessage());
            $output = ['success' => 0, 'msg' => $e->getMessage()];
        }

        return redirect()->back()->with('status', $output);
    }

    /**
     * Ticked rows in the list: invoice every selected order, or approve every selected payment. Each one runs on its
     * own, so one failure does not stop the rest; the message lists what failed and why.
     */
    public function bulk(Request $request)
    {
        $this->authorizeAccess();
        $ids = array_filter(array_map('intval', (array) $request->input('ids', [])));
        $done = [];
        $failed = [];
        foreach ($ids as $id) {
            $row = DB::table('mobile_inbox')->find($id);
            if (empty($row)) {
                continue;
            }
            try {
                $this->authorizeRow($id);
                if ($row->kind === 'order') {
                    $done[] = $this->inbox()->invoiceOrder($id, auth()->id())->invoice_no;
                } elseif ($row->kind === 'payment') {
                    $done[] = $this->inbox()->approvePayment($id, auth()->id())->payment_ref_no;
                }
            } catch (\Throwable $e) {
                \Log::warning('Mobile bulk '.$row->number.': '.$e->getMessage());
                $failed[] = $row->number.' ('.$e->getMessage().')';
            }
        }

        $what = $request->input('kind') === 'payment' ? 'payment(s) approved' : 'invoice(s) created';
        $msg = count($done).' '.$what.(empty($failed) ? '' : '. Not done: '.implode('; ', $failed));

        return redirect()->back()->with('status', ['success' => empty($failed) ? 1 : (empty($done) ? 0 : 1), 'msg' => $msg]);
    }

    /** One click: booker order -> final invoice (credit sale). */
    public function invoice($id)
    {
        $this->authorizeAccess();
        $this->authorizeRow($id);

        try {
            $sell = $this->inbox()->invoiceOrder($id, auth()->id());
            $output = ['success' => 1, 'msg' => 'Invoice '.$sell->invoice_no.' created'];
        } catch (\Exception $e) {
            \Log::emergency('Mobile invoice: File:'.$e->getFile().' Line:'.$e->getLine().' Message:'.$e->getMessage());
            $output = ['success' => 0, 'msg' => $e->getMessage()];
        }

        return redirect()->back()->with('status', $output);
    }

    /** Cloud sync panel / progress bar (polled). */
    /**
     * Mobile orders > Shop edits: shop changes bookers made in the app (location, photo, phone, address, route...).
     * Empty fields were filled automatically; changes to existing values wait here.
     */
    public function shopEdits(Request $request)
    {
        if (! auth()->user()->can('customer.update')) {
            abort(403, 'Unauthorized action.');
        }
        $business_id = request()->session()->get('user.business_id');
        $status = in_array($request->input('status'), ['waiting', 'applied', 'rejected', 'all']) ? $request->input('status') : 'waiting';

        $query = DB::table('booker_customer_updates as e')
            ->leftJoin('contacts as c', 'c.id', '=', 'e.contact_id')
            ->leftJoin('users as u', 'u.id', '=', 'e.booker_id')
            ->leftJoin('users as d', 'd.id', '=', 'e.decided_by')
            ->where('e.business_id', $business_id)
            ->select('e.*', 'c.name', 'c.supplier_business_name', 'c.mobile', 'c.address_line_1', 'c.city', 'c.position',
                'c.route_id', 'c.outlet_type', 'c.outlet_class', 'c.shop_photo',
                DB::raw("TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) as booker"),
                DB::raw("TRIM(CONCAT(COALESCE(d.first_name, ''), ' ', COALESCE(d.last_name, ''))) as decided_by_name"))
            ->orderByDesc('e.id');
        if ($status !== 'all') {
            $query->where('e.status', $status);
        }
        $rows = $query->paginate(50)->withQueryString();

        $counts = DB::table('mobile_inbox')->where('status', 'waiting')->groupBy('kind')->selectRaw('kind, COUNT(*) as c')->pluck('c', 'kind');
        $counts['shop_edit'] = DB::table('booker_customer_updates')->where('business_id', $business_id)->where('status', 'waiting')->count();
        $routes = DB::table('booker_routes')->where('business_id', $business_id)->pluck('name', 'id');

        return view('mobile_order.shop_edits', compact('rows', 'status', 'counts', 'routes'));
    }

    public function decideShopEdit(Request $request, $id)
    {
        if (! auth()->user()->can('customer.update')) {
            abort(403, 'Unauthorized action.');
        }
        try {
            if ($request->input('decision') === 'approve') {
                $this->inbox()->approveShopEdit((int) $id, auth()->id());
                $msg = 'Shop updated';
            } else {
                $this->inbox()->rejectShopEdit((int) $id, auth()->id());
                $msg = 'Change rejected';
            }
            $output = ['success' => 1, 'msg' => $msg];
        } catch (\Exception $e) {
            $output = ['success' => 0, 'msg' => $e->getMessage()];
        }

        return redirect()->back()->with('status', $output);
    }

    /** Ticked shop edits: approve or reject them all at once. */
    public function decideShopEditsBulk(Request $request)
    {
        if (! auth()->user()->can('customer.update')) {
            abort(403, 'Unauthorized action.');
        }
        $approve = $request->input('decision') === 'approve';
        $done = 0;
        $errors = [];
        $ids = DB::table('booker_customer_updates')->where('business_id', request()->session()->get('user.business_id'))
            ->where('status', 'waiting')->whereIn('id', array_map('intval', (array) $request->input('ids', [])))->pluck('id');
        foreach ($ids as $id) {
            try {
                $approve ? $this->inbox()->approveShopEdit($id, auth()->id()) : $this->inbox()->rejectShopEdit($id, auth()->id());
                $done++;
            } catch (\Exception $e) {
                $errors[] = '#'.$id.': '.$e->getMessage();
            }
        }
        $msg = $done.' shop edit(s) '.($approve ? 'approved' : 'rejected').($errors ? '; not done: '.implode(', ', $errors) : '');

        return redirect()->back()->with('status', ['success' => $done > 0 ? 1 : 0, 'msg' => $done ? $msg : ($errors ? $msg : 'Tick the shop edits first')]);
    }

    public function syncStatus()
    {
        return response()->json(SyncStatus::snapshot());
    }

    /**
     * Start a sync in the background. "Sync now" sends manual=1; every open POS page also calls this every couple
     * of minutes (layouts/partials/mobile_autosync), so it runs without any command or Task Scheduler.
     */
    public function syncNow(Request $request)
    {
        $started = SyncStatus::start();

        return response()->json(['started' => $started] + SyncStatus::snapshot());
    }

    public function reject(Request $request, $id)
    {
        $this->authorizeAccess();
        $this->authorizeRow($id);
        $request->validate(['reason' => 'required|string|max:191']);

        try {
            $this->inbox()->reject($id, auth()->id(), $request->input('reason'));
            $output = ['success' => 1, 'msg' => 'Rejected; the booker will see the reason'];
        } catch (\Exception $e) {
            $output = ['success' => 0, 'msg' => $e->getMessage()];
        }

        return redirect()->back()->with('status', $output);
    }
}
