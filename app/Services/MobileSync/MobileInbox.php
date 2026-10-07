<?php

namespace App\Services\MobileSync;

use App\Account;
use App\AccountTransaction;
use App\Contact;
use App\Events\TransactionPaymentAdded;
use App\Transaction;
use App\TransactionPayment;
use App\Utils\ContactUtil;
use App\Utils\ProductUtil;
use App\Services\WhatsappApiService;
use App\Utils\TransactionUtil;
use App\WhatsappDevice;
use Illuminate\Support\Facades\DB;

/**
 * Local side of the order-booker sync (config/mobile_sync.php): stores what bookers sent (mobile_inbox), turns an
 * approved order into a normal Sales Order and an approved collection into a normal customer payment, and works
 * out which statuses still have to be reported back to the cloud.
 */
class MobileInbox
{
    // Local status -> status the cloud and the phone know.
    const CLOUD_STATUS = ['waiting' => 'received', 'approved' => 'approved', 'rejected' => 'rejected', 'invoiced' => 'invoiced'];

    private $transactionUtil;

    private $business_id;

    private $location_id;

    public function __construct(int $business_id, int $location_id)
    {
        $this->transactionUtil = new TransactionUtil();
        $this->business_id = $business_id;
        $this->location_id = $location_id;
    }

