<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Default chart of accounts (Zoho Books style), as account types (Payment Accounts > Account Types), for every business:
 *   php artisan db:seed --class=ChartOfAccountsSeeder
 * 5 main types (Assets, Liabilities, Equity, Income, Expenses) with their accounts under them; each account has its
 * Zoho-style type (detail_type) and normal side (debit or credit increases). Also one Expense account per expense
 * category, and payment accounts with no type are put under "Cash & bank".
 * Safe to run again: accounts that exist are left as they are (names you changed are kept), missing ones are added.
 */
class ChartOfAccountsSeeder extends Seeder
{
    /**
     * main => [code, name, classification, [ [system_key, code, name, detail_type, debit_increases], ... ] ]
     * debit_increases: 1 = goes up with a debit (assets, expenses, contra income / equity), 0 = with a credit.
     */
    const CHART = [
        'assets' => ['1000', 'Assets', 'asset', [
            ['cash_unassigned', '1100', 'Cash in hand (no account)', 'cash', 1],
            ['cash_bank', '1150', 'Cash & bank', 'bank', 1],
            ['receivable', '1200', 'Accounts receivable (customers)', 'accounts_receivable', 1],
            ['inventory', '1300', 'Inventory (stock)', 'stock', 1],
            ['inventory_ordered', '1350', 'Stock ordered, not received', 'other_current_asset', 1],
            ['tax_input', '1400', 'Tax receivable', 'other_current_asset', 1],
            ['prepaid', '1450', 'Prepaid expenses', 'other_current_asset', 1],
            ['employee_advance', '1500', 'Employee advance', 'other_current_asset', 1],
            ['fixed_assets', '1600', 'Furniture & equipment', 'fixed_asset', 1],
        ]],
        'liabilities' => ['2000', 'Liabilities', 'liability', [
            ['payable', '2100', 'Accounts payable (suppliers)', 'accounts_payable', 0],
            ['expenses_payable', '2200', 'Expenses payable', 'other_current_liability', 0],
            ['tax_output', '2300', 'Tax payable', 'other_current_liability', 0],
            ['loans', '2500', 'Loans', 'long_term_liability', 0],
        ]],
        'equity' => ['3000', 'Equity', 'equity', [
            ['capital', '3100', "Owner's capital", 'equity', 0],
            ['opening_equity', '3200', 'Opening balance offset', 'equity', 0],
            ['retained_earnings', '3300', 'Retained earnings', 'equity', 0],
            ['drawings', '3400', 'Drawings', 'equity', 1],
            ['zakat', '3450', 'Zakat paid', 'equity', 1],
        ]],
        'income' => ['4000', 'Income', 'income', [
            ['sales', '4100', 'Sales', 'income', 0],
            ['sales_returns', '4150', 'Sales returns', 'income', 1],
            ['sales_discount', '4200', 'Discount given', 'income', 1],
            ['shipping_income', '4300', 'Shipping charges', 'income', 0],
            ['purchase_discount', '4400', 'Discount received', 'other_income', 0],
            ['other_income', '4450', 'General income', 'other_income', 0],
            ['rounding', '4500', 'Rounding differences', 'other_income', 0],
        ]],
        'expenses' => ['5000', 'Expenses', 'expense', [
            ['cogs', '5100', 'Cost of goods sold', 'cost_of_goods_sold', 1],
            ['stock_loss', '5200', 'Stock adjustment loss', 'cost_of_goods_sold', 1],
            ['purchase_expenses', '5300', 'Freight / purchase charges', 'cost_of_goods_sold', 1],
            ['expense_other', '5900', 'Other expenses (no category)', 'expense', 1],
        ]],
    ];

    /** Normal side of a main type. */
    const MAIN_DEBIT = ['asset' => 1, 'liability' => 0, 'equity' => 0, 'income' => 0, 'expense' => 1];

    public function run()
    {
        foreach (DB::table('business')->pluck('id') as $business_id) {
            self::seedBusiness((int) $business_id);
        }
    }

    public static function seedBusiness(int $business_id): void
    {
        $now = now();
        $ids = DB::table('account_types')->where('business_id', $business_id)->whereNotNull('system_key')->pluck('id', 'system_key')->all();
        $add = function (array $row) use ($business_id, $now) {
            return DB::table('account_types')->insertGetId($row + ['business_id' => $business_id, 'created_at' => $now, 'updated_at' => $now]);
        };

        foreach (self::CHART as $main_key => [$code, $name, $classification, $subs]) {
            if (! isset($ids[$main_key])) {
                $debit = self::MAIN_DEBIT[$classification];
                $ids[$main_key] = $add(['name' => $name, 'code' => $code, 'classification' => $classification,
                    'debit_increases' => $debit, 'credit_increases' => 1 - $debit,
                    'system_key' => $main_key, 'parent_account_type_id' => null]);
            }
            foreach ($subs as [$key, $sub_code, $sub_name, $detail_type, $debit]) {
                if (! isset($ids[$key])) {
                    $ids[$key] = $add(['name' => $sub_name, 'code' => $sub_code, 'classification' => $classification,
                        'detail_type' => $detail_type, 'debit_increases' => $debit, 'credit_increases' => 1 - $debit,
                        'system_key' => $key, 'parent_account_type_id' => $ids[$main_key]]);
                }
            }
        }

        // One Expense account per main expense category (sub categories post to their main one): 5401, 5402 ...
        $linked = DB::table('account_types')->where('business_id', $business_id)->whereNotNull('expense_category_id')->pluck('expense_category_id')->all();
        $next = max(5401, (int) DB::table('account_types')->where('business_id', $business_id)
            ->where('parent_account_type_id', $ids['expenses'])->whereRaw('code REGEXP "^54[0-9][0-9]$"')
            ->max(DB::raw('CAST(code AS UNSIGNED)')) + 1);
        foreach (DB::table('expense_categories')->where('business_id', $business_id)->whereNull('parent_id')->whereNull('deleted_at')->orderBy('id')->get(['id', 'name']) as $c) {
            if (! in_array($c->id, $linked)) {
                $add(['name' => $c->name, 'code' => (string) $next++, 'classification' => 'expense', 'detail_type' => 'expense',
                    'debit_increases' => 1, 'credit_increases' => 0,
                    'expense_category_id' => $c->id, 'parent_account_type_id' => $ids['expenses']]);
            }
        }

        // Payment accounts with no type (shafiq, Cash with booker ...) => "Cash & bank"
        DB::table('accounts')->where('business_id', $business_id)
            ->where(function ($q) {
                $q->whereNull('account_type_id')->orWhere('account_type_id', 0);
            })
            ->update(['account_type_id' => $ids['cash_bank']]);
    }
}
