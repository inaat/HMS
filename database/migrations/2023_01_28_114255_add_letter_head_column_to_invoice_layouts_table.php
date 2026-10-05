<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // skipped when the change is already in the database (e.g. a database
        // imported from another install without this migration being recorded)
        if (! Schema::hasColumn('invoice_layouts', 'show_letter_head')) {
            Schema::table('invoice_layouts', function (Blueprint $table) {
                $table->boolean('show_letter_head')->default(0)->after('business_id');
                $table->string('letter_head')->nullable()->after('show_letter_head');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
    }
};
