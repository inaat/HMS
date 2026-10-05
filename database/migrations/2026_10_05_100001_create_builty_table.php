<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('builty')) {
            return;
        }
        Schema::create('builty', function (Blueprint $table) {
            $table->increments('id');
            $table->string('biulty_number', 191);
            $table->unsignedInteger('goodstransportcompany_id');
            $table->date('biulty_date');
            $table->dateTime('recevied_date');
            $table->string('from_address', 191);
            $table->string('to_address', 191);
            $table->string('sender_name', 191);
            $table->decimal('amount', 8, 2)->default(0);
            $table->unsignedInteger('created_by');
            $table->string('document', 191)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('builty');
    }
};
