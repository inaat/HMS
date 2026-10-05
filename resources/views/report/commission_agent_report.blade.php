@extends('layouts.app')
@section('title', 'Commission Agent Report')

@section('content')
@php
    $brand_name = ! empty($filters['brand_id']) ? ($brands_dropdown[$filters['brand_id']] ?? '') : '';
    $category_name = ! empty($filters['category_id']) ? ($categories[$filters['category_id']] ?? '') : '';
    $location_name = ! empty($filters['location_id']) ? ($business_locations[$filters['location_id']] ?? '') : '';
    $agent_filter_name = ! empty($filters['commission_agent']) ? trim($commission_agents[$filters['commission_agent']] ?? '') : '';
    $product_name = ! empty($filters['product_id']) ? ($products_dropdown[$filters['product_id']] ?? '') : '';
    $commission_of = fn ($r) => $r->commission;
    $total_commission = $calculation_type == 'payment_received' ? $agents->sum('payment_commission') : $agents->sum('commission');
    $date_text = \Carbon::parse($filters['start_date'])->format(session('business.date_format')).' ~ '.\Carbon::parse($filters['end_date'])->format(session('business.date_format'));
    //One agent selected: open the full sales detail of that agent
    $default_tab = $agent_filter_name ? 'tab_lines' : 'tab_agents';
    $customer_of = fn ($r) => $r->customer.(! empty($r->supplier_business_name) ? ' ('.$r->supplier_business_name.')' : '');
@endphp

<section class="content-header no-print">
    <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">Commission Agent Report</h1>
</section>

