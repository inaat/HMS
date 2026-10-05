<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('goodstransportcompany')) {
            return;
        }
        Schema::create('goodstransportcompany', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id')->index();
            $table->string('name', 191);
            $table->text('description')->nullable();
            $table->unsignedInteger('created_by')->index();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goodstransportcompany');
    }
};
