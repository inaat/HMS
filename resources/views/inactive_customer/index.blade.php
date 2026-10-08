@extends('layouts.app')
@section('title', 'Customers Not Buying')

@section('content')
<section class="content-header no-print">
    <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">Customers Not Buying
        <small>bought before, but nothing in the last {{ $days }} days — biggest past buyers first</small>
    </h1>
</section>

<section class="content no-print">
    <div style="display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); margin-bottom: 16px;">
        <div style="background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 14px 16px;">
            <small class="text-muted">CUSTOMERS NOT BUYING ({{ $days }}+ DAYS)</small><br><b style="font-size: 20px;">{{ number_format($totals['count']) }}</b>
        </div>
        <div style="background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 14px 16px;">
            <small class="text-muted">THEY BOUGHT BEFORE (TOTAL)</small><br><b style="font-size: 20px;">@format_currency($totals['bought'])</b>
        </div>
        <div style="background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 14px 16px;">
            <small class="text-muted">THEIR DUE</small><br><b style="font-size: 20px; color: #dc2626;">@format_currency($totals['due'])</b>
        </div>
    </div>

    @component('components.filters', ['title' => __('report.filters')])
        <form method="GET" action="{{ action([\App\Http\Controllers\InactiveCustomerController::class, 'index']) }}" id="ic_filter_form">
            <div class="col-md-3">
                <div class="form-group">
                    <label for="ic_days">No purchase in</label>
                    <select name="days" id="ic_days" class="form-control select2" style="width:100%">
                        @foreach ([7 => '7+ days', 15 => '15+ days', 30 => '30+ days', 45 => '45+ days', 60 => '60+ days', 90 => '90+ days', 180 => '180+ days', 365 => '1+ year'] as $value => $label)
                            <option value="{{ $value }}" @if ($days == $value) selected @endif>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="col-md-6">
                <div class="form-group">
                    <label for="ic_search">Search customer</label>
                    <input type="text" name="search" id="ic_search" class="form-control" value="{{ $search }}" placeholder="Name, business name, mobile or city">
                </div>
            </div>
            <div class="col-md-3">
                <label>&nbsp;</label><br>
                <button type="submit" class="tw-dw-btn tw-dw-btn-primary tw-text-white"><i class="fa fa-filter"></i> @lang('report.apply_filters')</button>
            </div>
        </form>
    @endcomponent

    @component('components.widget', ['class' => 'box-primary', 'title' => 'Customers not buying'])
        @slot('tool')
            <div class="box-tools">
                <button type="button" class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-primary tw-text-white" onclick="window.print()"><i class="fa fa-print"></i> Print</button>
            </div>
        @endslot
        <div class="table-responsive">
            <table class="table table-bordered table-striped" id="ic_table">
                <thead>
                    <tr>
                        <th style="width: 40px;">#</th>
                        <th>@lang('contact.customer')</th>
                        <th>@lang('contact.mobile')</th>
                        <th>Last purchase</th>
                        <th>Invoices</th>
                        <th>Total bought</th>
                        <th>@lang('lang_v1.due')</th>
                        <th class="no-print">@lang('messages.actions')</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($customers as $c)
                        @php $ago = \Carbon::parse($c->last_purchase)->startOfDay()->diffInDays(\Carbon::today()); @endphp
                        <tr>
                            <td>{{ $loop->iteration }}</td>
                            <td>
                                <a href="{{ action([\App\Http\Controllers\ContactController::class, 'show'], [$c->id]) }}" target="_blank"><b>{{ $c->supplier_business_name ?: $c->name }}</b></a>
                                @if ($c->supplier_business_name && $c->name != $c->supplier_business_name)<br><small class="text-muted">{{ $c->name }}</small>@endif
                                @if ($c->city || $c->address_line_1)<br><small class="text-muted">{{ trim(implode(', ', array_filter([$c->address_line_1, $c->city]))) }}</small>@endif
                            </td>
                            <td>{{ $c->mobile }}</td>
                            <td data-order="{{ $c->last_purchase }}">
                                {{ @format_date($c->last_purchase) }}<br>
                                <small class="{{ $ago > 90 ? 'text-danger' : 'text-warning' }}"><b>{{ $ago }} days ago</b></small>
                            </td>
                            <td class="text-center">{{ $c->invoices }}</td>
                            <td data-order="{{ $c->total_bought }}">@format_currency($c->total_bought)</td>
                            <td data-order="{{ $c->due }}" @if ($c->due > 0) class="text-danger" style="font-weight: bold;" @endif>@format_currency($c->due)</td>
                            <td class="no-print text-nowrap">
                                <a class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-accent" href="{{ action([\App\Http\Controllers\ContactController::class, 'show'], [$c->id]) }}" target="_blank">
                                    <i class="fas fa-eye"></i> @lang('messages.view')</a>
                                @if ($wa = \App\Http\Controllers\DefaulterController::whatsappNumber($c->mobile))
                                    <a class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-success" href="https://wa.me/{{ $wa }}" target="_blank">
                                        <i class="fab fa-whatsapp"></i> WhatsApp</a>
                                @endif
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
        $('#ic_days').on('change', function () { $('#ic_filter_form').submit(); });
        $('#ic_table').DataTable({ order: [], pageLength: 50, columnDefs: [{ orderable: false, targets: [7] }] });
    });
</script>
@endsection
