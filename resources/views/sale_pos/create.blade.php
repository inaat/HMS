@extends('layouts.app')

@section('title', __('sale.pos_sale'))

@section('content')
    <section class="content no-print">
        <input type="hidden" id="amount_rounding_method" value="{{ $pos_settings['amount_rounding_method'] ?? '' }}">
        @if (!empty($pos_settings['allow_overselling']))
            <input type="hidden" id="is_overselling_allowed">
        @endif
        @if (session('business.enable_rp') == 1)
            <input type="hidden" id="reward_point_enabled">
        @endif
        @php
            $is_discount_enabled = $pos_settings['disable_discount'] != 1 ? true : false;
            $is_rp_enabled = session('business.enable_rp') == 1 ? true : false;
        @endphp
        {!! Form::open([
            'url' => action([\App\Http\Controllers\SellPosController::class, 'store']),
            'method' => 'post',
            'id' => 'add_pos_sell_form',
        ]) !!}
        <div class="row mb-12">
            <div class="col-md-12 tw-pt-0 tw-mb-14">
                <div class="row tw-flex lg:tw-flex-row md:tw-flex-col sm:tw-flex-col tw-flex-col tw-items-start md:tw-gap-4">
                    {{-- <div class="@if (empty($pos_settings['hide_product_suggestion'])) col-md-7 @else col-md-10 col-md-offset-1 @endif no-padding pr-12"> --}}
                    <div class="tw-px-3 tw-w-full  lg:tw-px-0 lg:tw-pr-0 @if(empty($pos_settings['hide_product_suggestion'])) lg:tw-w-[60%]  @else lg:tw-w-[100%] @endif">

                        <div class="tw-shadow-[rgba(17,_17,_26,_0.1)_0px_0px_16px] tw-rounded-2xl tw-bg-white tw-mb-2 md:tw-mb-8 tw-p-2">

                            {{-- <div class="box box-solid mb-12 @if (!isMobile()) mb-40 @endif"> --}}
                                <div class="box-body pb-0">
                                    {!! Form::hidden('location_id', $default_location->id ?? null, [
                                        'id' => 'location_id',
                                        'data-receipt_printer_type' => !empty($default_location->receipt_printer_type)
                                            ? $default_location->receipt_printer_type
                                            : 'browser',
                                        'data-default_payment_accounts' => $default_location->default_payment_accounts ?? '',
                                    ]) !!}
                                    <!-- sub_type -->
                                    {!! Form::hidden('sub_type', isset($sub_type) ? $sub_type : null) !!}
                                    <input type="hidden" id="item_addition_method"
                                        value="{{ $business_details->item_addition_method }}">
                                    @include('sale_pos.partials.pos_form')

                                    @include('sale_pos.partials.pos_form_totals')

                                    @include('sale_pos.partials.payment_modal')

                                    @if (empty($pos_settings['disable_suspend']))
                                        @include('sale_pos.partials.suspend_note_modal')
                                    @endif

                                    @if (empty($pos_settings['disable_recurring_invoice']))
                                        @include('sale_pos.partials.recurring_invoice_modal')
                                    @endif
                                </div>
                            {{-- </div> --}}
                        </div>
                    </div>
                    @if (empty($pos_settings['hide_product_suggestion']) && !isMobile())
                        <div class="md:tw-no-padding tw-w-full lg:tw-w-[40%] tw-px-5">
                            @include('sale_pos.partials.pos_sidebar')
                        </div>
                    @endif
                </div>
            </div>
        </div>
        @include('sale_pos.partials.pos_form_actions')
        {!! Form::close() !!}
    </section>

    <!-- This will be printed -->
    <section class="invoice print_section" id="receipt_section">
    </section>
    <div class="modal fade contact_modal" tabindex="-1" role="dialog" aria-labelledby="gridSystemModalLabel">
        @include('contact.create', ['quick_add' => true])
    </div>
    @if (empty($pos_settings['hide_product_suggestion']) && isMobile())
        @include('sale_pos.partials.mobile_product_suggestions')
    @endif
    <!-- /.content -->
    <div class="modal fade register_details_modal" tabindex="-1" role="dialog" aria-labelledby="gridSystemModalLabel">
    </div>
    <div class="modal fade close_register_modal" tabindex="-1" role="dialog" aria-labelledby="gridSystemModalLabel">
    </div>
    <!-- quick product modal -->
    <div class="modal fade quick_add_product_modal" tabindex="-1" role="dialog" aria-labelledby="modalTitle"></div>

    <div class="modal fade" id="expense_modal" tabindex="-1" role="dialog" aria-labelledby="gridSystemModalLabel">
    </div>

    @include('sale_pos.partials.configure_search_modal')

    @include('sale_pos.partials.recent_transactions_modal')

    @include('sale_pos.partials.weighing_scale_modal')
    <div class="modal fade" id="productModal" tabindex="-1" role="dialog" aria-labelledby="productModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">×</span>
                </button>
                <h5 class="modal-title" id="productModalLabel">Cost Calculation                </h5>
            </div>
            <div class="modal-body">
            <table id="modalTable" class="table table-condensed table-bordered table-striped table-responsive">
            <thead>
                <tr>
                    <th>Item Name</th>
                    <th>Quantity</th>
                    <th>Purchase Price</th>
                    <th>Total Cost</th>
                    <th>Total Profit</th>
                </tr>
            </thead>
            <tbody>
                <!-- Rows will be inserted here -->
            </tbody>
        </table>
        <hr>
        <!-- Sale Summary Table -->
