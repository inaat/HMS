<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

/**
 * Zakat module (App\Utils\ZakatUtil): yearly calculation snapshots, payments in cash or goods, and a marker on the
 * stock adjustments made when zakat is given in products (so they are not counted as a business loss in P&L).
 */
return new class extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('zakat_years')) {
            Schema::create('zakat_years', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('business_id')->index();
                $table->string('maslak', 30);
                $table->date('zakat_date')->comment('The day the zakat year completes (hawl)');
                $table->string('hijri_label', 60)->nullable();
                $table->text('settings')->nullable()->comment('JSON snapshot of the zakat settings used');
                $table->decimal('cash', 22, 4)->default(0);
                $table->decimal('stock_value', 22, 4)->default(0);
                $table->decimal('receivables', 22, 4)->default(0);
                $table->decimal('payables', 22, 4)->default(0);
                $table->text('manual_lines')->nullable()->comment('JSON [{label, amount}] added by hand (+ or -)');
                $table->decimal('net_wealth', 22, 4)->default(0);
                $table->decimal('nisab_value', 22, 4)->default(0);
                $table->decimal('rate', 8, 4)->default(2.5);
                $table->decimal('zakat_due', 22, 4)->default(0);
                $table->string('status', 10)->default('open')->comment('open | locked');
                $table->unsignedInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('zakat_payments')) {
            Schema::create('zakat_payments', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('business_id')->index();
                $table->unsignedInteger('zakat_year_id')->nullable()->index();
                $table->string('kind', 10)->comment('cash | goods');
                $table->string('recipient_name')->nullable();
                $table->string('recipient_mobile', 50)->nullable();
                $table->unsignedInteger('contact_id')->nullable();
                $table->string('category', 30)->nullable()->comment('One of the 8 masarif (ZakatUtil::CATEGORIES)');
                $table->decimal('amount_value', 22, 4)->default(0)->comment('Counts toward zakat (goods: selling value)');
                $table->decimal('cost_value', 22, 4)->default(0)->comment('Goods: purchase cost');
                $table->unsignedInteger('account_id')->nullable()->comment('Cash: account paid from');
                $table->unsignedInteger('transaction_id')->nullable()->comment('Goods: the stock adjustment');
                $table->unsignedInteger('location_id')->nullable();
                $table->string('note', 500)->nullable();
                $table->dateTime('paid_on');
                $table->unsignedInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('transactions', 'is_zakat')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->boolean('is_zakat')->default(0)->comment('Stock adjustment = zakat given in goods');
            });
        }

        Permission::firstOrCreate(['name' => 'zakat.manage', 'guard_name' => 'web']);
    }

    public function down()
    {
        Schema::dropIfExists('zakat_payments');
        Schema::dropIfExists('zakat_years');
        if (Schema::hasColumn('transactions', 'is_zakat')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->dropColumn('is_zakat');
            });
        }
    }
};
