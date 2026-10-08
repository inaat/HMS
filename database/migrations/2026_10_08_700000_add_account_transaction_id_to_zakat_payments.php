<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Zakat paid in cash from an account: remember the account entry, so deleting the zakat also removes it. */
return new class extends Migration
{
    public function up()
    {
        if (Schema::hasTable('zakat_payments') && ! Schema::hasColumn('zakat_payments', 'account_transaction_id')) {
            Schema::table('zakat_payments', function (Blueprint $table) {
                $table->unsignedInteger('account_transaction_id')->nullable()->after('account_id');
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('zakat_payments', 'account_transaction_id')) {
            Schema::table('zakat_payments', function (Blueprint $table) {
                $table->dropColumn('account_transaction_id');
            });
        }
    }
};
