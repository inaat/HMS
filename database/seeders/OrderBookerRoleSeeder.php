<?php

namespace Database\Seeders;

use App\Business;
use App\Services\MobileSync\LocalSnapshot;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * Creates the "Order Booker" role for every business (stored as "Order Booker#<business_id>", like roles made in
 * User Management > Roles). Users with this role log in to the mobile booker app only, never the POS website.
 * Safe to run again: existing roles are left alone.
 *
 *   php artisan db:seed --class=OrderBookerRoleSeeder
 */
class OrderBookerRoleSeeder extends Seeder
{
    public function run()
    {
        foreach (Business::pluck('id') as $business_id) {
            $role = Role::firstOrCreate(
                ['name' => LocalSnapshot::BOOKER_ROLE.'#'.$business_id, 'guard_name' => 'web'],
                ['business_id' => $business_id, 'is_default' => 0]
            );
            $this->command->info(($role->wasRecentlyCreated ? 'Created' : 'Already there').": {$role->name}");
        }
    }
}
