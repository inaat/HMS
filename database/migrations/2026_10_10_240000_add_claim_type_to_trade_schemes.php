<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** How the supplier settles the claims of a supplier-funded scheme: money (cash / bank), credit note, or stock. */
return new class extends Migration
{
    public function up()
    {
        if (Schema::hasColumn('trade_schemes', 'claim_type')) {
            return;
        }
        Schema::table('trade_schemes', function (Blueprint $table) {
            $table->enum('claim_type', ['cash', 'credit_note', 'stock'])->default('credit_note');
        });
    }

    public function down()
    {
        Schema::table('trade_schemes', function (Blueprint $table) {
            $table->dropColumn('claim_type');
        });
    }
};
