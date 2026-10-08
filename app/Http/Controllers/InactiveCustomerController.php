<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Customers who bought before but have not bought anything in the last N days (lost / sleeping customers).
 * Biggest past buyers first, so the most important ones are called first. Also feeds the dashboard alert.
 */
class InactiveCustomerController extends Controller
{
    // Dashboard alert: no purchase for this many days.
    const ALERT_DAYS = 30;

    public function index(Request $request)
    {
        if (! auth()->user()->can('customer.view')) {
            abort(403, 'Unauthorized action.');
        }
        $business_id = $request->session()->get('user.business_id');
        $days = (int) $request->input('days', self::ALERT_DAYS) ?: self::ALERT_DAYS;
        $search = trim((string) $request->input('search', ''));

        $customers = self::query($business_id, $days, $search)->get();
        // Due = same figure as Top Defaulters / customer list (unpaid invoices + opening balance)
        $due = $customers->isEmpty() ? collect() : (new \App\Utils\ContactUtil())
            ->getContactQuery($business_id, 'customer', $customers->pluck('id')->all())->get()->pluck('for_ordering_total_due', 'id');
        foreach ($customers as $c) {
            $c->due = (float) ($due[$c->id] ?? 0);
        }
        $totals = [
            'count' => $customers->count(),
            'bought' => $customers->sum('total_bought'),
            'due' => $customers->sum('due'),
        ];

        return view('inactive_customer.index', compact('customers', 'totals', 'days', 'search'));
    }

    /** Last final sale per customer older than $days; customers who never bought are left out. */
    public static function query($business_id, $days, $search = '')
    {
        $sales = DB::table('transactions')
            ->where('business_id', $business_id)
            ->where('type', 'sell')
            ->where('status', 'final')
            ->groupBy('contact_id')
            ->select('contact_id',
                DB::raw('MAX(transaction_date) as last_purchase'),
                DB::raw('COUNT(*) as invoices'),
                DB::raw('SUM(final_total) as total_bought'));

        $query = DB::table('contacts as c')
            ->joinSub($sales, 's', 's.contact_id', '=', 'c.id')
            ->where('c.business_id', $business_id)
            ->whereIn('c.type', ['customer', 'both'])
            ->where('c.contact_status', 'active')
            ->where('c.is_default', 0)
            ->whereNull('c.deleted_at')
            ->where('s.last_purchase', '<', \Carbon::today()->subDays($days)->format('Y-m-d'))
            ->select('c.id', 'c.name', 'c.supplier_business_name', 'c.mobile', 'c.city', 'c.address_line_1',
                's.last_purchase', 's.invoices', 's.total_bought')
            ->orderByDesc('s.total_bought');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('c.name', 'like', '%'.$search.'%')
                    ->orWhere('c.supplier_business_name', 'like', '%'.$search.'%')
                    ->orWhere('c.mobile', 'like', '%'.$search.'%')
                    ->orWhere('c.city', 'like', '%'.$search.'%');
            });
        }

        return $query;
    }
}
