<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // the sell list filters on business/type/status/sub_type and sorts newest
    // first; with this index MySQL reads the newest rows in order and stops at the
    // page size instead of sorting every sale. location_id is on the end so the
    // row count is answered from the index alone, without reading each sale
    public function up(): void
    {
        if (! empty(DB::select("SHOW INDEX FROM transactions WHERE Key_name = 'transactions_sell_list_index'"))) {
            return;
        }

        Schema::table('transactions', function (Blueprint $table) {
            $table->index(['business_id', 'type', 'status', 'sub_type', 'transaction_date', 'location_id'], 'transactions_sell_list_index');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('transactions_sell_list_index');
        });
    }
};
