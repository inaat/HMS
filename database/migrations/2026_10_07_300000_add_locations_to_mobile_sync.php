<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Order bookers with several business locations (config/mobile_sync.php). A booker may book for the locations of
 * their POS user ("Access locations" / "Access all locations"); stock and price are per location; an order carries
 * its location to the sales order and invoice. Rows without a location belong to MOBILE_SYNC_LOCATION_ID.
 * The mb_* tables live on the cloud copy, mobile_inbox on the local PC; each part runs where its table exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mb_products') && ! Schema::hasColumn('mb_products', 'loc_stock')) {
            Schema::table('mb_products', function (Blueprint $table) {
                $table->text('loc_stock')->nullable()->comment('JSON {location_id: [qty_available, reserved_qty]}');
                $table->text('loc_price')->nullable()->comment('JSON {location_id: price}; only locations selling the product');
            });
        }
        if (Schema::hasTable('mb_users') && ! Schema::hasColumn('mb_users', 'locations')) {
            Schema::table('mb_users', function (Blueprint $table) {
                $table->text('locations')->nullable()->comment('JSON [location ids] the booker may book for');
            });
        }
        if (Schema::hasTable('mb_orders') && ! Schema::hasColumn('mb_orders', 'location_id')) {
            Schema::table('mb_orders', function (Blueprint $table) {
                $table->unsignedInteger('location_id')->nullable()->after('contact_id');
            });
        }
        if (Schema::hasTable('mobile_inbox') && ! Schema::hasColumn('mobile_inbox', 'location_id')) {
            Schema::table('mobile_inbox', function (Blueprint $table) {
                $table->unsignedInteger('location_id')->nullable()->after('contact_id');
            });
        }
    }

    public function down(): void
    {
        foreach (['mb_products' => ['loc_stock', 'loc_price'], 'mb_users' => ['locations'], 'mb_orders' => ['location_id'], 'mobile_inbox' => ['location_id']] as $table => $cols) {
            if (Schema::hasTable($table)) {
                Schema::table($table, function (Blueprint $t) use ($table, $cols) {
                    foreach ($cols as $c) {
                        if (Schema::hasColumn($table, $c)) {
                            $t->dropColumn($c);
                        }
                    }
                });
            }
        }
    }
};
