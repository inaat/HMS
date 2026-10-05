@extends('layouts.app')
@section('title', __('home.home'))

@section('content')

    @include('home.partials.dash_styles')
    @php
        $dvHour = (int) \Carbon::now()->format('G');
        $dvGreeting = $dvHour < 12 ? 'Good Morning' : ($dvHour < 17 ? 'Good Afternoon' : 'Good Evening');
        $dvName = trim(Session::get('user.first_name') . ' ' . Session::get('user.last_name'));
        $dvInitials = strtoupper(mb_substr(Session::get('user.first_name') ?? '', 0, 1) . mb_substr(Session::get('user.last_name') ?? '', 0, 1));
    @endphp
    <div class="dash-v2">
        {{-- header: initials, greeting, location + date filter (wired by public/js/home.js) --}}
        <div class="dv-head">
            <div class="dv-avatar">{{ $dvInitials ?: 'U' }}</div>
            <div class="dv-title">
                <h2>{{ __('home.home') }}</h2>
                <div class="hello">{{ $dvGreeting }}, {{ $dvName }}!</div>
                <div class="sub">{{ Session::get('business.name') }}</div>
            </div>
            <div class="dv-tools">
                @if (auth()->user()->can('dashboard.data') && $is_admin)
                    @if (count($all_locations) > 1)
                        <div class="dv-location">
                            {!! Form::select('dashboard_location', $all_locations, null, [
                                'class' => 'form-control select2',
                                'placeholder' => __('lang_v1.select_location'),
                                'id' => 'dashboard_location',
                                'style' => 'width: 100%',
                            ]) !!}
                        </div>
                    @endif
                    <button type="button" id="dashboard_date_filter">
                        <i class="fa fa-calendar"></i>
                        <span>{{ __('messages.filter_by_date') }}</span>
                        <i class="fa fa-caret-down"></i>
                    </button>
                @endif
                <div class="dv-pill"><i class="fa fa-calendar-check"></i> {{ \Carbon::now()->format('d M Y') }}</div>
            </div>
        </div>

        @if (auth()->user()->can('dashboard.data') && $is_admin)
            {{-- receivable / payable / recovered / builty --}}
            <div class="dv-grid">
                <a href="{{ url('/get-parties?type=customer') }}" class="dv-stat big t-green">
                    <div class="ic"><i class="fa fa-arrow-down"></i></div>
                    <div class="txt">
                        <div class="lbl">Total Receivable</div>
                        <p class="num total_receivable"></p>
                        <p class="sub total_receivable_count"></p>
                    </div>
                </a>
                <a href="{{ url('/get-parties?type=supplier') }}" class="dv-stat big t-red">
                    <div class="ic"><i class="fa fa-arrow-up"></i></div>
                    <div class="txt">
                        <div class="lbl">Total Payable</div>
                        <p class="num total_payable"></p>
                        <p class="sub total_payable_count"></p>
                    </div>
                </a>
                <div class="dv-stat big t-teal">
                    <div class="ic"><i class="fa fa-hand-holding-usd"></i></div>
                    <div class="txt">
                        <div class="lbl">Total Recover Amount</div>
                        <p class="num recover_amount"></p>
                    </div>
                </div>
                <div class="dv-stat big t-orange">
                    <div class="ic"><i class="fa fa-truck"></i></div>
                    <div class="txt">
                        <div class="lbl">Total Builty Amount</div>
                        <p class="num total_builty"></p>
                    </div>
                </div>
            </div>

            <div class="dv-row">
                {{-- sales, purchases and expenses for the selected dates --}}
                <div class="dv-panel">
                    <div class="dv-panel-h">
                        <div class="ph-ic"><i class="fa fa-chart-line"></i></div>
                        <h5>Business Overview</h5>
                    </div>

                    <div class="dv-sub-h" style="margin-top: 0;"><i class="fa fa-shopping-cart"></i> Sales</div>
                    <div class="dv-acc">
                        <div class="dv-stat t-blue">
                            <div class="ic"><i class="fa fa-shopping-cart"></i></div>
                            <div class="txt"><div class="lbl">{{ __('home.total_sell') }}</div><p class="num total_sell"></p></div>
                        </div>
                        <div class="dv-stat t-green">
                            <div class="ic"><i class="fa fa-coins"></i></div>
                            <div class="txt"><div class="lbl">{{ __('lang_v1.net') }} @show_tooltip(__('lang_v1.net_home_tooltip'))</div><p class="num net"></p></div>
                        </div>
                        <div class="dv-stat t-orange">
                            <div class="ic"><i class="fa fa-file-invoice"></i></div>
                            <div class="txt"><div class="lbl">{{ __('home.invoice_due') }}</div><p class="num invoice_due"></p></div>
                        </div>
                        <div class="dv-stat t-red">
                            <div class="ic"><i class="fa fa-undo"></i></div>
                            <div class="txt">
                                <div class="lbl">{{ __('lang_v1.total_sell_return') }}
                                    <i class="fa fa-info-circle text-info hover-q no-print" aria-hidden="true" data-container="body"
                                        data-toggle="popover" data-placement="auto bottom" id="total_srp"
                                        data-value="{{ __('lang_v1.total_sell_return') }}-{{ __('lang_v1.total_sell_return_paid') }}"
                                        data-content="" data-html="true" data-trigger="hover"></i>
                                </div>
                                <p class="num total_sell_return"></p>
                            </div>
                        </div>
                    </div>

                    <div class="dv-sub-h is-red"><i class="fa fa-truck-loading"></i> Purchases &amp; Expenses</div>
                    <div class="dv-acc">
                        <div class="dv-stat t-blue">
                            <div class="ic"><i class="fa fa-cart-arrow-down"></i></div>
                            <div class="txt"><div class="lbl">{{ __('home.total_purchase') }}</div><p class="num total_purchase"></p></div>
                        </div>
                        <div class="dv-stat t-orange">
                            <div class="ic"><i class="fa fa-exclamation-triangle"></i></div>
                            <div class="txt"><div class="lbl">{{ __('home.purchase_due') }}</div><p class="num purchase_due"></p></div>
                        </div>
                        <div class="dv-stat t-red">
                            <div class="ic"><i class="fa fa-reply"></i></div>
                            <div class="txt">
                                <div class="lbl">{{ __('lang_v1.total_purchase_return') }}
                                    <i class="fa fa-info-circle text-info hover-q no-print" aria-hidden="true" data-container="body"
                                        data-toggle="popover" data-placement="auto bottom" id="total_prp"
                                        data-value="{{ __('lang_v1.total_purchase_return') }}-{{ __('lang_v1.total_purchase_return_paid') }}"
                                        data-content="" data-html="true" data-trigger="hover"></i>
                                </div>
                                <p class="num total_purchase_return"></p>
                            </div>
                        </div>
                        <div class="dv-stat t-violet">
                            <div class="ic"><i class="fa fa-money-bill-wave"></i></div>
                            <div class="txt"><div class="lbl">{{ __('lang_v1.expense') }}</div><p class="num total_expense"></p></div>
                        </div>
                    </div>

                    <div class="dv-sub-h"><i class="fa fa-chart-bar"></i> Sales, Purchases &amp; Expenses &ndash; last 30 days</div>
                    <div id="dv_daily_chart" style="height: 240px;"></div>
                </div>

                {{-- donut: filled from the same /home/get-totals reply home.js fetches (script at the bottom) --}}
                <div class="dv-panel">
                    <div class="dv-panel-h">
                        <div class="ph-ic"><i class="fa fa-chart-pie"></i></div>
                        <h5>Financial Overview</h5>
                    </div>
                    <div class="dv-donut-wrap">
                        <div id="dv_fin_donut"></div>
                        <ul class="dv-legend">
                            <li><span class="dot" style="background:#3b82c4"></span>{{ __('home.total_sell') }}<span class="v" id="dv_leg_sell">&ndash;</span></li>
                            <li><span class="dot" style="background:#1f9fa8"></span>{{ __('home.total_purchase') }}<span class="v" id="dv_leg_purchase">&ndash;</span></li>
                            <li><span class="dot" style="background:#8a63c9"></span>{{ __('lang_v1.expense') }}<span class="v" id="dv_leg_expense">&ndash;</span></li>
                            <li><span class="dot" style="background:#e58a2b"></span>{{ __('home.invoice_due') }}<span class="v" id="dv_leg_invoice_due">&ndash;</span></li>
                            <li><span class="dot" style="background:#2f9e6e"></span><b>{{ __('lang_v1.net') }}</b><span class="v" id="dv_leg_net">&ndash;</span></li>
                        </ul>
                    </div>
                </div>
            </div>
        @endif
    </div>
    @if (auth()->user()->can('dashboard.data'))
        <div class="tw-px-5 tw-py-6">
            <div class="tw-grid tw-grid-cols-1 tw-gap-4 sm:tw-gap-5 lg:tw-grid-cols-2">
                @if (auth()->user()->can('sell.view') || auth()->user()->can('direct_sell.view'))
                    @if (!empty($all_locations))
                        <div
                            class="tw-transition-all lg:tw-col-span-2 xl:tw-col-span-2 tw-duration-200 tw-bg-white tw-shadow-sm tw-rounded-xl tw-ring-1 hover:tw-shadow-md hover:tw--translate-y-0.5 tw-ring-gray-200">
                            <div class="tw-p-4 sm:tw-p-5">
                                <div class="tw-flex tw-items-center tw-gap-2.5">
                                    <div
                                        class="tw-border-2 tw-flex tw-items-center tw-justify-center tw-rounded-full tw-w-10 tw-h-10">
                                        <svg aria-hidden="true" class="tw-size-5 tw-text-sky-500 tw-shrink-0"
                                            xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-width="2"
                                            stroke="currentColor" fill="none" stroke-linecap="round"
                                            stroke-linejoin="round">
                                            <path stroke="none" d="M0 0h24v24H0z" fill="none"></path>
                                            <path d="M6 19m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0"></path>
                                            <path d="M17 19m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0"></path>
                                            <path d="M17 17h-11v-14h-2"></path>
                                            <path d="M6 5l14 1l-1 7h-13"></path>
                                        </svg>
                                    </div>

                                    <h3 class="tw-font-bold tw-text-base lg:tw-text-xl">
                                        {{ __('home.sells_last_30_days') }}
                                    </h3>
                                </div>
                                <div class="tw-mt-5">
                                    <div
                                        class="tw-grid tw-w-full tw-h-100 tw-border tw-border-gray-200 tw-border-dashed tw-rounded-xl tw-bg-gray-50 ">
                                        <p class="tw-text-sm tw-italic tw-font-normal tw-text-gray-400">
                                            {!! $sells_chart_1->container() !!}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endif

                    {{-- @if (!empty($widgets['after_sales_last_30_days']))
                        @foreach ($widgets['after_sales_last_30_days'] as $widget)
                            {!! $widget !!}
                        @endforeach
                    @endif --}}
                    @if (!empty($all_locations))
                        <div
                            class="tw-transition-all lg:tw-col-span-2 xl:tw-col-span-2 tw-duration-200 tw-bg-white tw-shadow-sm tw-rounded-xl tw-ring-1 hover:tw-shadow-md hover:tw--translate-y-0.5 tw-ring-gray-200">
                            <div class="tw-p-4 sm:tw-p-5">
                                <div class="tw-flex tw-items-center tw-gap-2.5">
                                    <div
                                        class="tw-border-2 tw-flex tw-items-center tw-justify-center tw-rounded-full tw-w-10 tw-h-10">
                                        <svg aria-hidden="true" class="tw-size-5 tw-text-sky-500 tw-shrink-0"
                                            xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-width="2"
                                            stroke="currentColor" fill="none" stroke-linecap="round"
                                            stroke-linejoin="round">
                                            <path stroke="none" d="M0 0h24v24H0z" fill="none"></path>
                                            <path d="M6 19m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0"></path>
                                            <path d="M17 19m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0"></path>
                                            <path d="M17 17h-11v-14h-2"></path>
                                            <path d="M6 5l14 1l-1 7h-13"></path>
                                        </svg>
                                    </div>
                                    <h3 class="tw-font-bold tw-text-base lg:tw-text-xl">
                                        {{ __('home.sells_current_fy') }}
                                    </h3>
                                </div>
                                <div class="tw-mt-5">
                                    <div
                                        class="tw-grid tw-w-full tw-h-100 tw-border tw-border-gray-200 tw-border-dashed tw-rounded-xl tw-bg-gray-50 ">
                                        <p class="tw-text-sm tw-italic tw-font-normal tw-text-gray-400">
                                            {!! $sells_chart_2->container() !!}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endif
                @endif
                {{-- @if (!empty($widgets['after_sales_current_fy']))
                    @foreach ($widgets['after_sales_current_fy'] as $widget)
                        {!! $widget !!}
                    @endforeach
                @endif --}}
                @if (auth()->user()->can('sell.view') || auth()->user()->can('direct_sell.view'))
                    <div
                        class="tw-transition-all lg:tw-col-span-1 tw-duration-200 tw-bg-white tw-shadow-sm tw-rounded-xl tw-ring-1 hover:tw-shadow-md hover:tw--translate-y-0.5 tw-ring-gray-200">
                        <div class="tw-p-4 sm:tw-p-5">
                            <div class="tw-flex tw-items-center tw-gap-2.5">
                                <div
                                    class="tw-border-2 tw-flex tw-items-center tw-justify-center tw-rounded-full tw-w-10 tw-h-10">
                                    <svg aria-hidden="true" class="tw-text-yellow-500 tw-size-5 tw-shrink-0"
                                        xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-width="2"
                                        stroke="currentColor" fill="none" stroke-linecap="round"
                                        stroke-linejoin="round">
                                        <path stroke="none" d="M0 0h24v24H0z" fill="none"></path>
                                        <path d="M12 9v4"></path>
                                        <path
                                            d="M10.363 3.591l-8.106 13.534a1.914 1.914 0 0 0 1.636 2.871h16.214a1.914 1.914 0 0 0 1.636 -2.87l-8.106 -13.536a1.914 1.914 0 0 0 -3.274 0z">
                                        </path>
                                        <path d="M12 16h.01"></path>
                                    </svg>
                                </div>
                                <div class="tw-flex tw-items-center tw-flex-1 tw-min-w-0 tw-gap-1">
                                    <div class="tw-w-full sm:tw-w-1/2 md:tw-w-1/2">
                                        <h3 class="tw-font-bold tw-text-base lg:tw-text-xl">
                                            {{ __('lang_v1.sales_payment_dues') }}
                                            @show_tooltip(__('lang_v1.tooltip_sales_payment_dues'))
                                        </h3>
                                    </div>
                                    <div class="tw-w-full sm:tw-w-1/2 md:tw-w-1/2">
                                        {!! Form::select('sales_payment_dues_location', $all_locations, null, [
                                            'class' => 'form-control select2',
                                            'placeholder' => __('lang_v1.select_location'),
                                            'id' => 'sales_payment_dues_location',
                                        ]) !!}
                                    </div>
                                </div>
                            </div>


                            <div class="tw-flow-root tw-mt-5  tw-border-gray-200">
                                <div class="tw--mx-4 tw--my-2 tw-overflow-x-auto sm:tw--mx-5">
                                    <div class="tw-inline-block tw-min-w-full tw-py-2 tw-align-middle sm:tw-px-5">
                                        <table class="table table-bordered table-striped" id="sales_payment_dues_table"
                                            style="width: 100%;">
                                            <thead>
                                                <tr>
                                                    <th>@lang('contact.customer')</th>
                                                    <th>@lang('sale.invoice_no')</th>
                                                    <th>@lang('home.due_amount')</th>
                                                    <th>@lang('messages.action')</th>
                                                </tr>
                                            </thead>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif
                @can('purchase.view')
                    <div
                        class="tw-transition-all lg:tw-col-span-1 tw-duration-200 tw-bg-white tw-shadow-sm tw-rounded-xl tw-ring-1 hover:tw-shadow-md hover:tw--translate-y-0.5 tw-ring-gray-200">
                        <div class="tw-p-4 sm:tw-p-5">
                            <div class="tw-flex tw-items-center tw-gap-2.5">
                                <div
                                    class="tw-border-2 tw-flex tw-items-center tw-justify-center tw-rounded-full tw-w-10 tw-h-10">
                                    <svg aria-hidden="true" class="tw-text-yellow-500 tw-size-5 tw-shrink-0"
                                        xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-width="2"
                                        stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                                        <path stroke="none" d="M0 0h24v24H0z" fill="none"></path>
                                        <path d="M12 9v4"></path>
                                        <path
                                            d="M10.363 3.591l-8.106 13.534a1.914 1.914 0 0 0 1.636 2.871h16.214a1.914 1.914 0 0 0 1.636 -2.87l-8.106 -13.536a1.914 1.914 0 0 0 -3.274 0z">
                                        </path>
                                        <path d="M12 16h.01"></path>
                                    </svg>
                                </div>
                                <div class="tw-flex tw-items-center tw-flex-1 tw-min-w-0 tw-gap-1">
                                    <div class="tw-w-full sm:tw-w-1/2 md:tw-w-1/2">
                                        <h3 class="tw-font-bold tw-text-base lg:tw-text-xl">
                                            {{ __('lang_v1.purchase_payment_dues') }}
                                            @show_tooltip(__('tooltip.payment_dues'))
                                        </h3>
                                    </div>
                                    <div class="tw-w-full sm:tw-w-1/2 md:tw-w-1/2">
                                        @if (count($all_locations) > 1)
                                            {!! Form::select('purchase_payment_dues_location', $all_locations, null, [
                                                'class' => 'form-control select2 ',
                                                'placeholder' => __('lang_v1.select_location'),
                                                'id' => 'purchase_payment_dues_location',
                                            ]) !!}
                                        @endif
                                    </div>
                                </div>

                            </div>
                            <div class="tw-flow-root tw-mt-5  tw-border-gray-200">
                                <div class="tw--mx-4 tw--my-2 tw-overflow-x-auto sm:tw--mx-5">
                                    <div class="tw-inline-block tw-min-w-full tw-py-2 tw-align-middle sm:tw-px-5">
                                        <table class="table table-bordered table-striped" id="purchase_payment_dues_table"
                                            style="width: 100%;">
                                            <thead>
                                                <tr>
                                                    <th>@lang('purchase.supplier')</th>
                                                    <th>@lang('purchase.ref_no')</th>
                                                    <th>@lang('home.due_amount')</th>
                                                    <th>@lang('messages.action')</th>
                                                </tr>
                                            </thead>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @endcan
                @can('stock_report.view')
                    <div
                        class="tw-transition-all lg:tw-col-span-2 tw-duration-200 tw-bg-white tw-shadow-sm tw-rounded-xl tw-ring-1 hover:tw-shadow-md hover:tw--translate-y-0.5 tw-ring-gray-200">
                        <div class="tw-p-4 sm:tw-p-5">
                            <div class="tw-flex tw-items-center tw-gap-2.5">
                                <div
                                    class="tw-border-2 tw-flex tw-items-center tw-justify-center tw-rounded-full tw-w-10 tw-h-10">
                                    <svg aria-hidden="true" class="tw-text-yellow-500 tw-size-5 tw-shrink-0"
                                        xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-width="2"
                                        stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                                        <path stroke="none" d="M0 0h24v24H0z" fill="none"></path>
                                        <path d="M3 12a9 9 0 1 0 18 0a9 9 0 0 0 -18 0"></path>
                                        <path d="M12 8v4"></path>
                                        <path d="M12 16h.01"></path>
                                    </svg>
                                </div>
                                <div class="tw-flex tw-items-center tw-flex-1 tw-min-w-0 tw-gap-1">
                                    <div class="tw-w-full sm:tw-w-1/2 md:tw-w-1/2">
                                        <h3 class="tw-font-bold tw-text-base lg:tw-text-xl">
                                            {{ __('home.product_stock_alert') }}
                                            @show_tooltip(__('tooltip.product_stock_alert'))
                                        </h3>
                                    </div>
                                    <div class="tw-w-full sm:tw-w-1/2 md:tw-w-1/2">
                                        @if (count($all_locations) > 1)
                                            {!! Form::select('stock_alert_location', $all_locations, null, [
                                                'class' => 'form-control select2',
                                                'placeholder' => __('lang_v1.select_location'),
                                                'id' => 'stock_alert_location',
                                            ]) !!}
                                        @endif
                                    </div>
                                </div>
                            </div>
                            <div class="tw-flow-root tw-mt-5  tw-border-gray-200">
                                <div class="tw--mx-4 tw--my-2 tw-overflow-x-auto sm:tw--mx-5">
                                    <div class="tw-inline-block tw-min-w-full tw-py-2 tw-align-middle sm:tw-px-5">
                                        <table class="table table-bordered table-striped" id="stock_alert_table"
                                            style="width: 100%;">
                                            <thead>
                                                <tr>
                                                    <th>@lang('sale.product')</th>
                                                    <th>@lang('business.location')</th>
                                                    <th>@lang('report.current_stock')</th>
                                                </tr>
                                            </thead>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    @if (session('business.enable_product_expiry') == 1)
                        <div
                            class="tw-transition-all lg:tw-col-span-1 tw-duration-200 tw-bg-white tw-shadow-sm tw-rounded-xl tw-ring-1 hover:tw-shadow-md hover:tw--translate-y-0.5 tw-ring-gray-200">
                            <div class="tw-p-4 sm:tw-p-5">
                                <div class="tw-flex tw-items-center tw-gap-2.5">
                                    <div
                                        class="tw-border-2 tw-flex tw-items-center tw-justify-center tw-rounded-full tw-w-10 tw-h-10">
                                        <svg aria-hidden="true" class="tw-text-yellow-500 tw-size-5 tw-shrink-0"
                                            xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-width="2"
                                            stroke="currentColor" fill="none" stroke-linecap="round"
                                            stroke-linejoin="round">
                                            <path stroke="none" d="M0 0h24v24H0z" fill="none"></path>
                                            <path d="M12 9v4"></path>
                                            <path
                                                d="M10.363 3.591l-8.106 13.534a1.914 1.914 0 0 0 1.636 2.871h16.214a1.914 1.914 0 0 0 1.636 -2.87l-8.106 -13.536a1.914 1.914 0 0 0 -3.274 0z">
                                            </path>
                                            <path d="M12 16h.01"></path>
                                        </svg>
                                    </div>
                                    <div class="tw-flex tw-items-center tw-flex-1 tw-min-w-0 tw-gap-1">
                                        <div class="tw-w-full sm:tw-w-1/2 md:tw-w-1/2">
                                            <h3 class="tw-font-bold tw-text-base lg:tw-text-xl">
                                                {{ __('home.stock_expiry_alert') }}
                                                @show_tooltip(
                                                __('tooltip.stock_expiry_alert', [
                                                'days'
                                                =>session('business.stock_expiry_alert_days', 30) ]) )
                                            </h3>
                                        </div>
                                    </div>
                                </div>
                                <div class="tw-flow-root tw-mt-5  tw-border-gray-200">
                                    <div class="tw--mx-4 tw--my-2 tw-overflow-x-auto sm:tw--mx-5">
                                        <div class="tw-inline-block tw-min-w-full tw-py-2 tw-align-middle sm:tw-px-5">
                                            <input type="hidden" id="stock_expiry_alert_days"
                                                value="{{ \Carbon::now()->addDays(session('business.stock_expiry_alert_days', 30))->format('Y-m-d') }}">
                                            <table class="table table-bordered table-striped" id="stock_expiry_alert_table">
                                                <thead>
                                                    <tr>
                                                        <th>@lang('business.product')</th>
                                                        <th>@lang('business.location')</th>
                                                        <th>@lang('report.stock_left')</th>
                                                        <th>@lang('product.expires_in')</th>
                                                    </tr>
                                                </thead>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endif
                @endcan
                @if (auth()->user()->can('so.view_all') || auth()->user()->can('so.view_own'))
                    <div
                        class="tw-transition-all lg:tw-col-span-2 tw-duration-200 tw-bg-white tw-shadow-sm tw-rounded-xl tw-ring-1 hover:tw-shadow-md hover:tw--translate-y-0.5 tw-ring-gray-200">
                        <div class="tw-p-4 sm:tw-p-5">
                            <div class="tw-flex tw-items-center tw-gap-2.5">
                                <div
                                    class="tw-border-2 tw-flex tw-items-center tw-justify-center tw-rounded-full tw-w-10 tw-h-10">
                                    <svg aria-hidden="true" class="tw-text-yellow-500 tw-size-5 tw-shrink-0"
                                        xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-width="2"
                                        stroke="currentColor" fill="none" stroke-linecap="round"
                                        stroke-linejoin="round">
                                        <path stroke="none" d="M0 0h24v24H0z" fill="none"></path>
                                        <path d="M3 12a9 9 0 1 0 18 0a9 9 0 0 0 -18 0"></path>
                                        <path d="M12 8v4"></path>
                                        <path d="M12 16h.01"></path>
                                    </svg>
                                </div>
                                <div class="tw-flex tw-items-center tw-flex-1 tw-min-w-0 tw-gap-1">
                                    <div class="tw-w-full sm:tw-w-1/2 md:tw-w-1/2">
                                        <h3 class="tw-font-bold tw-text-base lg:tw-text-xl">
                                            {{ __('lang_v1.sales_order') }}
                                        </h3>
                                    </div>
                                    <div class="tw-w-full sm:tw-w-1/2 md:tw-w-1/2">
                                        @if (count($all_locations) > 1)
                                            {!! Form::select('so_location', $all_locations, null, [
                                                'class' => 'form-control select2',
                                                'placeholder' => __('lang_v1.select_location'),
                                                'id' => 'so_location',
                                            ]) !!}
                                        @endif
                                    </div>
                                </div>
                            </div>
                            <div class="tw-flow-root tw-mt-5  tw-border-gray-200">
                                <div class="tw--mx-4 tw--my-2 tw-overflow-x-auto sm:tw--mx-5">
                                    <div class="tw-inline-block tw-min-w-full tw-py-2 tw-align-middle sm:tw-px-5">
                                        <table class="table table-bordered table-striped ajax_view"
                                            id="sales_order_table">
                                            <thead>
                                                <tr>
                                                    <th>@lang('messages.action')</th>
                                                    <th>@lang('messages.date')</th>
                                                    <th>@lang('restaurant.order_no')</th>
                                                    <th>@lang('sale.customer_name')</th>
                                                    <th>@lang('lang_v1.contact_no')</th>
                                                    <th>@lang('sale.location')</th>
                                                    <th>@lang('sale.status')</th>
                                                    <th>@lang('lang_v1.shipping_status')</th>
                                                    <th>@lang('lang_v1.quantity_remaining')</th>
                                                    <th>@lang('lang_v1.added_by')</th>
                                                </tr>
                                            </thead>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif
                @if (
                    !empty($common_settings['enable_purchase_requisition']) &&
                        (auth()->user()->can('purchase_requisition.view_all') || auth()->user()->can('purchase_requisition.view_own')))
                    <div
                        class="tw-transition-all lg:tw-col-span-2 tw-duration-200 tw-bg-white tw-shadow-sm tw-rounded-xl tw-ring-1 hover:tw-shadow-md hover:tw--translate-y-0.5 tw-ring-gray-200">
                        <div class="tw-p-4 sm:tw-p-5">
                            <div class="tw-flex tw-items-center tw-gap-2.5">
                                <div
                                    class="tw-border-2 tw-flex tw-items-center tw-justify-center tw-rounded-full tw-w-10 tw-h-10">
                                    <svg aria-hidden="true" class="tw-text-yellow-500 tw-size-5 tw-shrink-0"
                                        xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-width="2"
                                        stroke="currentColor" fill="none" stroke-linecap="round"
                                        stroke-linejoin="round">
                                        <path stroke="none" d="M0 0h24v24H0z" fill="none"></path>
                                        <path d="M10 10v4a1 1 0 0 0 1 1h2a1 1 0 0 0 1 -1v-4"></path>
                                        <path d="M9 6h6"></path>
                                        <path d="M10 6v-2a1 1 0 0 1 1 -1h2a1 1 0 0 1 1 1v2"></path>
                                        <circle cx="12" cy="16" r="2"></circle>
                                        <path d="M5 20h14a2 2 0 0 0 2 -2v-10"></path>
                                        <path d="M15 16v4"></path>
                                        <path d="M9 20v-4"></path>
                                    </svg>
                                </div>
                                <div class="tw-flex tw-items-center tw-flex-1 tw-min-w-0 tw-gap-1">
                                    <div class="tw-w-full sm:tw-w-1/2 md:tw-w-1/2">
                                        <h3 class="tw-font-bold tw-text-base lg:tw-text-xl">
                                            @lang('lang_v1.purchase_requisition')
                                        </h3>
                                    </div>
                                    <div class="tw-w-full sm:tw-w-1/2 md:tw-w-1/2">
                                        @if (count($all_locations) > 1)
                                            @if (count($all_locations) > 1)
                                                {!! Form::select('pr_location', $all_locations, null, [
                                                    'class' => 'form-control select2',
                                                    'placeholder' => __('lang_v1.select_location'),
                                                    'id' => 'pr_location',
                                                ]) !!}
                                            @endif
                                        @endif
                                    </div>
                                </div>
                            </div>
                            <div class="tw-flow-root tw-mt-5  tw-border-gray-200">
                                <div class="tw--mx-4 tw--my-2 tw-overflow-x-auto sm:tw--mx-5">
                                    <div class="tw-inline-block tw-min-w-full tw-py-2 tw-align-middle sm:tw-px-5">
                                        <table class="table table-bordered table-striped ajax_view"
                                            id="purchase_requisition_table" style="width: 100%;">
                                            <thead>
                                                <tr>
                                                    <th>@lang('messages.action')</th>
                                                    <th>@lang('messages.date')</th>
                                                    <th>@lang('purchase.ref_no')</th>
                                                    <th>@lang('purchase.location')</th>
                                                    <th>@lang('sale.status')</th>
                                                    <th>@lang('lang_v1.required_by_date')</th>
                                                    <th>@lang('lang_v1.added_by')</th>
                                                </tr>
                                            </thead>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif

                @if (
                    !empty($common_settings['enable_purchase_order']) &&
                        (auth()->user()->can('purchase_order.view_all') || auth()->user()->can('purchase_order.view_own')))

                    <div
                        class="tw-transition-all lg:tw-col-span-2 tw-duration-200 tw-bg-white tw-shadow-sm tw-rounded-xl tw-ring-1 hover:tw-shadow-md hover:tw--translate-y-0.5 tw-ring-gray-200">
                        <div class="tw-p-4 sm:tw-p-5">
                            <div class="tw-flex tw-items-center tw-gap-2.5">
                                <div
                                    class="tw-border-2 tw-flex tw-items-center tw-justify-center tw-rounded-full tw-w-10 tw-h-10">
                                    <svg aria-hidden="true" class="tw-text-yellow-500 tw-size-5 tw-shrink-0"
                                        xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-width="2"
                                        stroke="currentColor" fill="none" stroke-linecap="round"
                                        stroke-linejoin="round">
                                        <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                        <rect x="4" y="4" width="16" height="16" rx="2" />
                                        <line x1="4" y1="10" x2="20" y2="10" />
                                        <line x1="12" y1="4" x2="12" y2="20" />
                                        <line x1="12" y1="10" x2="16" y2="10" />
                                    </svg>
                                </div>
                                <div class="tw-flex tw-items-center tw-flex-1 tw-min-w-0 tw-gap-1">
                                    <div class="tw-w-full sm:tw-w-1/2 md:tw-w-1/2">
                                        <h3 class="tw-font-bold tw-text-base lg:tw-text-xl">
                                            @lang('lang_v1.purchase_order')
                                        </h3>
                                    </div>
                                    <div class="tw-w-full sm:tw-w-1/2 md:tw-w-1/2">
                                        @if (count($all_locations) > 1)
                                            {!! Form::select('po_location', $all_locations, null, [
                                                'class' => 'form-control select2',
                                                'placeholder' => __('lang_v1.select_location'),
                                                'id' => 'po_location',
                                            ]) !!}
                                        @endif
                                    </div>
                                </div>
                            </div>
                            <div class="tw-flow-root tw-mt-5  tw-border-gray-200">
                                <div class="tw--mx-4 tw--my-2 tw-overflow-x-auto sm:tw--mx-5">
                                    <div class="tw-inline-block tw-min-w-full tw-py-2 tw-align-middle sm:tw-px-5">
                                        <table class="table table-bordered table-striped ajax_view"
                                            id="purchase_order_table" style="width: 100%;">
                                            <thead>
                                                <tr>
                                                    <th>@lang('messages.action')</th>
                                                    <th>@lang('messages.date')</th>
                                                    <th>@lang('purchase.ref_no')</th>
                                                    <th>@lang('purchase.location')</th>
                                                    <th>@lang('purchase.supplier')</th>
                                                    <th>@lang('sale.status')</th>
                                                    <th>@lang('lang_v1.quantity_remaining')</th>
                                                    <th>@lang('lang_v1.added_by')</th>
                                                </tr>
                                            </thead>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                @endif
                @if (auth()->user()->can('access_pending_shipments_only') ||
                        auth()->user()->can('access_shipping') ||
                        auth()->user()->can('access_own_shipping'))
                    <div
                        class="tw-transition-all lg:tw-col-span-2 tw-duration-200 tw-bg-white tw-shadow-sm tw-rounded-xl tw-ring-1 hover:tw-shadow-md hover:tw--translate-y-0.5 tw-ring-gray-200">
                        <div class="tw-p-4 sm:tw-p-5">
                            <div class="tw-flex tw-items-center tw-gap-2.5">
                                <div
                                    class="tw-border-2 tw-flex tw-items-center tw-justify-center tw-rounded-full tw-w-10 tw-h-10">
                                    <svg aria-hidden="true" class="tw-text-yellow-500 tw-size-5 tw-shrink-0"
                                        xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-width="2"
                                        stroke="currentColor" fill="none" stroke-linecap="round"
                                        stroke-linejoin="round">
                                        <path stroke="none" d="M0 0h24v24H0z" fill="none"></path>
                                        <path d="M7 17m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0"></path>
                                        <path d="M17 17m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0"></path>
                                        <path d="M5 17h-2v-4m-1 -8h11v12m-4 0h6m4 0h2v-6h-8m0 -5h5l3 5"></path>
                                        <path d="M3 9l4 0"></path>
                                    </svg>
                                </div>
                                <div class="tw-flex tw-items-center tw-flex-1 tw-min-w-0 tw-gap-1">
                                    <div class="tw-w-full sm:tw-w-1/2 md:tw-w-1/2">
                                        <h3 class="tw-font-bold tw-text-base lg:tw-text-xl">
                                            @lang('lang_v1.pending_shipments')
                                        </h3>
                                    </div>
                                    <div class="tw-w-full sm:tw-w-1/2 md:tw-w-1/2">
                                        @if (count($all_locations) > 1)
                                            {!! Form::select('pending_shipments_location', $all_locations, null, [
                                                'class' => 'form-control select2 ',
                                                'placeholder' => __('lang_v1.select_location'),
                                                'id' => 'pending_shipments_location',
                                            ]) !!}
                                        @endif
                                    </div>
                                </div>
                            </div>
                            <div class="tw-flow-root tw-mt-5  tw-border-gray-200">
                                <div class="tw--mx-4 tw--my-2 tw-overflow-x-auto sm:tw--mx-5">
                                    <div class="tw-inline-block tw-min-w-full tw-py-2 tw-align-middle sm:tw-px-5">
                                        <table class="table table-bordered table-striped ajax_view" id="shipments_table">
                                            <thead>
                                                <tr>
                                                    <th>@lang('messages.action')</th>
                                                    <th>@lang('messages.date')</th>
                                                    <th>@lang('sale.invoice_no')</th>
                                                    <th>@lang('sale.customer_name')</th>
                                                    <th>@lang('lang_v1.contact_no')</th>
                                                    <th>@lang('sale.location')</th>
                                                    <th>@lang('lang_v1.shipping_status')</th>
                                                    @if (!empty($custom_labels['shipping']['custom_field_1']))
                                                        <th>
                                                            {{ $custom_labels['shipping']['custom_field_1'] }}
                                                        </th>
                                                    @endif
                                                    @if (!empty($custom_labels['shipping']['custom_field_2']))
                                                        <th>
                                                            {{ $custom_labels['shipping']['custom_field_2'] }}
                                                        </th>
                                                    @endif
                                                    @if (!empty($custom_labels['shipping']['custom_field_3']))
                                                        <th>
                                                            {{ $custom_labels['shipping']['custom_field_3'] }}
                                                        </th>
                                                    @endif
                                                    @if (!empty($custom_labels['shipping']['custom_field_4']))
                                                        <th>
                                                            {{ $custom_labels['shipping']['custom_field_4'] }}
                                                        </th>
                                                    @endif
                                                    @if (!empty($custom_labels['shipping']['custom_field_5']))
                                                        <th>
                                                            {{ $custom_labels['shipping']['custom_field_5'] }}
                                                        </th>
                                                    @endif
                                                    <th>@lang('sale.payment_status')</th>
                                                    <th>@lang('restaurant.service_staff')</th>
                                                </tr>
                                            </thead>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif
                @if (auth()->user()->can('account.access') && config('constants.show_payments_recovered_today') == true)
                    <div
                        class="tw-transition-all lg:tw-col-span-2 tw-duration-200 tw-bg-white tw-shadow-sm tw-rounded-xl tw-ring-1 hover:tw-shadow-md hover:tw--translate-y-0.5 tw-ring-gray-200">
                        <div class="tw-p-4 sm:tw-p-5">
                            <div class="tw-flex tw-items-center tw-gap-2.5">
                                <div
                                    class="tw-border-2 tw-flex tw-items-center tw-justify-center tw-rounded-full tw-w-10 tw-h-10">
                                    <svg aria-hidden="true" class="tw-text-yellow-500 tw-size-5 tw-shrink-0"
                                        xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-width="2"
                                        stroke="currentColor" fill="none" stroke-linecap="round"
                                        stroke-linejoin="round">
                                        <path stroke="none" d="M0 0h24v24H0z" fill="none"></path>
                                        <path d="M12 9v4"></path>
                                        <path
                                            d="M10.363 3.591l-8.106 13.534a1.914 1.914 0 0 0 1.636 2.871h16.214a1.914 1.914 0 0 0 1.636 -2.87l-8.106 -13.536a1.914 1.914 0 0 0 -3.274 0z">
                                        </path>
                                        <path d="M12 16h.01"></path>
                                    </svg>
                                </div>
                                <div class="tw-flex tw-items-center tw-flex-1 tw-min-w-0 tw-gap-1">
                                    <div class="tw-w-full sm:tw-w-1/2 md:tw-w-1/2">
                                        <h3 class="tw-font-bold tw-text-base lg:tw-text-xl">
                                            @lang('lang_v1.payment_recovered_today')
                                        </h3>
                                    </div>

                                </div>
                            </div>
                            <div class="tw-flow-root tw-mt-5  tw-border-gray-200">
                                <div class="tw--mx-4 tw--my-2 tw-overflow-x-auto sm:tw--mx-5">
                                    <div class="tw-inline-block tw-min-w-full tw-py-2 tw-align-middle sm:tw-px-5">
                                        <table class="table table-bordered table-striped" id="cash_flow_table">
                                            <thead>
                                                <tr>
                                                    <th>@lang('messages.date')</th>
                                                    <th>@lang('account.account')</th>
                                                    <th>@lang('lang_v1.description')</th>
                                                    <th>@lang('lang_v1.payment_method')</th>
                                                    <th>@lang('lang_v1.payment_details')</th>
                                                    <th>@lang('account.credit')</th>
                                                    <th>@lang('lang_v1.account_balance')
                                                        @show_tooltip(__('lang_v1.account_balance_tooltip'))</th>
                                                    <th>@lang('lang_v1.total_balance')
                                                        @show_tooltip(__('lang_v1.total_balance_tooltip'))</th>
                                                </tr>
                                            </thead>
                                            <tfoot>
                                                <tr class="bg-gray font-17 footer-total text-center">
                                                    <td colspan="5"><strong>@lang('sale.total'):</strong></td>
                                                    <td class="footer_total_credit"></td>
                                                    <td colspan="2"></td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif
                {{-- @if (!empty($widgets['after_dashboard_reports']))
                    @foreach ($widgets['after_dashboard_reports'] as $widget)
                        {!! $widget !!}
                    @endforeach
                @endif --}}
            </div>
        </div>
    @endif

