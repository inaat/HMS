<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
        if (! Schema::hasTable('dropbox_tokens')) {
            Schema::create('dropbox_tokens', function (Blueprint $table) {
                $table->id();
                $table->string('dropbox_app_key')->nullable();
                $table->string('dropbox_app_secret')->nullable();
                $table->string('dropbox_auth_token')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('dropbox_tokens');
    }
};
