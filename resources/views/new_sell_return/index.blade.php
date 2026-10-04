@extends('layouts.app')
@section('title', __('lang_v1.sell_return'))

@section('content')


<!-- Main content -->
<section class="content">
	{!! Form::open(['url' =>action([\App\Http\Controllers\WithOutSellReturnController::class, 'SellReturnSave']), 'method' => 'post', 'id' => 'add_return_form', 'files' => true ]) !!}
@component('components.widget', ['class' => 'box-primary'])
		<div class="row">
			<div class=" col-sm-3 ">
				<div class="form-group">
					{!! Form::label('customer_id', __('contact.customer') . ':*') !!}
					<div class="input-group">
						<span class="input-group-addon">
							<i class="fa fa-user"></i>
						</span>
						{!! Form::select('contact_id', [], null, ['class' => 'form-control', 'placeholder' => __('messages.please_select'), 'required', 'id' => 'customer_id']); !!}
						
					</div>
				</div>
				
			</div>
			<div class="col-sm-3  hide">
				<div class="form-group">
					{!! Form::label('ref_no', __('return.ref_no').':') !!}
					@show_tooltip(__('lang_v1.leave_empty_to_autogenerate'))
					{!! Form::text('ref_no', null, ['class' => 'form-control']); !!}
				</div>
			</div>
			<div class=" col-sm-3 ">
				<div class="form-group">
					{!! Form::label('transaction_date', __('Return Date') . ':*') !!}
					<div class="input-group">
						<span class="input-group-addon">
							<i class="fa fa-calendar"></i>
						</span>
						{!! Form::text('transaction_date', @format_datetime('now'), ['class' => 'form-control', 'readonly', 'required']); !!}
					</div>
				</div>
			</div>
						
			@if(count($business_locations) == 1)
				@php 
					$search_disable = false; 
				@endphp
			@else
				@php $default_location = null;
				$search_disable = true;
				@endphp
			@endif
			<div class="col-sm-3">
				<div class="form-group">
					{!! Form::label('location_id', __('purchase.business_location').':*') !!}
					{!! Form::select('location_id', $business_locations, null, ['class' => 'form-control select2', 'placeholder' => __('messages.please_select'), 'required']); !!}
				</div>
			</div>

			


			
		</div>
	
		
	@endcomponent

	
	@component('components.widget', ['class' => 'box-primary'])
		<div class="row">
			
			<div class="col-sm-8">
				<div class="form-group">
					<div class="input-group">
						<span class="input-group-addon">
							<i class="fa fa-search"></i>
						</span>
						{!! Form::text('search_product', null, ['class' => 'form-control mousetrap', 'id' => 'search_product', 'placeholder' => __('lang_v1.search_product_placeholder'), 'disabled' => $search_disable]); !!}
					</div>
				</div>
			</div>
		
		</div>
	
		<div class="row">
			<div class="col-sm-12">
				<div class="table-responsive">
					<table class="table table-condensed table-bordered table-th-green text-center table-striped" id="purchase_entry_table">
						    	<thead>
				            <tr class="bg-green">
				              	<th>#</th>
				              	<th>@lang('product.product_name')</th>
				              	<th>@lang('sale.unit_price')</th>
				              	<th>@lang('lang_v1.sell_quantity')</th>
				              	<th>@lang('lang_v1.return_quantity')</th>
				              	<th>@lang('lang_v1.return_subtotal')</th>
                                <th><i class="fa fa-trash" aria-hidden="true"></i></th>
				            </tr>
				        </thead>
						<tbody></tbody>
					</table>
				</div>
				<hr/>
			

				<input type="hidden" id="row_count" value="0">
			</div>
		</div>
        	<div class="box-body ">
				<div class="row">
			<div class="col-md-12 text-right">
				{!! Form::hidden('final_total', 0 , ['id' => 'grand_total_hidden']); !!}
						<b>@lang('Return Total'): </b><span id="grand_total" class="display_currency" data-currency_symbol='true'>0</span>
			</div>
		</div>
		     
			<br>
			<div class="row">
				<div class="col-sm-12">
					<button type="button" id="submit_purchase_form" class="btn btn-primary pull-right btn-flat">@lang('messages.save')</button>
				</div>
			</div>
		</div>
	@endcomponent

	
{!! Form::close() !!}
</section>


<!-- /.content -->
@endsection


