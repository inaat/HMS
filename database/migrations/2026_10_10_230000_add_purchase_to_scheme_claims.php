<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A claim settled in stock: the purchase (received at cost, paid by the claim) that brought the goods in. */
return new class extends Migration
{
    public function up()
    {
        if (Schema::hasColumn('scheme_claims', 'purchase_transaction_id')) {
            return;
        }
        Schema::table('scheme_claims', function (Blueprint $table) {
            $table->unsignedInteger('purchase_transaction_id')->nullable()->comment('free stock received for the claim');
        });
    }

    public function down()
    {
        Schema::table('scheme_claims', function (Blueprint $table) {
            $table->dropColumn('purchase_transaction_id');
        });
    }
};
