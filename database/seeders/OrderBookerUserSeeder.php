<?php

namespace Database\Seeders;

use App\Business;
use App\Services\MobileSync\LocalSnapshot;
use App\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Creates a first order booker for the mobile app: user "booker1" with the Order Booker role (made by
 * OrderBookerRoleSeeder if missing) in the first business, with a random password printed once.
 * Safe to run again: an existing booker1 is left alone. Change the password in User Management > Users.
 *
 *   php artisan db:seed --class=OrderBookerUserSeeder
 */
class OrderBookerUserSeeder extends Seeder
{
    const USERNAME = 'booker1';

    public function run()
    {
        $this->call(OrderBookerRoleSeeder::class);

        if (User::where('username', self::USERNAME)->exists()) {
            $this->command->info('Already there: user '.self::USERNAME.' (password unchanged)');

            return;
        }

        $business_id = Business::orderBy('id')->value('id');
        $password = 'bk'.random_int(100000, 999999);

        $user = User::create([
            'user_type' => 'user',
            'surname' => '',
            'first_name' => 'Booker',
            'last_name' => 'One',
            'username' => self::USERNAME,
            'email' => self::USERNAME.'@booker.local',
            'password' => bcrypt($password),
            'business_id' => $business_id,
            'allow_login' => 1,
            'status' => 'active',
            'language' => 'en',
        ]);
        $user->assignRole(LocalSnapshot::BOOKER_ROLE.'#'.$business_id);

        $this->command->info('Created order booker: username '.self::USERNAME.'  password '.$password);
        $this->command->line('It reaches the mobile app with the next sync (Sell > Mobile orders > Sync now).');
    }
}
