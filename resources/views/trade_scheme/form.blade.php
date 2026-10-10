@extends('layouts.app')
@php
    $editing = $scheme && empty($scheme->copy_of);
    $title = $editing ? 'Edit scheme '.$scheme->code : (! empty($scheme->copy_of) ? 'Copy of scheme '.$scheme->code : 'Add trade scheme');
    $val = fn ($field, $default = null) => old($field, $scheme->{$field} ?? $default);
    $num = fn ($v) => $v === null || $v === '' ? '' : rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.');
    $classes = ['A', 'B', 'C', 'D', 'E'];
    if (old('slab_buy')) {
        $slab_rows = collect(old('slab_buy'))->map(function ($b, $i) use ($classes) {
            $row = (object) ['buy_qty' => $b, 'free_qty' => old('slab_free')[$i] ?? '', 'percent' => old('slab_percent')[$i] ?? '', 'class_percents' => []];
            foreach ($classes as $c) {
                $row->class_percents[$c] = old('slab_class_'.$c)[$i] ?? '';
            }

            return $row;
        });
    } else {
        $slab_rows = $slabs->isNotEmpty()
            ? $slabs->map(function ($s) {
                $s->class_percents = json_decode((string) ($s->class_percents ?? ''), true) ?: [];

                return $s;
            })
            : collect([(object) ['buy_qty' => '', 'free_qty' => '', 'percent' => '', 'class_percents' => []]]);
    }
    $locations_picked = old('location_ids', json_decode((string) ($scheme->location_ids ?? ''), true) ?: []);
    $date_val = fn ($field) => old($field, ! empty($scheme->{$field}) && $editing ? \Carbon::parse($scheme->{$field})->format(session('business.date_format')) : '');
@endphp
@section('title', $title)

@section('content')
<section class="content-header">
    <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">{{ $title }}</h1>
</section>

