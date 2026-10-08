<?php

namespace App\Utils;

use App\Business;
use App\Events\StockAdjustmentCreatedOrModified;
use App\Transaction;
use Illuminate\Support\Facades\DB;

/**
 * Zakat for the business: the maslak presets behind Settings > Business Settings > Zakat, Hijri dates, the yearly
 * calculation (cash + stock + receivables - payables, nisab, rate) and recording zakat given in cash or in goods.
 *
 * The presets are commonly taught positions, editable per shop; the settings screen tells the owner to confirm
 * them with their mufti / alim. Settings live in business.common_settings['zakat'].
 */
class ZakatUtil extends Util
{
    /**
     * Maslak => preset. goods: zakat on trade goods may be given as the goods themselves (at market value);
     * debts: business debts owed are deducted; obligatory false = recommended (mustahab) only.
     */
    const MASLAKS = [
        'hanafi_deobandi' => ['label' => 'Hanafi — Deobandi', 'ur' => 'حنفی — دیوبندی', 'note_ur' => 'تجارتی مال پر زکوٰۃ فرض ہے۔ مال خود (بازاری قیمت پر) دینا جائز ہے۔ کاروبار کے ذمے واجب الادا قرض منہا کیے جاتے ہیں۔', 'obligatory' => true, 'goods' => true, 'debts' => true, 'nisab' => 'silver',
            'note' => 'Zakat on trade goods is obligatory. Giving the goods themselves (at market value) is allowed. Debts the business must pay are deducted.'],
        'hanafi_barelvi' => ['label' => 'Hanafi — Barelvi', 'ur' => 'حنفی — بریلوی', 'note_ur' => 'تجارتی مال پر زکوٰۃ فرض ہے۔ مال خود (بازاری قیمت پر) دینا جائز ہے۔ کاروبار کے ذمے واجب الادا قرض منہا کیے جاتے ہیں۔', 'obligatory' => true, 'goods' => true, 'debts' => true, 'nisab' => 'silver',
            'note' => 'Zakat on trade goods is obligatory. Giving the goods themselves (at market value) is allowed. Debts the business must pay are deducted.'],
        'ahl_e_hadith' => ['label' => 'Ahl-e-Hadith', 'ur' => 'اہلِ حدیث', 'note_ur' => 'عام طور پر یہی بیان کیا جاتا ہے: تجارتی مال پر زکوٰۃ واجب ہے؛ اگر مستحق کے لیے بہتر ہو تو مال کی صورت میں دینا جائز ہے۔', 'obligatory' => true, 'goods' => true, 'debts' => true, 'nisab' => 'silver',
            'note' => 'Commonly taught: zakat on trade goods is due; giving goods is allowed when it is better for the recipient.'],
        'shafii' => ['label' => "Shafi'i", 'ur' => 'شافعی', 'note_ur' => 'تجارتی مال کی زکوٰۃ رقم (اس کی قیمت) کی صورت میں دی جاتی ہے، مال کی صورت میں نہیں۔ قرض زکوٰۃ کو کم نہیں کرتا۔', 'obligatory' => true, 'goods' => false, 'debts' => false, 'nisab' => 'gold',
            'note' => 'Zakat on trade goods is paid in money (their value), not with the goods. Debts do not reduce zakat.'],
        'maliki' => ['label' => 'Maliki', 'ur' => 'مالکی', 'note_ur' => 'تجارتی مال کی زکوٰۃ رقم (اس کی قیمت) کی صورت میں دی جاتی ہے، مال کی صورت میں نہیں۔', 'obligatory' => true, 'goods' => false, 'debts' => true, 'nisab' => 'gold',
            'note' => 'Zakat on trade goods is paid in money (their value), not with the goods.'],
        'hanbali' => ['label' => 'Hanbali', 'ur' => 'حنبلی', 'note_ur' => 'تجارتی مال کی زکوٰۃ رقم (اس کی قیمت) کی صورت میں دی جاتی ہے، مال کی صورت میں نہیں۔', 'obligatory' => true, 'goods' => false, 'debts' => true, 'nisab' => 'gold',
            'note' => 'Zakat on trade goods is paid in money (their value), not with the goods.'],
        'jafari' => ['label' => "Shia — Fiqh Ja'fari", 'ur' => 'شیعہ — فقہ جعفریہ', 'note_ur' => 'تجارتی مال پر زکوٰۃ مستحب ہے، واجب نہیں۔ خمس الگ فریضہ ہے اور اس کا حساب یہاں نہیں کیا جاتا۔', 'obligatory' => false, 'goods' => true, 'debts' => true, 'nisab' => 'silver',
            'note' => 'Zakat on trade goods is recommended (mustahab), not obligatory. Khums is a separate duty and is not calculated here.'],
    ];