<div id="saleSummary">
    <table class="table table-bordered">
        <thead>
            <tr>
                <th>Summary</th>
                <th>Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><strong>Sale Amount:</strong></td>
                <td><span id="modalSaleAmount"></span></td>
            </tr>
            <tr>
                <td><strong>Total Cost:</strong></td>
                <td><span id="modalTotalCost"></span></td>
            </tr>
            <tr>
                <td><strong>Profit:</strong></td>
                <td><span id="modalProfit"></span></td>
            </tr>
        </tbody>
    </table>
</div>

            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

@stop
@section('css')
    <!-- include module css -->
    @if (!empty($pos_module_data))
        @foreach ($pos_module_data as $key => $value)
            @if (!empty($value['module_css_path']))
                @includeIf($value['module_css_path'])
            @endif
        @endforeach
    @endif
@stop
@section('javascript')
    <script src="{{ asset('js/pos.js?v=' . $asset_v) }}"></script>
    <script src="{{ asset('js/printer.js?v=' . $asset_v) }}"></script>
    <script src="{{ asset('js/product.js?v=' . $asset_v) }}"></script>
    <script src="{{ asset('js/opening_stock.js?v=' . $asset_v) }}"></script>
    @include('sale_pos.partials.keyboard_shortcuts')

    <!-- Call restaurant module if defined -->
    @if (in_array('tables', $enabled_modules) ||
            in_array('modifiers', $enabled_modules) ||
            in_array('service_staff', $enabled_modules))
        <script src="{{ asset('js/restaurant.js?v=' . $asset_v) }}"></script>
    @endif
    <!-- include module js -->
    @if (!empty($pos_module_data))
        @foreach ($pos_module_data as $key => $value)
            @if (!empty($value['module_js_path']))
                @includeIf($value['module_js_path'], ['view_data' => $value['view_data']])
            @endif
        @endforeach
    @endif
    <script>
$(document).ready(function() {
    // When the "Open Modal" button is clicked
    $('.cost_calculation').on('click', function() {
        // Clear the previous modal content (table body)
        $('#modalTable tbody').empty();
        $('#modalProductDetails').empty();
        $('#modalSaleAmount').text('');
        $('#modalTotalCost').text('');
        $('#modalTaxPayable').text('');
        $('#modalProfit').text('');

        // Initialize totals to zero
        let allsaleAmount = 0;
        let alltotalCost = 0;
        let allprofit = 0;

        // Iterate over each row in the #pos_table
        $('table#pos_table').find('tr.product_row').each(function() {
            var productRow = $(this);
            
            // Retrieve values from each column in the row
            var name = productRow.find('.pproduct_name').text(); // Product name
            var quantity =  __number_uf(productRow.find('input.pos_quantity').val()) || 0; // Quantity (default to 0 if empty)
            var purchasePrice =  __number_uf(productRow.find('.fifo_purchase_price').text()) || 0; // Purchase price (default to 0)
            var sellingPrice =  __number_uf(productRow.find('input.pos_unit_price_inc_tax').val()) || 0; // Selling price (default to 0)
            var base_unit_multiplier =  __number_uf(productRow.find('input.base_unit_multiplier').val()) || 1; // Base unit multiplier (default to 1)

            // Calculate the total cost for this product
            var totalCost = quantity * (purchasePrice * base_unit_multiplier);
            
            // Update the overall sale amount, total cost, and profit
            allsaleAmount += quantity * sellingPrice;
            alltotalCost += totalCost;
            allprofit += (sellingPrice * quantity) - totalCost; // Profit = (Selling Price * Quantity) - Total Cost

            // Create a new row for the modal's table
            var newRow = $('<tr>');
            newRow.append('<td>' + name + '</td>');
            newRow.append('<td>' + quantity + '</td>');
            newRow.append('<td>' + formatCurrency(purchasePrice * base_unit_multiplier) + '</td>');
            newRow.append('<td>' + formatCurrency(totalCost) + '</td>');
            newRow.append('<td>' + formatCurrency((sellingPrice * quantity) - totalCost) + '</td>');

            // Append the new row to the modal's table
            $('#modalTable tbody').append(newRow);
        });

        // Populate Sale Summary
        $('#modalSaleAmount').text(formatCurrency(allsaleAmount));
        $('#modalTotalCost').text(formatCurrency(alltotalCost));
        $('#modalProfit').text(formatCurrency(allprofit));

        // // Optionally, you can show the modal
         $('#productModal').modal('show');
    });
});

// Function to format numbers as currency
function formatCurrency(amount) {
    return '₨ ' + amount.toLocaleString(); // Format the number with thousands separators
}

</script>
    <script>
        $(document).on('click', '.send-whatsapp-btn', function(e) {
            e.preventDefault();
            var url = $(this).attr('href');
            var btn = $(this);
            btn.html('<i class="fas fa-spinner fa-spin"></i>');
            $.get(url, function(result) {
                btn.html('<i class="fab fa-whatsapp"></i> WhatsApp');
                if (result && result.success === false) {
                    toastr.error(result.msg);
                } else {
                    toastr.success((result && result.msg) ? result.msg : 'WhatsApp sent successfully!');
                }
            }).fail(function() {
                btn.html('<i class="fab fa-whatsapp"></i> WhatsApp');
                toastr.error('Failed to send WhatsApp.');
            });
        });
    </script>
@endsection
