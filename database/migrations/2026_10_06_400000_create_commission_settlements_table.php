<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        //One locked period of one commission agent: figures frozen when it is locked, paid by expenses
        if (! Schema::hasTable('commission_settlements')) {
            Schema::create('commission_settlements', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('business_id')->index();
                $table->unsignedInteger('agent_id')->index()->comment('users.id of the commission agent');
                $table->date('period_start');
                $table->date('period_end');
                $table->decimal('sales_amount', 22, 4)->default(0)->comment('Sales of the period, as sold');
                $table->decimal('sales_commission', 22, 4)->default(0);
                $table->decimal('returns_amount', 22, 4)->default(0)->comment('Returns made in the period, any sale date');
                $table->decimal('returns_commission', 22, 4)->default(0);
                $table->decimal('carry_brought_forward', 22, 4)->default(0)->comment('Returns commission left over from the previous period');
                $table->decimal('commission', 22, 4)->default(0)->comment('sales - returns - brought forward');
                $table->decimal('payable', 22, 4)->default(0);
                $table->decimal('carry_forward', 22, 4)->default(0)->comment('Taken off the next period when returns are more than sales');
                $table->unsignedInteger('locked_by')->nullable();
                $table->dateTime('locked_at')->nullable();
                $table->text('note')->nullable();
                $table->timestamps();
            });
        }

        //Expenses that paid a settlement (the expense is the real payment: P&L, accounts, expense list)
        if (! Schema::hasTable('commission_settlement_payments')) {
            Schema::create('commission_settlement_payments', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('settlement_id')->index();
                $table->unsignedInteger('transaction_id')->index()->comment('The expense transaction');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_settlement_payments');
        Schema::dropIfExists('commission_settlements');
    }
};
