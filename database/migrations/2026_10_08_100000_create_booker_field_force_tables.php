<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Field force for order bookers (Unilever / Hilal style): routes with weekdays (PJP), shop type / class / order on
 * the route, GPS check-in visits, attendance, and bookers' edits to shops waiting for approval.
 * Shop GPS stays in contacts.position ("lat,lng"); shop photos in media (model Contact, model_media_type shop_photo).
 *
 * Local tables (shop PC) are created everywhere; the mb_* landing tables only where mb_customers exists (cloud).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('booker_routes')) {
            Schema::create('booker_routes', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('business_id')->index();
                $table->string('name');
                $table->unsignedInteger('location_id')->nullable();
                $table->string('days', 50)->nullable()->comment('JSON weekdays 1=Mon..7=Sun');
                $table->unsignedInteger('booker_id')->nullable()->index();
                $table->boolean('is_active')->default(1);
                $table->timestamps();
            });
        }

        Schema::table('contacts', function (Blueprint $table) {
            if (! Schema::hasColumn('contacts', 'route_id')) {
                $table->unsignedInteger('route_id')->nullable()->index()->comment('booker_routes.id');
            }
            if (! Schema::hasColumn('contacts', 'outlet_type')) {
                $table->string('outlet_type', 50)->nullable()->comment('kiryana, general store, wholesale, medical...');
            }
            if (! Schema::hasColumn('contacts', 'outlet_class')) {
                $table->string('outlet_class', 5)->nullable()->comment('A / B / C');
            }
            if (! Schema::hasColumn('contacts', 'visit_sequence')) {
                $table->unsignedInteger('visit_sequence')->nullable()->comment('Order on the route');
            }
        });

        if (! Schema::hasTable('booker_visits')) {
            Schema::create('booker_visits', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->char('uuid', 36)->unique();
                $table->unsignedInteger('business_id')->index();
                $table->unsignedInteger('booker_id')->index();
                $table->unsignedInteger('contact_id')->nullable()->index();
                $table->unsignedInteger('route_id')->nullable();
                $table->dateTime('started_at')->index();
                $table->dateTime('ended_at')->nullable();
                $table->decimal('lat', 10, 7)->nullable();
                $table->decimal('lng', 10, 7)->nullable();
                $table->decimal('accuracy_m', 8, 1)->nullable();
                $table->decimal('distance_m', 10, 1)->nullable()->comment('From the shop location; null = shop had none');
                $table->boolean('within_range')->nullable();
                $table->string('photo')->nullable()->comment('Path under public/uploads/booker');
                $table->string('outcome', 20)->default('no_order')->comment('order | payment | no_order | closed | other');
                $table->string('reason')->nullable();
                $table->string('note', 1000)->nullable();
                $table->text('order_uuids')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('booker_attendance')) {
            Schema::create('booker_attendance', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->char('uuid', 36)->unique();
                $table->unsignedInteger('business_id')->index();
                $table->unsignedInteger('booker_id')->index();
                $table->string('kind', 10)->comment('start | end');
                $table->dateTime('at')->index();
                $table->decimal('lat', 10, 7)->nullable();
                $table->decimal('lng', 10, 7)->nullable();
                $table->decimal('accuracy_m', 8, 1)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('booker_customer_updates')) {
            Schema::create('booker_customer_updates', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->char('uuid', 36)->unique();
                $table->unsignedInteger('business_id')->index();
                $table->unsignedInteger('booker_id');
                $table->unsignedInteger('contact_id')->nullable()->index();
                $table->text('fields')->comment('JSON: changes waiting for approval');
                $table->text('applied')->nullable()->comment('JSON: fields that were empty and applied at once');
                $table->string('photo')->nullable();
                $table->string('status', 20)->default('waiting')->comment('waiting | applied | rejected');
                $table->unsignedInteger('decided_by')->nullable();
                $table->dateTime('decided_at')->nullable();
                $table->timestamps();
            });
        }

        // Cloud landing tables (where the booker app's data arrives).
        if (Schema::hasTable('mb_customers')) {
            Schema::table('mb_customers', function (Blueprint $table) {
                foreach (['route_id' => 'int', 'position' => 'str', 'photo_url' => 'str', 'outlet_type' => 'str', 'outlet_class' => 'str', 'visit_sequence' => 'int'] as $col => $type) {
                    if (! Schema::hasColumn('mb_customers', $col)) {
                        $type === 'int' ? $table->unsignedInteger($col)->nullable() : $table->string($col)->nullable();
                    }
                }
            });
            if (! Schema::hasTable('mb_routes')) {
                Schema::create('mb_routes', function (Blueprint $table) {
                    $table->unsignedInteger('id')->primary();
                    $table->string('name');
                    $table->unsignedInteger('location_id')->nullable();
                    $table->string('days', 50)->nullable();
                    $table->unsignedInteger('booker_id')->nullable();
                    $table->boolean('active')->default(1);
                    $table->char('row_hash', 32)->nullable();
                    $table->timestamp('created_at')->nullable();
                    $table->timestamp('updated_at')->nullable()->index();
                });
            }
            foreach (['mb_visits', 'mb_attendance', 'mb_customer_updates'] as $name) {
                if (! Schema::hasTable($name)) {
                    Schema::create($name, function (Blueprint $table) {
                        $table->bigIncrements('id');
                        $table->char('uuid', 36)->unique();
                        $table->unsignedInteger('user_id')->index();
                        $table->longText('data')->comment('JSON as the app sent it');
                        $table->string('photo')->nullable()->comment('File under storage/app/booker');
                        $table->string('status', 20)->default('pending')->index()->comment('pending | received');
                        $table->timestamps();
                    });
                }
            }
        }
    }

    public function down(): void
    {
        foreach (['mb_customer_updates', 'mb_attendance', 'mb_visits', 'mb_routes', 'booker_customer_updates', 'booker_attendance', 'booker_visits', 'booker_routes'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('contacts', function (Blueprint $table) {
            foreach (['route_id', 'outlet_type', 'outlet_class', 'visit_sequence'] as $c) {
                if (Schema::hasColumn('contacts', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
        if (Schema::hasTable('mb_customers')) {
            Schema::table('mb_customers', function (Blueprint $table) {
                foreach (['route_id', 'position', 'photo_url', 'outlet_type', 'outlet_class', 'visit_sequence'] as $c) {
                    if (Schema::hasColumn('mb_customers', $c)) {
                        $table->dropColumn($c);
                    }
                }
            });
        }
    }
};
