<script type="text/javascript">
    base_path = "{{ url('/') }}";
    //used for push notification
    APP = {};
    APP.PUSHER_APP_KEY = '{{ config('broadcasting.connections.pusher.key') }}';
    APP.PUSHER_APP_CLUSTER = '{{ config('broadcasting.connections.pusher.options.cluster') }}';
    APP.INVOICE_SCHEME_SEPARATOR = '{{ config('constants.invoice_scheme_separator') }}';
    //variable from app service provider
    APP.PUSHER_ENABLED = '{{ $__is_pusher_enabled }}';
    @auth
    @php
        $user = Auth::user();
    @endphp
    APP.USER_ID = "{{ $user->id }}";
    @else
        APP.USER_ID = '';
    @endauth
</script>

<!--[if lt IE 9]>
<script src="https://oss.maxcdn.com/html5shiv/3.7.3/html5shiv.min.js?v=$asset_v"></script>
<script src="https://oss.maxcdn.com/respond/1.4.2/respond.min.js?v=$asset_v"></script>
<![endif]-->

<script src="{{ asset('js/vendor.js?v=' . $asset_v) }}"></script>

@if (file_exists(public_path('js/lang/' . session()->get('user.language', config('app.locale')) . '.js')))
    <script src="{{ asset('js/lang/' . session()->get('user.language', config('app.locale')) . '.js?v=' . $asset_v) }}">
    </script>
@else
    <script src="{{ asset('js/lang/en.js?v=' . $asset_v) }}"></script>
@endif
@php
    $business_date_format = session('business.date_format', config('constants.default_date_format'));
    $datepicker_date_format = str_replace('d', 'dd', $business_date_format);
    $datepicker_date_format = str_replace('m', 'mm', $datepicker_date_format);
    $datepicker_date_format = str_replace('Y', 'yyyy', $datepicker_date_format);

    $moment_date_format = str_replace('d', 'DD', $business_date_format);
    $moment_date_format = str_replace('m', 'MM', $moment_date_format);
    $moment_date_format = str_replace('Y', 'YYYY', $moment_date_format);

    $business_time_format = session('business.time_format');
    $moment_time_format = 'HH:mm';
    if ($business_time_format == 12) {
        $moment_time_format = 'hh:mm A';
    }

    $common_settings = !empty(session('business.common_settings')) ? session('business.common_settings') : [];

    $default_datatable_page_entries = !empty($common_settings['default_datatable_page_entries'])
        ? $common_settings['default_datatable_page_entries']
        : 25;
@endphp

<script>
    Dropzone.autoDiscover = false;
    moment.tz.setDefault('{{ Session::get('business.time_zone') }}');
    $(document).ready(function() {
        $.ajaxSetup({
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            }
        });

        @if (config('app.debug') == false)
            $.fn.dataTable.ext.errMode = 'throw';
        @endif
    });

    var financial_year = {
        start: moment('{{ Session::get('financial_year.start') }}'),
        end: moment('{{ Session::get('financial_year.end') }}'),
    }
    @if (file_exists(public_path('AdminLTE/plugins/select2/lang/' . session()->get('user.language', config('app.locale')) . '.js')))
        //Default setting for select2
        $.fn.select2.defaults.set("language", "{{ session()->get('user.language', config('app.locale')) }}");
    @endif

    var datepicker_date_format = "{{ $datepicker_date_format }}";
    var moment_date_format = "{{ $moment_date_format }}";
    var moment_time_format = "{{ $moment_time_format }}";

    var app_locale = "{{ session()->get('user.language', config('app.locale')) }}";

    var non_utf8_languages = [
        @foreach (config('constants.non_utf8_languages') as $const)
            "{{ $const }}",
        @endforeach
    ];

    var __default_datatable_page_entries = "{{ $default_datatable_page_entries }}";

    var __new_notification_count_interval = "{{ config('constants.new_notification_count_interval', 60) }}000";
</script>

@if (file_exists(public_path('js/lang/' . session()->get('user.language', config('app.locale')) . '.js')))
    <script src="{{ asset('js/lang/' . session()->get('user.language', config('app.locale')) . '.js?v=' . $asset_v) }}">
    </script>