<section class="content">
    {!! Form::open(['url' => $editing ? action([\App\Http\Controllers\TradeSchemeController::class, 'update'], [$scheme->id]) : action([\App\Http\Controllers\TradeSchemeController::class, 'store']),
        'method' => $editing ? 'put' : 'post', 'id' => 'scheme_form']) !!}

    @component('components.widget', ['class' => 'box-primary', 'title' => 'Scheme'])
        <div class="row">
            <div class="col-md-3">
                <div class="form-group">
                    {!! Form::label('code', 'Code:*') !!}
                    {!! Form::text('code', $editing ? $scheme->code : old('code', $next_code), ['class' => 'form-control', 'required', 'maxlength' => 40]) !!}
                </div>
            </div>
            <div class="col-md-5">
                <div class="form-group">
                    {!! Form::label('name', 'Name:*') !!}
                    {!! Form::text('name', $val('name'), ['class' => 'form-control', 'required', 'placeholder' => 'e.g. CandyLand Power Play Oct']) !!}
                </div>
            </div>
            <div class="col-md-2">
                <div class="form-group">
                    {!! Form::label('starts_at', 'From:') !!}
                    <div class="input-group">
                        <span class="input-group-addon"><i class="fa fa-calendar"></i></span>
                        {!! Form::text('starts_at', $date_val('starts_at'), ['class' => 'form-control scheme_date', 'readonly', 'placeholder' => 'any date']) !!}
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="form-group">
                    {!! Form::label('ends_at', 'To:') !!}
                    <div class="input-group">
                        <span class="input-group-addon"><i class="fa fa-calendar"></i></span>
                        {!! Form::text('ends_at', $date_val('ends_at'), ['class' => 'form-control scheme_date', 'readonly', 'placeholder' => 'no end']) !!}
                    </div>
                </div>
            </div>
        </div>
    @endcomponent

    @component('components.widget', ['class' => 'box-primary', 'title' => 'What is bought'])
        <div class="row">
            <div class="col-md-3">
                <div class="form-group">
                    {!! Form::label('scope', 'Applies to:*') !!}
                    {!! Form::select('scope', ['product' => 'One product', 'products' => 'A group of products', 'brand' => 'A whole brand (all its products)'], $val('scope', 'product'),
                        ['class' => 'form-control select2', 'style' => 'width:100%', 'id' => 'scope']) !!}
                </div>
            </div>
            <div class="col-md-6 scope_product">
                <div class="form-group">
                    {!! Form::label('buy_item', 'Product:*') !!}
                    <select name="buy_item" id="buy_item" class="form-control" style="width:100%">
                        @if ($buy_pick && ! old('buy_item'))<option value="{{ $buy_pick['id'] }}" selected>{{ $buy_pick['text'] }}</option>@endif
                    </select>
                    <p class="help-block">A product with variations can be picked whole ("all variations") or one variation.</p>
                </div>
            </div>
            <div class="col-md-9 scope_products">
                <div class="form-group">
                    {!! Form::label('group_items', 'Products of the group:*') !!}
                    <select name="group_items[]" id="group_items" class="form-control" style="width:100%" multiple>
                        @foreach (old('group_items') ? [] : $group_picks as $g)<option value="{{ $g['id'] }}" selected>{{ $g['text'] }}</option>@endforeach
                    </select>
                    <p class="help-block">E.g. all "Candies Rs.5": type and pick each product. Quantities of all of them add up.</p>
                </div>
            </div>
            <div class="col-md-4 scope_brand">
                <div class="form-group">
                    {!! Form::label('brand_id', 'Brand:*') !!}
                    {!! Form::select('brand_id', $brands, $val('brand_id'), ['class' => 'form-control select2', 'style' => 'width:100%', 'placeholder' => __('messages.please_select')]) !!}
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-md-3">
                <div class="form-group">
                    {!! Form::label('condition_type', 'Condition:*') !!}
                    {!! Form::select('condition_type', ['qty' => 'Quantity bought', 'value' => 'Bill value (Rs) of these products'], $val('condition_type', 'qty'),
                        ['class' => 'form-control select2', 'style' => 'width:100%', 'id' => 'condition_type']) !!}
                </div>
            </div>
            <div class="col-md-3 cond_qty scope_product">
                <div class="form-group">
                    {!! Form::label('unit_id', 'Counted in unit:') !!}
                    <select name="unit_id" id="unit_id" class="form-control select2" style="width:100%" data-value="{{ $val('unit_id') }}"></select>
                    <p class="help-block">Slabs count this unit, e.g. CTN.</p>
                </div>
            </div>
            <div class="col-md-3 cond_qty scope_many">
                <div class="form-group">
                    {!! Form::label('count_unit', 'Counted in:') !!}
                    {!! Form::select('count_unit', ['big' => 'Boxes / cartons (each product\'s big unit)', 'base' => 'Pieces'], $val('count_unit', 'big') === 'base' ? 'base' : 'big',
                        ['class' => 'form-control select2', 'style' => 'width:100%']) !!}
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group">
                    {!! Form::label('channel', 'For customers:*') !!}
                    {!! Form::select('channel', ['all' => 'All customers', 'retail' => 'Retail only', 'wholesale' => 'Wholesale only'], $val('channel', 'all'),
                        ['class' => 'form-control select2', 'style' => 'width:100%']) !!}
                    <p class="help-block">From the customer's Outlet type ("Wholesale" = wholesale).</p>
                </div>
            </div>
        </div>
    @endcomponent

    @component('components.widget', ['class' => 'box-primary', 'title' => 'Reward'])
        <div class="row">
            <div class="col-md-3">
                <div class="form-group">
                    {!! Form::label('reward_type', 'Reward:*') !!}
                    {!! Form::select('reward_type', ['free' => 'Free goods', 'percent' => '% discount on these products'], $val('reward_type', 'free'),
                        ['class' => 'form-control select2', 'style' => 'width:100%', 'id' => 'reward_type']) !!}
                </div>
            </div>
            <div class="col-md-3 reward_free free_mode_box">
                <div class="form-group">
                    {!! Form::label('free_mode', 'Free item:*') !!}
                    {!! Form::select('free_mode', ['same' => 'Same product (discount on its line)', 'other' => 'Another product (free line)'], $val('free_mode', 'same'),
                        ['class' => 'form-control select2', 'style' => 'width:100%', 'id' => 'free_mode']) !!}
                </div>
            </div>
            <div class="col-md-4 reward_free" id="free_item_box">
                <div class="form-group">
                    {!! Form::label('free_item', 'Free product:*') !!}
                    <select name="free_item" id="free_item" class="form-control" style="width:100%">
                        @if ($free_pick && ! old('free_item'))<option value="{{ $free_pick['id'] }}" selected>{{ $free_pick['text'] }}</option>@endif
                    </select>
                </div>
            </div>
            <div class="col-md-2 reward_free">
                <div class="form-group">
                    {!! Form::label('free_unit_id', 'Free qty unit:') !!}
                    <select name="free_unit_id" id="free_unit_id" class="form-control select2" style="width:100%" data-value="{{ $val('free_unit_id') }}"></select>
                </div>
            </div>
        </div>

        <label>Slabs</label>
        <p class="help-block reward_percent" style="margin-top:0;">Give one % for everybody, or a % per customer class (Outlet class A–E; customers without a class get the lowest %).</p>
        <div class="table-responsive">
        <table class="table table-bordered" id="slab_table" style="max-width:900px;">
            <thead>
                <tr>
                    <th id="buy_head">Buy qty</th>
                    <th class="reward_free">Free qty</th>
                    <th class="reward_percent">% (all)</th>
                    @foreach ($classes as $c)<th class="reward_percent">Class {{ $c }} %</th>@endforeach
                    <th style="width:40px;"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($slab_rows as $s)
                    <tr>
                        <td><input type="text" name="slab_buy[]" class="form-control input_number" value="{{ $num($s->buy_qty) }}" placeholder="12"></td>
                        <td class="reward_free"><input type="text" name="slab_free[]" class="form-control input_number" value="{{ $num($s->free_qty) }}" placeholder="1"></td>
                        <td class="reward_percent"><input type="text" name="slab_percent[]" class="form-control input_number" value="{{ $num($s->percent ?? '') }}" placeholder="2"></td>
                        @foreach ($classes as $c)
                            <td class="reward_percent"><input type="text" name="slab_class_{{ $c }}[]" class="form-control input_number" value="{{ $num($s->class_percents[$c] ?? '') }}"></td>
                        @endforeach
                        <td class="text-center"><i class="fa fa-times text-danger cursor-pointer remove_slab" style="margin-top:10px;"></i></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
        <button type="button" class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-primary" id="add_slab"><i class="fa fa-plus"></i> Add slab</button>
        <div class="checkbox reward_free" style="margin-top:12px;">
            <label>{!! Form::checkbox('repeat', 1, (bool) $val('repeat', 1), ['class' => 'input-icheck']) !!}
                <b>Repeat</b> — 12+1 gives 2 free for 24, 3 for 36 … (off: once per bill / line, e.g. "not for multiple purchases")</label>
        </div>
    @endcomponent

    @component('components.widget', ['class' => 'box-primary', 'title' => 'Where, who pays, limit'])
        <div class="row">
            <div class="col-md-4">
                <div class="form-group">
                    {!! Form::label('location_ids', 'Locations:') !!}
                    {!! Form::select('location_ids[]', $locations, $locations_picked, ['class' => 'form-control select2', 'multiple', 'style' => 'width:100%', 'id' => 'location_ids', 'data-placeholder' => 'All locations']) !!}
                </div>
            </div>
            <div class="col-md-2">
                <div class="form-group">
                    {!! Form::label('funded_by', 'Funded by:*') !!}
                    {!! Form::select('funded_by', ['own' => 'Own (our cost)', 'supplier' => 'Supplier (claim back)'], $val('funded_by', 'own'), ['class' => 'form-control select2', 'style' => 'width:100%', 'id' => 'funded_by']) !!}
                </div>
            </div>
            <div class="col-md-3 supplier_box">
                <div class="form-group">
                    {!! Form::label('supplier_id', 'Supplier / company:') !!}
                    {!! Form::select('supplier_id', $suppliers, $val('supplier_id'), ['class' => 'form-control select2', 'style' => 'width:100%', 'placeholder' => __('messages.please_select')]) !!}
                </div>
            </div>
            <div class="col-md-2 supplier_box">
                <div class="form-group">
                    {!! Form::label('claim_type', 'Claim paid back as:') !!}
                    {!! Form::select('claim_type', ['cash' => 'Money (cash / bank)', 'credit_note' => 'Credit note', 'stock' => 'Stock (free goods)'], $val('claim_type', 'credit_note'), ['class' => 'form-control select2', 'style' => 'width:100%']) !!}
                </div>
            </div>
            <div class="col-md-2 reward_free">
                <div class="form-group">
                    {!! Form::label('budget_qty', 'Budget (total free qty):') !!}
                    {!! Form::text('budget_qty', $num($val('budget_qty')), ['class' => 'form-control input_number', 'placeholder' => 'no limit']) !!}
                    <p class="help-block">In the free unit; the scheme stops when it is used up.</p>
                </div>
            </div>
            <div class="clearfix"></div>
            <div class="col-md-8">
                <div class="form-group">
                    {!! Form::label('notes', 'Notes:') !!}
                    {!! Form::textarea('notes', $val('notes'), ['class' => 'form-control', 'rows' => 2]) !!}
                </div>
            </div>
            <div class="col-md-4">
                <div class="checkbox" style="margin-top:28px;">
                    <label>{!! Form::checkbox('is_active', 1, (bool) $val('is_active', 1), ['class' => 'input-icheck']) !!} <b>Active</b></label>
                </div>
            </div>
        </div>
    @endcomponent

    <div class="text-center" style="margin-bottom:30px;">
        <a href="{{ action([\App\Http\Controllers\TradeSchemeController::class, 'index']) }}" class="tw-dw-btn tw-dw-btn-neutral tw-text-white">@lang('messages.close')</a>
        <button type="submit" class="tw-dw-btn tw-dw-btn-primary tw-text-white tw-dw-btn-lg"><i class="fa fa-save"></i> @lang('messages.save')</button>
    </div>
    {!! Form::close() !!}
