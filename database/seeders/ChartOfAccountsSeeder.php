<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Default chart of accounts (Zoho Books style), as account types (Payment Accounts > Account Types), for every business:
 *   php artisan db:seed --class=ChartOfAccountsSeeder   (or Accounting > Set up chart of accounts)
 * 5 main types (Assets, Liabilities, Equity, Income, Expenses) with the accounts a trading business needs; each account
 * has its Zoho-style type (detail_type) and normal side (debit or credit increases).
 * Expenses: the basic expense categories are added to Expenses (only when no similar one exists), and every main
 * expense category gets its own account. Payment accounts with no type go under "Cash accounts" (a bank account can be
 * moved to "Bank accounts" in Payment Accounts > edit).
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
            ['cash_accounts', '1110', 'Cash accounts (shop cash, petty cash, bookers)', 'cash', 1],
            ['cash_bank', '1150', 'Bank accounts', 'bank', 1],
            ['receivable', '1200', 'Accounts receivable (customers)', 'accounts_receivable', 1],
            ['inventory', '1300', 'Inventory (stock)', 'stock', 1],
            // cost of items sold while their stock was 0 (purchase not entered yet); moves to Inventory once a purchase covers them
            ['inventory_unmatched', '1310', 'Stock sold before purchase entered', 'stock', 1],
            ['inventory_ordered', '1350', 'Stock ordered, not received', 'other_current_asset', 1],
            ['tax_input', '1400', 'Tax receivable', 'other_current_asset', 1],
            ['prepaid', '1450', 'Prepaid expenses / advances paid', 'other_current_asset', 1],
            ['employee_advance', '1500', 'Employee advance', 'other_current_asset', 1],
            ['fixed_assets', '1600', 'Furniture & equipment', 'fixed_asset', 1],
            ['vehicles', '1610', 'Vehicles', 'fixed_asset', 1],
            ['computers', '1620', 'Computers & electronics', 'fixed_asset', 1],
            ['accumulated_depreciation', '1690', 'Accumulated depreciation', 'fixed_asset', 0],
        ]],
        'liabilities' => ['2000', 'Liabilities', 'liability', [
            ['payable', '2100', 'Accounts payable (suppliers)', 'accounts_payable', 0],
            ['expenses_payable', '2200', 'Expenses payable', 'other_current_liability', 0],
            ['salaries_payable', '2210', 'Salaries payable', 'other_current_liability', 0],
            ['tax_output', '2300', 'Tax payable', 'other_current_liability', 0],
            ['commission_payable', '2400', 'Commission payable (agents)', 'other_current_liability', 0],
            ['investor_payable', '2450', 'Investor profit payable', 'other_current_liability', 0],
            ['loans', '2500', 'Loans (bank / others)', 'long_term_liability', 0],
        ]],
        'equity' => ['3000', 'Equity', 'equity', [
            ['capital', '3100', "Owner's capital", 'equity', 0],
            ['investor_capital', '3150', "Investors' capital", 'equity', 0],
            ['opening_equity', '3200', 'Opening balance offset', 'equity', 0],
            ['retained_earnings', '3300', 'Retained earnings', 'equity', 0],
            ['drawings', '3400', 'Drawings', 'equity', 1],
            // investors' share of the profit (locked settlements): a distribution, not an expense (like the POS)
            ['investor_share', '3460', "Investors' profit share", 'equity', 1],
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
            ['depreciation', '5800', 'Depreciation', 'expense', 1],
            // zakat (cash or goods) is the business's expense, shown after operating profit
            ['zakat', '5850', 'Zakat paid', 'other_expense', 1],
            ['expense_other', '5900', 'Other expenses (no category)', 'expense', 1],
        ]],
    ];

    /** Basic expense categories a business needs: [name, pattern of an existing category that already covers it]. */
    const BASIC_EXPENSES = [
        ['Salaries & wages', 'salar|wage|pay ?roll'],
        ['Rent', 'rent'],
        ['Electricity & utilities', 'electric|utilit|bill|gas|water'],
        ['Fuel & transport', 'fuel|petrol|diesel|transport|freight|cartage'],
        ['Telephone & internet', 'phone|mobile|internet'],
        ['Repairs & maintenance', 'repair|mainten'],
        ['Office & stationery', 'office|station|printing'],
        ['Food & tea', 'food|tea|meal'],
        ['Advertising', 'advert|marketing|promotion'],
        ['Bank charges', 'bank'],
        ['Miscellaneous', 'misc|other'],
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

        // Charts made by the first version: zakat was under Equity (it is an expense), "Cash & bank" held every payment
        // account (now Cash accounts / Bank accounts; the ones it put there automatically move to Cash accounts)
        DB::table('account_types')->where('id', $ids['zakat'])->where('classification', '!=', 'expense')->update([
            'parent_account_type_id' => $ids['expenses'], 'classification' => 'expense', 'detail_type' => 'other_expense',
            'code' => '5850', 'debit_increases' => 1, 'credit_increases' => 0, 'updated_at' => $now,
        ]);
        if (DB::table('account_types')->where('id', $ids['cash_bank'])->where('name', 'Cash & bank')->exists()) {
            DB::table('account_types')->where('id', $ids['cash_bank'])->update(['name' => 'Bank accounts', 'updated_at' => $now]);
            DB::table('accounts')->where('business_id', $business_id)->where('account_type_id', $ids['cash_bank'])
                ->update(['account_type_id' => $ids['cash_accounts']]);
        }

        // Basic expense categories (only once per business, and only where no similar category exists)
        $flag = 'ledger_basic_expenses_'.$business_id;
        if (! DB::table('system')->where('key', $flag)->exists()) {
            $existing = DB::table('expense_categories')->where('business_id', $business_id)->whereNull('deleted_at')->pluck('name')->all();
            foreach (self::BASIC_EXPENSES as [$name, $pattern]) {
                $covered = collect($existing)->contains(function ($n) use ($pattern) {
                    return preg_match('/'.$pattern.'/i', $n);
                });
                if (! $covered) {
                    DB::table('expense_categories')->insert(['business_id' => $business_id, 'name' => $name, 'created_at' => $now, 'updated_at' => $now]);
                }
            }
            DB::table('system')->insert(['key' => $flag, 'value' => $now->toDateTimeString()]);
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

        // Every shop needs a counter cash account for cash sales: made once (rename it any time; banks are added by hand
        // because their names are the business's own)
        $flag = 'ledger_shop_cash_'.$business_id;
        if (! DB::table('system')->where('key', $flag)->exists()) {
            $has_cash = DB::table('accounts')->where('business_id', $business_id)->whereNull('deleted_at')
                ->where(function ($q) {
                    $q->where('name', 'like', '%shop cash%')->orWhere('name', 'like', '%counter%')->orWhere('name', 'like', '%cash in hand%');
                })->exists();
            if (! $has_cash) {
                $created_by = DB::table('users')->where('business_id', $business_id)->whereNull('deleted_at')->orderBy('id')->value('id');
                DB::table('accounts')->insert(['business_id' => $business_id, 'name' => 'Shop cash', 'account_number' => 'CASH-SHOP',
                    'account_type_id' => $ids['cash_accounts'], 'note' => 'Counter cash: cash sales and cash payments of the shop',
                    'created_by' => $created_by ?: 1, 'is_closed' => 0, 'created_at' => $now, 'updated_at' => $now]);
            }
            DB::table('system')->insert(['key' => $flag, 'value' => $now->toDateTimeString()]);
        }

        // Payment accounts with no type (shafiq, Cash with booker ...) => "Cash accounts"
        DB::table('accounts')->where('business_id', $business_id)
            ->where(function ($q) {
                $q->whereNull('account_type_id')->orWhere('account_type_id', 0);
            })
            ->update(['account_type_id' => $ids['cash_accounts']]);
    }
}
