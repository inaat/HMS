<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
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
        if (! Schema::hasColumn('business', 'currency_precision')) {
            Schema::table('business', function (Blueprint $table) {
                $table->tinyInteger('currency_precision')->default(2)->after('time_format');
                $table->tinyInteger('quantity_precision')->default(2)->after('currency_precision');
            });
        }

        //clear blade directive cache
        Artisan::call('view:clear');
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
