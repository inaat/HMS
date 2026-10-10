<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Claims for supplier-funded trade schemes: the free goods given in a period are claimed back from the supplier
 * (e.g. Hilal), then settled by credit note (supplier ledger discount), cash / bank, or free stock.
 */
return new class extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('scheme_claims')) {
        Schema::create('scheme_claims', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('business_id')->index();
            $table->string('claim_no', 40);
            $table->unsignedInteger('supplier_id')->index();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('total_value', 22, 4)->default(0)->comment('claimed amount');
            $table->decimal('sale_value', 22, 4)->default(0)->comment('free goods at sale price, for information');
            $table->enum('status', ['claimed', 'received'])->default('claimed');
            $table->enum('settled_by', ['credit_note', 'cash', 'stock'])->nullable();
            $table->decimal('received_amount', 22, 4)->nullable();
            $table->date('settled_at')->nullable();
            $table->string('settlement_ref')->nullable();
            $table->unsignedInteger('ledger_transaction_id')->nullable()->comment('ledger_discount on the supplier (credit note)');
            $table->unsignedInteger('account_transaction_id')->nullable()->comment('deposit into a payment account (cash / bank)');
            $table->text('notes')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();
        });
        }

        if (Schema::hasTable('scheme_claim_lines')) {
            return;
        }
        Schema::create('scheme_claim_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('scheme_claim_id')->index();
            $table->unsignedBigInteger('trade_scheme_id');
            $table->unsignedInteger('variation_id')->nullable();
            $table->decimal('free_qty', 22, 4)->default(0)->comment('base units');
            $table->decimal('cost_value', 22, 4)->default(0);
            $table->decimal('sale_value', 22, 4)->default(0);
        });
    }

    public function down()
    {
        Schema::dropIfExists('scheme_claim_lines');
        Schema::dropIfExists('scheme_claims');
    }
};
