@extends('layouts.app')
@section('title', $type == 'customer' ? __('Customer Report') : ($type == 'supplier' ? __('Supplier Report') : __('report.purchase_sell')))

@section('content')


<!-- Main content -->
<section class="content" style="zoom:90%">

    <br>
    <div class="row">
        <div class="col-xs-4" id="customers">
            <div class=" tw-mb-4 tw-transition-all lg:tw-col-span-2 tw-duration-200 tw-bg-white tw-shadow-sm tw-rounded-xl tw-ring-1 hover:tw-shadow-md hover:tw-translate-y-0.5 tw-ring-gray-200">
                <div class="">
                    <div class="box-header">

                        <h3 class="box-title">  @if($type=='customer')Customers @elseif($type=='supplier') Suppliers @endif</h3>
                        <!-- Search Box for Filtering Customers -->
                        <div class="form-group">
                            <input type="text" id="customerSearch" class="form-control" placeholder="Search ...">
                        </div>
                        <table class="table table-striped" id="customerTable">
                            @foreach($total_contacts as $key => $value)
                            <tr class="customer-row" data-customer-id="{{ $value->contact_id }}">
                                <th>{{ $value->contact_name }}:</th>
                                <td>
                                    <span class="total_purchase f-right "> @format_currency($value->total_due) </span>
                                </td>
                            </tr>
                            @endforeach
                        </table>

                        <!-- Total Summary -->
                        <div class="total-summary">
                            <p> 
                            @if($type=='customer')Total Receivable: @elseif($type=='supplier') Total Payable: @endif
                                <strong class="f-right">
                                    @php
                                    $totalReceivables = $total_contacts->sum('total_due');
                                    @endphp
                                    @format_currency($totalReceivables)
                                </strong>
                            </p>
                        </div>

                    </div>

                </div>
            </div>
        </div>



        <!-- Transactions Section -->
        <div class="col-xs-8" id="transactions">
            <div class=" tw-mb-4 tw-transition-all lg:tw-col-span-2 tw-duration-200 tw-bg-white tw-shadow-sm tw-rounded-xl tw-ring-1 hover:tw-shadow-md hover:tw-translate-y-0.5 tw-ring-gray-200">
                <div class="">
                    <div class="box-header">

                        <h3 class="box-title">Transactions</h3>
                    </div>
                    <div class="row hide">
                        <div class="col-md-12">
                            <div class="col-md-3">
                                <div class="form-group">
                                    {!! Form::label('ledger_date_range', __('report.date_range') . ':') !!}
                                    {!! Form::text('ledger_date_range', null, ['placeholder' => __('lang_v1.select_a_date_range'), 'class' => 'form-control', 'readonly']); !!}
                                </div>
                            </div>

                        </div>

                    </div>

                    <div class="row">
                        <div class="col-md-12">

                            <div id="contact_ledger_div"></div>


                        </div>
                    </div>

                </div>
            </div>

        </div>
    </div>
</section>
<!-- /.content -->

@stop

@section('javascript')
<script src="{{ asset('js/report.js?v=' . $asset_v) }}"></script>
<script>
    $(document).ready(function() {
        // Initialize date range picker with callback to update input value
        $('#ledger_date_range').daterangepicker(
            dateRangeSettings
            , function(start, end) {
                $('#ledger_date_range').val(start.format(moment_date_format) + ' ~ ' + end.format(moment_date_format));
                fetchTransactions(); // Fetch transactions when date range is changed
            }
        );

        // Trigger fetchTransactions when ledger date or location is changed
        $('#ledger_date_range, #ledger_location').change(function() {
            fetchTransactions(); // Re-fetch transactions when filters are updated
        });

        // Initial call to fetch transactions (with no customerId or default value)
        fetchTransactions();

        // Search functionality to filter customers by name
        $('#customerSearch').on('input', function() {
            var searchQuery = $(this).val().toLowerCase();
            $('#customerTable tr').each(function() {
                var customerName = $(this).find('th').text().toLowerCase();
                if (customerName.indexOf(searchQuery) !== -1) {
                    $(this).show();
                } else {
                    $(this).hide();
                }
            });
        });

        // Add click event listener to customer rows
        $('.customer-row').on('click', function() {
            // Remove active class from all rows
            $('.customer-row').removeClass('active');
            // Add active class to clicked row
            $(this).addClass('active');
            // Fetch transactions for the selected customer
            var customerId = $(this).data('customer-id');
            fetchTransactions(customerId);
        });

        // Function to fetch transactions for a customer using jQuery AJAX
        function fetchTransactions(customerId = null) {
            // If no customerId is provided, it will use the default one or fetch all.
            var url = '/get-party/ledger';
            if (customerId) {
                url += '?contact_id=' + customerId; // Add customerId to the URL for the specific customer
            }
            var start_date = '';
            var end_date = '';

            if ($('#ledger_date_range').val()) {
                start_date = $('#ledger_date_range').data('daterangepicker').startDate.format('YYYY-MM-DD');
                end_date = $('#ledger_date_range').data('daterangepicker').endDate.format('YYYY-MM-DD');
            }

            var format = $('input[name="ledger_format"]:checked').val();
            var data = {
                start_date: start_date
                , end_date: end_date,

            }
            // Make the AJAX call to fetch transactions
            $.ajax({
                url: url
                , data: data
                , dataType: 'html'
                , success: function(result) {
                    $('#contact_ledger_div').html(result); // Update the ledger div with the result
                    __currency_convert_recursively($('#contact_ledger_div')); // Handle currency formatting

                    // Initialize DataTable (if needed)
                    if ($('#ledger_table').length) {
                        $('#ledger_table').DataTable({
                            searching: false
                            , ordering: false
                            , paging: false
                            , fixedHeader: false
                            , dom: 't' // Only show the table without extra elements like pagination
                        });
                    }
                }
                , error: function(xhr, status, error) {
                    console.error("Error fetching transactions:", error);
                }
            });
        }

        // Trigger click on the first customer row to make it active by default on page load
        if ($('.customer-row').length > 0) {
            var firstRow = $('.customer-row:first'); // Get the first row
            firstRow.addClass('active'); // Mark the first row as active
            var customerId = firstRow.data('customer-id'); // Get the customer ID from the first row
            fetchTransactions(customerId); // Fetch the transactions for the first customer
        }
    });

</script>
@endsection
