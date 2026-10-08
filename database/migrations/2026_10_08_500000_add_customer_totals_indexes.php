<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Covering indexes for the customer list / defaulters totals (ContactUtil::customerTotalsQuery): MySQL adds up
 * invoices and payments from these slim indexes instead of reading every transaction row.
 */
return new class extends Migration
{
    private array $indexes = [
        'transactions' => ['tx_contact_totals', '(business_id, type, contact_id, status, final_total, transaction_date)'],
        'transaction_payments' => ['tp_tx_amount', '(transaction_id, is_return, amount)'],
    ];

    public function up()
    {
        foreach ($this->indexes as $table => [$name, $columns]) {
            if (empty(DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$name]))) {
                DB::statement("ALTER TABLE `{$table}` ADD INDEX `{$name}` {$columns}");
            }
        }
    }

    public function down()
    {
        foreach ($this->indexes as $table => [$name]) {
            if (! empty(DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$name]))) {
                DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$name}`");
            }
        }
    }
};
