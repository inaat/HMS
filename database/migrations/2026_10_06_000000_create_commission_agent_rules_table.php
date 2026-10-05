<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('commission_agent_rules')) {
            return;
        }
        //Brand / product wise commission of a sales commission agent; overrides users.cmmsn_percent
        Schema::create('commission_agent_rules', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('brand_id')->nullable();
            $table->unsignedInteger('product_id')->nullable();
            $table->enum('type', ['percentage', 'fixed'])->default('percentage');
            $table->decimal('value', 22, 4)->default(0);
            $table->timestamps();

            $table->index(['business_id', 'user_id']);
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_agent_rules');
    }
};
