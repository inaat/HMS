@extends('layouts.app')
@section('title', 'Trade schemes')

@section('content')
@php
    $badge = ['running' => 'label-success', 'upcoming' => 'label-info', 'ended' => 'label-default', 'off' => 'label-default', 'budget used' => 'label-warning'];
    $q = fn ($v) => rtrim(rtrim(number_format((float) $v, 4, '.', ','), '0'), '.');
@endphp
<section class="content-header">
    <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">Trade schemes
        <small>"buy 12 get 1 free" offers — applied by themselves on POS and Add Sale</small>
    </h1>
</section>

<section class="content">
    @component('components.widget', ['class' => 'box-primary', 'title' => 'All schemes'])
        @slot('tool')
            <div class="box-tools">
                <a href="{{ action([\App\Http\Controllers\TradeSchemeController::class, 'create']) }}" class="tw-dw-btn tw-bg-gradient-to-r tw-from-indigo-600 tw-to-blue-500 tw-font-bold tw-text-white tw-border-none tw-rounded-full pull-right">
                    <i class="fa fa-plus"></i> Add scheme</a>
            </div>
        @endslot
        <div class="table-responsive">
            <table class="table table-bordered table-striped" id="scheme_table">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Buy</th>
                        <th>Slabs</th>
                        <th>Free</th>
                        <th>Dates</th>
                        <th>Locations</th>
                        <th>Funded by</th>
                        <th>Free given</th>
                        <th>Status</th>
                        <th>@lang('messages.action')</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($schemes as $s)
                        <tr>
                            <td><b>{{ $s->code }}</b></td>
                            <td>{{ $s->name }}</td>
                            <td>
                                {{ $s->product_name }}@if ($s->variation_id && $s->product_type === 'variable') - {{ $s->variation_name }}@endif
                                @if (! $s->variation_id && $s->product_type === 'variable') <small class="text-muted">(all variations)</small>@endif
                                <br><small class="text-muted">counted in {{ $s->unit_name ?: 'base unit' }}</small>
                            </td>
                            <td style="white-space:nowrap;">
                                <b>{{ $s->slab_text }}</b>
                                @if ($s->repeat) <br><small class="text-muted">repeats</small>@endif
                            </td>
                            <td>
                                @if ($s->free_mode === 'same')
                                    same product <small class="text-muted">({{ $s->free_unit_name ?: 'base unit' }})</small>
                                @else
                                    {{ $s->free_product_name }}@if ($s->free_product_type === 'variable') - {{ $s->free_variation_name }}@endif
                                    <small class="text-muted">({{ $s->free_unit_name ?: 'base unit' }})</small>
                                @endif
                            </td>
                            <td style="white-space:nowrap;">
                                {{ $s->starts_at ? @format_date($s->starts_at) : 'any' }} –<br>{{ $s->ends_at ? @format_date($s->ends_at) : 'no end' }}
                            </td>
                            <td><small>{{ $s->location_text }}</small></td>
                            <td>
                                @if ($s->funded_by === 'supplier')
                                    <span class="label label-primary">Supplier</span><br><small>{{ $s->supplier_name ?: '—' }}</small>
                                    <br><small class="text-muted">claim paid as {{ ['cash' => 'money', 'credit_note' => 'credit note', 'stock' => 'stock'][$s->claim_type ?? 'credit_note'] ?? '' }}</small>
                                @else
                                    <span class="label label-default">Own</span>
                                @endif
                            </td>
                            <td style="white-space:nowrap;">
                                {{ $q($s->free_used) }} {{ $s->free_unit_name }}
                                @if ($s->budget_qty !== null)
                                    <br><small class="{{ $s->free_used >= (float) $s->budget_qty ? 'text-danger' : 'text-muted' }}">of {{ $q($s->budget_qty) }} budget</small>
                                @endif
                            </td>
                            <td><span class="label {{ $badge[$s->status] ?? 'label-default' }}">{{ $s->status }}</span></td>
                            <td style="white-space:nowrap;">
                                <a href="{{ action([\App\Http\Controllers\TradeSchemeController::class, 'edit'], [$s->id]) }}" class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-primary">
                                    <i class="fa fa-edit"></i> Edit</a>
                                <a href="{{ action([\App\Http\Controllers\TradeSchemeController::class, 'copy'], [$s->id]) }}" class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-info" title="New scheme with the same rules">
                                    <i class="fa fa-copy"></i> Copy</a>
                                {!! Form::open(['url' => action([\App\Http\Controllers\TradeSchemeController::class, 'toggle'], [$s->id]), 'method' => 'post', 'style' => 'display:inline;']) !!}
                                    <button type="submit" class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline {{ $s->is_active ? 'tw-dw-btn-warning' : 'tw-dw-btn-success' }}">
                                        <i class="fa fa-power-off"></i> {{ $s->is_active ? 'Switch off' : 'Switch on' }}</button>
                                {!! Form::close() !!}
                                {!! Form::open(['url' => action([\App\Http\Controllers\TradeSchemeController::class, 'destroy'], [$s->id]), 'method' => 'delete', 'style' => 'display:inline;',
                                    'onsubmit' => "return confirm('Delete scheme ".e($s->code)."? If it was used on sales it is only switched off.')"]) !!}
                                    <button type="submit" class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-error"><i class="fa fa-trash"></i></button>
                                {!! Form::close() !!}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endcomponent
</section>
@endsection

@section('javascript')
<script type="text/javascript">
    $(document).ready(function () {
        $('#scheme_table').DataTable({ order: [], pageLength: 50, columnDefs: [{ orderable: false, targets: [10] }] });
    });
</script>
@endsection
