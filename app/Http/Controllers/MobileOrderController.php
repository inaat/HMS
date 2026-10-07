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
        $rows = $query->paginate(50)->withQueryString();

        $counts = DB::table('mobile_inbox')->where('status', 'waiting')->groupBy('kind')->selectRaw('kind, COUNT(*) as c')->pluck('c', 'kind');
        $last_run = json_decode((string) DB::table('system')->where('key', 'mobile_sync_last_run')->value('value'), true);

        $shop_edits = DB::table('booker_customer_updates')->where('business_id', request()->session()->get('user.business_id'))->where('status', 'waiting')->count();

        return view('mobile_order.index', compact('rows', 'kind', 'status', 'counts', 'last_run', 'locations', 'location', 'shop_edits'));
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
        $status = in_array($request->input('status'), ['waiting', 'applied', 'rejected', 'all']) ? $request->input('status') : 'all';

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
