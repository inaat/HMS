<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Order booker may edit shops from the app (User Management > Edit user > "Can edit shops"); off = edit locked.
 * Cloud: mb_users.can_edit (pushed copy).
 */
return new class extends Migration
{
    public function up()
    {
        if (! Schema::hasColumn('users', 'mobile_can_edit_shops')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('mobile_can_edit_shops')->default(1);
            });
        }
        if (Schema::hasTable('mb_users') && ! Schema::hasColumn('mb_users', 'can_edit')) {
            Schema::table('mb_users', function (Blueprint $table) {
                $table->boolean('can_edit')->default(1);
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('users', 'mobile_can_edit_shops')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('mobile_can_edit_shops');
            });
        }
        if (Schema::hasTable('mb_users') && Schema::hasColumn('mb_users', 'can_edit')) {
            Schema::table('mb_users', function (Blueprint $table) {
                $table->dropColumn('can_edit');
            });
        }
    }
};