</section>
@endsection

@section('javascript')
<script type="text/javascript">
    $(document).ready(function () {
        var unitsUrl = "{{ action([\App\Http\Controllers\TradeSchemeController::class, 'units']) }}";
        var productsUrl = "{{ action([\App\Http\Controllers\TradeSchemeController::class, 'products']) }}";

        $('.scheme_date').datepicker({ autoclose: true, format: datepicker_date_format, clearBtn: true });

        // product pickers: products and variations, typed search
        var pickerOptions = {
            placeholder: 'Type product name or SKU',
            minimumInputLength: 1,
            ajax: {
                url: productsUrl, dataType: 'json', delay: 250,
                data: function (params) { return { q: params.term }; },
                processResults: function (data) { return data; }
            }
        };
        $('#buy_item, #free_item, #group_items').select2(pickerOptions);

        // unit boxes follow the chosen product
        function loadUnits(item, $select, emptyText) {
            var keep = $select.val() || $select.data('value');
            $select.empty();
            if (emptyText !== null) { $select.append(new Option(emptyText, '', false, false)); }
            if (! item) { $select.trigger('change'); return; }
            $.getJSON(unitsUrl, { item: item }, function (units) {
                $.each(units, function (i, u) {
                    $select.append(new Option(u.text, u.id, false, String(u.id) === String(keep)));
                });
                $select.trigger('change');
            });
        }
        function freeUnitSource() { return $('#free_mode').val() === 'same' ? $('#buy_item').val() : $('#free_item').val(); }
        $('#buy_item').on('change', function () {
            loadUnits($(this).val(), $('#unit_id'), null);
            if ($('#free_mode').val() === 'same') { loadUnits(freeUnitSource(), $('#free_unit_id'), 'Same as bought'); }
        });
        $('#free_item').on('change', function () {
            if ($('#free_mode').val() === 'other') { loadUnits(freeUnitSource(), $('#free_unit_id'), null); }
        });

        // show only what the chosen scope / condition / reward needs
        function refresh() {
            var scope = $('#scope').val(), cond = $('#condition_type').val(), reward = $('#reward_type').val();
            $('.scope_product').not('.cond_qty').toggle(scope === 'product');
            $('.scope_products').toggle(scope === 'products');
            $('.scope_brand').toggle(scope === 'brand');
            $('.cond_qty.scope_product').toggle(scope === 'product' && cond === 'qty');
            $('.cond_qty.scope_many').toggle(scope !== 'product' && cond === 'qty');
            $('.reward_free').toggle(reward === 'free');
            $('.reward_percent').toggle(reward === 'percent');
            // free goods of the same product only for one product counted by quantity
            var sameAllowed = scope === 'product' && cond === 'qty';
            if (! sameAllowed && $('#free_mode').val() === 'same') { $('#free_mode').val('other').trigger('change.select2'); }
            $('.free_mode_box').toggle(reward === 'free' && sameAllowed);
            var other = $('#free_mode').val() === 'other';
            $('#free_item_box').toggle(reward === 'free' && other);
            $('#buy_head').text(cond === 'value' ? 'Bill value Rs' : 'Buy qty');
        }
        $('#scope, #condition_type, #reward_type').on('change', refresh);
        $('#free_mode').on('change', function () {
            refresh();
            loadUnits(freeUnitSource(), $('#free_unit_id'), $(this).val() === 'other' ? null : 'Same as bought');
        });
        refresh();
        loadUnits(freeUnitSource(), $('#free_unit_id'), $('#free_mode').val() === 'other' ? null : 'Same as bought');
        loadUnits($('#buy_item').val(), $('#unit_id'), null);

        $('#funded_by').on('change', function () { $('.supplier_box').toggle($(this).val() === 'supplier'); }).trigger('change');

        // slabs
        $('#add_slab').on('click', function () {
            var row = $('#slab_table tbody tr').first().clone();
            row.find('input').val('');
            $('#slab_table tbody').append(row);
            refresh();
        });
        $(document).on('click', '.remove_slab', function () {
            if ($('#slab_table tbody tr').length > 1) { $(this).closest('tr').remove(); }
        });
    });
</script>
@endsection