@else
    <script src="{{ asset('js/lang/en.js?v=' . $asset_v) }}"></script>
@endif

<script src="{{ asset('js/functions.js?v=' . $asset_v) }}"></script>
<script src="{{ asset('js/common.js?v=' . $asset_v) }}"></script>
<script src="{{ asset('js/app.js?v=' . $asset_v) }}"></script>
<script src="{{ asset('js/help-tour.js?v=' . $asset_v) }}"></script>
<script src="{{ asset('js/documents_and_note.js?v=' . $asset_v) }}"></script>

<!-- TODO -->
@if (file_exists(public_path('AdminLTE/plugins/select2/lang/' . session()->get('user.language', config('app.locale')) . '.js')))
    <script
        src="{{ asset('AdminLTE/plugins/select2/lang/' . session()->get('user.language', config('app.locale')) . '.js?v=' . $asset_v) }}">
    </script>
@endif
@php
    $validation_lang_file = 'messages_' . session()->get('user.language', config('app.locale')) . '.js';
@endphp
@if (file_exists(public_path() . '/js/jquery-validation-1.16.0/src/localization/' . $validation_lang_file))
    <script src="{{ asset('js/jquery-validation-1.16.0/src/localization/' . $validation_lang_file . '?v=' . $asset_v) }}">
    </script>
@endif

@if (!empty($__system_settings['additional_js']))
    {!! $__system_settings['additional_js'] !!}
@endif
@yield('javascript')

@if (Module::has('Essentials'))
    @includeIf('essentials::layouts.partials.footer_part')
@endif

