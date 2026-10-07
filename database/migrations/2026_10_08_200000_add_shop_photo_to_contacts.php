<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shop photo taken by an order booker (path under public/, e.g. uploads/booker/<uuid>.jpg). The same path is used
 * on the cloud copy, which keeps the file the phone uploaded.
 */
return new class extends Migration
{
    public function up()
    {
        if (! Schema::hasColumn('contacts', 'shop_photo')) {
            Schema::table('contacts', function (Blueprint $table) {
                $table->string('shop_photo')->nullable()->after('position');
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('contacts', 'shop_photo')) {
            Schema::table('contacts', function (Blueprint $table) {
                $table->dropColumn('shop_photo');
            });
        }
    }
};
