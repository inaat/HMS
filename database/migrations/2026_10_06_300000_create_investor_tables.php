<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

/**
 * Investors: profit share by brand / product / overall business, capital ledger, locked settlements and payouts.
 * Kept separate from the Accounts module on purpose (nothing is written to account_transactions).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('investors')) {
            Schema::create('investors', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('business_id')->index();
                $table->string('name', 191);
                $table->string('mobile', 50)->nullable();
                $table->unsignedInteger('contact_id')->nullable();
                $table->text('notes')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        //Capital ledger: money put in (invest) or taken back (withdraw)
        if (! Schema::hasTable('investor_capitals')) {
            Schema::create('investor_capitals', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('business_id')->index();
                $table->unsignedInteger('investor_id')->index();
                $table->date('date');
                $table->enum('type', ['invest', 'withdraw']);
                $table->decimal('amount', 22, 4);
                $table->string('method', 50)->nullable();
                $table->string('reference', 191)->nullable();
                $table->text('note')->nullable();
                $table->unsignedInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        //What the investor gets a share of: overall net profit, a brand's or a product's gross profit
        if (! Schema::hasTable('investor_deals')) {
            Schema::create('investor_deals', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('business_id')->index();
                $table->unsignedInteger('investor_id')->index();
                $table->enum('scope', ['overall', 'brand', 'product']);
                $table->unsignedInteger('brand_id')->nullable();
                $table->unsignedInteger('product_id')->nullable();
                $table->unsignedInteger('location_id')->nullable();
                $table->enum('share_type', ['percentage', 'capital'])->default('percentage');
                $table->decimal('share_percent', 8, 4)->default(0);
                $table->decimal('pool_capital', 22, 4)->default(0);
                $table->date('start_date');
                $table->date('end_date')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('investor_settlements')) {
            Schema::create('investor_settlements', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('business_id')->index();
                $table->date('period_start');
                $table->date('period_end');
                $table->enum('status', ['draft', 'locked'])->default('locked');
                $table->dateTime('locked_at')->nullable();
                $table->unsignedInteger('locked_by')->nullable();
                $table->text('note')->nullable();
                $table->timestamps();
            });
        }

        //Frozen figures of a locked settlement, one row per deal
        if (! Schema::hasTable('investor_settlement_lines')) {
            Schema::create('investor_settlement_lines', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('settlement_id')->index();
                $table->unsignedInteger('investor_id')->index();
                $table->unsignedInteger('deal_id')->index();
                $table->string('scope_label', 191);
                $table->decimal('profit_base', 22, 4)->default(0);
                $table->decimal('share_percent_used', 10, 4)->default(0);
                $table->decimal('avg_capital_used', 22, 4)->default(0);
                $table->decimal('share_amount', 22, 4)->default(0);
                $table->decimal('loss_brought_forward', 22, 4)->default(0);
                $table->decimal('loss_carried_forward', 22, 4)->default(0);
                $table->decimal('payable', 22, 4)->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('investor_payouts')) {
            Schema::create('investor_payouts', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('business_id')->index();
                $table->unsignedInteger('investor_id')->index();
                $table->unsignedInteger('settlement_id')->nullable();
                $table->date('paid_on');
                $table->decimal('amount', 22, 4);
                $table->string('method', 50)->nullable();
                $table->string('reference', 191)->nullable();
                $table->text('note')->nullable();
                $table->unsignedInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        foreach (['investor.view', 'investor.create', 'investor.update', 'investor.delete', 'investor.settle', 'investor.payout'] as $name) {
            if (! Permission::where('name', $name)->exists()) {
                Permission::create(['name' => $name, 'guard_name' => 'web']);
            }
        }
    }

    public function down(): void
    {
        foreach (['investor_payouts', 'investor_settlement_lines', 'investor_settlements', 'investor_deals', 'investor_capitals', 'investors'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
