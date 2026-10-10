<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Order-booker tables made after the migrations that extend them (e.g. a copy where create_mobile_sync_tables ran in
 * a later batch) miss columns such as mb_users.locations. Runs those migrations again; each only adds what is missing.
 * Needed for MOBILE_SYNC_ROLE=single, where this server's own booker tables are used.
 */
return new class extends Migration
{
    public function up()
    {
        foreach ([
            '2026_10_07_300000_add_locations_to_mobile_sync.php',
            '2026_10_08_100000_create_booker_field_force_tables.php',
            '2026_10_08_300000_add_mobile_can_edit_shops_to_users.php',
            '2026_10_08_400000_add_booker_ids_to_booker_routes.php',
        ] as $file) {
            $path = __DIR__.'/'.$file;
            if (is_file($path)) {
                (require $path)->up();
            }
        }
    }

    public function down()
    {
        // nothing: the columns belong to the migrations above
    }
};
