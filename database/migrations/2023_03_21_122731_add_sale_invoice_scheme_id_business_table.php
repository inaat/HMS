<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

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
        // the UPDATE only seeds the new column: on a database that already has it,
        // it would overwrite each location's chosen sale invoice scheme
        if (! Schema::hasColumn('business_locations', 'sale_invoice_scheme_id')) {
            Schema::table('business_locations', function (Blueprint $table) {
                $table->integer('sale_invoice_scheme_id')->after('invoice_scheme_id')->nullable();
                //invoice_scheme_id
            });

            DB::statement('UPDATE business_locations SET sale_invoice_scheme_id = invoice_scheme_id');
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('business_locations', function (Blueprint $table) {
            //
        });
    }
};
