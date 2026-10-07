<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A route can have several order bookers: booker_ids = JSON list of user ids. booker_id stays (first booker) so
 * older booker apps keep working. Cloud: mb_routes.booker_ids (pushed copy).
 */
return new class extends Migration
{
    public function up()
    {
        foreach (['booker_routes', 'mb_routes'] as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'booker_ids')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->string('booker_ids', 500)->nullable()->after('booker_id')->comment('JSON list of order booker user ids');
                });
                DB::table($table)->whereNotNull('booker_id')->update(['booker_ids' => DB::raw("CONCAT('[', booker_id, ']')")]);
            }
        }
    }

    public function down()
    {
        foreach (['booker_routes', 'mb_routes'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'booker_ids')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropColumn('booker_ids');
                });
            }
        }
    }
};
