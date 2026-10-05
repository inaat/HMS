@extends('layouts.app')
@section('title', __('lang_v1.sell_return'))

@section('content')

<!-- Content Header (Page header) -->
<section class="content-header no-print">
	<h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">@lang('lang_v1.sell_return')</h1>
</section>

<!-- Main content -->
<section class="content no-print">

	{!! Form::hidden('location_id', $sell->location->id, ['id' => 'location_id', 'data-receipt_printer_type' => $sell->location->receipt_printer_type ]); !!}

	{!! Form::open(['url' => action([\App\Http\Controllers\SellReturnController::class, 'store']), 'method' => 'post', 'id' => 'sell_return_form' ]) !!}
	{!! Form::hidden('transaction_id', $sell->id); !!}
	<div class="box box-solid">
		<div class="box-header">
			<h3 class="box-title">@lang('lang_v1.parent_sale')</h3>
		</div>
		<div class="box-body">
			<div class="row">
				<div class="col-sm-4">
					<strong>@lang('sale.invoice_no'):</strong> {{ $sell->invoice_no }} <br>
					<strong>@lang('messages.date'):</strong> {{@format_date($sell->transaction_date)}}
				</div>
				<div class="col-sm-4">
					<strong>@lang('contact.customer'):</strong> {{ $sell->contact->name }} <br>
					<strong>@lang('purchase.business_location'):</strong> {{ $sell->location->name }}
				</div>
			</div>
		</div>
	</div>
	<div class="box box-solid">
		<div class="box-body">
			<div class="row">
				<div class="col-sm-4">
					<div class="form-group">
						{!! Form::label('invoice_no', __('sale.invoice_no').':') !!}
						{!! Form::text('invoice_no', !empty($sell->return_parent->invoice_no) ? $sell->return_parent->invoice_no : null, ['class' => 'form-control']); !!}
					</div>
				</div>
				<div class="col-sm-3">
					<div class="form-group">
						{!! Form::label('transaction_date', __('messages.date') . ':*') !!}
						<div class="input-group">
							<span class="input-group-addon">
								<i class="fa fa-calendar"></i>
							</span>
							@php
							$transaction_date = !empty($sell->return_parent->transaction_date) ? $sell->return_parent->transaction_date : 'now';
							@endphp
							{!! Form::text('transaction_date', @format_datetime($transaction_date), ['class' => 'form-control', 'readonly', 'required']); !!}
						</div>
					</div>
				</div>
				<div class="col-sm-12">
					<table class="table bg-gray" id="sell_return_table">
						<thead>
							<tr class="bg-green">
								<th>#</th>
								<th>@lang('product.product_name')</th>
								<th>@lang('sale.unit_price')</th>
								<th>@lang('lang_v1.sell_quantity')</th>
								<th>@lang('lang_v1.return_quantity')</th>
								<th>@lang('lang_v1.return_subtotal')</th>
							</tr>
						</thead>
						<tbody>
							@foreach($sell->sell_lines as $sell_line)
							@php
							$check_decimal = 'false';
							if($sell_line->product->unit->allow_decimal == 0){
							$check_decimal = 'true';
							}

							$unit_name = $sell_line->product->unit->short_name;

							if(!empty($sell_line->sub_unit)) {
							$unit_name = $sell_line->sub_unit->short_name;

							if($sell_line->sub_unit->allow_decimal == 0){
							$check_decimal = 'true';
							} else {
							$check_decimal = 'false';
							}
							}

							@endphp
							<tr>
								<td>{{ $loop->iteration }}</td>
								<td>
									{{ $sell_line->product->name }}
									@if( $sell_line->product->type == 'variable')
									- {{ $sell_line->variations->product_variation->name}}
									- {{ $sell_line->variations->name}}
									@endif
									<br>
									{{ $sell_line->variations->sub_sku }}
								</td>
								<td><span class="display_currency" data-currency_symbol="true">{{ $sell_line->unit_price_inc_tax }}</span></td>
								<td>{{ $sell_line->formatted_qty }} {{$unit_name}}</td>

								<td>
									<input type="text" name="products[{{$loop->index}}][quantity]" value="{{@format_quantity($sell_line->quantity_returned)}}" class="form-control input-sm input_number return_qty input_quantity" data-rule-abs_digit="{{$check_decimal}}" data-msg-abs_digit="@lang('lang_v1.decimal_value_not_allowed')" data-rule-max-value="{{$sell_line->quantity}}" data-msg-max-value="@lang('validation.custom-messages.quantity_not_available', ['qty' => $sell_line->formatted_qty, 'unit' => $unit_name ])">
									<input name="products[{{$loop->index}}][unit_price_inc_tax]" type="hidden" class="unit_price" value="{{@num_format($sell_line->unit_price_inc_tax)}}">
									<input name="products[{{$loop->index}}][sell_line_id]" type="hidden" value="{{$sell_line->id}}">
								</td>
								<td>
									<div class="return_subtotal"></div>
								</td>
							</tr>
							@endforeach
						</tbody>
					</table>
				</div>
			</div>
			<div class="row">
				@php
				$discount_type = !empty($sell->return_parent->discount_type) ? $sell->return_parent->discount_type : $sell->discount_type;
				$discount_amount = !empty($sell->return_parent->discount_amount) ? $sell->return_parent->discount_amount : $sell->discount_amount;
				@endphp
				<div class="col-sm-4">
					<div class="form-group">
						{!! Form::label('discount_type', __( 'purchase.discount_type' ) . ':') !!}
						{!! Form::select('discount_type', [ '' => __('lang_v1.none'), 'fixed' => __( 'lang_v1.fixed' ), 'percentage' => __( 'lang_v1.percentage' )], $discount_type, ['class' => 'form-control']); !!}
					</div>
				</div>
				<div class="col-sm-4">
					<div class="form-group">
						{!! Form::label('discount_amount', __( 'purchase.discount_amount' ) . ':') !!}
						{!! Form::text('discount_amount', @num_format($discount_amount), ['class' => 'form-control input_number']); !!}
					</div>
				</div>
			</div>
			@php
			$tax_percent = 0;
			if(!empty($sell->tax)){
			$tax_percent = $sell->tax->amount;
			}
			@endphp
			{!! Form::hidden('tax_id', $sell->tax_id); !!}
			{!! Form::hidden('tax_amount', 0, ['id' => 'tax_amount']); !!}
			{!! Form::hidden('tax_percent', $tax_percent, ['id' => 'tax_percent']); !!}
			<div class="row">
				<div class="col-sm-12 text-right">
					<strong>@lang('lang_v1.total_return_discount'):</strong>
					&nbsp;(-) <span id="total_return_discount"></span>
				</div>
				<div class="col-sm-12 text-right">
					<strong>@lang('lang_v1.total_return_tax') - @if(!empty($sell->tax))({{$sell->tax->name}} - {{$sell->tax->amount}}%)@endif : </strong>
					&nbsp;(+) <span id="total_return_tax"></span>
				</div>
				<div class="col-sm-12 text-right">
					<strong>@lang('lang_v1.return_total'): </strong>&nbsp;
					<span id="net_return">0</span>
				</div>
			</div>

			{{-- Refund: the return first clears what is still due on the sale, only the rest goes back to the customer --}}
			@php
				$refund_payment = $refund_info['refund_payment'];
			@endphp
			{!! Form::hidden('manage_refund', 1) !!}
			<div class="row">
				<div class="col-sm-12">
					<div class="sr-refund-box">
						<div class="sr-refund-sale">
							<span>Sale total: <strong><span class="display_currency" data-currency_symbol="true">{{ $refund_info['sale_total'] }}</span></strong></span>
							<span>Paid: <strong><span class="display_currency" data-currency_symbol="true">{{ $refund_info['sale_paid'] }}</span></strong></span>
							<span>Still due: <strong><span class="display_currency" data-currency_symbol="true">{{ $refund_info['sale_due'] }}</span></strong></span>
							@if($refund_info['other_refunded'] > 0)
								<span>Already refunded by hand: <strong><span class="display_currency" data-currency_symbol="true">{{ $refund_info['other_refunded'] }}</span></strong></span>
							@endif
						</div>
						<div class="row">
							<div class="col-sm-3">
								<div class="checkbox" style="margin-top: 28px;">
									<label>
										<input type="checkbox" name="refund_now" value="1" id="refund_now" @if(! empty($refund_payment) || empty($sell->return_parent)) checked @endif>
										<strong>Refund to customer now</strong>
									</label>
								</div>
							</div>
							<div class="col-sm-3">
								<div class="form-group">
									{!! Form::label('refund_method', 'Refund method:') !!}
									{!! Form::select('refund_method', $payment_types, ! empty($refund_payment) ? $refund_payment->method : 'cash', ['class' => 'form-control refund-field', 'id' => 'refund_method']) !!}
								</div>
							</div>
							<div class="col-sm-3">
								<div class="form-group">
									{!! Form::label('refund_amount', 'Refund amount:') !!}
									{!! Form::text('refund_amount', ! empty($refund_payment) ? @num_format($refund_payment->amount) : null, ['class' => 'form-control input_number refund-field', 'id' => 'refund_amount']) !!}
								</div>
							</div>
						</div>
						<p class="sr-refund-help" id="refund_help"></p>
					</div>
				</div>
			</div>
			<br>
			<div class="row">
				<div class="col-sm-12">
					<button type="submit" class="tw-dw-btn tw-dw-btn-primary tw-text-white pull-right">@lang('messages.save')</button>
				</div>
			</div>
		</div>
	</div>
	{!! Form::close() !!}

