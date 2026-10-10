<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier bonus on purchases (buy 10 get 1 free): the free part of a purchase line, in base units. The line's
 * quantity includes it and its prices are the effective cost (amount ÷ paid + free).
 */
return new class extends Migration
{
    public function up()
    {
        Schema::table('purchase_lines', function (Blueprint $table) {
            $table->decimal('bonus_qty', 22, 4)->default(0)->comment('free (bonus) quantity in base units, included in quantity');
        });
    }

    public function down()
    {
        Schema::table('purchase_lines', function (Blueprint $table) {
            $table->dropColumn('bonus_qty');
        });
    }
};