@endsection


<div class="modal fade payment_modal" tabindex="-1" role="dialog" aria-labelledby="gridSystemModalLabel">
</div>
<div class="modal fade edit_pso_status_modal" tabindex="-1" role="dialog"></div>
<div class="modal fade edit_payment_modal" tabindex="-1" role="dialog" aria-labelledby="gridSystemModalLabel">
</div>

@section('css')
    <style>
        .select2-container {
            width: 100% !important;
        }
    </style>
@endsection

@section('javascript')
    <script src="{{ asset('js/home.js?v=' . $asset_v) }}"></script>
    <script type="text/javascript">
        // Daily columns (last 30 days): same look as the babekhyber dashboard chart (rounded gradient columns, light grid)
        function dv_load_daily_chart() {
            if (typeof Highcharts === 'undefined' || !document.getElementById('dv_daily_chart')) {
                return;
            }
            $.getJSON('/home/dashboard-chart', { location_id: $('#dashboard_location').val() || '' }, function(d) {
                var labels = d.labels || [];
                Highcharts.chart('dv_daily_chart', {
                    chart: { type: 'column', backgroundColor: 'transparent', spacingTop: 4 },
                    credits: { enabled: false },
                    exporting: { enabled: false },
                    title: { text: null },
                    colors: [
                        { linearGradient: { x1: 0, y1: 0, x2: 0, y2: 1 }, stops: [[0, '#1fb5a6'], [1, '#1f7fa8']] },
                        { linearGradient: { x1: 0, y1: 0, x2: 0, y2: 1 }, stops: [[0, '#8fe08a'], [1, '#3fb56f']] },
                        { linearGradient: { x1: 0, y1: 0, x2: 0, y2: 1 }, stops: [[0, '#f7e27a'], [1, '#e9c34a']] }
                    ],
                    legend: {
                        align: 'right', verticalAlign: 'top', layout: 'horizontal',
                        itemStyle: { color: '#1f2d27', fontWeight: '600', fontSize: '12px' }, symbolRadius: 3
                    },
                    xAxis: {
                        categories: labels, crosshair: { color: 'rgba(47,158,110,.08)' },
                        lineColor: '#e3ece7', tickLength: 0,
                        labels: { style: { color: '#6b7a73', fontSize: '11px' }, rotation: -40,
                            step: labels.length > 20 ? 3 : (labels.length > 12 ? 2 : 1) }
                    },
                    yAxis: {
                        title: { text: null },
                        gridLineColor: '#eef3f0',
                        labels: {
                            style: { color: '#6b7a73', fontSize: '11px' },
                            formatter: function() {
                                var v = Math.abs(this.value);
                                return v >= 1e7 ? (this.value / 1e7) + ' Cr' : (v >= 1e5 ? (this.value / 1e5) + ' Lac' : (v >= 1e3 ? (this.value / 1e3) + 'k' : this.value));
                            }
                        }
                    },
                    tooltip: {
                        shared: true, useHTML: true, borderRadius: 10, borderColor: '#e3ece7', backgroundColor: '#ffffff',
                        headerFormat: '<div style="font-size:11px;color:#6b7a73;margin-bottom:4px">{point.key}</div>',
                        pointFormatter: function() {
                            return '<div><span style="color:' + (this.color.stops ? this.color.stops[1][1] : this.color) + '">&#9679;</span> ' +
                                this.series.name + ': <b>' + __currency_trans_from_en(this.y, true) + '</b></div>';
                        }
                    },
                    plotOptions: {
                        column: { pointPadding: 0.02, groupPadding: 0.08, borderWidth: 0, borderRadius: 3, minPointLength: 2 }
                    },
                    series: [
                        { name: @json(__('home.total_sell')), data: d.sell || [] },
                        { name: @json(__('home.total_purchase')), data: d.purchase || [] },
                        { name: @json(__('lang_v1.expense')), data: d.expense || [] }
                    ]
                });
            });
        }
        $(document).ready(function() {
            dv_load_daily_chart();
            $('#dashboard_location').on('change', dv_load_daily_chart);
        });

        // Financial Overview donut: uses the same /home/get-totals reply home.js already fetches, so it always matches
        // the cards and re-draws when the location / date filter changes.
        $(document).ajaxSuccess(function(event, xhr, settings) {
            if (!settings.url || settings.url.indexOf('/home/get-totals') === -1) {
                return;
            }
            var d = xhr.responseJSON || {};
            var sell = parseFloat(d.total_sell) || 0;
            var purchase = parseFloat(d.total_purchase) || 0;
            var expense = parseFloat(d.total_expense) || 0;
            var invoice_due = parseFloat(d.invoice_due) || 0;
            var net = parseFloat(d.net) || 0;
            var fmt = function(v) { return __currency_trans_from_en(v, true); };
            var compact = function(v) {
                var a = Math.abs(v), sign = v < 0 ? '-' : '';
                if (a >= 1e7) { return sign + (a / 1e7).toFixed(2) + ' Cr'; }
                if (a >= 1e5) { return sign + (a / 1e5).toFixed(2) + ' Lac'; }
                if (a >= 1e3) { return sign + (a / 1e3).toFixed(1) + 'k'; }
                return sign + a.toFixed(0);
            };

            $('#dv_leg_sell').html(fmt(sell));
            $('#dv_leg_purchase').html(fmt(purchase));
            $('#dv_leg_expense').html(fmt(expense));
            $('#dv_leg_invoice_due').html(fmt(invoice_due));
            $('#dv_leg_net').html(fmt(net));

            if (typeof Highcharts === 'undefined' || !document.getElementById('dv_fin_donut')) {
                return;
            }
            Highcharts.chart('dv_fin_donut', {
                chart: { type: 'pie', backgroundColor: 'transparent', margin: [0, 0, 0, 0], spacing: [0, 0, 0, 0] },
                title: {
                    text: '<div style="text-align:center;line-height:1.2"><span style="font-size:10px;color:#6b7a73">{{ __('lang_v1.net') }}</span><br><b style="font-size:14px;color:#1f2d27">' + compact(net) + '</b></div>',
                    useHTML: true, verticalAlign: 'middle', floating: true, y: 6
                },
                credits: { enabled: false },
                exporting: { enabled: false },
                tooltip: { pointFormatter: function() { return '<b>' + fmt(this.y) + '</b>'; } },
                plotOptions: { pie: { innerSize: '72%', borderWidth: 2, dataLabels: { enabled: false } } },
                series: [{
                    name: 'Amount',
                    data: [
                        { name: @json(__('home.total_sell')), y: sell, color: '#3b82c4' },
                        { name: @json(__('home.total_purchase')), y: purchase, color: '#1f9fa8' },
                        { name: @json(__('lang_v1.expense')), y: expense, color: '#8a63c9' },
                        { name: @json(__('home.invoice_due')), y: invoice_due, color: '#e58a2b' }
                    ]
                }]
            });
        });
    </script>
    <script src="{{ asset('js/payment.js?v=' . $asset_v) }}"></script>
    @includeIf('sales_order.common_js')
    @includeIf('purchase_order.common_js')
    @if (!empty($all_locations))
        {!! $sells_chart_1->script() !!}
        {!! $sells_chart_2->script() !!}
    @endif
    <script type="text/javascript">
        $(document).ready(function() {
            sales_order_table = $('#sales_order_table').DataTable({
                processing: true,
                serverSide: true,
                fixedHeader:false,
                scrollY: "75vh",
                scrollX: true,
                scrollCollapse: true,
                aaSorting: [
                    [1, 'desc']
                ],
                "ajax": {
                    "url": '{{ action([\App\Http\Controllers\SellController::class, 'index']) }}?sale_type=sales_order',
                    "data": function(d) {
                        d.for_dashboard_sales_order = true;

                        if ($('#so_location').length > 0) {
                            d.location_id = $('#so_location').val();
                        }
                    }
                },
                columnDefs: [{
                    "targets": 7,
                    "orderable": false,
                    "searchable": false
                }],
                columns: [{
                        data: 'action',
                        name: 'action'
                    },
                    {
                        data: 'transaction_date',
                        name: 'transaction_date'
                    },
                    {
                        data: 'invoice_no',
                        name: 'invoice_no'
                    },
                    {
                        data: 'conatct_name',
                        name: 'conatct_name'
                    },
                    {
                        data: 'mobile',
                        name: 'contacts.mobile'
                    },
                    {
                        data: 'business_location',
                        name: 'bl.name'
                    },
                    {
                        data: 'status',
                        name: 'status'
                    },
                    {
                        data: 'shipping_status',
                        name: 'shipping_status'
                    },
                    {
                        data: 'so_qty_remaining',
                        name: 'so_qty_remaining',
                        "searchable": false
                    },
                    {
                        data: 'added_by',
                        name: 'u.first_name'
                    },
                ]
            });

            @if (auth()->user()->can('account.access') && config('constants.show_payments_recovered_today') == true)

                // Cash Flow Table
                cash_flow_table = $('#cash_flow_table').DataTable({
                    processing: true,
                    serverSide: true,
                    fixedHeader:false,
                    "ajax": {
                        "url": "{{ action([\App\Http\Controllers\AccountController::class, 'cashFlow']) }}",
                        "data": function(d) {
                            d.type = 'credit';
                            d.only_payment_recovered = true;
                        }
                    },
                    "ordering": false,
                    "searching": false,
                    columns: [{
                            data: 'operation_date',
                            name: 'operation_date'
                        },
                        {
                            data: 'account_name',
                            name: 'account_name'
                        },
                        {
                            data: 'sub_type',
                            name: 'sub_type'
                        },
                        {
                            data: 'method',
                            name: 'TP.method'
                        },
                        {
                            data: 'payment_details',
                            name: 'payment_details',
                            searchable: false
                        },
                        {
                            data: 'credit',
                            name: 'amount'
                        },
                        {
                            data: 'balance',
                            name: 'balance'
                        },
                        {
                            data: 'total_balance',
                            name: 'total_balance'
                        },
                    ],
                    "fnDrawCallback": function(oSettings) {
                        __currency_convert_recursively($('#cash_flow_table'));
                    },
                    "footerCallback": function(row, data, start, end, display) {
                        var footer_total_credit = 0;

                        for (var r in data) {
                            footer_total_credit += $(data[r].credit).data('orig-value') ? parseFloat($(
                                data[r].credit).data('orig-value')) : 0;
                        }
                        $('.footer_total_credit').html(__currency_trans_from_en(footer_total_credit));
                    }
                });
            @endif

            $('#so_location').change(function() {
                sales_order_table.ajax.reload();
            });
            @if (!empty($common_settings['enable_purchase_order']))
                //Purchase table
                purchase_order_table = $('#purchase_order_table').DataTable({
                    processing: true,
                    serverSide: true,
                    fixedHeader:false,
                    aaSorting: [
                        [1, 'desc']
                    ],
                    scrollY: "75vh",
                    scrollX: true,
                    scrollCollapse: true,
                    ajax: {
                        url: '{{ action([\App\Http\Controllers\PurchaseOrderController::class, 'index']) }}',
                        data: function(d) {
                            d.from_dashboard = true;

                            if ($('#po_location').length > 0) {
                                d.location_id = $('#po_location').val();
                            }
                        },
                    },
                    columns: [{
                            data: 'action',
                            name: 'action',
                            orderable: false,
                            searchable: false
                        },
                        {
                            data: 'transaction_date',
                            name: 'transaction_date'
                        },
                        {
                            data: 'ref_no',
                            name: 'ref_no'
                        },
                        {
                            data: 'location_name',
                            name: 'BS.name'
                        },
                        {
                            data: 'name',
                            name: 'contacts.name'
                        },
                        {
                            data: 'status',
                            name: 'transactions.status'
                        },
                        {
                            data: 'po_qty_remaining',
                            name: 'po_qty_remaining',
                            "searchable": false
                        },
                        {
                            data: 'added_by',
                            name: 'u.first_name'
                        }
                    ]
                })

                $('#po_location').change(function() {
                    purchase_order_table.ajax.reload();
                });
            @endif

            @if (!empty($common_settings['enable_purchase_requisition']))
                //Purchase table
                purchase_requisition_table = $('#purchase_requisition_table').DataTable({
                    processing: true,
                    serverSide: true,
                    fixedHeader:false,
                    aaSorting: [
                        [1, 'desc']
                    ],
                    scrollY: "75vh",
                    scrollX: true,
                    scrollCollapse: true,
                    ajax: {
                        url: '{{ action([\App\Http\Controllers\PurchaseRequisitionController::class, 'index']) }}',
                        data: function(d) {
                            d.from_dashboard = true;

                            if ($('#pr_location').length > 0) {
                                d.location_id = $('#pr_location').val();
                            }
                        },
                    },
                    columns: [{
                            data: 'action',
                            name: 'action',
                            orderable: false,
                            searchable: false
                        },
                        {
                            data: 'transaction_date',
                            name: 'transaction_date'
                        },
                        {
                            data: 'ref_no',
                            name: 'ref_no'
                        },
                        {
                            data: 'location_name',
                            name: 'BS.name'
                        },
                        {
                            data: 'status',
                            name: 'status'
                        },
                        {
                            data: 'delivery_date',
                            name: 'delivery_date'
                        },
                        {
                            data: 'added_by',
                            name: 'u.first_name'
                        },
                    ]
                })

                $('#pr_location').change(function() {
                    purchase_requisition_table.ajax.reload();
                });

                $(document).on('click', 'a.delete-purchase-requisition', function(e) {
                    e.preventDefault();
                    swal({
                        title: LANG.sure,
                        icon: 'warning',
                        buttons: true,
                        dangerMode: true,
                    }).then(willDelete => {
                        if (willDelete) {
                            var href = $(this).attr('href');
                            $.ajax({
                                method: 'DELETE',
                                url: href,
                                dataType: 'json',
                                success: function(result) {
                                    if (result.success == true) {
                                        toastr.success(result.msg);
                                        purchase_requisition_table.ajax.reload();
                                    } else {
                                        toastr.error(result.msg);
                                    }
                                },
                            });
                        }
                    });
                });
            @endif

            sell_table = $('#shipments_table').DataTable({
                processing: true,
                serverSide: true,
                fixedHeader:false,
                aaSorting: [
                    [1, 'desc']
                ],
                scrollY: "75vh",
                scrollX: true,
                scrollCollapse: true,
                "ajax": {
                    "url": '{{ action([\App\Http\Controllers\SellController::class, 'index']) }}',
                    "data": function(d) {
                        d.only_pending_shipments = true;
                        if ($('#pending_shipments_location').length > 0) {
                            d.location_id = $('#pending_shipments_location').val();
                        }
                    }
                },
                columns: [{
                        data: 'action',
                        name: 'action',
                        searchable: false,
                        orderable: false
                    },
                    {
                        data: 'transaction_date',
                        name: 'transaction_date'
                    },
                    {
                        data: 'invoice_no',
                        name: 'invoice_no'
                    },
                    {
                        data: 'conatct_name',
                        name: 'conatct_name'
                    },
                    {
                        data: 'mobile',
                        name: 'contacts.mobile'
                    },
                    {
                        data: 'business_location',
                        name: 'bl.name'
                    },
                    {
                        data: 'shipping_status',
                        name: 'shipping_status'
                    },
                    @if (!empty($custom_labels['shipping']['custom_field_1']))
                        {
                            data: 'shipping_custom_field_1',
                            name: 'shipping_custom_field_1'
                        },
                    @endif
                    @if (!empty($custom_labels['shipping']['custom_field_2']))
                        {
                            data: 'shipping_custom_field_2',
                            name: 'shipping_custom_field_2'
                        },
                    @endif
                    @if (!empty($custom_labels['shipping']['custom_field_3']))
                        {
                            data: 'shipping_custom_field_3',
                            name: 'shipping_custom_field_3'
                        },
                    @endif
                    @if (!empty($custom_labels['shipping']['custom_field_4']))
                        {
                            data: 'shipping_custom_field_4',
                            name: 'shipping_custom_field_4'
                        },
                    @endif
                    @if (!empty($custom_labels['shipping']['custom_field_5']))
                        {
                            data: 'shipping_custom_field_5',
                            name: 'shipping_custom_field_5'
                        },
                    @endif {
                        data: 'payment_status',
                        name: 'payment_status'
                    },
                    {
                        data: 'waiter',
                        name: 'ss.first_name',
                        @if (empty($is_service_staff_enabled))
                            visible: false
                        @endif
                    }
                ],
                "fnDrawCallback": function(oSettings) {
                    __currency_convert_recursively($('#sell_table'));
                },
                createdRow: function(row, data, dataIndex) {
                    $(row).find('td:eq(4)').attr('class', 'clickable_td');
                }
            });

            $('#pending_shipments_location').change(function() {
                sell_table.ajax.reload();
            });
        });
    </script>
    
@endsection
