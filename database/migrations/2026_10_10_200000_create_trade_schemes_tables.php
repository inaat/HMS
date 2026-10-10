<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trade schemes ("buy 12 get 1 free"): the scheme master, its slabs, and which scheme a sell line used.
 * Only adds tables / columns; nothing existing changes.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::create('trade_schemes', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('business_id')->index();
            $table->string('code', 40);
            $table->string('name');
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->boolean('is_active')->default(1);
            // what is bought: a product (all its variations) or one variation, counted in unit_id (CTN, piece ...)
            $table->unsignedInteger('product_id')->index();
            $table->unsignedInteger('variation_id')->nullable();
            $table->unsignedInteger('unit_id')->nullable();
            // what is free: the same product (as a discount on its line) or another variation (a free line)
            $table->enum('free_mode', ['same', 'other'])->default('same');
            $table->unsignedInteger('free_variation_id')->nullable();
            $table->unsignedInteger('free_unit_id')->nullable();
            // slabs repeat: 12+1 gives 2 free for 24, 3 for 36 ...
            $table->boolean('repeat')->default(1);
            $table->text('location_ids')->nullable()->comment('JSON list; empty = all locations');
            $table->enum('funded_by', ['own', 'supplier'])->default('own');
            $table->unsignedInteger('supplier_id')->nullable();
            $table->decimal('budget_qty', 22, 4)->nullable()->comment('total free qty allowed, in the free unit; empty = no limit');
            $table->text('notes')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'code']);
        });

        Schema::create('trade_scheme_slabs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('trade_scheme_id')->index();
            $table->decimal('buy_qty', 22, 4)->comment('in the scheme unit');
            $table->decimal('free_qty', 22, 4)->comment('in the free unit');
        });

        Schema::table('transaction_sell_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('trade_scheme_id')->nullable()->index();
            $table->decimal('scheme_free_qty', 22, 4)->default(0)->comment('free quantity in base units given by the scheme on this line');
        });
    }

    public function down()
    {
        Schema::table('transaction_sell_lines', function (Blueprint $table) {
            $table->dropColumn(['trade_scheme_id', 'scheme_free_qty']);
        });
        Schema::dropIfExists('trade_scheme_slabs');
        Schema::dropIfExists('trade_schemes');
    }
};
