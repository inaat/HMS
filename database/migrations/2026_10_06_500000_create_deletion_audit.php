<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deletion audit: MySQL triggers record every delete of sales / sale lines / variations / contacts, from the app
 * or from anywhere else (phpMyAdmin, scripts, cascades started outside the app), with the stock still linked to
 * purchases and who did it. The app tags each request in @app_context (SetDbAppContext middleware); an empty
 * context means the row was deleted outside the app.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('deletion_audit')) {
            Schema::create('deletion_audit', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('table_name', 40)->index();
                $table->unsignedBigInteger('row_id')->index();
                $table->unsignedInteger('transaction_id')->nullable()->index();
                $table->string('info', 500)->nullable();
                $table->decimal('linked_qty', 22, 4)->default(0)->comment('Quantity linked to purchases when deleted');
                $table->string('app_context', 500)->nullable()->comment('User / page from the app; empty = outside the app');
                $table->string('db_user', 100)->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }

        foreach (['audit_transactions_delete', 'audit_sell_lines_delete', 'audit_variations_delete', 'audit_contacts_delete'] as $name) {
            DB::unprepared("DROP TRIGGER IF EXISTS $name");
        }

        //BEFORE delete: the sale lines / links still exist, so the linked quantity can be read
        DB::unprepared("
            CREATE TRIGGER audit_transactions_delete BEFORE DELETE ON transactions FOR EACH ROW
            BEGIN
                IF OLD.type IN ('sell', 'sell_return', 'sell_transfer', 'purchase', 'purchase_return', 'purchase_transfer', 'opening_stock', 'stock_adjustment', 'production_purchase', 'production_sell') THEN
                    INSERT INTO deletion_audit (table_name, row_id, transaction_id, info, linked_qty, app_context, db_user)
                    VALUES ('transactions', OLD.id, OLD.id,
                        CONCAT('type=', OLD.type, ' status=', IFNULL(OLD.status, ''), ' invoice=', IFNULL(OLD.invoice_no, ''), ' ref=', IFNULL(OLD.ref_no, ''),
                            ' date=', IFNULL(OLD.transaction_date, ''), ' total=', IFNULL(OLD.final_total, 0), ' contact=', IFNULL(OLD.contact_id, ''), ' created_by=', IFNULL(OLD.created_by, '')),
                        IFNULL((SELECT SUM(m.quantity - m.qty_returned) FROM transaction_sell_lines_purchase_lines m
                            JOIN transaction_sell_lines s ON s.id = m.sell_line_id WHERE s.transaction_id = OLD.id AND m.purchase_line_id > 0), 0),
                        @app_context, CURRENT_USER());
                    -- its lines go by cascade (cascades fire no trigger): record each line too
                    INSERT INTO deletion_audit (table_name, row_id, transaction_id, info, linked_qty, app_context, db_user)
                    SELECT 'transaction_sell_lines', s.id, OLD.id,
                        CONCAT('deleted with transaction ', OLD.id, ' (', OLD.type, ' ', IFNULL(OLD.invoice_no, OLD.ref_no), ') product=', s.product_id, ' qty=', s.quantity),
                        IFNULL((SELECT SUM(m.quantity - m.qty_returned) FROM transaction_sell_lines_purchase_lines m WHERE m.sell_line_id = s.id AND m.purchase_line_id > 0), 0),
                        @app_context, CURRENT_USER()
                    FROM transaction_sell_lines s WHERE s.transaction_id = OLD.id;
                END IF;
            END
        ");

        DB::unprepared("
            CREATE TRIGGER audit_sell_lines_delete BEFORE DELETE ON transaction_sell_lines FOR EACH ROW
            BEGIN
                INSERT INTO deletion_audit (table_name, row_id, transaction_id, info, linked_qty, app_context, db_user)
                VALUES ('transaction_sell_lines', OLD.id, OLD.transaction_id,
                    CONCAT('product=', OLD.product_id, ' variation=', OLD.variation_id, ' qty=', OLD.quantity, ' returned=', OLD.quantity_returned),
                    IFNULL((SELECT SUM(m.quantity - m.qty_returned) FROM transaction_sell_lines_purchase_lines m WHERE m.sell_line_id = OLD.id AND m.purchase_line_id > 0), 0),
                    @app_context, CURRENT_USER());
            END
        ");

        //A deleted variation / contact also deletes its sale lines / sales by cascade (cascades do not fire triggers)
        DB::unprepared("
            CREATE TRIGGER audit_variations_delete BEFORE DELETE ON variations FOR EACH ROW
            BEGIN
                INSERT INTO deletion_audit (table_name, row_id, info, linked_qty, app_context, db_user)
                VALUES ('variations', OLD.id,
                    CONCAT('product=', OLD.product_id, ' sku=', IFNULL(OLD.sub_sku, ''), ' sale lines deleted with it=',
                        (SELECT COUNT(*) FROM transaction_sell_lines s WHERE s.variation_id = OLD.id)),
                    IFNULL((SELECT SUM(m.quantity - m.qty_returned) FROM transaction_sell_lines_purchase_lines m
                        JOIN transaction_sell_lines s ON s.id = m.sell_line_id WHERE s.variation_id = OLD.id AND m.purchase_line_id > 0), 0),
                    @app_context, CURRENT_USER());
                INSERT INTO deletion_audit (table_name, row_id, transaction_id, info, linked_qty, app_context, db_user)
                SELECT 'transaction_sell_lines', s.id, s.transaction_id,
                    CONCAT('deleted with variation ', OLD.id, ' (sku ', IFNULL(OLD.sub_sku, ''), ') qty=', s.quantity),
                    IFNULL((SELECT SUM(m.quantity - m.qty_returned) FROM transaction_sell_lines_purchase_lines m WHERE m.sell_line_id = s.id AND m.purchase_line_id > 0), 0),
                    @app_context, CURRENT_USER()
                FROM transaction_sell_lines s WHERE s.variation_id = OLD.id;
            END
        ");

        DB::unprepared("
            CREATE TRIGGER audit_contacts_delete BEFORE DELETE ON contacts FOR EACH ROW
            BEGIN
                INSERT INTO deletion_audit (table_name, row_id, info, linked_qty, app_context, db_user)
                VALUES ('contacts', OLD.id,
                    CONCAT('name=', IFNULL(OLD.name, ''), ' sales deleted with it=',
                        (SELECT COUNT(*) FROM transactions t WHERE t.contact_id = OLD.id AND t.type = 'sell')),
                    IFNULL((SELECT SUM(m.quantity - m.qty_returned) FROM transaction_sell_lines_purchase_lines m
                        JOIN transaction_sell_lines s ON s.id = m.sell_line_id JOIN transactions t ON t.id = s.transaction_id
                        WHERE t.contact_id = OLD.id AND m.purchase_line_id > 0), 0),
                    @app_context, CURRENT_USER());
                INSERT INTO deletion_audit (table_name, row_id, transaction_id, info, linked_qty, app_context, db_user)
                SELECT 'transaction_sell_lines', s.id, s.transaction_id,
                    CONCAT('deleted with contact ', OLD.id, ' (', IFNULL(OLD.name, ''), ') invoice=', IFNULL(t.invoice_no, ''), ' qty=', s.quantity),
                    IFNULL((SELECT SUM(m.quantity - m.qty_returned) FROM transaction_sell_lines_purchase_lines m WHERE m.sell_line_id = s.id AND m.purchase_line_id > 0), 0),
                    @app_context, CURRENT_USER()
                FROM transaction_sell_lines s JOIN transactions t ON t.id = s.transaction_id WHERE t.contact_id = OLD.id;
            END
        ");
    }

    public function down(): void
    {
        foreach (['audit_transactions_delete', 'audit_sell_lines_delete', 'audit_variations_delete', 'audit_contacts_delete'] as $name) {
            DB::unprepared("DROP TRIGGER IF EXISTS $name");
        }
        Schema::dropIfExists('deletion_audit');
    }
};
