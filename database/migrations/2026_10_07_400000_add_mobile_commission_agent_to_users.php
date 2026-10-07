<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An order booker's sales can earn commission for a sales commission agent (User Management > Edit user > "Commission
 * agent for mobile orders"): the sales order and invoice made from the booker's order get that agent, as if picked on
 * the Add Sale screen, so commission rules, reports and payouts count them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'mobile_commission_agent_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedInteger('mobile_commission_agent_id')->nullable()->comment('Order booker: commission agent for their mobile orders');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'mobile_commission_agent_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('mobile_commission_agent_id');
            });
        }
    }
};
