<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('google_drive_settings')) {
            return;
        }
        Schema::create('google_drive_settings', function (Blueprint $table) {
            $table->id();
            $table->text('client_id')->nullable();
            $table->text('client_secret')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('scope')->nullable();
            $table->string('connected_email')->nullable();
            $table->string('folder_id')->nullable();
            $table->unsignedInteger('keep_last')->default(10);
            $table->timestamp('last_upload_at')->nullable();
            $table->text('last_upload_status')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_drive_settings');
    }
};
