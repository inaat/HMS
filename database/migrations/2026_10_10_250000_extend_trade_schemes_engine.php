<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Trade scheme engine, second step (supplier trade plans like CandyLand's):
 *  - what is bought: one product, a group of products, or a whole brand;
 *  - condition: quantity (scheme unit / each product's box-carton / pieces) or bill value in Rs;
 *  - reward: free goods or a % discount, the % optionally by customer class (A–E);
 *  - channel: all / retail / wholesale (customer outlet type).
 * Sale lines keep every scheme they got in scheme_data (JSON: id, free_qty, discount).
 */
return new class extends Migration
{
    public function up()
    {
        DB::statement('ALTER TABLE trade_schemes MODIFY product_id INT UNSIGNED NULL');
        // each column guarded: some may already exist on a server where an earlier run stopped half way
        $has = fn ($t, $c) => Schema::hasColumn($t, $c);
        Schema::table('trade_schemes', function (Blueprint $table) use ($has) {
            if (! $has('trade_schemes', 'scope')) {
                $table->enum('scope', ['product', 'products', 'brand'])->default('product');
            }
            if (! $has('trade_schemes', 'product_ids')) {
                $table->text('product_ids')->nullable()->comment('JSON list of "v<variation_id>" / "p<product_id>" for scope products');
            }
            if (! $has('trade_schemes', 'brand_id')) {
                $table->unsignedInteger('brand_id')->nullable();
            }
            if (! $has('trade_schemes', 'condition_type')) {
                $table->enum('condition_type', ['qty', 'value'])->default('qty');
            }
            if (! $has('trade_schemes', 'count_unit')) {
                $table->enum('count_unit', ['unit', 'big', 'base'])->default('unit')
                    ->comment('qty counted in: unit = unit_id (one product), big = each product box/carton, base = pieces');
            }
            if (! $has('trade_schemes', 'reward_type')) {
                $table->enum('reward_type', ['free', 'percent'])->default('free');
            }
            if (! $has('trade_schemes', 'channel')) {
                $table->enum('channel', ['all', 'retail', 'wholesale'])->default('all');
            }
        });
        Schema::table('trade_scheme_slabs', function (Blueprint $table) use ($has) {
            if (! $has('trade_scheme_slabs', 'percent')) {
                $table->decimal('percent', 8, 4)->nullable()->comment('reward % (reward_type percent)');
            }
            if (! $has('trade_scheme_slabs', 'class_percents')) {
                $table->text('class_percents')->nullable()->comment('JSON {"A":1.5,"B":1.5,"C":1,...}: % by customer class');
            }
        });
        if (! $has('transaction_sell_lines', 'scheme_data')) {
            Schema::table('transaction_sell_lines', function (Blueprint $table) {
                $table->text('scheme_data')->nullable()->comment('JSON [{id, free_qty (base), discount (Rs)}] of the schemes this line got');
            });
        }
    }

    public function down()
    {
        Schema::table('transaction_sell_lines', function (Blueprint $table) {
            $table->dropColumn('scheme_data');
        });
        Schema::table('trade_scheme_slabs', function (Blueprint $table) {
            $table->dropColumn(['percent', 'class_percents']);
        });
        Schema::table('trade_schemes', function (Blueprint $table) {
            $table->dropColumn(['scope', 'product_ids', 'brand_id', 'condition_type', 'count_unit', 'reward_type', 'channel']);
        });
    }
};
