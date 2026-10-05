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
        // skipped when the change is already in the database (e.g. a database
        // imported from another install without this migration being recorded)
        if (! Schema::hasColumn('products', 'secondary_unit_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->integer('secondary_unit_id')->nullable()->after('unit_id')->index();
            });
        }

        if (! Schema::hasColumn('purchase_lines', 'secondary_unit_quantity')) {
            Schema::table('purchase_lines', function (Blueprint $table) {
                $table->decimal('secondary_unit_quantity', 22, 4)->default(0)->after('quantity');
            });
        }

        if (! Schema::hasColumn('transaction_sell_lines', 'secondary_unit_quantity')) {
            Schema::table('transaction_sell_lines', function (Blueprint $table) {
                $table->decimal('secondary_unit_quantity', 22, 4)->default(0)->after('quantity');
            });
        }

        if (! Schema::hasColumn('stock_adjustment_lines', 'secondary_unit_quantity')) {
            Schema::table('stock_adjustment_lines', function (Blueprint $table) {
                $table->decimal('secondary_unit_quantity', 22, 4)->default(0)->after('quantity');
            });
        }
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
