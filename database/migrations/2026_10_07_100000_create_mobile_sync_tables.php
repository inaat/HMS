<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Order-booker mobile sync (cloud side, see config/mobile_sync.php).
 *
 * mb_users / mb_products / mb_customers / mb_invoices are read-only copies pushed by the local PC (ids are the
 * local ids). mb_orders / mb_payments are what bookers send from the app; each carries the UUID the phone made, so
 * a resend after a dropped connection is ignored. The local PC collects them and reports their status back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mb_users', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary()->comment('Local users.id');
            $table->string('username')->unique();
            $table->string('password');
            $table->string('name');
            $table->string('code', 20)->comment('Number prefix for this booker, e.g. ALI7');
            $table->boolean('allow_login')->default(1);
            $table->char('row_hash', 32);
            $table->timestamps();
        });

        Schema::create('mb_tokens', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('user_id')->index();
            $table->char('token_hash', 64)->unique();
            $table->string('device_name')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('mb_products', function (Blueprint $table) {
            $table->unsignedInteger('variation_id')->primary();
            $table->unsignedInteger('product_id');
            $table->string('name');
            $table->string('sku')->nullable();
            $table->string('unit', 50)->nullable()->comment('Base unit; price and stock are per base unit');
            $table->text('units')->nullable()->comment('JSON [{id, name, multiplier, allow_decimal}] the product sells in, as Util::getSubUnits');
            $table->string('category')->nullable();
            $table->string('brand')->nullable();
            $table->decimal('price', 22, 4)->default(0)->comment('Selling price inc. tax at the booker location');
            $table->boolean('enable_stock')->default(1);
            $table->decimal('stock_qty', 22, 4)->default(0)->comment('qty_available at the location');
            $table->decimal('reserved_qty', 22, 4)->default(0)->comment('Local sales orders not yet invoiced');
            $table->string('image')->nullable();
            $table->boolean('active')->default(1);
            $table->char('row_hash', 32);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable()->index();
        });

        Schema::create('mb_customers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('local_id')->nullable()->unique()->comment('Local contacts.id, null until the local PC creates it');
            $table->char('uuid', 36)->nullable()->unique()->comment('Set when a booker added the customer');
            $table->string('name');
            $table->string('business_name')->nullable();
            $table->string('mobile')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->decimal('credit_limit', 22, 4)->nullable();
            $table->decimal('balance_due', 22, 4)->default(0);
            $table->unsignedInteger('created_by')->nullable()->comment('mb_users.id when a booker added it');
            $table->string('status', 20)->default('active')->comment('active | pending (booker-made, not on local yet) | deleted');
            $table->char('row_hash', 32)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable()->index();
        });

        Schema::create('mb_invoices', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary()->comment('Local transactions.id');
            $table->unsignedInteger('contact_id')->index();
            $table->string('invoice_no');
            $table->dateTime('transaction_date');
            $table->decimal('final_total', 22, 4);
            $table->decimal('paid', 22, 4)->default(0);
            $table->decimal('due', 22, 4)->default(0);
            $table->boolean('active')->default(1)->comment('0 = fully paid or gone; the app drops it');
            $table->char('row_hash', 32);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable()->index();
        });

        Schema::create('mb_orders', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('uuid', 36)->unique();
            $table->unsignedInteger('user_id')->index();
            $table->string('number', 40)->comment('Order slip number from the phone, e.g. ALI7-0001');
            $table->unsignedInteger('seq')->default(0);
            $table->unsignedInteger('contact_id')->nullable()->comment('Local contacts.id');
            $table->char('customer_uuid', 36)->nullable()->comment('Booker-made customer');
            $table->dateTime('order_date');
            $table->text('note')->nullable();
            $table->decimal('total', 22, 4)->default(0);
            $table->boolean('short_stock')->default(0);
            $table->string('status', 20)->default('pending')->index()->comment('pending | received | approved | rejected | invoiced');
            $table->unsignedInteger('local_so_id')->nullable();
            $table->string('local_so_no')->nullable();
            $table->string('invoice_no')->nullable();
            $table->string('reject_reason')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable()->index();
        });

        Schema::create('mb_order_lines', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('order_id')->index();
            $table->unsignedInteger('variation_id');
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('sub_unit_id')->nullable()->comment('Unit the booker chose, e.g. CTN 24');
            $table->decimal('multiplier', 22, 4)->default(1);
            $table->decimal('sub_unit_qty', 22, 4)->comment('Quantity in the chosen unit');
            $table->decimal('sub_unit_price', 22, 4)->comment('Price per chosen unit');
            $table->decimal('quantity', 22, 4)->comment('Base units = sub_unit_qty x multiplier, as sell lines store it');
            $table->decimal('unit_price', 22, 4)->comment('Price per base unit');
            $table->decimal('line_total', 22, 4);
        });

        Schema::create('mb_payments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('uuid', 36)->unique();
            $table->unsignedInteger('user_id')->index();
            $table->string('number', 40)->comment('Receipt number from the phone, e.g. ALI7-R-0001');
            $table->unsignedInteger('seq')->default(0);
            $table->unsignedInteger('contact_id')->nullable();
            $table->char('customer_uuid', 36)->nullable();
            $table->decimal('amount', 22, 4);
            $table->string('method', 30)->default('cash');
            $table->string('cheque_number')->nullable();
            $table->string('bank_ref')->nullable();
            $table->text('note')->nullable();
            $table->text('allocations')->nullable()->comment('JSON [{invoice_id, amount}]; empty = oldest dues first');
            $table->dateTime('paid_on');
            $table->string('status', 20)->default('pending')->index()->comment('pending | received | approved | rejected');
            $table->string('local_ref')->nullable()->comment('Local payment_ref_no once posted');
            $table->string('reject_reason')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable()->index();
        });

        Schema::create('mb_meta', function (Blueprint $table) {
            $table->string('key', 50)->primary();
            $table->text('value')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['mb_meta', 'mb_payments', 'mb_order_lines', 'mb_orders', 'mb_invoices', 'mb_customers', 'mb_products', 'mb_tokens', 'mb_users'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