<script type="text/javascript">
    $(document).ready(function() {
        var locale = "{{ session()->get('user.language', config('app.locale')) }}";
        var isRTL =
            @if (in_array(session()->get('user.language', config('app.locale')), config('constants.langs_rtl')))
                true;
            @else
                false;
            @endif

        $('#calendar').fullCalendar('option', {
            locale: locale,
            isRTL: isRTL
        });
        // side bar toggle  
        $(".drop_down").click(function(event) {
            event.preventDefault();
            var $chiled = $(this).next(".chiled");
            var svgElement = $(this).find(".svg");
            $(".chiled").not($chiled).slideUp();
            $chiled.slideToggle(function() {
                $(".svg").each(function() {
                    var $currentSvgElement = $(this);
                    if ($currentSvgElement.closest(".drop_down").next(".chiled").is(
                            ":visible")) {
                        // If the corresponding menu is visible, set the arrow pointing upwards
                        $currentSvgElement.html(
                            '<path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M6 9l6 6l6 -6" />'
                        );
                    } else {
                        // Otherwise, set the arrow pointing downwards
                        $currentSvgElement.html(
                            '<path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M15 6l-6 6l6 6" />'
                        );
                    }
                });
            });
        });

        $('.small-view-button').on('click', function() {
            $('.side-bar').addClass('small-view-side-active');
            $('.overlay').fadeIn('slow');
        });

        $('.overlay').on('click', function() {
            $('.overlay').fadeOut('slow');
            $('.side-bar').removeClass('small-view-side-active');
        });

        $(window).on('resize', function() {
            if ($(window).width() >= 992) {
                $('.overlay').fadeOut('slow');
                $('.side-bar').removeClass('small-view-side-active');
            }

            if ($('.side-bar').hasClass('small-view-side-active')) {
                $('.overlay').fadeIn('slow');
            }
        });

        $(document).on('click', function(e) {
            $('[data-toggle="popover"]').popover();

            $(document).on('click', function(e) {
                $('[data-toggle="popover"]').each(function() {
                    // Check if the clicked element is the popover button or inside the popover
                    if (!$(this).is(e.target) && $(this).has(e.target).length === 0 &&
                        $('.popover').has(e.target).length === 0) {
                        $(this).popover('hide');
                    }
                });
            });

        });

        $('.side-bar-collapse').click(function() {
            $('.side-bar').toggle('slow');
        });

        $('.dt-buttons.btn-group').find('a.btn').removeClass('btn-default');
        $('.dt-buttons.btn-group').find('a.btn').removeClass('btn');

        // $('.date_range').on('show.daterangepicker', function (ev, picker) {
        //     $(picker.container).insertAfter($(this));
        // });
        $(document).keydown(function(event) {
    // Check for Alt + N key combination
    if (event.altKey && event.key === 'o') {
        event.preventDefault(); // Prevent default action if necessary

        //alert('Alt + o Pressed'); // Display alert when Alt + N is pressed

        $.ajax({
            url: '/shortcutPayModal?type=purchase',
            dataType: 'html',
            success: function(result) {
                // Update the modal content and show the modal
                $('.view_modal')
                    .html(result)
                    .modal('show');
            },
        });
    }
});
        $(document).keydown(function(event) {
    // Check for Alt + N key combination
    if (event.altKey && event.key === 'n') {
        event.preventDefault(); // Prevent default action if necessary

      //  alert('Alt + N Pressed'); // Display alert when Alt + N is pressed

        $.ajax({
            url: '/shortcutPayModal?type=sell',
            dataType: 'html',
            success: function(result) {
                // Update the modal content and show the modal
                $('.view_modal')
                    .html(result)
                    .modal('show');
            },
        });
    }
});
        $(document).keydown(function(event) {
            
            // Check if 'Alt' key and 'J' key are pressed simultaneously
            if (event.altKey && event.key === 'j') {
                event.preventDefault(); // Prevent the default action if necessary

              //  alert('Alt + J')
                $.ajax({
                    url: '/party-to-party',
                    dataType: 'html',
                    success: function(result) {
                        $('.view_modal')
                            .html(result)
                            .modal('show');

                        // __currency_convert_recursively($('.view_modal'));
                        $('#paid_on').datetimepicker({
                            format: moment_date_format + ' ' + moment_time_format,
                            ignoreReadonly: true,
                        });
                        // $('.view_modal')
                        //     .find('form#pay_contact_due_form')
                        //     .validate();
                    },
                });
            }
        });
        // Handle change event forgetpay_customer_id dropdown in the modal
        $('.view_modal').on('change', '.getpay_customer_id', function() {
    var id = $(this).val();  // Capture the selected value from the dropdown
    if (id) {
        $('div.view_modal').modal('hide');
        $.ajax({
                    url: '/payments/pay-contact-due/'+id+'?type=sell',
                    dataType: 'html',
                    success: function(result) {
                        $('.view_modal')
                            .html(result)
                            .modal('show');

                        // __currency_convert_recursively($('.view_modal'));
                        $('#paid_on').datetimepicker({
                            format: moment_date_format + ' ' + moment_time_format,
                            ignoreReadonly: true,
                        });
                        $('.view_modal')
                            .find('form#pay_contact_due_form')
                            .validate();
                    },
                });
    }
});
        // Handle change event forgetpay_supplier_id dropdown in the modal
        $('.view_modal').on('change', '.getpay_supplier_id', function() {
    var id = $(this).val();  // Capture the selected value from the dropdown
    if (id) {
        $('div.view_modal').modal('hide');
        $.ajax({
                    url: '/payments/pay-contact-due/'+id+'?type=purchase',
                    dataType: 'html',
                    success: function(result) {
                        $('.view_modal')
                            .html(result)
                            .modal('show');

                        // __currency_convert_recursively($('.view_modal'));
                        $('#paid_on').datetimepicker({
                            format: moment_date_format + ' ' + moment_time_format,
                            ignoreReadonly: true,
                        });
                        $('.view_modal')
                            .find('form#pay_contact_due_form')
                            .validate();
                    },
                });
    }
});
        // Handle change event for party_supplier_id dropdown in the modal
        $('.view_modal').on('change', '.party_supplier_id', function() {
    var id = $(this).val();  // Capture the selected value from the dropdown
    if (id) {
        $.ajax({
            method: 'get',
            url: '/get-contact-due/' + id,  // Fix: Concatenate the ID correctly with the URL
            dataType: 'text',
            success: function(result) {
                var $dueText = $('.view_modal .party_supplier_due_text');
                
                if (result.trim() !== '') {  // Check if result is not empty
                    $dueText.find('span').text(result);  // Update the span with the result
                    $dueText.removeClass('hide');  // Make the text visible
                } else {
                    $dueText.find('span').text('');  // Clear the span content
                    $dueText.addClass('hide');  // Hide the text
                }
            },
            error: function(xhr, status, error) {
                console.error("Error fetching contact due: " + status + " - " + error);
            }
        });
    }
});
// Listen for input in the '.party_payment_amount_received' field inside '.view_modal'
$('.view_modal').on('input', '.party_payment_amount_received', function() {
    var receivedAmount = $(this).val();  // Get the current value of the "Received" field inside the modal

    // Update the "Paid" field with the same value as "Received" inside the modal
    $('.view_modal .party_payment_amount_paid').val(receivedAmount);
});
$('.view_modal').on('input', '.party_payment_amount_paid', function() {
    var receivedAmount = $(this).val();  // Get the current value of the "Received" field inside the modal

    // Update the "Paid" field with the same value as "Received" inside the modal
    $('.view_modal .party_payment_amount_received').val(receivedAmount);
});



$(document).on('submit', 'form#party_payment_add_form', function(e) {
    e.preventDefault();
    var form = $(this);
    
    // Clear previous error messages
    $('.error').remove();

    // Form validation
    var isValid = true;

    // Validate customer_id
    if ($('input[name="customer_id"]').val() == '') {
        isValid = false;
        $('input[name="customer_id"]').after('<div class="error">Customer ID is required.</div>');
    }

    // Validate supplier_id
    if ($('input[name="supplier_id"]').val() == '') {
        isValid = false;
        $('input[name="supplier_id"]').after('<div class="error">Supplier ID is required.</div>');
    }

    // Validate amount_received
    var amountReceived = $('input[name="amount_received"]').val();
    if (amountReceived == '' || parseFloat(amountReceived) <= 0) {
        isValid = false;
        $('input[name="amount_received"]').after('<div class="error">Amount received must be greater than 0.</div>');
    }

    // Validate amount_paid
    var amountPaid = $('input[name="amount_paid"]').val();
    if (amountPaid == '' || parseFloat(amountPaid) < 0) {
        isValid = false;
        $('input[name="amount_paid"]').after('<div class="error">Amount paid must be greater than or equal to 0.</div>');
    }

    // If validation fails, don't proceed with AJAX
    if (!isValid) {
        form.find('button[type="submit"]').prop('disabled', false);
        return false;
    }

    var data = form.serialize();

    $.ajax({
        method: 'POST',
        url: $(this).attr('action'),
        dataType: 'json',
        data: data,
        beforeSend: function(xhr) {
            __disable_submit_button(form.find('button[type="submit"]'));
        },
        success: function(result) {
            if (result.success == true) {
                $('div.view_modal').modal('hide');
                toastr.success(result.msg);
            } else {
                toastr.error(result.msg);
            }
        },
    });
});
        // Handle change event for party_customer_id dropdown in the modal
        $('.view_modal').on('change', '.party_customer_id', function() {
    var id = $(this).val();  // Capture the selected value from the dropdown
    if (id) {
        $.ajax({
            method: 'get',
            url: '/get-contact-due/' + id,  // Fix: Concatenate the ID correctly with the URL
            dataType: 'text',
            success: function(result) {
                var $dueText = $('.view_modal .party_customer_due_text');
                
                if (result.trim() !== '') {  // Check if result is not empty
                    $dueText.find('span').text(result);  // Update the span with the result
                    $dueText.removeClass('hide');  // Make the text visible
                } else {
                    $dueText.find('span').text('');  // Clear the span content
                    $dueText.addClass('hide');  // Hide the text
                }
            },
            error: function(xhr, status, error) {
                console.error("Error fetching contact due: " + status + " - " + error);
            }
        });
    }
});
    });
</script>