    /** The 8 categories (masarif) of zakat recipients, Quran 9:60. */
    const CATEGORIES = [
        'fuqara' => 'Poor (Fuqara)',
        'masakin' => 'Needy (Masakin)',
        'amilin' => 'Zakat workers (Amilin)',
        'muallafa' => 'Hearts to be reconciled (Muallafat al-Qulub)',
        'riqab' => 'Freeing captives (Riqab)',
        'gharimin' => 'People in debt (Gharimin)',
        'fi_sabilillah' => 'In the cause of Allah (Fi Sabilillah)',
        'ibn_sabil' => 'Stranded traveller (Ibn al-Sabil)',
    ];

    const SILVER_NISAB_GRAMS = 612.36;

    const GOLD_NISAB_GRAMS = 87.48;

    public static function defaults(): array
    {
        return [
            'enabled' => 0,
            'maslak' => 'hanafi_deobandi',
            'zakat_date' => null,
            'year_type' => 'lunar',
            'nisab_basis' => 'silver',
            'silver_price' => 0,
            'gold_price' => 0,
            'stock_basis' => 'sale',
            'receivables_mode' => 'all',
            'receivables_days' => 365,
            'deduct_payables' => 1,
            'goods_allowed' => 1,
        ];
    }

    /** The shop's zakat settings (saved values over defaults). */
    public static function settings($business): array
    {
        if (! $business instanceof Business) {
            $business = Business::find($business);
        }
        $saved = (array) (($business->common_settings ?? [])['zakat'] ?? []);
        $settings = array_merge(self::defaults(), array_filter($saved, function ($v) {
            return $v !== null && $v !== '';
        }));

        // The settings form saves the date in the business date format and prices with thousand separators.
        $util = new Util();
        foreach (['silver_price', 'gold_price'] as $key) {
            $settings[$key] = is_numeric($settings[$key]) ? (float) $settings[$key] : (float) $util->num_uf($settings[$key]);
        }
        if (! empty($settings['zakat_date']) && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $settings['zakat_date'])) {
            try {
                $settings['zakat_date'] = \Carbon::createFromFormat($business->date_format ?: 'd-m-Y', $settings['zakat_date'])->format('Y-m-d');
            } catch (\Throwable $e) {
                $settings['zakat_date'] = null;
            }
        }

