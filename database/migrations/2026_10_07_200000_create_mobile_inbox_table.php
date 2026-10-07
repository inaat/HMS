<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local side of the order-booker sync: what `mobile-sync:run` collected from the cloud. Orders and payments wait
 * here for staff approval (Sell > Mobile orders); booker-made customers are created as contacts at once and kept
 * here only to map the phone's UUID to the contact. cloud_status is the last status reported back to the cloud.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_inbox', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('kind', 20)->comment('order | payment | customer');
            $table->char('uuid', 36)->unique();
            $table->unsignedInteger('booker_id')->nullable()->comment('users.id');
            $table->string('number', 40)->nullable()->comment('Slip / receipt number from the phone');
            $table->unsignedInteger('contact_id')->nullable()->index();
            $table->char('customer_uuid', 36)->nullable();
            $table->decimal('total', 22, 4)->default(0);
            $table->boolean('short_stock')->default(0);
            $table->longText('data')->comment('JSON as received: lines / allocations / customer fields');
            $table->dateTime('booked_at')->nullable()->comment('Order date / paid on, from the phone');
            $table->string('status', 20)->default('waiting')->index()->comment('waiting | approved | rejected | invoiced');
            $table->unsignedInteger('transaction_id')->nullable()->comment('Sales order made on approval');
            $table->unsignedInteger('payment_id')->nullable()->comment('Parent transaction_payments.id made on approval');
            $table->string('ref')->nullable()->comment('Sales order no / payment ref no');
            $table->string('invoice_no')->nullable();
            $table->string('reject_reason')->nullable();
            $table->unsignedInteger('decided_by')->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->string('cloud_status', 20)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_inbox');
    }
};