</section>
@stop
@section('javascript')
<script src="{{ asset('js/printer.js?v=' . $asset_v) }}"></script>
<script src="{{ asset('js/sell_return.js?v=' . $asset_v) }}"></script>
<script type="text/javascript">
	$(document).ready(function() {
		$('form#sell_return_form').validate();
		update_sell_return_total();
		//Date picker
		// $('#transaction_date').datepicker({
		//     autoclose: true,
		//     format: datepicker_date_format
		// });
	});
	$(document).on('change', 'input.return_qty, #discount_amount, #discount_type', function() {
		update_sell_return_total()
	});

	function update_sell_return_total() {
		var net_return = 0;
		$('table#sell_return_table tbody tr').each(function() {
			var quantity = __read_number($(this).find('input.return_qty'));
			var unit_price = __read_number($(this).find('input.unit_price'));
			var subtotal = quantity * unit_price;
			$(this).find('.return_subtotal').text(__currency_trans_from_en(subtotal, true));
			net_return += subtotal;
		});
		var discount = 0;
		if ($('#discount_type').val() == 'fixed') {
			discount = __read_number($("#discount_amount"));
		} else if ($('#discount_type').val() == 'percentage') {
			var discount_percent = __read_number($("#discount_amount"));
			discount = __calculate_amount('percentage', discount_percent, net_return);
		}
		discounted_net_return = net_return - discount;

		var tax_percent = $('input#tax_percent').val();
		var total_tax = __calculate_amount('percentage', tax_percent, discounted_net_return);
		var net_return_inc_tax = total_tax + discounted_net_return;

		$('input#tax_amount').val(total_tax);
		$('span#total_return_discount').text(__currency_trans_from_en(discount, true));
		$('span#total_return_tax').text(__currency_trans_from_en(total_tax, true));
		$('span#net_return').text(__currency_trans_from_en(net_return_inc_tax, true));

		update_refund(net_return_inc_tax);
	}

	//Refund = return total minus what the customer still owes on the sale (and minus refunds paid by hand)
	var sale_due = {{ (float) $refund_info['sale_due'] }};
	var other_refunded = {{ (float) $refund_info['other_refunded'] }};
	var refund_touched = false; //user changed the tick or the amount himself
	var last_return_total = 0;
	//Editing a return: keep the refund saved last time unless it was the suggested one
	var refund_first_run = true;
	var is_existing_return = {{ ! empty($sell->return_parent) ? 'true' : 'false' }};
	var saved_refund = {{ ! empty($refund_payment) ? (float) $refund_payment->amount : 0 }};

	function update_refund(return_total) {
		if (typeof return_total == 'undefined') {
			return_total = last_return_total;
		}
		last_return_total = return_total;

		var max_refund = Math.max(0, return_total - other_refunded);
		var suggested = Math.min(Math.max(0, return_total - sale_due), max_refund);
		var adjusted = Math.min(return_total, sale_due);

		if (refund_first_run) {
			refund_first_run = false;
			if (is_existing_return && Math.abs(saved_refund - suggested) > 0.009) {
				refund_touched = true;
			}
		}

		if (! refund_touched) {
			$('#refund_now').prop('checked', true);
			__write_number($('#refund_amount'), suggested);
		} else if (__read_number($('#refund_amount')) > max_refund) {
			__write_number($('#refund_amount'), max_refund);
		}

		var refunding = $('#refund_now').is(':checked');
		$('.refund-field').prop('disabled', ! refunding);
		var refund = refunding ? __read_number($('#refund_amount')) : 0;

		var help = '';
		if (return_total <= 0) {
			help = 'Enter the return quantity.';
		} else {
			if (adjusted > 0) {
				help += '<strong>' + __currency_trans_from_en(adjusted, true) + '</strong> is taken off what the customer still owes on this sale. ';
			}
			if (refund > 0) {
				help += '<strong>' + __currency_trans_from_en(refund, true) + '</strong> is paid back to the customer (' + $('#refund_method option:selected').text() + ').';
			} else if (suggested > 0) {
				help += '<span class="text-danger">No refund: ' + __currency_trans_from_en(suggested, true) + ' stays as the customer\'s credit balance.</span>';
			}
		}
		$('#refund_help').html(help);
	}

	$(document).on('change', '#refund_now', function() {
		refund_touched = true;
		if ($(this).is(':checked') && __read_number($('#refund_amount')) <= 0) {
			__write_number($('#refund_amount'), Math.min(Math.max(0, last_return_total - sale_due), Math.max(0, last_return_total - other_refunded)));
		}
		update_refund();
	});
	$(document).on('change keyup', '#refund_amount', function() {
		refund_touched = true;
		update_refund();
	});
	$(document).on('change', '#refund_method', function() {
		update_refund();
	});
</script>
<style>
	.sr-refund-box { border: 1px solid #e5e7eb; border-radius: 10px; padding: 12px 15px 4px; margin-top: 15px; background: #f9fafb; }
	.sr-refund-sale { display: flex; flex-wrap: wrap; gap: 6px 22px; font-size: 13px; color: #374151; }
	.sr-refund-help { font-size: 13px; color: #374151; margin: 0 0 8px; }
</style>
@endsection