    /**
     * Save what /api/sync/inbox returned. Booker-made customers become contacts straight away (so the order can be
     * approved); orders and payments wait for staff. Already stored UUIDs are skipped.
     */
    public function store(array $inbox): array
    {
        $added = ['customers' => 0, 'orders' => 0, 'payments' => 0];

        foreach ($inbox['customers'] ?? [] as $c) {
            if (DB::table('mobile_inbox')->where('uuid', $c['uuid'])->exists()) {
                continue;
            }
            DB::transaction(function () use ($c) {
                $result = (new ContactUtil())->createNewContact([
                    'business_id' => $this->business_id,
                    'type' => 'customer',
                    'name' => $c['name'],
                    'supplier_business_name' => $c['business_name'] ?? null,
                    'mobile' => $c['mobile'] ?: '-',
                    'address_line_1' => $c['address'] ?? null,
                    'city' => $c['city'] ?? null,
                    'created_by' => $this->bookerOrAdmin($c['created_by'] ?? null),
                    'contact_status' => 'active',
                ]);
                DB::table('mobile_inbox')->insert([
                    'kind' => 'customer',
                    'uuid' => $c['uuid'],
                    'booker_id' => $c['created_by'] ?? null,
                    'contact_id' => $result['data']->id,
                    'data' => json_encode($c),
                    'booked_at' => $c['created_at'] ?? now(),
                    'status' => 'approved',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
            $added['customers']++;
        }

        foreach ($inbox['orders'] ?? [] as $o) {
            if (DB::table('mobile_inbox')->where('uuid', $o['uuid'])->exists()) {
                continue;
            }
            DB::table('mobile_inbox')->insert([
                'kind' => 'order',
                'uuid' => $o['uuid'],
                'booker_id' => $o['user_id'],
                'number' => $o['number'],
                'contact_id' => $o['contact_id'] ?: $this->contactForUuid($o['customer_uuid'] ?? null),
                'location_id' => $o['location_id'] ?? $this->location_id,
                'customer_uuid' => $o['customer_uuid'] ?? null,
                'total' => $o['total'],
                'short_stock' => $o['short_stock'] ? 1 : 0,
                'data' => json_encode(['note' => $o['note'] ?? null, 'lines' => $o['lines'] ?? []]),
                'booked_at' => $o['order_date'],
                'status' => 'waiting',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            // The cloud already sent the customer the order slip on WhatsApp when the booker saved it.
            $added['orders']++;
        }

        foreach ($inbox['payments'] ?? [] as $p) {
            if (DB::table('mobile_inbox')->where('uuid', $p['uuid'])->exists()) {
                continue;
            }
            DB::table('mobile_inbox')->insert([
                'kind' => 'payment',
                'uuid' => $p['uuid'],
                'booker_id' => $p['user_id'],
                'number' => $p['number'],
                'contact_id' => $p['contact_id'] ?: $this->contactForUuid($p['customer_uuid'] ?? null),
                'customer_uuid' => $p['customer_uuid'] ?? null,
                'total' => $p['amount'],
                'data' => json_encode(array_intersect_key($p, array_flip(['method', 'cheque_number', 'bank_ref', 'note', 'allocations']))),
                'booked_at' => $p['paid_on'],
                'status' => 'waiting',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $added['payments']++;
        }

        return $added;
    }

    /**
     * Approved orders whose sales order has since been turned into an invoice (Add Sale > Sales order).
     */
    public function markInvoiced(): int
    {
        $count = 0;
        $rows = DB::table('mobile_inbox')->where('kind', 'order')->where('status', 'approved')->whereNotNull('transaction_id')->get();
        foreach ($rows as $row) {
            $sells = Transaction::where('business_id', $this->business_id)
                ->where('type', 'sell')
                ->where('status', 'final')
                ->where(function ($q) use ($row) {
                    $q->whereJsonContains('sales_order_ids', (string) $row->transaction_id)
                        ->orWhereJsonContains('sales_order_ids', (int) $row->transaction_id);
                })
                ->pluck('invoice_no');
            if ($sells->isNotEmpty()) {
                DB::table('mobile_inbox')->where('id', $row->id)->update([
                    'status' => 'invoiced', 'invoice_no' => $sells->implode(', '), 'updated_at' => now(),
                ]);
                $count++;
            }
        }

        return $count;
    }

    /** Status changes the cloud has not been told about yet, in the shape /api/sync/ack takes. */
    public function pendingAcks(): array
    {
        $ack = ['customers' => [], 'orders' => [], 'payments' => [], 'ids' => []];
        $rows = DB::table('mobile_inbox')
            ->where(function ($q) {
                $q->whereNull('cloud_status')->orWhereColumn('cloud_status', '!=', 'status');
            })
            ->get();

        foreach ($rows as $row) {
            $ack['ids'][$row->id] = $row->status;
            if ($row->kind === 'customer') {
                $ack['customers'][] = ['uuid' => $row->uuid, 'local_id' => $row->contact_id];
            } elseif ($row->kind === 'order') {
                $ack['orders'][] = [
                    'uuid' => $row->uuid,
                    'status' => self::CLOUD_STATUS[$row->status],
                    'local_so_id' => $row->transaction_id,
                    'local_so_no' => $row->ref,
                    'invoice_no' => $row->invoice_no,
                    'reject_reason' => $row->reject_reason,
                ];
            } else {
                $ack['payments'][] = [
                    'uuid' => $row->uuid,
                    'status' => self::CLOUD_STATUS[$row->status] ?? 'received',
                    'local_ref' => $row->ref,
                    'reject_reason' => $row->reject_reason,
                ];
            }
        }

        return $ack;
    }

    public function ackDone(array $ids): void
    {
        foreach ($ids as $id => $status) {
            DB::table('mobile_inbox')->where('id', $id)->where('status', $status)->update(['cloud_status' => $status]);
        }
    }

    /**
     * Approve a booker order: make a Sales Order (status "ordered") with the booker's lines, in the units they
     * chose, as the Add Sale screen would. Staff can still edit it, then invoice it from Add Sale.
     */
    public function approveOrder(int $id, int $user_id): Transaction
    {
        return DB::transaction(function () use ($id, $user_id) {
            $row = $this->lockWaiting($id, 'order');
            $data = json_decode($row->data, true);

            $product_ids = array_column($data['lines'], 'product_id');
            $products = DB::table('products')->whereIn('id', $product_ids)->get()->keyBy('id');
            $tax_rates = DB::table('tax_rates')->where('business_id', $this->business_id)->pluck('amount', 'id');

            $lines = [];
            foreach ($data['lines'] as $l) {
                $product = $products->get($l['product_id']);
                if (empty($product) || ! DB::table('variations')->where('id', $l['variation_id'])->whereNull('deleted_at')->exists()) {
                    throw new \Exception('A product in this order no longer exists');
                }
                $price_inc_tax = (float) $l['sub_unit_price'];
                $rate = ! empty($product->tax) ? (float) ($tax_rates[$product->tax] ?? 0) : 0;
                $price_exc_tax = $rate > 0 ? $price_inc_tax / (1 + $rate / 100) : $price_inc_tax;

                // Same keys the Add Sale form posts; quantity and price per chosen unit, the util divides by
                // base_unit_multiplier so the sell line holds base units.
                $lines[] = [
                    'product_id' => $l['product_id'],
                    'variation_id' => $l['variation_id'],
                    'quantity' => (float) $l['sub_unit_qty'],
                    'unit_price' => $price_exc_tax,
                    'unit_price_inc_tax' => $price_inc_tax,
                    'item_tax' => $price_inc_tax - $price_exc_tax,
                    'tax_id' => $product->tax,
                    'line_discount_type' => 'fixed',
                    'line_discount_amount' => 0,
                    'sub_unit_id' => $l['sub_unit_id'],
                    'product_unit_id' => $product->unit_id,
                    'base_unit_multiplier' => (float) $l['multiplier'],
                    'enable_stock' => $product->enable_stock,
                    'product_type' => $product->type,
                    'sell_line_note' => '',
                ];
            }

            $invoice_total = (new ProductUtil())->calculateInvoiceTotal($lines, null, ['discount_type' => 'fixed', 'discount_amount' => 0], false);
            $booker = $this->bookerName($row->booker_id);

            $transaction = $this->transactionUtil->createSellTransaction($this->business_id, [
                'type' => 'sales_order',
                'status' => 'ordered',
                'location_id' => $row->location_id ?: $this->location_id,
                'contact_id' => $this->contactOf($row),
                'transaction_date' => $row->booked_at ?: now(),
                'final_total' => $invoice_total['final_total'],
                'discount_type' => 'fixed',
                'discount_amount' => 0,
                'is_direct_sale' => 1,
                'sale_note' => $data['note'] ?? null,
                'staff_note' => 'Mobile order '.$row->number.' by '.$booker,
                'commission_agent' => $this->commissionAgentFor($row->booker_id),
                'source' => 'mobile',
            ], $invoice_total, $this->bookerOrAdmin($row->booker_id), false);

            $this->transactionUtil->createOrUpdateSellLines($transaction, $lines, $transaction->location_id, false, null, [], false);
            $this->transactionUtil->activityLog($transaction, 'added');

            DB::table('mobile_inbox')->where('id', $row->id)->update([
                'status' => 'approved',
                'transaction_id' => $transaction->id,
                'ref' => $transaction->invoice_no,
                'decided_by' => $user_id,
                'decided_at' => now(),
                'updated_at' => now(),
            ]);

            return $transaction;
        });
    }

    /**
     * One click from Mobile orders: the booker order becomes a final invoice (credit sale), the same records the
     * Add Sale screen makes when a sales order is invoiced: sales order (made first if still waiting), sell lines in
     * the units ordered with so_line_id, stock down, purchase mapping, payment status, sales order completed.
     */
    public function invoiceOrder(int $id, int $user_id): Transaction
    {
        $row = DB::table('mobile_inbox')->where('id', $id)->where('kind', 'order')->first();
        if (empty($row)) {
            throw new \Exception('Not found');
        }
        if ($row->status === 'waiting') {
            $this->approveOrder($id, $user_id);
        }

        $sell = DB::transaction(function () use ($id, $user_id) {
            $row = DB::table('mobile_inbox')->where('id', $id)->lockForUpdate()->first();
            if ($row->status !== 'approved' || empty($row->transaction_id)) {
                throw new \Exception('Already '.$row->status);
            }
            $so = Transaction::with('sell_lines')->where('business_id', $this->business_id)->where('type', 'sales_order')->findOrFail($row->transaction_id);
            $products = DB::table('products')->whereIn('id', $so->sell_lines->pluck('product_id'))->get()->keyBy('id');
            $multipliers = DB::table('units')->pluck('base_unit_multiplier', 'id');

            // The sales order lines as Add Sale posts them: quantity and prices per unit ordered.
            $lines = [];
            foreach ($so->sell_lines as $sl) {
                $remaining = $sl->quantity - $sl->so_quantity_invoiced;
                $product = $products->get($sl->product_id);
                if ($remaining <= 0 || empty($product)) {
                    continue;
                }
                $m = ! empty($sl->sub_unit_id) ? ((float) ($multipliers[$sl->sub_unit_id] ?? 1) ?: 1) : 1;
                $lines[] = [
                    'product_id' => $sl->product_id,
                    'variation_id' => $sl->variation_id,
                    'quantity' => $remaining / $m,
                    'unit_price' => $sl->unit_price_before_discount * $m,
                    'unit_price_inc_tax' => $sl->unit_price_inc_tax * $m,
                    'item_tax' => $sl->item_tax * $m,
                    'tax_id' => $sl->tax_id,
                    'line_discount_type' => $sl->line_discount_type ?: 'fixed',
                    'line_discount_amount' => ($sl->line_discount_type ?: 'fixed') === 'fixed' ? $sl->line_discount_amount * $m : $sl->line_discount_amount,
                    'sub_unit_id' => $sl->sub_unit_id,
                    'product_unit_id' => $product->unit_id,
                    'base_unit_multiplier' => $m,
                    'enable_stock' => $product->enable_stock,
                    'product_type' => $product->type,
                    'so_line_id' => $sl->id,
                    'sell_line_note' => $sl->sell_line_note,
                ];
            }
            if (empty($lines)) {
                throw new \Exception('Nothing left to invoice on sales order '.$so->invoice_no);
            }

            $discount = ['discount_type' => $so->discount_type ?: 'fixed', 'discount_amount' => $so->discount_amount ?: 0];
            $invoice_total = (new ProductUtil())->calculateInvoiceTotal($lines, $so->tax_id, $discount, false);

            $sell = $this->transactionUtil->createSellTransaction($this->business_id, [
                'type' => 'sell',
                'status' => 'final',
                'location_id' => $so->location_id,
                'contact_id' => $so->contact_id,
                'transaction_date' => now(),
                'final_total' => $invoice_total['final_total'],
                'tax_rate_id' => $so->tax_id,
                'discount_type' => $discount['discount_type'],
                'discount_amount' => $discount['discount_amount'],
                'is_direct_sale' => 1,
                'sale_note' => $so->additional_notes,
                'staff_note' => $so->staff_note,
                'commission_agent' => $so->commission_agent,
                'selling_price_group_id' => $so->selling_price_group_id,
                'sales_order_ids' => [$so->id],
                'source' => 'mobile',
            ], $invoice_total, $user_id, false);

            $this->transactionUtil->createOrUpdateSellLines($sell, $lines, $so->location_id, false, null, [], false);

            foreach ($lines as $l) {
                if ($l['enable_stock']) {
                    (new ProductUtil())->decreaseProductQuantity($l['product_id'], $l['variation_id'], $so->location_id, $l['quantity'] * $l['base_unit_multiplier']);
                }
            }

            $sell->payment_status = $this->transactionUtil->updatePaymentStatus($sell->id, $sell->final_total);

            $business = DB::table('business')->find($this->business_id);
            $this->transactionUtil->mapPurchaseSell([
                'id' => $this->business_id,
                'accounting_method' => $business->accounting_method,
                'location_id' => $so->location_id,
                'pos_settings' => empty($business->pos_settings) ? (new \App\Utils\BusinessUtil())->defaultPosSettings() : json_decode($business->pos_settings, true),
            ], $sell->sell_lines, 'purchase');

            $this->transactionUtil->updateSalesOrderStatus([$so->id]);
            $this->transactionUtil->activityLog($sell, 'added');

            DB::table('mobile_inbox')->where('id', $row->id)->update([
                'status' => 'invoiced', 'invoice_no' => $sell->invoice_no, 'updated_at' => now(),
            ]);

            return $sell;
        });

        \App\Events\SellCreatedOrModified::dispatch($sell);
        try {
            // Same "new sale" notification the POS sends (if a template has auto send on).
            (new \App\Utils\NotificationUtil())->autoSendNotification($this->business_id, 'new_sale', $sell, $sell->contact);
        } catch (\Throwable $e) {
            \Log::warning('Mobile invoice notification: '.$e->getMessage());
        }

        return $sell;
    }

    /**
     * Approve a collection: one customer payment into the booker's own "Cash with <booker>" account, paid
     * against the invoices the booker chose first, the rest against the oldest dues (as Pay due does).
     */
    public function approvePayment(int $id, int $user_id): TransactionPayment
    {
        return DB::transaction(function () use ($id, $user_id) {
            $row = $this->lockWaiting($id, 'payment');
            $data = json_decode($row->data, true);
            $contact = Contact::where('business_id', $this->business_id)->findOrFail($this->contactOf($row));
            $booker = $this->bookerName($row->booker_id);

            $method = in_array($data['method'] ?? 'cash', ['cash', 'cheque', 'bank_transfer']) ? $data['method'] : 'cash';
            $note = trim('Mobile receipt '.$row->number.' by '.$booker.'. '.($data['note'] ?? ''));
            $ref_count = $this->transactionUtil->setAndGetReferenceCount('sell_payment', $this->business_id);
            $inputs = [
                'amount' => (float) $row->total,
                'method' => $method,
                'cheque_number' => $data['cheque_number'] ?? null,
                'transaction_no' => $data['bank_ref'] ?? null,
                'note' => $note,
                'paid_on' => $row->booked_at ?: now(),
                'created_by' => $this->bookerOrAdmin($row->booker_id),
                'payment_for' => $contact->id,
                'business_id' => $this->business_id,
                'is_advance' => 1,
                'payment_ref_no' => $this->transactionUtil->generateReferenceNumber('sell_payment', $ref_count, $this->business_id),
                'payment_type' => AccountTransaction::getAccountTransactionType('sell'),
            ];
            if ($this->transactionUtil->isModuleEnabled('account', $this->business_id)) {
                $inputs['account_id'] = $this->bookerAccountId($row->booker_id, $booker, $user_id);
            }

            $parent = TransactionPayment::create($inputs);
            event(new TransactionPaymentAdded($parent, $inputs + ['transaction_type' => 'sell']));

            // Invoices the booker chose first.
            $remaining = (float) $row->total;
            foreach ($data['allocations'] ?? [] as $a) {
                $sell = Transaction::where('business_id', $this->business_id)->where('contact_id', $contact->id)
                    ->where('type', 'sell')->where('status', 'final')->find($a['invoice_id']);
                if (empty($sell) || $remaining <= 0) {
                    continue;
                }
                $due = $sell->final_total - $this->transactionUtil->getTotalPaid($sell->id);
                $amount = min((float) $a['amount'], $due, $remaining);
                if ($amount <= 0) {
                    continue;
                }
                $child_count = $this->transactionUtil->setAndGetReferenceCount('sell_payment', $this->business_id);
                TransactionPayment::create([
                    'transaction_id' => $sell->id,
                    'business_id' => $this->business_id,
                    'amount' => $amount,
                    'method' => $parent->method,
                    'transaction_no' => $parent->transaction_no,
                    'cheque_number' => $parent->cheque_number,
                    'paid_on' => $parent->paid_on,
                    'created_by' => $parent->created_by,
                    'payment_for' => $contact->id,
                    'parent_id' => $parent->id,
                    'payment_ref_no' => $this->transactionUtil->generateReferenceNumber('sell_payment', $child_count, $this->business_id),
                ]);
                $this->transactionUtil->updatePaymentStatus($sell->id, $sell->final_total);
                $remaining -= $amount;
            }

            // The rest against the oldest dues; anything left over becomes customer advance, as Pay due does.
            if ($remaining > 0.0001) {
                $rest = $parent->replicate();
                $rest->id = $parent->id;
                $rest->amount = $remaining;
                $excess = $this->transactionUtil->payAtOnce($rest, 'sell');
                if (! empty($excess)) {
                    $this->transactionUtil->updateContactBalance($contact, $excess);
                }
            }

            $business = DB::table('business')->where('id', $this->business_id)->value('name');
            $this->whatsapp($contact->id, "Dear {$contact->name},\n\nWe have received a payment of: ".number_format((float) $row->total, 2)
                ."\nReceipt: {$row->number} ({$parent->payment_ref_no})\nCollected by: {$booker}"
                ."\nRemaining Balance: ".number_format((float) $this->transactionUtil->getContactDue($contact->id), 2)
                ."\nBusiness Name : {$business}");

            DB::table('mobile_inbox')->where('id', $row->id)->update([
                'status' => 'approved',
                'payment_id' => $parent->id,
                'ref' => $parent->payment_ref_no,
                'decided_by' => $user_id,
                'decided_at' => now(),
                'updated_at' => now(),
            ]);

            return $parent;
        });
    }

    public function reject(int $id, int $user_id, string $reason): void
    {
        DB::transaction(function () use ($id, $user_id, $reason) {
            $row = $this->lockWaiting($id, null);
            DB::table('mobile_inbox')->where('id', $row->id)->update([
                'status' => 'rejected',
                'reject_reason' => mb_substr($reason, 0, 191),
                'decided_by' => $user_id,
                'decided_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * WhatsApp to the customer through the business's connected device (Settings > WhatsApp), the same way the POS
     * sends payment messages. Skipped when nothing is connected, the customer has no mobile, or
     * MOBILE_SYNC_WHATSAPP=false; a failure is only logged, never stops the sync or the approval.
     */
    private function whatsapp(int $contact_id, string $text): void
    {
        try {
            if (! config('mobile_sync.whatsapp')) {
                return;
            }
            $contact = Contact::find($contact_id);
            if (empty($contact) || strlen(preg_replace('/\D/', '', (string) $contact->mobile)) < 10) {
                return;
            }
            if (! WhatsappDevice::where('business_id', $this->business_id)->where('status', 'connected')->exists()) {
                return;
            }
            (new WhatsappApiService())->sendTestMsg(WhatsappDevice::instanceFor($this->business_id), $contact->mobile, $text);
        } catch (\Throwable $e) {
            \Log::warning('Mobile booker WhatsApp to contact '.$contact_id.' failed: '.$e->getMessage());
        }
    }

    private function lockWaiting(int $id, ?string $kind)
    {
        $row = DB::table('mobile_inbox')->where('id', $id)->whereIn('kind', $kind ? [$kind] : ['order', 'payment'])->lockForUpdate()->first();
        if (empty($row)) {
            throw new \Exception('Not found');
        }
        if ($row->status !== 'waiting') {
            throw new \Exception('Already '.$row->status);
        }

        return $row;
    }

    private function contactOf($row): int
    {
        $contact_id = $row->contact_id ?: $this->contactForUuid($row->customer_uuid);
        if (empty($contact_id)) {
            throw new \Exception('The customer has not arrived yet; run the sync and try again');
        }

        return (int) $contact_id;
    }

    private function contactForUuid(?string $uuid): ?int
    {
        return empty($uuid) ? null : DB::table('mobile_inbox')->where('kind', 'customer')->where('uuid', $uuid)->value('contact_id');
    }

    private function bookerName($booker_id): string
    {
        $u = DB::table('users')->where('id', $booker_id)->first(['first_name', 'last_name', 'username']);

        return empty($u) ? 'booker #'.$booker_id : (trim($u->first_name.' '.$u->last_name) ?: $u->username);
    }

    /**
     * Commission agent for a booker's orders: the one set on the booker (User Management > Edit user > "Commission
     * agent for mobile orders"), else the booker when they are a commission agent themselves.
     */
    private function commissionAgentFor($booker_id): ?int
    {
        $u = DB::table('users')->where('id', $booker_id)->first(['id', 'is_cmmsn_agnt', 'mobile_commission_agent_id']);
        if (empty($u)) {
            return null;
        }

        return $u->mobile_commission_agent_id ? (int) $u->mobile_commission_agent_id : ($u->is_cmmsn_agnt ? (int) $u->id : null);
    }

    /** The booker as "added by" when they still exist locally, otherwise the first admin of the business. */
    private function bookerOrAdmin($booker_id): int
    {
        if (! empty($booker_id) && DB::table('users')->where('id', $booker_id)->where('business_id', $this->business_id)->exists()) {
            return (int) $booker_id;
        }

        return (int) DB::table('users')->where('business_id', $this->business_id)->whereNull('deleted_at')->orderBy('id')->value('id');
    }

    /** "Cash with <booker>": cash the booker collected and has not handed over yet. Made on first use. */
    private function bookerAccountId($booker_id, string $booker, int $user_id): int
    {
        $number = 'MOBILE-'.$booker_id;
        $account = Account::where('business_id', $this->business_id)->where('account_number', $number)->first();
        if (empty($account)) {
            $account = Account::create([
                'business_id' => $this->business_id,
                'name' => 'Cash with '.$booker,
                'account_number' => $number,
                'account_type_id' => 0,
                'note' => 'Collections by order booker '.$booker.' not yet handed over. Hand over = Fund transfer to shop cash.',
                'created_by' => $user_id,
            ]);
        }

        return $account->id;
    }
}
