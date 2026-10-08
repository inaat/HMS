{{-- Settings > Business Settings > Zakat (App\Utils\ZakatUtil). Saved in common_settings['zakat']. English + Urdu. --}}
@php
    $zakat = \App\Utils\ZakatUtil::settings($business);
    $maslaks = \App\Utils\ZakatUtil::MASLAKS;
    $current = \App\Utils\ZakatUtil::maslak($zakat);
@endphp
<style>
    .zk-ur { font-family: 'Jameel Noori Nastaleeq', 'Noto Nastaliq Urdu', 'Noto Naskh Arabic', 'Segoe UI', sans-serif; direction: rtl; unicode-bidi: isolate; }
    label .zk-ur { font-weight: bold; margin-left: 6px; }
    .help-block .zk-ur { display: block; margin-top: 2px; }
</style>
<div class="pos-tab-content">
    <div class="row">
        <div class="col-sm-12">
            <div class="alert alert-info" style="margin-bottom:12px;">
                <i class="fa fa-info-circle"></i>
                Choose your maslak: the rules below are filled in for it, and you can change any of them.
                These are commonly taught positions — please confirm them with your mufti / alim.
                <div class="zk-ur" style="margin-top:4px;">
                    اپنا مسلک منتخب کریں: نیچے کے اصول اسی کے مطابق بھر دیے جاتے ہیں، اور آپ ان میں سے کوئی بھی بدل سکتے ہیں۔
                    یہ عام طور پر بیان کیے جانے والے مسائل ہیں — براہِ کرم اپنے مفتی / عالم سے تصدیق کر لیں۔
                </div>
            </div>
        </div>
        <div class="col-sm-6">
            <div class="form-group">
                <div class="checkbox" style="margin-top:0;">
                    <label>
                        <input type="hidden" name="common_settings[zakat][enabled]" value="0">
                        {!! Form::checkbox('common_settings[zakat][enabled]', 1, ! empty($zakat['enabled']), ['class' => 'input-icheck']) !!}
                        <b>Enable Zakat</b> <span class="zk-ur">زکوٰۃ فعال کریں</span>
                        <br><small class="text-muted">Reports → Zakat, and the Zakat button on the POS screen</small><small class="text-muted zk-ur" style="display:block;">رپورٹس میں زکوٰۃ، اور POS اسکرین پر زکوٰۃ کا بٹن</small>
                    </label>
                </div>
            </div>
        </div>
        <div class="clearfix"></div>

        <div class="col-sm-4">
            <div class="form-group">
                <label for="zakat_maslak">Maslak (school of fiqh): <span class="zk-ur">مسلک (فقہ)</span></label>
                <select name="common_settings[zakat][maslak]" id="zakat_maslak" class="form-control select2" style="width:100%;">
                    @foreach ($maslaks as $key => $m)
                        <option value="{{ $key }}" @if ($zakat['maslak'] == $key) selected @endif
                            data-goods="{{ $m['goods'] ? 1 : 0 }}" data-debts="{{ $m['debts'] ? 1 : 0 }}"
                            data-nisab="{{ $m['nisab'] }}" data-note="{{ $m['note'] }}" data-note-ur="{{ $m['note_ur'] ?? '' }}">{{ $m['label'] }} — {{ $m['ur'] ?? '' }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="col-sm-8">
            <div class="form-group">
                <label>&nbsp;</label>
                <p class="help-block" style="margin-top:6px;">
                    <span id="zakat_maslak_note">{{ $current['note'] }}</span>
                    <span class="zk-ur" id="zakat_maslak_note_ur">{{ $current['note_ur'] ?? '' }}</span>
                </p>
            </div>
        </div>
        <div class="clearfix"></div>

        <div class="col-sm-4">
            <div class="form-group">
                <label for="zakat_date">Your yearly zakat date: <span class="zk-ur">سالانہ زکوٰۃ کی تاریخ</span></label>
                @show_tooltip('The day each year you count your wealth and zakat becomes due — one Hijri year after your last zakat. Example: last zakat on 1 Ramadan 1447 → next zakat date 1 Ramadan 1448.')
                <div class="input-group">
                    <span class="input-group-addon"><i class="fa fa-calendar"></i></span>
                    {!! Form::text('common_settings[zakat][zakat_date]', ! empty($zakat['zakat_date']) ? @format_date($zakat['zakat_date']) : null,
                        ['class' => 'form-control', 'id' => 'zakat_date', 'readonly']) !!}
                </div>
                <p class="help-block" id="zakat_hijri">
                    {{ ! empty($zakat['zakat_date']) ? \App\Utils\ZakatUtil::hijri($zakat['zakat_date']).' — ' : '' }}one Hijri year after your last zakat.
                    Zakat you already gave this year: add it in Reports → Zakat.
                    <span class="zk-ur">آپ کی پچھلی زکوٰۃ سے ایک ہجری سال بعد۔ اس سال جو زکوٰۃ پہلے دے چکے ہیں، وہ رپورٹس ← زکوٰۃ میں درج کریں۔</span>
                </p>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="form-group">
                <label for="zakat_year_type">Year: <span class="zk-ur">سال</span></label>
                {!! Form::select('common_settings[zakat][year_type]', [
                        'lunar' => 'Lunar (Hijri) 2.5% · قمری',
                        'solar' => 'Solar (English) 2.577% · شمسی',
                    ], $zakat['year_type'], ['class' => 'form-control select2', 'style' => 'width:100%', 'id' => 'zakat_year_type']) !!}
            </div>
        </div>
        <div class="col-sm-4">
            <div class="form-group">
                <label for="zakat_stock_basis">Value stock at: <span class="zk-ur">اسٹاک کی قیمت</span></label>
                {!! Form::select('common_settings[zakat][stock_basis]', [
                        'sale' => 'Selling price · فروخت کی قیمت',
                        'cost' => 'Purchase cost · خرید کی قیمت',
                    ], $zakat['stock_basis'], ['class' => 'form-control select2', 'style' => 'width:100%', 'id' => 'zakat_stock_basis']) !!}
            </div>
        </div>
        <div class="clearfix"></div>

        <div class="col-sm-4">
            <div class="form-group">
                <label for="zakat_nisab_basis">Nisab: <span class="zk-ur">نصاب</span></label>
                {!! Form::select('common_settings[zakat][nisab_basis]', [
                        'silver' => 'Silver 612.36 g · چاندی',
                        'gold' => 'Gold 87.48 g · سونا',
                    ], $zakat['nisab_basis'], ['class' => 'form-control select2', 'style' => 'width:100%', 'id' => 'zakat_nisab_basis']) !!}
                <p class="help-block">Silver is better for the poor. <span class="zk-ur">چاندی کا نصاب غریبوں کے لیے بہتر ہے۔</span></p>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="form-group">
                <label for="zakat_silver_price">Silver price per gram (optional): <span class="zk-ur">چاندی فی گرام (اختیاری)</span></label>
                {!! Form::text('common_settings[zakat][silver_price]', $zakat['silver_price'] ? @num_format($zakat['silver_price']) : null,
                    ['class' => 'form-control input_number', 'id' => 'zakat_silver_price', 'placeholder' => 'today\'s rate · آج کا ریٹ']) !!}
            </div>
        </div>
        <div class="col-sm-4">
            <div class="form-group">
                <label for="zakat_gold_price">Gold price per gram (optional): <span class="zk-ur">سونا فی گرام (اختیاری)</span></label>
                {!! Form::text('common_settings[zakat][gold_price]', $zakat['gold_price'] ? @num_format($zakat['gold_price']) : null,
                    ['class' => 'form-control input_number', 'id' => 'zakat_gold_price', 'placeholder' => 'today\'s rate · آج کا ریٹ']) !!}
                <p class="help-block">No price entered = nisab not checked: zakat is simply 2.5% of your wealth.
                    <span class="zk-ur">قیمت درج نہ کریں تو نصاب نہیں دیکھا جائے گا: زکوٰۃ صرف آپ کے مال کا 2.5٪ ہوگی۔</span></p>
            </div>
        </div>
        <div class="clearfix"></div>

        <div class="col-sm-4">
            <div class="form-group">
                <label for="zakat_receivables_mode">Customer dues: <span class="zk-ur">گاہکوں کے واجبات</span></label>
                {!! Form::select('common_settings[zakat][receivables_mode]', [
                        'all' => 'All dues · تمام واجبات',
                        'skip_old' => 'Skip inactive customers · غیر فعال چھوڑ دیں',
                    ], $zakat['receivables_mode'], ['class' => 'form-control select2', 'style' => 'width:100%', 'id' => 'zakat_receivables_mode']) !!}
            </div>
        </div>
        <div class="col-sm-4">
            <div class="form-group">
                <label for="zakat_receivables_days">Inactive for (days): <span class="zk-ur">غیر فعال (دن)</span></label>
                {!! Form::number('common_settings[zakat][receivables_days]', $zakat['receivables_days'], ['class' => 'form-control', 'min' => 1, 'id' => 'zakat_receivables_days']) !!}
                <p class="help-block">Old, doubtful dues: count them when received.
                    <span class="zk-ur">پرانے، مشکوک واجبات: وصول ہونے پر شمار کریں۔</span></p>
            </div>
        </div>
        <div class="clearfix"></div>

        <div class="col-sm-4">
            <div class="form-group">
                <label for="zakat_deduct_payables">Debts the business owes (supplier dues): <span class="zk-ur">کاروبار کے ذمے قرض</span></label>
                {!! Form::select('common_settings[zakat][deduct_payables]', [
                        1 => 'Deduct them · منہا کریں',
                        0 => 'Do not deduct · منہا نہ کریں',
                    ], (int) $zakat['deduct_payables'], ['class' => 'form-control select2', 'style' => 'width:100%', 'id' => 'zakat_deduct_payables']) !!}
            </div>
        </div>
        <div class="col-sm-4">
            <div class="form-group">
                <label for="zakat_goods_allowed">Give zakat in products: <span class="zk-ur">زکوٰۃ اشیاء کی صورت میں</span></label>
                {!! Form::select('common_settings[zakat][goods_allowed]', [
                        1 => 'Allowed (POS button) · جائز',
                        0 => 'Not allowed — cash only · جائز نہیں',
                    ], (int) $zakat['goods_allowed'], ['class' => 'form-control select2', 'style' => 'width:100%', 'id' => 'zakat_goods_allowed']) !!}
            </div>
        </div>
        <div class="clearfix"></div>

        @php
            $zakat_accounts = \DB::table('accounts')->where('business_id', $business->id)->where('is_closed', 0)->whereNull('deleted_at')->orderBy('name')->pluck('name', 'id');
            $zakat_chosen = array_map('intval', (array) ($zakat['accounts'] ?? []));
        @endphp
        <div class="col-sm-8">
            <div class="form-group">
                <label for="zakat_accounts">Payment accounts counted as cash & bank: <span class="zk-ur">زکوٰۃ میں شامل پیمنٹ اکاؤنٹس</span></label>
                <select name="common_settings[zakat][accounts][]" id="zakat_accounts" class="form-control select2" multiple style="width:100%;"
                    data-placeholder="All accounts · تمام اکاؤنٹس">
                    @foreach ($zakat_accounts as $id => $name)
                        <option value="{{ $id }}" @if (in_array((int) $id, $zakat_chosen, true)) selected @endif>{{ $name }}</option>
                    @endforeach
                </select>
                <p class="help-block">Leave empty to count all accounts. Leave out money that is not the shop's own (loan, investor capital…).
                    <span class="zk-ur">خالی چھوڑیں تو تمام اکاؤنٹس شامل ہوں گے۔ جو رقم دکان کی اپنی نہیں (قرض، سرمایہ کار کا سرمایہ…) اسے شامل نہ کریں۔</span></p>
            </div>
        </div>
    </div>
</div>
