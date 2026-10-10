<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Investor capital and payouts can go through a payment account (cash / bank): which account, and the account entry
 * made for it (deleted with the record). Empty for old records. Accounting posts them to the books.
 */
return new class extends Migration
{
    public function up()
    {
        foreach (['investor_capitals', 'investor_payouts'] as $name) {
            if (Schema::hasTable($name) && ! Schema::hasColumn($name, 'account_id')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->unsignedInteger('account_id')->nullable();
                    $table->unsignedInteger('account_transaction_id')->nullable()->index();
                });
            }
        }
    }

    public function down()
    {
        foreach (['investor_capitals', 'investor_payouts'] as $name) {
            if (Schema::hasColumn($name, 'account_id')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->dropIndex(['account_transaction_id']);
                    $table->dropColumn(['account_id', 'account_transaction_id']);
                });
            }
        }
    }
};