        return $settings;
    }

    public static function maslak(array $settings): array
    {
        return self::MASLAKS[$settings['maslak']] ?? self::MASLAKS['hanafi_deobandi'];
    }

    /** Zakat may be given in products here: module on and the shop allows it (preset from the maslak). */
    public static function goodsAllowed(array $settings): bool
    {
        return ! empty($settings['enabled']) && ! empty($settings['goods_allowed']);
    }

    /** 2.5% of a lunar (Hijri) year; 2.577% when the shop counts a solar year (365/354 days). */
    public static function rate(array $settings): float
    {
        return ($settings['year_type'] ?? 'lunar') === 'solar' ? 2.577 : 2.5;
    }

    public static function hijri($date): string
    {
        try {
            $fmt = new \IntlDateFormatter('en_US@calendar=islamic-umalqura', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE,
                config('app.timezone'), \IntlDateFormatter::TRADITIONAL, 'd MMMM y');

            return $fmt->format(new \DateTime($date)).' AH';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** Nisab in money: grams of the chosen metal x the price per gram the owner entered. */
    public static function nisabValue(array $settings): float
    {
        return $settings['nisab_basis'] === 'gold'
            ? self::GOLD_NISAB_GRAMS * (float) $settings['gold_price']
            : self::SILVER_NISAB_GRAMS * (float) $settings['silver_price'];
    }

    /**
     * The zakatable wealth on $date, from the POS's own numbers: cash & bank (payment accounts), stock for sale
     * (selling price or cost), customer dues and (when the maslak deducts debts) supplier dues.
     */
    public function calculate(int $business_id, string $date, array $settings, array $manual_lines = []): array
    {
        $cash = (float) DB::table('account_transactions as at')
            ->join('accounts as a', 'a.id', '=', 'at.account_id')
            ->where('a.business_id', $business_id)
            ->whereNull('a.deleted_at')
            ->whereNull('at.deleted_at')
            ->whereDate('at.operation_date', '<=', $date)
            ->sum(DB::raw("IF(at.type = 'credit', at.amount, -1 * at.amount)"));

        $stock = (float) (new TransactionUtil())->getOpeningClosingStock($business_id, $date, null, false, $settings['stock_basis'] === 'sale');

        $contactUtil = new ContactUtil();
        $customers = $contactUtil->getContactQuery($business_id, 'customer')->get();
        $skip_before = $settings['receivables_mode'] === 'skip_old'
            ? \Carbon::parse($date)->subDays(max(1, (int) $settings['receivables_days']))->format('Y-m-d') : null;
        $receivables = 0;
        $skipped = 0;
        foreach ($customers as $c) {
            $due = (float) $c->for_ordering_total_due;
            if ($due <= 0) {
                continue;
            }
            if ($skip_before && (empty($c->max_transaction_date) || $c->max_transaction_date < $skip_before)) {
                $skipped += $due;

                continue;
            }
            $receivables += $due;
        }

        $payables = 0;
        if (! empty($settings['deduct_payables'])) {
            foreach ($contactUtil->getContactQuery($business_id, 'supplier')->get() as $s) {
                $payables += max(0, (float) $s->display_due);
            }
        }

        $manual = 0;
        foreach ($manual_lines as $line) {
            $manual += (float) ($line['amount'] ?? 0);
        }

        $net = $cash + $stock + $receivables - $payables + $manual;
        $nisab = self::nisabValue($settings);
        $rate = self::rate($settings);
        $reaches = $nisab <= 0 || $net >= $nisab;

        return [
            'cash' => round($cash, 2),
            'stock_value' => round($stock, 2),
            'receivables' => round($receivables, 2),
            'receivables_skipped' => round($skipped, 2),
            'payables' => round($payables, 2),
            'manual' => round($manual, 2),
            'net_wealth' => round($net, 2),
            'nisab_value' => round($nisab, 2),
            'rate' => $rate,
            'reaches_nisab' => $reaches,
            'zakat_due' => $reaches && $net > 0 ? round($net * $rate / 100, 2) : 0,
        ];
    }

    /** The open zakat year (made for today when none is open), so payments always have a year. */
    public function currentYear(int $business_id, array $settings, ?int $user_id = null)
    {
        $year = DB::table('zakat_years')->where('business_id', $business_id)->where('status', 'open')->orderByDesc('id')->first();
        if ($year) {
            return $year;
        }
        $date = ! empty($settings['zakat_date']) ? $settings['zakat_date'] : date('Y-m-d');
        $id = DB::table('zakat_years')->insertGetId([
            'business_id' => $business_id,
            'maslak' => $settings['maslak'],
            'zakat_date' => $date,
            'hijri_label' => self::hijri($date),
            'settings' => json_encode($settings),
            'rate' => self::rate($settings),
            'status' => 'open',
            'created_by' => $user_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('zakat_years')->find($id);
    }

    /**
     * Zakat given in goods: a stock adjustment marked is_zakat (stock goes down, FIFO-mapped like any adjustment,
     * no invoice, no due) plus the zakat payment. $lines: product_id, variation_id, quantity (base unit),
     * value (selling value counted toward zakat). Returns the zakat_payments id.
     */
    public function giveGoods(int $business_id, int $location_id, int $user_id, array $lines, array $recipient, string $accounting_method): int
    {
        $productUtil = new ProductUtil();
        $transactionUtil = new TransactionUtil();
        $settings = self::settings($business_id);

        return DB::transaction(function () use ($business_id, $location_id, $user_id, $lines, $recipient, $accounting_method, $productUtil, $transactionUtil, $settings) {
            $adjustment_lines = [];
            $cost = 0;
            $value = 0;
            foreach ($lines as $l) {
                $unit_cost = (float) DB::table('variations')->where('id', $l['variation_id'])->value('dpp_inc_tax');
                $adjustment_lines[] = [
                    'product_id' => $l['product_id'],
                    'variation_id' => $l['variation_id'],
                    'quantity' => $l['quantity'],
                    'unit_price' => $unit_cost,
                ];
                $cost += $unit_cost * $l['quantity'];
                $value += (float) $l['value'];
                $productUtil->decreaseProductQuantity($l['product_id'], $l['variation_id'], $location_id, $l['quantity']);
            }

            $ref_count = $productUtil->setAndGetReferenceCount('stock_adjustment');
            $adjustment = Transaction::create([
                'business_id' => $business_id,
                'location_id' => $location_id,
                'type' => 'stock_adjustment',
                'adjustment_type' => 'normal',
                'is_zakat' => 1,
                'transaction_date' => now(),
                'ref_no' => $productUtil->generateReferenceNumber('stock_adjustment', $ref_count),
                'final_total' => $cost,
                'total_amount_recovered' => 0,
                'additional_notes' => 'Zakat'.(! empty($recipient['name']) ? ': '.$recipient['name'] : ''),
                'created_by' => $user_id,
            ]);
            $adjustment->stock_adjustment_lines()->createMany($adjustment_lines);
            $transactionUtil->mapPurchaseSell(
                ['id' => $business_id, 'accounting_method' => $accounting_method, 'location_id' => $location_id],
                $adjustment->stock_adjustment_lines, 'stock_adjustment');
            event(new StockAdjustmentCreatedOrModified($adjustment, 'added'));
            $transactionUtil->activityLog($adjustment, 'added', null, [], false);

            $year = $this->currentYear($business_id, $settings, $user_id);

            return DB::table('zakat_payments')->insertGetId([
                'business_id' => $business_id,
                'zakat_year_id' => $year->id,
                'kind' => 'goods',
                'recipient_name' => $recipient['name'] ?? null,
                'recipient_mobile' => $recipient['mobile'] ?? null,
                'contact_id' => $recipient['contact_id'] ?? null,
                'category' => $recipient['category'] ?? null,
                'amount_value' => round($value, 4),
                'cost_value' => round($cost, 4),
                'transaction_id' => $adjustment->id,
                'location_id' => $location_id,
                'note' => $recipient['note'] ?? null,
                'paid_on' => now(),
                'created_by' => $user_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    /** Zakat given in cash: the payment and (when paid from an account) the account goes down. */
    public function giveCash(int $business_id, int $user_id, array $data): int
    {
        $settings = self::settings($business_id);

        return DB::transaction(function () use ($business_id, $user_id, $data, $settings) {
            $year = $this->currentYear($business_id, $settings, $user_id);
            if (! empty($data['account_id'])) {
                \App\AccountTransaction::createAccountTransaction([
                    'amount' => $data['amount'],
                    'account_id' => $data['account_id'],
                    'type' => 'debit',
                    'operation_date' => $data['paid_on'],
                    'created_by' => $user_id,
                    'note' => 'Zakat'.(! empty($data['name']) ? ': '.$data['name'] : ''),
                ]);
            }

            return DB::table('zakat_payments')->insertGetId([
                'business_id' => $business_id,
                'zakat_year_id' => $year->id,
                'kind' => $data['kind'] ?? 'cash',
                'recipient_name' => $data['name'] ?? null,
                'recipient_mobile' => $data['mobile'] ?? null,
                'category' => $data['category'] ?? null,
                'amount_value' => $data['amount'],
                'account_id' => $data['account_id'] ?? null,
                'note' => $data['note'] ?? null,
                'paid_on' => $data['paid_on'],
                'created_by' => $user_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }
}
