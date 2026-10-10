<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Double-entry books on the existing account types (Payment Accounts > Account Types = chart of accounts):
 *  - account_types gets code / classification / system_key / expense_category_id (all nullable, nothing changes for
 *    the types you have); ChartOfAccountsSeeder fills the default chart.
 *  - ledger_journals + ledger_lines: new, empty tables for the journal entries.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::table('account_types', function (Blueprint $table) {
            if (! Schema::hasColumn('account_types', 'code')) {
                $table->string('code', 20)->nullable()->after('name');
            }
            if (! Schema::hasColumn('account_types', 'classification')) {
                $table->string('classification', 10)->nullable()->after('code'); // asset, liability, equity, income, expense
            }
            // Normal side: assets / expenses (and contra accounts like Sales returns, Drawings) go up with a debit,
            // liabilities / equity / income with a credit
            if (! Schema::hasColumn('account_types', 'debit_increases')) {
                $table->boolean('debit_increases')->nullable()->after('classification');
            }
            if (! Schema::hasColumn('account_types', 'credit_increases')) {
                $table->boolean('credit_increases')->nullable()->after('debit_increases');
            }
            // Zoho-style account type: cash, bank, accounts_receivable, stock, other_current_asset, fixed_asset,
            // accounts_payable, other_current_liability, long_term_liability, equity, income, other_income,
            // cost_of_goods_sold, expense, other_expense (reports group by it)
            if (! Schema::hasColumn('account_types', 'detail_type')) {
                $table->string('detail_type', 30)->nullable()->after('classification');
            }
            if (! Schema::hasColumn('account_types', 'system_key')) {
                $table->string('system_key', 50)->nullable()->after('classification'); // fixed types the posting code looks for
            }
            if (! Schema::hasColumn('account_types', 'expense_category_id')) {
                $table->unsignedInteger('expense_category_id')->nullable()->after('system_key');
            }
        });

        if (! Schema::hasTable('ledger_journals')) {
            Schema::create('ledger_journals', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('business_id');
                $table->unsignedInteger('location_id')->nullable();
                $table->dateTime('entry_date');
                $table->string('source_type', 30); // sell, purchase, payment, ... or manual
                $table->unsignedInteger('source_id')->nullable();
                $table->unsignedInteger('transaction_id')->nullable(); // POS record to open from the ledger
                $table->string('ref_no', 191)->nullable();
                $table->string('memo', 255)->nullable();
                $table->char('fingerprint', 32)->nullable(); // unchanged POS record => journal not written again
                $table->unsignedInteger('created_by')->nullable();
                $table->timestamps();
                $table->unique(['business_id', 'source_type', 'source_id']);
                $table->index(['business_id', 'entry_date']);
            });
        }

        if (! Schema::hasTable('ledger_lines')) {
            Schema::create('ledger_lines', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('journal_id')->index();
                $table->unsignedInteger('business_id');
                $table->unsignedInteger('account_type_id'); // chart account (account_types)
                $table->unsignedInteger('account_id')->nullable(); // payment account (shafiq, bank ...), cash & bank lines only
                $table->unsignedInteger('contact_id')->nullable()->index(); // customer / supplier, receivable & payable lines
                $table->decimal('debit', 22, 4)->default(0);
                $table->decimal('credit', 22, 4)->default(0);
                $table->string('note', 191)->nullable();
                // manual journal line on a payment account: the matching Payment Accounts entry it made
                $table->unsignedInteger('account_transaction_id')->nullable()->index();
                $table->index(['business_id', 'account_type_id']);
                $table->index('account_id');
            });
        } elseif (! Schema::hasColumn('ledger_lines', 'account_transaction_id')) {
            Schema::table('ledger_lines', function (Blueprint $table) {
                $table->unsignedInteger('account_transaction_id')->nullable()->index();
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('ledger_lines');
        Schema::dropIfExists('ledger_journals');
        Schema::table('account_types', function (Blueprint $table) {
            $table->dropColumn(['code', 'classification', 'detail_type', 'debit_increases', 'credit_increases', 'system_key', 'expense_category_id']);
        });
    }
};