<section class="content">
    {{-- Main filters: apply to every tab --}}
    <div class="box box-solid no-print cmmsn-card">
        <div class="box-body">
            <form method="GET" action="{{ action([\App\Http\Controllers\ReportController::class, 'getCommissionAgentReport']) }}" id="cmmsn_report_form">
                <input type="hidden" name="start_date" id="cmmsn_start_date" value="{{ $filters['start_date'] }}">
                <input type="hidden" name="end_date" id="cmmsn_end_date" value="{{ $filters['end_date'] }}">
                {{-- Set by the brand / product filters inside the tabs --}}
                <input type="hidden" name="brand_id" id="cmmsn_brand_id" value="{{ $filters['brand_id'] ?? '' }}">
                <input type="hidden" name="product_id" id="cmmsn_product_id" value="{{ $filters['product_id'] ?? '' }}">
                <div class="row">
                    <div class="col-md-3 col-sm-6">
                        <div class="form-group">
                            <label for="cmmsn_date_filter"><i class="fa fa-calendar"></i> Date range</label>
                            <input type="text" id="cmmsn_date_filter" class="form-control cmmsn-date" readonly>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="form-group">
                            <label for="commission_agent"><i class="fa fa-user"></i> Commission agent</label>
                            {!! Form::select('commission_agent', $commission_agents, $filters['commission_agent'] ?? null, ['class' => 'form-control select2', 'id' => 'commission_agent', 'style' => 'width:100%', 'placeholder' => 'All agents']) !!}
                        </div>
                    </div>
                    <div class="col-md-2 col-sm-6">
                        <div class="form-group">
                            <label for="cmmsn_top_brand"><i class="fa fa-tags"></i> Brand</label>
                            {!! Form::select(null, $brands_dropdown, $filters['brand_id'] ?? null, ['class' => 'form-control select2 cmmsn-tab-filter', 'id' => 'cmmsn_top_brand', 'data-target' => '#cmmsn_brand_id', 'style' => 'width:100%', 'placeholder' => 'All brands']) !!}
                        </div>
                    </div>
                    <div class="col-md-2 col-sm-6">
                        <div class="form-group">
                            <label for="category_id"><i class="fa fa-folder-open"></i> Category</label>
                            {!! Form::select('category_id', $categories, $filters['category_id'] ?? null, ['class' => 'form-control select2', 'id' => 'category_id', 'style' => 'width:100%', 'placeholder' => 'All categories']) !!}
                        </div>
                    </div>
                    <div class="col-md-2 col-sm-6">
                        <div class="form-group">
                            <label for="location_id"><i class="fa fa-map-marker"></i> Location</label>
                            {!! Form::select('location_id', $business_locations, $filters['location_id'] ?? null, ['class' => 'form-control select2', 'id' => 'location_id', 'style' => 'width:100%']) !!}
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div id="cmmsn_print_area">
        {{-- Report heading (also the print header) --}}
        <div class="cmmsn-heading">
            <div class="cmmsn-print-only">
                <h3>{{ session('business.name') }}</h3>
                <h4>Commission Agent Report - <span class="cmmsn_print_tab_title"></span></h4>
            </div>
            <div class="cmmsn-heading-main">
                @if($agent_filter_name)
                    <span class="cmmsn-agent-name"><i class="fa fa-user"></i> {{ $agent_filter_name }}</span>
                    @if($agents->isNotEmpty())
                        <span class="cmmsn-chip">Commission {{ @num_format($agents->first()->cmmsn_percent) }}%@if($agents->first()->rule_count) + {{ $agents->first()->rule_count }} brand/product rules @endif</span>
                    @endif
                    <button type="button" class="tw-dw-btn tw-dw-btn-sm tw-text-white cmmsn-whatsapp cmmsn-whatsapp-btn no-print" data-agent="{{ $filters['commission_agent'] }}">
                        <i class="fab fa-whatsapp"></i> Send to agent on WhatsApp
                    </button>
                @else
                    <span class="cmmsn-agent-name"><i class="fa fa-users"></i> All agents</span>
                @endif
                <span class="cmmsn-chip"><i class="fa fa-calendar"></i> {{ $date_text }}</span>
                @if($location_name)<span class="cmmsn-chip">Location: {{ $location_name }}</span>@endif
                @if($category_name)<span class="cmmsn-chip">Category: {{ $category_name }}</span>@endif
                @if($brand_name)<span class="cmmsn-chip">Brand: {{ $brand_name }}</span>@endif
                @if($product_name)<span class="cmmsn-chip">Product: {{ $product_name }}</span>@endif
                <a href="{{ action([\App\Http\Controllers\ReportController::class, 'getCommissionAgentReport']) }}" class="cmmsn-reset no-print"><i class="fa fa-undo"></i> Reset filters</a>
            </div>
        </div>

        {{-- Print-only summary: what is owed to the agent, readable at a glance --}}
        <div class="cmmsn-print-only cmmsn-print-summary">
            <table class="table table-bordered cmmsn-summary-table">
                <tr>
                    <th>Commission agent</th>
                    <td>{{ $agent_filter_name ?: 'All agents' }}</td>
                    <th>Period</th>
                    <td>{{ $date_text }}</td>
                </tr>
                <tr>
                    <th>Invoices</th>
                    <td>{{ number_format($agents->sum('invoice_count')) }}</td>
                    <th>Commission %</th>
                    <td>
                        {{ $agent_filter_name && $agents->isNotEmpty() ? @num_format($agents->first()->cmmsn_percent).'%' : 'Per agent' }}
                        @if($agent_filter_name && $agents->isNotEmpty() && $agents->first()->rule_count)
                            (+ {{ $agents->first()->rule_count }} brand/product rules)
                        @endif
                    </td>
                </tr>
                <tr>
                    <th>Gross sale</th>
                    <td>@format_currency($agents->sum('gross_amount'))</td>
                    <th>Qty sold / returned</th>
                    <td>{{ @format_quantity($agents->sum('qty_sold')) }} / {{ @format_quantity($agents->sum('qty_returned')) }}</td>
                </tr>
                <tr>
                    <th>Returns</th>
                    <td>@format_currency($agents->sum('gross_amount') - $agents->sum('net_amount'))</td>
                    <th>Net sale</th>
                    <td><strong>@format_currency($agents->sum('net_amount'))</strong></td>
                </tr>
                @if($calculation_type == 'payment_received')
                    <tr>
                        <th>Payment received</th>
                        <td>@format_currency($agents->sum('payment_received'))</td>
                        <th>Commission basis</th>
                        <td>Payments received</td>
                    </tr>
                @endif
                <tr class="cmmsn-payable">
                    <th colspan="3" class="text-right">Commission payable</th>
                    <td>@format_currency($total_commission)</td>
                </tr>
            </table>

            @if($brands->isNotEmpty())
                <div class="cmmsn-print-brands">
                <h5 class="cmmsn-print-subtitle">Brand wise summary</h5>
                <table class="table table-bordered cmmsn-table">
                    <thead>
                        <tr>
                            <th>Brand</th>
                            <th class="text-right">Qty sold</th>
                            <th class="text-right">Qty returned</th>
                            <th class="text-right">Net sale</th>
                            <th class="text-right">Commission</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($brands->groupBy('brand')->sortByDesc(fn ($rows) => $rows->sum('net_amount')) as $brand_rows)
                            <tr>
                                <td>{{ $brand_rows->first()->brand ?: '(No brand)' }}</td>
                                <td class="text-right">{{ @format_quantity($brand_rows->sum('qty_sold')) }}</td>
                                <td class="text-right">{{ @format_quantity($brand_rows->sum('qty_returned')) }}</td>
                                <td class="text-right">@format_currency($brand_rows->sum('net_amount'))</td>
                                <td class="text-right">@format_currency($brand_rows->sum('commission'))</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="cmmsn-total">
                            <td>Total</td>
                            <td class="text-right">{{ @format_quantity($brands->sum('qty_sold')) }}</td>
                            <td class="text-right">{{ @format_quantity($brands->sum('qty_returned')) }}</td>
                            <td class="text-right">@format_currency($brands->sum('net_amount'))</td>
                            <td class="text-right">@format_currency($brands->sum('commission'))</td>
                        </tr>
                    </tfoot>
                </table>
                </div>
            @endif

            @if($products->isNotEmpty())
                <div class="cmmsn-print-products">
                <h5 class="cmmsn-print-subtitle">Product wise summary</h5>
                <table class="table table-bordered cmmsn-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Product</th>
                            <th>SKU</th>
                            <th>Brand</th>
                            <th class="text-right">Qty sold</th>
                            <th class="text-right">Qty returned</th>
                            <th class="text-right">Net sale</th>
                            <th class="text-right">Commission</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($products->groupBy(fn ($r) => $r->product_id.'_'.$r->sub_sku)->sortByDesc(fn ($rows) => $rows->sum('net_amount')) as $product_rows)
                            @php $first = $product_rows->first(); @endphp
                            <tr>
                                <td>{{ $loop->iteration }}</td>
                                <td>{{ $first->product }}@if($first->product_type == 'variable') - {{ $first->variation }}@endif</td>
                                <td>{{ $first->sub_sku }}</td>
                                <td>{{ $first->brand }}</td>
                                <td class="text-right">{{ @format_quantity($product_rows->sum('qty_sold')) }} {{ $first->unit }}</td>
                                <td class="text-right">{{ @format_quantity($product_rows->sum('qty_returned')) }}</td>
                                <td class="text-right">@format_currency($product_rows->sum('net_amount'))</td>
                                <td class="text-right">@format_currency($product_rows->sum($commission_of))</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="cmmsn-total">
                            <td colspan="4">Total ({{ $products->pluck('product_id')->unique()->count() }} products)</td>
                            <td class="text-right">{{ @format_quantity($products->sum('qty_sold')) }}</td>
                            <td class="text-right">{{ @format_quantity($products->sum('qty_returned')) }}</td>
                            <td class="text-right">@format_currency($products->sum('net_amount'))</td>
                            <td class="text-right">@format_currency($products->sum($commission_of))</td>
                        </tr>
                    </tfoot>
                </table>
                </div>
            @endif
            <h5 class="cmmsn-print-subtitle"><span class="cmmsn_print_tab_title"></span></h5>
        </div>

        {{-- Totals --}}
        <div class="row cmmsn-stats">
            <div class="col-md-3 col-xs-6">
                <div class="cmmsn-stat">
                    <span class="cmmsn-stat-label">Net sale</span>
                    <span class="cmmsn-stat-value">@format_currency($agents->sum('net_amount'))</span>
                </div>
            </div>
            <div class="col-md-3 col-xs-6">
                <div class="cmmsn-stat cmmsn-stat-accent">
                    <span class="cmmsn-stat-label">Commission</span>
                    <span class="cmmsn-stat-value">@format_currency($total_commission)</span>
                </div>
            </div>
            <div class="col-md-3 col-xs-6">
                <div class="cmmsn-stat">
                    <span class="cmmsn-stat-label">Invoices</span>
                    <span class="cmmsn-stat-value">{{ number_format($agents->sum('invoice_count')) }}</span>
                </div>
            </div>
            <div class="col-md-3 col-xs-6">
                <div class="cmmsn-stat">
                    <span class="cmmsn-stat-label">Qty sold (net)</span>
                    <span class="cmmsn-stat-value">{{ @format_quantity($agents->sum('qty_sold') - $agents->sum('qty_returned')) }}</span>
                </div>
            </div>
        </div>

        <div class="nav-tabs-custom cmmsn-card">
            <ul class="nav nav-tabs no-print">
                <li class="{{ $default_tab == 'tab_agents' ? 'active' : '' }}"><a href="#tab_agents" data-toggle="tab"><i class="fa fa-users"></i> Agents summary</a></li>
                <li class="{{ $default_tab == 'tab_lines' ? 'active' : '' }}"><a href="#tab_lines" data-toggle="tab"><i class="fa fa-list"></i> Sales detail</a></li>
                <li><a href="#tab_products" data-toggle="tab"><i class="fa fa-cubes"></i> Product wise</a></li>
                <li><a href="#tab_brands" data-toggle="tab"><i class="fa fa-tags"></i> Brand wise</a></li>
                <li><a href="#tab_invoices" data-toggle="tab"><i class="fa fa-file-alt"></i> Invoices</a></li>
            </ul>
            <div class="tab-content">

                {{-- ============ Agents summary ============ --}}
                <div class="tab-pane {{ $default_tab == 'tab_agents' ? 'active' : '' }}" id="tab_agents" data-title="Agents summary">
                    <div class="cmmsn-toolbar no-print">
                        <input type="text" class="form-control input-sm cmmsn-search-input" data-table="#cmmsn_agents_table" placeholder="Search agent...">
                        <button type="button" class="tw-dw-btn tw-dw-btn-primary tw-text-white tw-dw-btn-sm cmmsn-print"><i class="fa fa-print"></i> Print</button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover cmmsn-table" id="cmmsn_agents_table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Commission agent</th>
                                    <th class="text-right">Comm. %</th>
                                    <th class="text-right">Invoices</th>
                                    <th class="text-right">Qty sold</th>
                                    <th class="text-right">Qty returned</th>
                                    <th class="text-right">Gross sale</th>
                                    <th class="text-right">Net sale</th>
                                    <th class="text-right">Commission</th>
                                    @if($calculation_type == 'payment_received')
                                        <th class="text-right">Payment received</th>
                                        <th class="text-right">Comm. on payment</th>
                                    @endif
                                    <th class="no-print"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($agents as $agent)
                                    <tr>
                                        <td>{{ $loop->iteration }}</td>
                                        <td><strong>{{ $agent->agent_name }}</strong></td>
                                        <td class="text-right">{{ @num_format($agent->cmmsn_percent) }}%@if($agent->rule_count)<br><small class="text-muted">+ {{ $agent->rule_count }} rules</small>@endif</td>
                                        <td class="text-right">{{ number_format($agent->invoice_count) }}</td>
                                        <td class="text-right">{{ @format_quantity($agent->qty_sold) }}</td>
                                        <td class="text-right">{{ @format_quantity($agent->qty_returned) }}</td>
                                        <td class="text-right">@format_currency($agent->gross_amount)</td>
                                        <td class="text-right">@format_currency($agent->net_amount)</td>
                                        <td class="text-right"><strong>@format_currency($agent->commission)</strong></td>
                                        @if($calculation_type == 'payment_received')
                                            <td class="text-right">@format_currency($agent->payment_received)</td>
                                            <td class="text-right"><strong>@format_currency($agent->payment_commission)</strong></td>
                                        @endif
                                        <td class="no-print text-center">
                                            <a href="#" class="cmmsn-agent-detail tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-primary" data-agent="{{ $agent->agent_id }}"><i class="fa fa-eye"></i> Full detail</a>
                                            <button type="button" class="cmmsn-whatsapp tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-success" data-agent="{{ $agent->agent_id }}" title="Send this agent's report on WhatsApp"><i class="fab fa-whatsapp"></i> WhatsApp</button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="12" class="text-center text-muted">No sales with a commission agent for these filters.</td></tr>
                                @endforelse
                            </tbody>
                            <tfoot>
                                <tr class="cmmsn-total">
                                    <td colspan="3">Total</td>
                                    <td class="text-right">{{ number_format($agents->sum('invoice_count')) }}</td>
                                    <td class="text-right">{{ @format_quantity($agents->sum('qty_sold')) }}</td>
                                    <td class="text-right">{{ @format_quantity($agents->sum('qty_returned')) }}</td>
                                    <td class="text-right">@format_currency($agents->sum('gross_amount'))</td>
                                    <td class="text-right">@format_currency($agents->sum('net_amount'))</td>
                                    <td class="text-right">@format_currency($agents->sum('commission'))</td>
                                    @if($calculation_type == 'payment_received')
                                        <td class="text-right">@format_currency($agents->sum('payment_received'))</td>
                                        <td class="text-right">@format_currency($agents->sum('payment_commission'))</td>
                                    @endif
                                    <td class="no-print"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <p class="text-muted small tw-mb-0">
                        Commission = net sale (excluding tax, after returns) &times; agent commission %.
                        @if($calculation_type == 'payment_received')
                            Your POS settings pay commission on <strong>payments received</strong>, shown in the last columns.
                        @endif
                    </p>
                </div>

                {{-- ============ Sales detail: every line with date, invoice and customer ============ --}}
                <div class="tab-pane {{ $default_tab == 'tab_lines' ? 'active' : '' }}" id="tab_lines" data-title="Sales detail">
                    <div class="cmmsn-toolbar no-print">
                        {!! Form::select(null, $brands_dropdown, $filters['brand_id'] ?? null, ['class' => 'form-control select2 cmmsn-tab-filter', 'data-target' => '#cmmsn_brand_id', 'style' => 'width:180px', 'placeholder' => 'All brands']) !!}
                        {!! Form::select(null, $products_dropdown, $filters['product_id'] ?? null, ['class' => 'form-control select2 cmmsn-tab-filter', 'data-target' => '#cmmsn_product_id', 'style' => 'width:240px', 'placeholder' => 'All products']) !!}
                        <input type="text" class="form-control input-sm cmmsn-search-input" data-table="#cmmsn_lines_table" data-mode="rows" placeholder="Search invoice, customer, product...">
                        <button type="button" class="tw-dw-btn tw-dw-btn-primary tw-text-white tw-dw-btn-sm cmmsn-print"><i class="fa fa-print"></i> Print</button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover cmmsn-table" id="cmmsn_lines_table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Invoice no.</th>
                                    <th>Customer</th>
                                    <th>Commission agent</th>
                                    <th>Product</th>
                                    <th>Brand</th>
                                    <th class="text-right">Qty</th>
                                    <th class="text-right">Returned</th>
                                    <th class="text-right">Price</th>
                                    <th class="text-right">Amount</th>
                                    <th class="text-right">Commission</th>
                                </tr>
                            </thead>
                            @forelse($lines->groupBy(fn ($r) => substr($r->transaction_date, 0, 10)) as $day => $day_lines)
                                <tbody class="cmmsn-group">
                                    @foreach($day_lines as $row)
                                        <tr class="cmmsn-row">
                                            <td class="text-nowrap">{{ @format_date($row->transaction_date) }}</td>
                                            <td><a href="#" data-href="{{ action([\App\Http\Controllers\SellController::class, 'show'], [$row->transaction_id]) }}" class="btn-modal" data-container=".view_modal">{{ $row->invoice_no }}</a></td>
                                            <td>{{ $customer_of($row) }}</td>
                                            <td>{{ $row->agent_name }}</td>
                                            <td>{{ $row->product }}@if($row->product_type == 'variable') - {{ $row->variation }}@endif</td>
                                            <td>{{ $row->brand }}</td>
                                            <td class="text-right">{{ @format_quantity($row->qty_sold) }} {{ $row->unit }}</td>
                                            <td class="text-right">{{ $row->qty_returned > 0 ? @format_quantity($row->qty_returned) : '' }}</td>
                                            <td class="text-right">@format_currency($row->unit_price)</td>
                                            <td class="text-right">@format_currency($row->net_amount)</td>
                                            <td class="text-right">@format_currency($commission_of($row))<br><small class="text-muted">{{ $row->rule_text }}</small></td>
                                        </tr>
                                    @endforeach
                                    <tr class="cmmsn-subtotal">
                                        <td colspan="6" class="text-right">{{ @format_date($day) }} total ({{ $day_lines->pluck('transaction_id')->unique()->count() }} invoices)</td>
                                        <td class="text-right">{{ @format_quantity($day_lines->sum('qty_sold')) }}</td>
                                        <td class="text-right">{{ @format_quantity($day_lines->sum('qty_returned')) }}</td>
                                        <td></td>
                                        <td class="text-right">@format_currency($day_lines->sum('net_amount'))</td>
                                        <td class="text-right">@format_currency($day_lines->sum($commission_of))</td>
                                    </tr>
                                </tbody>
                            @empty
                                <tbody><tr><td colspan="11" class="text-center text-muted">No sales for these filters.</td></tr></tbody>
                            @endforelse
                            <tfoot>
                                <tr class="cmmsn-total">
                                    <td colspan="6">Grand total ({{ number_format($lines->pluck('transaction_id')->unique()->count()) }} invoices, {{ number_format($lines->count()) }} lines)</td>
                                    <td class="text-right">{{ @format_quantity($lines->sum('qty_sold')) }}</td>
                                    <td class="text-right">{{ @format_quantity($lines->sum('qty_returned')) }}</td>
                                    <td></td>
                                    <td class="text-right">@format_currency($lines->sum('net_amount'))</td>
                                    <td class="text-right">@format_currency($lines->sum($commission_of))</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                {{-- ============ Product wise ============ --}}
                <div class="tab-pane" id="tab_products" data-title="Product wise">
                    <div class="cmmsn-toolbar no-print">
                        {!! Form::select(null, $brands_dropdown, $filters['brand_id'] ?? null, ['class' => 'form-control select2 cmmsn-tab-filter', 'data-target' => '#cmmsn_brand_id', 'style' => 'width:180px', 'placeholder' => 'All brands']) !!}
                        {!! Form::select(null, $products_dropdown, $filters['product_id'] ?? null, ['class' => 'form-control select2 cmmsn-tab-filter', 'data-target' => '#cmmsn_product_id', 'style' => 'width:240px', 'placeholder' => 'All products']) !!}
                        <input type="text" class="form-control input-sm cmmsn-search-input" data-table="#cmmsn_products_table" placeholder="Search product, SKU, agent...">
                        <button type="button" class="tw-dw-btn tw-dw-btn-primary tw-text-white tw-dw-btn-sm cmmsn-print"><i class="fa fa-print"></i> Print</button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered cmmsn-table" id="cmmsn_products_table">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>SKU</th>
                                    <th>Brand</th>
                                    <th>Commission agent</th>
                                    <th class="text-right">Invoices</th>
                                    <th class="text-right">Qty sold</th>
                                    <th class="text-right">Qty returned</th>
                                    <th class="text-right">Avg. price</th>
                                    <th class="text-right">Net sale</th>
                                    <th class="text-right">Commission</th>
                                </tr>
                            </thead>
                            @forelse($products->groupBy(fn ($r) => $r->product_id.'_'.$r->sub_sku)->sortByDesc(fn ($rows) => $rows->sum('net_amount')) as $product_rows)
                                <tbody class="cmmsn-group">
                                    @foreach($product_rows->sortByDesc('net_amount') as $row)
                                        @php $net_qty = $row->qty_sold - $row->qty_returned; @endphp
                                        <tr>
                                            @if($loop->first)
                                                <td rowspan="{{ $product_rows->count() }}"><strong>{{ $row->product }}</strong>@if($row->product_type == 'variable') - {{ $row->variation }}@endif</td>
                                                <td rowspan="{{ $product_rows->count() }}">{{ $row->sub_sku }}</td>
                                                <td rowspan="{{ $product_rows->count() }}">{{ $row->brand }}</td>
                                            @endif
                                            <td>{{ $row->agent_name }}</td>
                                            <td class="text-right">{{ number_format($row->invoice_count) }}</td>
                                            <td class="text-right">{{ @format_quantity($row->qty_sold) }} {{ $row->unit }}</td>
                                            <td class="text-right">{{ @format_quantity($row->qty_returned) }}</td>
                                            <td class="text-right">@format_currency($net_qty != 0 ? $row->net_amount / $net_qty : 0)</td>
                                            <td class="text-right">@format_currency($row->net_amount)</td>
                                            <td class="text-right">@format_currency($commission_of($row))<br><small class="text-muted">{{ $row->rule_text }}</small></td>
                                        </tr>
                                    @endforeach
                                    @if($product_rows->count() > 1)
                                        <tr class="cmmsn-subtotal">
                                            <td colspan="4" class="text-right">{{ $product_rows->first()->product }} total</td>
                                            <td class="text-right">{{ number_format($product_rows->sum('invoice_count')) }}</td>
                                            <td class="text-right">{{ @format_quantity($product_rows->sum('qty_sold')) }}</td>
                                            <td class="text-right">{{ @format_quantity($product_rows->sum('qty_returned')) }}</td>
                                            <td></td>
                                            <td class="text-right">@format_currency($product_rows->sum('net_amount'))</td>
                                            <td class="text-right">@format_currency($product_rows->sum($commission_of))</td>
                                        </tr>
                                    @endif
                                </tbody>
                            @empty
                                <tbody><tr><td colspan="10" class="text-center text-muted">No sales for these filters.</td></tr></tbody>
                            @endforelse
                            <tfoot>
                                <tr class="cmmsn-total">
                                    <td colspan="5">Total ({{ $products->pluck('product_id')->unique()->count() }} products)</td>
                                    <td class="text-right">{{ @format_quantity($products->sum('qty_sold')) }}</td>
                                    <td class="text-right">{{ @format_quantity($products->sum('qty_returned')) }}</td>
                                    <td></td>
                                    <td class="text-right">@format_currency($products->sum('net_amount'))</td>
                                    <td class="text-right">@format_currency($products->sum($commission_of))</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                {{-- ============ Brand wise ============ --}}
                <div class="tab-pane" id="tab_brands" data-title="Brand wise">
                    <div class="cmmsn-toolbar no-print">
                        {!! Form::select(null, $brands_dropdown, $filters['brand_id'] ?? null, ['class' => 'form-control select2 cmmsn-tab-filter', 'data-target' => '#cmmsn_brand_id', 'style' => 'width:180px', 'placeholder' => 'All brands']) !!}
                        <input type="text" class="form-control input-sm cmmsn-search-input" data-table="#cmmsn_brands_table" placeholder="Search brand or agent...">
                        <button type="button" class="tw-dw-btn tw-dw-btn-primary tw-text-white tw-dw-btn-sm cmmsn-print"><i class="fa fa-print"></i> Print</button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered cmmsn-table" id="cmmsn_brands_table">
                            <thead>
                                <tr>
                                    <th>Brand</th>
                                    <th>Commission agent</th>
                                    <th class="text-right">Products</th>
                                    <th class="text-right">Qty sold</th>
                                    <th class="text-right">Qty returned</th>
                                    <th class="text-right">Net sale</th>
                                    <th class="text-right">Commission</th>
                                </tr>
                            </thead>
                            @forelse($brands->groupBy('brand')->sortByDesc(fn ($rows) => $rows->sum('net_amount')) as $brand_rows)
                                <tbody class="cmmsn-group">
                                    @foreach($brand_rows->sortByDesc('net_amount') as $row)
                                        <tr>
                                            @if($loop->first)
                                                <td rowspan="{{ $brand_rows->count() }}"><strong>{{ $row->brand ?: '(No brand)' }}</strong></td>
                                            @endif
                                            <td>{{ $row->agent_name }}</td>
                                            <td class="text-right">{{ $row->product_count }}</td>
                                            <td class="text-right">{{ @format_quantity($row->qty_sold) }}</td>
                                            <td class="text-right">{{ @format_quantity($row->qty_returned) }}</td>
                                            <td class="text-right">@format_currency($row->net_amount)</td>
                                            <td class="text-right">@format_currency($row->commission)</td>
                                        </tr>
                                    @endforeach
                                    @if($brand_rows->count() > 1)
                                        <tr class="cmmsn-subtotal">
                                            <td colspan="3" class="text-right">{{ $brand_rows->first()->brand ?: '(No brand)' }} total</td>
                                            <td class="text-right">{{ @format_quantity($brand_rows->sum('qty_sold')) }}</td>
                                            <td class="text-right">{{ @format_quantity($brand_rows->sum('qty_returned')) }}</td>
                                            <td class="text-right">@format_currency($brand_rows->sum('net_amount'))</td>
                                            <td class="text-right">@format_currency($brand_rows->sum('commission'))</td>
                                        </tr>
                                    @endif
                                </tbody>
                            @empty
                                <tbody><tr><td colspan="7" class="text-center text-muted">No sales for these filters.</td></tr></tbody>
                            @endforelse
                            <tfoot>
                                <tr class="cmmsn-total">
                                    <td colspan="3">Total ({{ $brands->pluck('brand')->unique()->count() }} brands)</td>
                                    <td class="text-right">{{ @format_quantity($brands->sum('qty_sold')) }}</td>
                                    <td class="text-right">{{ @format_quantity($brands->sum('qty_returned')) }}</td>
                                    <td class="text-right">@format_currency($brands->sum('net_amount'))</td>
                                    <td class="text-right">@format_currency($brands->sum('commission'))</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                {{-- ============ Invoices ============ --}}
                <div class="tab-pane" id="tab_invoices" data-title="Invoices">
                    <div class="cmmsn-toolbar no-print">
                        <input type="text" class="form-control input-sm cmmsn-search-input" data-table="#cmmsn_invoices_table" placeholder="Search invoice no., customer or agent...">
                        <button type="button" class="tw-dw-btn tw-dw-btn-primary tw-text-white tw-dw-btn-sm cmmsn-print"><i class="fa fa-print"></i> Print</button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover cmmsn-table" id="cmmsn_invoices_table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Invoice no.</th>
                                    <th>Customer</th>
                                    <th>Commission agent</th>
                                    <th>Location</th>
                                    <th>Payment</th>
                                    <th class="text-right">Qty</th>
                                    <th class="text-right">Invoice total</th>
                                    <th class="text-right">Commissionable sale</th>
                                    <th class="text-right">Commission</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($invoices->sortBy('transaction_date') as $row)
                                    <tr>
                                        <td class="text-nowrap">{{ @format_datetime($row->transaction_date) }}</td>
                                        <td><a href="#" data-href="{{ action([\App\Http\Controllers\SellController::class, 'show'], [$row->transaction_id]) }}" class="btn-modal" data-container=".view_modal">{{ $row->invoice_no }}</a></td>
                                        <td>{{ $customer_of($row) }}</td>
                                        <td>{{ $row->agent_name }}</td>
                                        <td>{{ $row->location }}</td>
                                        <td><span class="label @payment_status($row->payment_status)">{{ __('lang_v1.' . $row->payment_status) }}</span></td>
                                        <td class="text-right">{{ @format_quantity($row->net_qty) }}</td>
                                        <td class="text-right">@format_currency($row->final_total)</td>
                                        <td class="text-right">@format_currency($row->net_amount)</td>
                                        <td class="text-right">@format_currency($commission_of($row))</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="10" class="text-center text-muted">No invoices for these filters.</td></tr>
                                @endforelse
                            </tbody>
                            <tfoot>
                                <tr class="cmmsn-total">
                                    <td colspan="6">Total ({{ number_format($invoices->count()) }} invoices)</td>
                                    <td class="text-right">{{ @format_quantity($invoices->sum('net_qty')) }}</td>
                                    <td class="text-right">@format_currency($invoices->sum('final_total'))</td>
                                    <td class="text-right">@format_currency($invoices->sum('net_amount'))</td>
                                    <td class="text-right">@format_currency($invoices->sum($commission_of))</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        {{-- Print-only signatures --}}
        <div class="cmmsn-print-only cmmsn-signatures">
            <div>Prepared by</div>
            <div>Commission agent</div>
            <div>Approved by</div>
        </div>
    </div>
