<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('transaction_payments', 'return_transaction_id')) {
            return;
        }
        //Sell return a 'return_adjustment' payment belongs to: a return settling a customer's unpaid invoice
        //is stored as a pair of such payments (one on the invoice, one on the return), no money moves
        Schema::table('transaction_payments', function (Blueprint $table) {
            $table->unsignedInteger('return_transaction_id')->nullable()->after('transaction_id');
            $table->index('return_transaction_id');
        });
    }

    public function down(): void
    {
        Schema::table('transaction_payments', function (Blueprint $table) {
            $table->dropIndex(['return_transaction_id']);
            $table->dropColumn('return_transaction_id');
        });
    }
};
