<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('builtyitems')) {
            return;
        }
        Schema::create('builtyitems', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('builty_id');
            $table->string('item', 191);
            $table->string('item_quantity', 191);
            $table->string('weight', 191);
            $table->decimal('charges', 8, 2)->default(0);
            $table->timestamps();

            $table->foreign('builty_id')->references('id')->on('builty')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('builtyitems');
    }
};
