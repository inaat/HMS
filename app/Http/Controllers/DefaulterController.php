<?php

namespace App\Http\Controllers;

use App\Services\WhatsappApiService;
use App\Utils\ContactUtil;
use App\Utils\TransactionUtil;
use App\WhatsappDevice;
use Illuminate\Http\Request;

/**
 * Customers with the biggest unpaid balance, with WhatsApp payment reminders.
 * "Due" is the same figure as the customer list's Total Sale Due column:
 * unpaid final invoices + unpaid opening balance (for_ordering_total_due).
 */
class DefaulterController extends Controller
{
    protected $contactUtil;

    protected $transactionUtil;

    protected $whatsappApiService;

    public function __construct(ContactUtil $contactUtil, TransactionUtil $transactionUtil, WhatsappApiService $whatsappApiService)
    {
        $this->contactUtil = $contactUtil;
        $this->transactionUtil = $transactionUtil;
        $this->whatsappApiService = $whatsappApiService;
    }

    public function index(Request $request)
    {
        if (! auth()->user()->can('customer.view')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = $request->session()->get('user.business_id');

        $limit = (int) $request->input('limit', 25);
        $min_due = (float) $request->input('min_due', 0);
        $inactive_days = (int) $request->input('inactive_days', 0);
        $search = trim((string) $request->input('search', ''));

        $all = $this->defaulters($business_id, $min_due, $inactive_days, [], $search);

        // counters over every defaulter matching the filters, not just the shown top N
        $today = \Carbon::today();
        $counters = [
            'count' => $all->count(),
            'total_due' => $all->sum('due'),
            'reachable' => $all->whereNotNull('whatsapp')->count(),
            'over_90' => $all->filter(function ($c) use ($today) {
                return empty($c->max_transaction_date) || \Carbon::parse($c->max_transaction_date)->diffInDays($today) > 90;
            })->count(),
        ];

        $defaulters = $limit > 0 ? $all->take($limit) : $all;
        $counters['top_due'] = $defaulters->sum('due');

        $default_message = "Dear {name},\n\nThis is a reminder that your outstanding balance is {due}.\nLast purchase: {last_sale}.\n\nPlease clear your dues at your earliest convenience.\n\n{business}";

        return view('defaulter.index')->with(compact('defaulters', 'counters', 'limit', 'min_due', 'inactive_days', 'search', 'default_message'));
    }

    /**
     * Sends one reminder. Called once per customer by the page, so the
     * page can show progress for a bulk send.
     */
    public function send(Request $request)
    {
        if (! auth()->user()->can('customer.view')) {
            abort(403, 'Unauthorized action.');
        }

        $request->validate([
            'contact_id' => 'required|integer',
            'message' => 'required|string|max:2000',
        ]);

        $business_id = $request->session()->get('user.business_id');

        // due is recomputed here so the message never carries a stale figure
        $contact = $this->defaulters($business_id, 0, 0, [$request->input('contact_id')])->first();
        if (empty($contact)) {
            return response()->json(['ok' => false, 'message' => 'Customer has no outstanding balance.']);
        }
        if (empty($contact->whatsapp)) {
            return response()->json(['ok' => false, 'message' => 'No valid mobile number.']);
        }

        $text = strtr($request->input('message'), [
            '{name}' => $contact->name,
            '{due}' => $this->transactionUtil->num_f($contact->due, true),
            '{last_sale}' => $contact->max_transaction_date ? $this->transactionUtil->format_date($contact->max_transaction_date) : '-',
            '{business}' => $request->session()->get('business.name'),
        ]);

        try {
            $response = $this->whatsappApiService->sendTestMsg(WhatsappDevice::instanceFor($business_id), $contact->whatsapp, $text);
        } catch (\Exception $e) {
            \Log::emergency('Defaulter WhatsApp reminder: '.$e->getMessage());

            return response()->json(['ok' => false, 'message' => 'WhatsApp gateway unreachable.']);
        }

        if (empty($response) || ! empty($response['error'])) {
            return response()->json(['ok' => false, 'message' => $response['message'] ?? 'WhatsApp did not accept the message.']);
        }

        return response()->json(['ok' => true, 'message' => 'Sent']);
    }

    /**
     * Customers with a positive due, biggest first.
     */
    protected function defaulters($business_id, $min_due = 0, $inactive_days = 0, $contact_ids = [], $search = null)
    {
        $query = $this->contactUtil->getContactQuery($business_id, 'customer', $contact_ids)
            ->havingRaw('for_ordering_total_due > ?', [max($min_due, 0.009)])
            ->orderByDesc('for_ordering_total_due');

        //Customer search: name, business name, mobile or contact ID
        if (! empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('contacts.name', 'like', '%'.$search.'%')
                    ->orWhere('contacts.supplier_business_name', 'like', '%'.$search.'%')
                    ->orWhere('contacts.mobile', 'like', '%'.$search.'%')
                    ->orWhere('contacts.contact_id', 'like', '%'.$search.'%');
            });
        }

        if ($inactive_days > 0) {
            $query->havingRaw('(max_transaction_date IS NULL OR max_transaction_date < ?)', [\Carbon::today()->subDays($inactive_days)->format('Y-m-d')]);
        }

        return $query->get()->map(function ($contact) {
            $contact->due = (float) $contact->for_ordering_total_due;
            $contact->whatsapp = static::whatsappNumber($contact->mobile);

            return $contact;
        });
    }

    /**
     * Normalises a stored mobile to the international digits the gateway
     * expects: 03001234567 / +92 300 1234567 -> 923001234567. Null if unusable.
     */
    public static function whatsappNumber($mobile)
    {
        $digits = preg_replace('/\D/', '', (string) $mobile);

        if (strlen($digits) == 11 && substr($digits, 0, 1) == '0') {
            $digits = '92'.substr($digits, 1);
        } elseif (strlen($digits) == 14 && substr($digits, 0, 4) == '0092') {
            $digits = substr($digits, 2);
        }

        return strlen($digits) >= 11 && strlen($digits) <= 15 ? $digits : null;
    }
}