@section('javascript')
<script type="text/javascript">
//Datetime picker
$('#transaction_date').datetimepicker({
        format: moment_date_format + ' ' + moment_time_format,
        ignoreReadonly: true,
    });
    //On Change of quantity
    $(document).on('change', '.purchase_quantity,.purchase_unit_cost', function() {
        var row = $(this).closest('tr');

            $('#purchase_entry_table tbody').append(
              update_purchase_entry_row_values(row)
            );
            update_table_sr_number();
            update_table_total();
    });
	$(document).ready( function(){
$(document).on('click', 'button#submit_purchase_form', function(e) {
    e.preventDefault();

    //Check if product is present or not.
    if ($('table#purchase_entry_table tbody tr').length <= 0) {
        toastr.warning(LANG.no_products_added);
        $('input#search_product').select();
        return false;
    }

    if ($('form#add_return_form').valid()) {
        $(this).attr('disabled', true);
        $('form#add_return_form').submit();
    }
});
		   //get customer
    $('select#customer_id').select2({
        ajax: {
            url: '/contacts/customers',
            dataType: 'json',
            delay: 250,
            data: function(params) {
                return {
                    q: params.term, // search term
                    page: params.page,
                };
            },
            processResults: function(data) {
                return {
                    results: data,
                };
            },
        },
        templateResult: function (data) { 
            var template = '';
            if (data.supplier_business_name) {
                template += data.supplier_business_name + "<br>";
            }
            template += data.text + "<br>" + LANG.mobile + ": " + data.mobile;

            if (typeof(data.total_rp) != "undefined") {
                var rp = data.total_rp ? data.total_rp : 0;
                template += "<br><i class='fa fa-gift text-success'></i> " + rp;
            }

            return  template;
        },
        minimumInputLength: 1,
        language: {
            noResults: function() {
                var name = $('#customer_id')
                    .data('select2')
                    .dropdown.$search.val();
              
            },
        },
        escapeMarkup: function(markup) {
            return markup;
        },
    });
		$(document).on('change', '#location_id', function() {
    toggle_search();
   ;
});
    $(document).on('click', '.remove_purchase_entry_row', function() {
        swal({
            title: LANG.sure,
            icon: 'warning',
            buttons: true,
            dangerMode: true,
        }).then(value => {
            if (value) {
                $(this)
                    .closest('tr')
                    .remove();
                update_table_total();
                update_table_sr_number(); 
            }
        });
    });
		 //Add products
    if ($('#search_product').length > 0) {
        $('#search_product')
            .autocomplete({
                source: function(request, response) {
                    $.getJSON(
                        '/without-invoice-return/get_products',
                        { contact_id: $('#customer_id').val(),location_id: $('#location_id').val(), term: request.term },
                        response
                    );
                },
                minLength: 2,
                response: function(event, ui) {
                    if (ui.content.length == 1) {
                        ui.item = ui.content[0];
                        $(this)
                            .data('ui-autocomplete')
                            ._trigger('select', 'autocompleteselect', ui);
                        $(this).autocomplete('close');
                    } else if (ui.content.length == 0) {
                                 toastr.error(LANG.no_products_found);
                        $('input#search_product').select();
                    }
                },
                select: function(event, ui) {
                    $(this).val(null);
                    get_return_entry_row(ui.item.product_id, ui.item.variation_id);
                },
            })
            .autocomplete('instance')._renderItem = function(ul, item) {
            return $('<li>')
                .append('<div>' + item.text + '</div>')
                .appendTo(ul);
        };
    }
	});
		function toggle_search() {
    if ($('#location_id').val()) {
        $('#search_product').removeAttr('disabled');
        $('#search_product').focus();
    } else {
        $('#search_product').attr('disabled', true);
    }
}
//variation_id is null when weighing_scale_barcode is used.
function get_return_entry_row(product_id, variation_id) {

        
        var add_via_ajax = true;

        //Search for variation id in each row of pos table
        $('#purchase_entry_table tbody')
            .find('tr')
            .each(function() {
                var row_v_id = $(this)
                    .find('.row_variation_id')
                    .val();
                if(row_v_id==variation_id){
                    toastr.error('Alerady Exist');
                    $('input#search_product')
                        .focus()
                        .select();
					add_via_ajax= false;

                }else{
					 add_via_ajax= true;
				}
              
              

            });

    if (add_via_ajax) {
         var row_count = $('#row_count').val();
        var location_id = $('#location_id').val();
        var contact_id = $('#customer_id').val();
        var data = { 
            product_id: product_id, 
            row_count: row_count, 
            variation_id: variation_id,
            location_id: location_id,
            contact_id: contact_id
        };

       
    $.ajax({
            method: 'POST',
            url: '/without-invoice-returns/get_return_entry_row',
            dataType: 'html',
            data: data,
            success: function(result) {
                append_return_lines(result, row_count);
            },
        });
    }
}
function append_return_lines(data, row_count, trigger_change = false) {
    $(data)
        .find('.purchase_quantity')
        .each(function() {
            row = $(this).closest('tr');

            $('#purchase_entry_table tbody').append(
              update_purchase_entry_row_values(row)
            );
            update_table_sr_number();
            update_table_total();
         
        });
    if ($(data).find('.purchase_quantity').length) {
        $('#row_count').val(
            $(data).find('.purchase_quantity').length + parseInt(row_count)
        );
    }
}
function update_purchase_entry_row_values(row) {
    if (typeof row != 'undefined') {
     var quantity = __read_number(row.find('.purchase_quantity'), false);
        var unit_cost_price = __read_number(row.find('.purchase_unit_cost'), false);
        var row_subtotal = quantity * unit_cost_price;
        
        row.find('.row_subtotal').text(
            __currency_trans_from_en(row_subtotal, false)
        );
        __write_number(row.find('.row_subtotal_input'), row_subtotal, false);

        return row;
    }
}
function update_table_sr_number() {
    var sr_number = 1;
    $('table#purchase_entry_table tbody')
        .find('.sr_number')
        .each(function() {
            $(this).text(sr_number);
            sr_number++;
        });
}
function update_table_total() {
    var total_quantity = 0;
    var total_subtotal = 0;

    $('#purchase_entry_table tbody')
        .find('tr')
        .each(function() {
            total_quantity += __read_number($(this).find('.purchase_quantity'), false);
          
            total_subtotal += __read_number($(this).find('.row_subtotal_input'), false);
        });

    //$('#total_quantity').text(__number_f(total_quantity, false));
 
    $('#grand_total').text(__currency_trans_from_en(total_subtotal, false));
    __write_number($('input#grand_total_hidden'), total_subtotal, false);
}
</script>
@endsection
