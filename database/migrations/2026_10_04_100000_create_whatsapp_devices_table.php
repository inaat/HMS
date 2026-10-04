<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('whatsapp_devices')) {
            return;
        }
        Schema::create('whatsapp_devices', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('instance', 191)->unique();
            $table->string('name', 191);
            $table->string('number', 191)->nullable();
            $table->enum('connection_type', ['qr', 'meta_api'])->default('qr');
            $table->enum('status', ['initiate', 'connected', 'disconnected'])->default('initiate');
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('delay_time')->nullable();
            $table->string('phone_number_id', 191)->nullable();
            $table->string('waba_id', 191)->nullable();
            $table->text('access_token')->nullable();
            $table->timestamp('last_used_at', 6)->nullable();
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
        });

        // the gateway session that was hardcoded as 'Fine' stays with the first
        // business, so the phone already scanned there keeps working
        $business_id = DB::table('business')->orderBy('id')->value('id');
        if (! empty($business_id)) {
            DB::table('whatsapp_devices')->insert([
                'business_id' => $business_id,
                'instance' => 'Fine',
                'name' => 'Fine',
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_devices');
    }
};