</section>

<div class="modal fade view_modal" tabindex="-1" role="dialog"></div>
@endsection

@section('css')
<style>
    .cmmsn-card { border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,.08); border: 1px solid #e5e7eb; }
    .cmmsn-card .box-body { padding: 15px 15px 0; }
    .cmmsn-date { background: #fff !important; cursor: pointer; }
    .nav-tabs-custom.cmmsn-card { overflow: hidden; }
    .nav-tabs-custom > .nav-tabs > li > a { font-weight: 600; }
    .cmmsn-heading { margin-bottom: 12px; }
    .cmmsn-heading-main { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
    .cmmsn-agent-name { font-size: 18px; font-weight: 700; color: #111827; margin-right: 4px; }
    .cmmsn-chip { display: inline-block; padding: 3px 10px; border-radius: 999px; background: #f3f4f6; border: 1px solid #e5e7eb; font-size: 12px; color: #374151; }
    .cmmsn-print-only { display: none; }
    .cmmsn-reset { margin-left: auto; font-size: 13px; }
    .cmmsn-whatsapp-btn { background: #25d366; border-color: #25d366; }
    .cmmsn-whatsapp-btn:hover { background: #1ebe5a; border-color: #1ebe5a; }
    #cmmsn_agents_table td .tw-dw-btn { white-space: nowrap; }
    .cmmsn-stat { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 14px 16px; margin-bottom: 15px; box-shadow: 0 1px 3px rgba(0,0,0,.06); }
    .cmmsn-stat-label { display: block; font-size: 12px; color: #6b7280; text-transform: uppercase; letter-spacing: .04em; }
    .cmmsn-stat-value { display: block; font-size: 22px; font-weight: 700; color: #111827; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .cmmsn-stat-accent { background: #ecfdf5; border-color: #a7f3d0; }
    .cmmsn-stat-accent .cmmsn-stat-value { color: #047857; }
    .cmmsn-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 12px; padding-bottom: 12px; border-bottom: 1px solid #f3f4f6; }
    .cmmsn-toolbar .cmmsn-search-input { width: 260px; max-width: 100%; height: 34px; }
    .cmmsn-toolbar .cmmsn-print { margin-left: auto; }
    .cmmsn-table { margin-bottom: 10px; }
    .cmmsn-table > thead > tr > th { background: #f9fafb; white-space: nowrap; }
    .cmmsn-table td { vertical-align: middle !important; }
    .cmmsn-group + .cmmsn-group { border-top: 2px solid #d1d5db; }
    .cmmsn-subtotal td { background: #f3f4f6; font-weight: 600; }
    .cmmsn-total td { background: #e5e7eb; font-weight: 700; }
    @media (max-width: 767px) {
        .cmmsn-toolbar > * , .cmmsn-toolbar .select2-container { width: 100% !important; }
        .cmmsn-toolbar .cmmsn-print { margin-left: 0; }
        .cmmsn-stat-value { font-size: 17px; }
    }

    @media print {
        @page { margin: 10mm; }
        .cmmsn-print-only { display: block; }
        .cmmsn-heading .cmmsn-print-only { text-align: center; margin-bottom: 8px; }
        .cmmsn-print-only h3, .cmmsn-print-only h4 { margin: 2px 0; }
        /* The printed summary table replaces the on-screen chips and cards */
        .cmmsn-heading-main, .cmmsn-stats { display: none !important; }
        .cmmsn-summary-table { font-size: 12px; margin-bottom: 10px; }
        .cmmsn-summary-table th { width: 20%; background: #f3f4f6 !important; }
        .cmmsn-payable th, .cmmsn-payable td { font-size: 15px; font-weight: 700; background: #e5e7eb !important; }
        .cmmsn-print-subtitle { font-weight: 700; margin: 10px 0 4px; font-size: 13px; }
        /* Printing the product / brand tab: skip the same list in the summary */
        #cmmsn_print_area[data-tab="tab_products"] .cmmsn-print-products,
        #cmmsn_print_area[data-tab="tab_brands"] .cmmsn-print-brands { display: none !important; }
        .cmmsn-card { box-shadow: none; border: none; }
        .cmmsn-signatures { display: flex !important; justify-content: space-between; margin-top: 50px; }
        .cmmsn-signatures div { width: 28%; border-top: 1px solid #000; text-align: center; padding-top: 4px; font-size: 12px; }
        .cmmsn-table { font-size: 11px; }
        .cmmsn-table th, .cmmsn-table td { padding: 3px 5px !important; }
        .cmmsn-table a { color: inherit; text-decoration: none; }
        .cmmsn-table thead { display: table-header-group; }
        .cmmsn-table tr { page-break-inside: avoid; }
        .table-responsive { overflow: visible !important; }
        .nav-tabs-custom { border: none; }
        .tab-content > .tab-pane:not(.active) { display: none !important; }
        .cmmsn-group:not([style*="display: none"]) { display: table-row-group !important; }
    }
</style>
@endsection

@section('javascript')
<script type="text/javascript">
    $(document).ready(function() {
        var $form = $('#cmmsn_report_form');

        //Remember the open tab when the page reloads with new filters
        function submit_report(tab) {
            tab = tab || $('.nav-tabs li.active a').attr('href') || '';
            $form.attr('action', $form.attr('action').split('#')[0] + tab);
            $form.submit();
        }

        //Date range: reload as soon as a range is picked
        var start = moment('{{ $filters['start_date'] }}', 'YYYY-MM-DD');
        var end = moment('{{ $filters['end_date'] }}', 'YYYY-MM-DD');
        $('#cmmsn_date_filter').val(start.format(moment_date_format) + ' ~ ' + end.format(moment_date_format));
        $('#cmmsn_date_filter').daterangepicker($.extend({}, dateRangeSettings, { startDate: start, endDate: end }), function(start, end) {
            $('#cmmsn_date_filter').val(start.format(moment_date_format) + ' ~ ' + end.format(moment_date_format));
            $('#cmmsn_start_date').val(start.format('YYYY-MM-DD'));
            $('#cmmsn_end_date').val(end.format('YYYY-MM-DD'));
            submit_report();
        });

        //Main filters
        $form.find('select').on('change', function() {
            submit_report();
        });

        //Brand / product filters inside the tabs
        $('.cmmsn-tab-filter').on('change', function() {
            $($(this).data('target')).val($(this).val() || '');
            submit_report();
        });

        //Open the tab from the URL (kept after filtering)
        if (location.hash && $('.nav-tabs a[href="' + location.hash + '"]').length) {
            $('.nav-tabs a[href="' + location.hash + '"]').tab('show');
        }
        function update_print_title() {
            var $tab = $('.tab-content > .tab-pane.active');
            $('.cmmsn_print_tab_title').text($tab.data('title') || '');
            $('#cmmsn_print_area').attr('data-tab', $tab.attr('id') || '');
        }
        $('.nav-tabs a[data-toggle="tab"]').on('shown.bs.tab', function(e) {
            history.replaceState(null, '', location.pathname + location.search + $(e.target).attr('href'));
            update_print_title();
        });
        update_print_title();

        //Print only the open tab
        $('.cmmsn-print').on('click', function() {
            update_print_title();
            window.print();
        });

        //Agent "Full detail": that agent's sales detail
        $(document).on('click', '.cmmsn-agent-detail', function(e) {
            e.preventDefault();
            var agent = String($(this).data('agent'));

            //Agent already selected: the page has the data, just open the tab
            if (agent === '{{ $filters['commission_agent'] ?? '' }}') {
                $('.nav-tabs a[href="#tab_lines"]').tab('show');
                $('html, body').animate({ scrollTop: $('.nav-tabs-custom').offset().top - 60 }, 200);
                return;
            }

            $form.find('select').off('change');
            $('#commission_agent').val(agent).trigger('change');
            submit_report('#tab_lines');
        });

        //Send one agent's report (with the filters on screen) as a PDF on WhatsApp
        $(document).on('click', '.cmmsn-whatsapp', function() {
            var btn = $(this);
            var data = {};
            $.each($form.serializeArray(), function(i, field) {
                data[field.name] = field.value;
            });
            data.commission_agent = btn.data('agent');
            data._token = '{{ csrf_token() }}';

            var original_html = btn.html();
            btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Sending...');
            $.ajax({
                method: 'POST',
                url: "{{ action([\App\Http\Controllers\ReportController::class, 'sendCommissionAgentReportWhatsapp']) }}",
                dataType: 'json',
                data: data,
                success: function(result) {
                    if (result.success) {
                        toastr.success(result.msg);
                    } else {
                        toastr.error(result.msg);
                    }
                },
                error: function() {
                    toastr.error("{{ __('messages.something_went_wrong') }}");
                },
                complete: function() {
                    btn.prop('disabled', false).html(original_html);
                }
            });
        });

        //Quick search inside a tab
        $('.cmmsn-search-input').on('keyup', function() {
            var term = $(this).val().toLowerCase();
            var $table = $($(this).data('table'));
            var $groups = $table.find('tbody.cmmsn-group');

            if ($(this).data('mode') == 'rows') {
                //Search single lines; hide day totals while searching
                $table.find('tr.cmmsn-row').each(function() {
                    $(this).toggle($(this).text().toLowerCase().indexOf(term) > -1);
                });
                $table.find('tr.cmmsn-subtotal').toggle(term == '');
                $groups.each(function() {
                    $(this).toggle($(this).find('tr.cmmsn-row:visible').length > 0 || term == '');
                });
            } else if ($groups.length) {
                //Keep a whole product / brand group together
                $groups.each(function() {
                    $(this).toggle($(this).text().toLowerCase().indexOf(term) > -1);
                });
            } else {
                $table.find('tbody tr').each(function() {
                    $(this).toggle($(this).text().toLowerCase().indexOf(term) > -1);
                });
            }
        });
    });
</script>
@endsection
