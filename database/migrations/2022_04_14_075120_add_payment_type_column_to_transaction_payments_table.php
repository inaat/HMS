<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // databases imported from another install may already have the column
        // without this migration being recorded
        if (Schema::hasColumn('transaction_payments', 'payment_type')) {
            return;
        }

        Schema::table('transaction_payments', function (Blueprint $table) {
            $table->string('payment_type')->nullable()->after('method')->comments('either credit or debit')->index();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
    }
};
