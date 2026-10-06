<?php

namespace App\Listeners;

use App\Transaction;
use App\Utils\TransactionUtil;

/**
 * A cash refund on a sell return was added, edited or deleted (from any screen): the credit the return
 * has left changed, so it settles the customer's unpaid invoices again (TransactionUtil::settleSellReturn).
 * Listens to TransactionPaymentAdded / Updated / Deleted.
 */
class SettleSellReturnOnPaymentChange
{
    protected $transactionUtil;

    public function __construct(TransactionUtil $transactionUtil)
    {
        $this->transactionUtil = $transactionUtil;
    }

    public function handle($event)
    {
        $payment = $event->transactionPayment;
        if (empty($payment) || empty($payment->transaction_id) || $payment->method == TransactionUtil::RETURN_ADJUSTMENT_METHOD) {
            return;
        }

        $sell_return = Transaction::where('id', $payment->transaction_id)->where('type', 'sell_return')->first();
        if (! empty($sell_return)) {
            $this->transactionUtil->settleSellReturn($sell_return);
        }
    }
}
