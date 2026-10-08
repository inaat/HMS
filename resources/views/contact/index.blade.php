@extends('layouts.app')
@section('title', __('lang_v1.' . $type . 's'))
@php
    $api_key = env('GOOGLE_MAP_API_KEY');
@endphp
@if (!empty($api_key))
    @section('css')
        @include('contact.partials.google_map_styles')
    @endsection
@endif
@section('content')
    <!-- Content Header (Page header) -->
    <section class="content-header">
        <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black"> @lang('lang_v1.' . $type . 's')
            <small class="tw-text-sm md:tw-text-base tw-text-gray-700 tw-font-semibold">@lang('contact.manage_your_contact', ['contacts' => __('lang_v1.' . $type . 's')])</small>
        </h1>
    </section>

    <!-- Main content -->
    <section class="content">
        @component('components.filters', ['title' => __('report.filters')])
            @if ($type == 'customer')
                <div class="col-md-3">
                    <div class="form-group">
                        <label>
                            {!! Form::checkbox('has_sell_due', 1, false, ['class' => 'input-icheck', 'id' => 'has_sell_due']) !!} <strong>@lang('lang_v1.sell_due')</strong>
                        </label>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label>
                            {!! Form::checkbox('has_sell_return', 1, false, ['class' => 'input-icheck', 'id' => 'has_sell_return']) !!} <strong>@lang('lang_v1.sell_return')</strong>
                        </label>
                    </div>
                </div>
            @elseif($type == 'supplier')
                <div class="col-md-3">
                    <div class="form-group">
                        <label>
                            {!! Form::checkbox('has_purchase_due', 1, false, ['class' => 'input-icheck', 'id' => 'has_purchase_due']) !!} <strong>@lang('report.purchase_due')</strong>
                        </label>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label>
                            {!! Form::checkbox('has_purchase_return', 1, false, ['class' => 'input-icheck', 'id' => 'has_purchase_return']) !!} <strong>@lang('lang_v1.purchase_return')</strong>
                        </label>
                    </div>
                </div>
            @endif
            <div class="col-md-3">
                <div class="form-group">
                    <label>
                        {!! Form::checkbox('has_advance_balance', 1, false, ['class' => 'input-icheck', 'id' => 'has_advance_balance']) !!} <strong>@lang('lang_v1.advance_balance')</strong>
                    </label>
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group">
                    <label>
                        {!! Form::checkbox('has_opening_balance', 1, false, ['class' => 'input-icheck', 'id' => 'has_opening_balance']) !!} <strong>@lang('lang_v1.opening_balance')</strong>
                    </label>
                </div>
            </div>
            @if ($type == 'customer')
                <div class="col-md-3">
                    <div class="form-group">
                        <label for="has_no_sell_from">@lang('lang_v1.has_no_sell_from'):</label>
                        {!! Form::select(
                            'has_no_sell_from',
                            [
                                'one_month' => __('lang_v1.one_month'),
                                'three_months' => __('lang_v1.three_months'),
                                'six_months' => __('lang_v1.six_months'),
                                'one_year' => __('lang_v1.one_year'),
                            ],
                            null,
                            ['class' => 'form-control', 'id' => 'has_no_sell_from', 'placeholder' => __('messages.please_select')],
                        ) !!}
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="form-group">
                        <label for="cg_filter">@lang('lang_v1.customer_group'):</label>
                        {!! Form::select('cg_filter', $customer_groups, null, ['class' => 'form-control', 'id' => 'cg_filter']) !!}
                    </div>
                </div>
            @endif

            @if (config('constants.enable_contact_assign') === true)
                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('assigned_to', __('lang_v1.assigned_to') . ':') !!}
                        {!! Form::select('assigned_to', $users, null, ['class' => 'form-control select2', 'style' => 'width:100%']) !!}
                    </div>
                </div>
            @endif

            <div class="col-md-3">
                <div class="form-group">
                    <label for="status_filter">@lang('sale.status'):</label>
                    {!! Form::select(
                        'status_filter',
                        ['active' => __('business.is_active'), 'inactive' => __('lang_v1.inactive')],
                        null,
                        ['class' => 'form-control', 'id' => 'status_filter', 'placeholder' => __('lang_v1.none')],
                    ) !!}
                </div>
            </div>
        @endcomponent
        <input type="hidden" value="{{ $type }}" id="contact_type">
        @component('components.widget', [
            'class' => 'box-primary',
            'title' => __('contact.all_your_contact', ['contacts' => __('lang_v1.' . $type . 's')]),
        ])
            @if (auth()->user()->can('supplier.create') ||
                    auth()->user()->can('customer.create') ||
                    auth()->user()->can('supplier.view_own') ||
                    auth()->user()->can('customer.view_own'))
                @slot('tool')
                    <div class="box-tools">
                        <a class="tw-dw-btn tw-bg-gradient-to-r tw-from-indigo-600 tw-to-blue-500 tw-font-bold tw-text-white tw-border-none tw-rounded-full btn-modal"
                                data-href="{{ action([\App\Http\Controllers\ContactController::class, 'create'], ['type' => $type]) }}"
                                data-container=".contact_modal">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                class="icon icon-tabler icons-tabler-outline icon-tabler-plus">
                                <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                <path d="M12 5l0 14" />
                                <path d="M5 12l14 0" />
                                </svg> @lang('messages.add')
                        </a>
                    </div>
                @endslot
            @endif
            @if (auth()->user()->can('supplier.view') ||
                    auth()->user()->can('customer.view') ||
                    auth()->user()->can('supplier.view_own') ||
                    auth()->user()->can('customer.view_own'))
                <div class="table-responsive">
                    <table class="table table-bordered table-striped" id="contact_table">
                        <thead>
                            <tr>
                                <th style="width: 80px;">@lang('messages.action')</th>
                                <th>@lang('lang_v1.contact_id')</th>
                                @if ($type == 'supplier')
                                    {{-- Same layout as customers: name, mobile, dues, address first (app.js follows) --}}
                                    <th>@lang('business.business_name')</th>
                                    <th>@lang('contact.name')</th>
                                    <th>@lang('contact.mobile')</th>
                                    <th>@lang('contact.total_purchase_due')</th>
                                    <th>@lang('lang_v1.total_purchase_return_due')</th>
                                    <th>@lang('business.address')</th>
                                    <th>@lang('business.email')</th>
                                    <th>@lang('contact.tax_no')</th>
                                    <th>@lang('contact.pay_term')</th>
                                    <th>@lang('account.opening_balance')</th>
                                    <th>@lang('lang_v1.advance_balance')</th>
                                    <th>@lang('lang_v1.added_on')</th>
                                @elseif($type == 'customer')
                                    {{-- Order: who, how to reach, what they owe; then the rest (app.js columns follow) --}}
                                    <th>@lang('business.business_name')</th>
                                    <th>@lang('user.name')</th>
                                    <th>@lang('contact.mobile')</th>
                                    <th>@lang('contact.total_sale_due')</th>
                                    <th>@lang('lang_v1.total_sell_return_due')</th>
                                    <th>@lang('business.address')</th>
                                    <th>@lang('business.email')</th>
                                    <th>@lang('contact.tax_no')</th>
                                    <th>@lang('lang_v1.credit_limit')</th>
                                    <th>@lang('contact.pay_term')</th>
                                    <th>@lang('account.opening_balance')</th>
                                    <th>@lang('lang_v1.advance_balance')</th>
                                    <th>@lang('lang_v1.added_on')</th>
                                    @if ($reward_enabled)
                                        <th id="rp_col">{{ session('business.rp_name') }}</th>
                                    @endif
                                    <th>@lang('lang_v1.customer_group')</th>
                                @endif
                                @php
                                    $custom_labels = json_decode(session('business.custom_labels'), true);
                                    // Only custom fields given a name in Settings > Business Settings > Custom Labels
                                    $shown_custom_fields = array_values(array_filter(range(1, 10), function ($n) use ($custom_labels) {
                                        return ! empty($custom_labels['contact']['custom_field_'.$n]);
                                    }));
                                @endphp
                                @foreach ($shown_custom_fields as $n)
                                    <th data-cf="{{ $n }}">{{ $custom_labels['contact']['custom_field_'.$n] }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tfoot>
                            {{-- Footer cells line up with the header above (custom fields: only the named ones) --}}
                            @php
                                $after_dues = $type == 'customer' ? 9 + ($reward_enabled ? 1 : 0) : 7;
                                $after_dues += count($shown_custom_fields);
                            @endphp
                            <tr class="bg-gray font-17 text-center footer-total">
                                <td colspan="5"><strong>@lang('sale.total'):</strong></td>
                                <td class="footer_contact_due"></td>
                                <td class="footer_contact_return_due"></td>
                                @if ($after_dues > 0)
                                    <td colspan="{{ $after_dues }}"></td>
                                @endif
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        @endcomponent

        <div class="modal fade contact_modal" tabindex="-1" role="dialog" aria-labelledby="gridSystemModalLabel">
        </div>
        <div class="modal fade pay_contact_due_modal" tabindex="-1" role="dialog" aria-labelledby="gridSystemModalLabel">
        </div>

    </section>
    <!-- /.content -->
@stop
@section('javascript')
    @if (!empty($api_key))
        <script>
            // This example adds a search box to a map, using the Google Place Autocomplete
            // feature. People can enter geographical searches. The search box will return a
            // pick list containing a mix of places and predicted search terms.

            // This example requires the Places library. Include the libraries=places
            // parameter when you first load the API. For example:
            // <script src="https://maps.googleapis.com/maps/api/js?key=YOUR_API_KEY&libraries=places">

            function initAutocomplete() {
                var map = new google.maps.Map(document.getElementById('map'), {
                    center: {
                        lat: 34.8,
                        lng: 71.9
                    },
                    zoom: 10,
                    mapTypeId: 'roadmap'
                });

                // Shop location pin: open on the saved location; click the map or drag the pin to set it
                // (stored in the hidden "position" field as "lat,lng", used by the booker app and routes).
                var pin = null;
                function setPin(latLng, pan) {
                    if (!pin) {
                        pin = new google.maps.Marker({map: map, position: latLng, draggable: true, title: 'Shop location'});
                        pin.addListener('dragend', function (e) { setPin(e.latLng); });
                    }
                    pin.setPosition(latLng);
                    $('#position').val(latLng.lat().toFixed(7) + ',' + latLng.lng().toFixed(7));
                    if (pan) { map.setCenter(latLng); map.setZoom(17); }
                }
                map.addListener('click', function (e) { setPin(e.latLng); });

                var saved = String($('#position').val() || '').split(',');
                if (saved.length === 2 && !isNaN(parseFloat(saved[0])) && !isNaN(parseFloat(saved[1]))) {
                    setPin(new google.maps.LatLng(parseFloat(saved[0]), parseFloat(saved[1])), true);
                } else if (navigator.geolocation) {
                    navigator.geolocation.getCurrentPosition(function(position) {
                        initialLocation = new google.maps.LatLng(position.coords.latitude, position.coords.longitude);
                        map.setCenter(initialLocation);
                    });
                }


                // Create the search box and link it to the UI element.
                var input = document.getElementById('shipping_address');
                var searchBox = new google.maps.places.SearchBox(input);
                map.controls[google.maps.ControlPosition.TOP_LEFT].push(input);

                // Bias the SearchBox results towards current map's viewport.
                map.addListener('bounds_changed', function() {
                    searchBox.setBounds(map.getBounds());
                });

                var markers = [];
                // Listen for the event fired when the user selects a prediction and retrieve
                // more details for that place.
                searchBox.addListener('places_changed', function() {
                    var places = searchBox.getPlaces();

                    if (places.length == 0) {
                        return;
                    }

                    // Clear out the old markers.
                    markers.forEach(function(marker) {
                        marker.setMap(null);
                    });
                    markers = [];

                    // For each place, get the icon, name and location.
                    var bounds = new google.maps.LatLngBounds();
                    places.forEach(function(place) {
                        if (!place.geometry) {
                            console.log("Returned place contains no geometry");
                            return;
                        }
                        // Move the (draggable) shop pin there and set the position field
                        setPin(place.geometry.location);

                        if (place.geometry.viewport) {
                            // Only geocodes have viewport.
                            bounds.union(place.geometry.viewport);
                        } else {
                            bounds.extend(place.geometry.location);
                        }
                    });
                    map.fitBounds(bounds);
                });
            }
        </script>
        <script src="https://maps.googleapis.com/maps/api/js?key={{ $api_key }}&libraries=places" async defer></script>
        <script type="text/javascript">
            $(document).on('shown.bs.modal', '.contact_modal', function(e) {
                initAutocomplete();
            });
        </script>
    @endif
@endsection
