@extends('layouts.app')
@php
    $editing = $scheme && empty($scheme->copy_of);
    $title = $editing ? 'Edit scheme '.$scheme->code : (! empty($scheme->copy_of) ? 'Copy of scheme '.$scheme->code : 'Add trade scheme');
    $val = fn ($field, $default = null) => old($field, $scheme->{$field} ?? $default);
    $num = fn ($v) => $v === null || $v === '' ? '' : rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.');
    $slab_rows = old('slab_buy') ? collect(old('slab_buy'))->map(fn ($b, $i) => (object) ['buy_qty' => $b, 'free_qty' => old('slab_free')[$i] ?? ''])
        : ($slabs->isNotEmpty() ? $slabs : collect([(object) ['buy_qty' => '', 'free_qty' => '']]));
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
                    {!! Form::text('name', $val('name'), ['class' => 'form-control', 'required', 'placeholder' => 'e.g. Hilal Candy Ramzan 12+1']) !!}
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
            <div class="col-md-6">
                <div class="form-group">
                    {!! Form::label('buy_item', 'Product:*') !!}
                    <select name="buy_item" id="buy_item" class="form-control" style="width:100%" required>
                        @if ($buy_pick && ! old('buy_item'))<option value="{{ $buy_pick['id'] }}" selected>{{ $buy_pick['text'] }}</option>@endif
                    </select>
                    <p class="help-block">A product with variations can be picked whole ("all variations") or one variation.</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group">
                    {!! Form::label('unit_id', 'Counted in unit:') !!}
                    <select name="unit_id" id="unit_id" class="form-control select2" style="width:100%" data-value="{{ $val('unit_id') }}"></select>
                    <p class="help-block">Slabs count this unit, e.g. CTN.</p>
                </div>
            </div>
        </div>

        <label>Slabs</label>
        <table class="table table-bordered" id="slab_table" style="max-width:520px;">
            <thead><tr><th>Buy qty</th><th>Free qty</th><th style="width:40px;"></th></tr></thead>
            <tbody>
                @foreach ($slab_rows as $s)
                    <tr>
                        <td><input type="text" name="slab_buy[]" class="form-control input_number" value="{{ $num($s->buy_qty) }}" placeholder="12"></td>
                        <td><input type="text" name="slab_free[]" class="form-control input_number" value="{{ $num($s->free_qty) }}" placeholder="1"></td>
                        <td class="text-center"><i class="fa fa-times text-danger cursor-pointer remove_slab" style="margin-top:10px;"></i></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <button type="button" class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-primary" id="add_slab"><i class="fa fa-plus"></i> Add slab</button>
        <div class="checkbox" style="margin-top:12px;">
            <label>{!! Form::checkbox('repeat', 1, (bool) $val('repeat', 1), ['class' => 'input-icheck']) !!}
                <b>Repeat</b> — 12+1 gives 2 free for 24, 3 for 36 … (off: only once per line)</label>
        </div>
    @endcomponent

    @component('components.widget', ['class' => 'box-primary', 'title' => 'What is free'])
        <div class="row">
            <div class="col-md-3">
                <div class="form-group">
                    {!! Form::label('free_mode', 'Free item:*') !!}
                    {!! Form::select('free_mode', ['same' => 'Same product (discount on its line)', 'other' => 'Another product (free line)'], $val('free_mode', 'same'),
                        ['class' => 'form-control select2', 'style' => 'width:100%', 'id' => 'free_mode']) !!}
                </div>
            </div>
            <div class="col-md-5" id="free_item_box">
                <div class="form-group">
                    {!! Form::label('free_item', 'Free product:*') !!}
                    <select name="free_item" id="free_item" class="form-control" style="width:100%">
                        @if ($free_pick && ! old('free_item'))<option value="{{ $free_pick['id'] }}" selected>{{ $free_pick['text'] }}</option>@endif
                    </select>
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group">
                    {!! Form::label('free_unit_id', 'Free qty unit:') !!}
                    <select name="free_unit_id" id="free_unit_id" class="form-control select2" style="width:100%" data-value="{{ $val('free_unit_id') }}"></select>
                    <p class="help-block">Same product: empty = same unit as bought.</p>
                </div>
            </div>
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
            <div class="col-md-2">
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

        $('.scheme_date').datepicker({ autoclose: true, format: datepicker_date_format, clearBtn: true });

        // product pickers: products and variations, typed search
        $('#buy_item, #free_item').select2({
            placeholder: 'Type product name or SKU',
            minimumInputLength: 1,
            ajax: {
                url: "{{ action([\App\Http\Controllers\TradeSchemeController::class, 'products']) }}",
                dataType: 'json', delay: 250,
                data: function (params) { return { q: params.term }; },
                processResults: function (data) { return data; }
            }
        });

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
        $('#free_mode').on('change', function () {
            var other = $(this).val() === 'other';
            $('#free_item_box').toggle(other);
            $('#free_item').prop('required', other);
            loadUnits(freeUnitSource(), $('#free_unit_id'), other ? null : 'Same as bought');
        }).trigger('change');
        loadUnits($('#buy_item').val(), $('#unit_id'), null);

        $('#funded_by').on('change', function () { $('.supplier_box').toggle($(this).val() === 'supplier'); }).trigger('change');

        // slabs
        $('#add_slab').on('click', function () {
            $('#slab_table tbody').append('<tr><td><input type="text" name="slab_buy[]" class="form-control input_number" placeholder="24"></td>'
                + '<td><input type="text" name="slab_free[]" class="form-control input_number" placeholder="3"></td>'
                + '<td class="text-center"><i class="fa fa-times text-danger cursor-pointer remove_slab" style="margin-top:10px;"></i></td></tr>');
        });
        $(document).on('click', '.remove_slab', function () {
            if ($('#slab_table tbody tr').length > 1) { $(this).closest('tr').remove(); }
        });
    });
</script>
@endsection
