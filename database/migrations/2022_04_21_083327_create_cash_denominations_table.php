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
        if (! Schema::hasTable('cash_denominations')) {
            Schema::create('cash_denominations', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->integer('business_id');
                $table->decimal('amount', 22, 4);
                $table->integer('total_count');
                $table->morphs('model');
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
        Schema::dropIfExists('cash_denominations');
    }
};
