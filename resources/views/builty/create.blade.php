@extends('layouts.app')
@section('title', __('Add Builty'))

@section('content')

<!-- Content Header (Page header) -->
<section class="content-header">
    <h1>@lang('Add Builty') <i class="fa fa-keyboard-o hover-q text-muted" aria-hidden="true" data-container="body" data-toggle="popover" data-placement="bottom" data-content="@include('purchase.partials.keyboard_shortcuts_details')" data-html="true" data-trigger="hover" data-original-title="" title=""></i></h1>
</section>

<!-- Main content -->
<section class="content">

	<!-- Page level currency setting -->
	<input type="hidden" id="p_code" value="{{$currency_details->code}}">
	<input type="hidden" id="p_symbol" value="{{$currency_details->symbol}}">
	<input type="hidden" id="p_thousand" value="{{$currency_details->thousand_separator}}">
	<input type="hidden" id="p_decimal" value="{{$currency_details->decimal_separator}}">

	@include('layouts.partials.error')

	{!! Form::open(['url' => action([\App\Http\Controllers\BuiltyController::class,'store']), 'method' => 'post', 'id' => 'add_purchase_form', 'files' => true ]) !!}
	@component('components.widget', ['class' => 'box-primary'])
		<div class="row">
			<div class="col-sm-3">
				<div class="form-group">
					{!! Form::label('goodstransportcompany_id', __('Transport Companies') . ':*') !!}
					<div class="input-group">

						{!! Form::select('goodstransportcompany_id', $TransportCompany, null, ['placeholder' => __('messages.please_select'), 'class' => 'form-control select2', 'required']); !!}
						<span class="input-group-btn">
							<button type="button" @if(!auth()->user()->can('brand.create')) disabled @endif class="btn btn-default bg-white btn-flat btn-modal" data-href="{{action([\App\Http\Controllers\TransportCompanyController::class, 'create'], ['quick_add' => true])}}" title="@lang('brand.add_brand')" data-container=".view_modal"><i class="fa fa-plus-circle text-primary fa-lg"></i></button>
						</span>
					</div>
				</div>
			</div>
			<div class="col-sm-3 ">
				<div class="form-group">
					{!! Form::label('biulty_number', __('Builty No'). ':*') !!}
					{!! Form::text('biulty_number', null, ['class' => 'form-control', 'required']); !!}
				</div>
			</div>
			<div class="col-sm-3 ">
				<div class="form-group">
					{!! Form::label('recevied_date', __('Received Date') . ':*') !!}
					<div class="input-group">
						<span class="input-group-addon">
							<i class="fa fa-calendar"></i>
						</span>
						{!! Form::text('recevied_date', @format_datetime('now'), ['class' => 'form-control', 'readonly', 'required']); !!}
					</div>
				</div>
			</div>
			<div class="col-sm-3">
                <div class="form-group">
                    {!! Form::label('document', __('purchase.attach_document') . ':') !!}
                    {!! Form::file('document', ['id' => 'upload_document']); !!}
                    <p class="help-block">@lang('purchase.max_file_size', ['size' => (config('constants.document_size_limit') / 1000000)])</p>
                </div>
            </div>

			<div class="clearfix"></div>
			<div class="col-sm-3 ">
				<div class="form-group">
					{!! Form::label('biulty_date', __('Builty Date') . ':*') !!}
					<div class="input-group">
						<span class="input-group-addon">
							<i class="fa fa-calendar"></i>
						</span>

						{!! Form::text('biulty_date', null, ['class' => 'form-control  builty_date', 'required']); !!}
					</div>
				</div>
			</div>
				<div class="col-sm-3 ">
					<div class="form-group">
						{!! Form::label('from_address', __('From') . ':*') !!}
						<div class="input-group">
							<span class="input-group-addon">
							</span>
							{!! Form::text('from_address', null, ['class' => 'form-control', 'required']); !!}
						</div>
					</div>
				</div>



				<div class="col-sm-3 ">
					<div class="form-group">
						{!! Form::label('to_address', __('To') . ':*') !!}
						<div class="input-group">
							<span class="input-group-addon">
							</span>
							{!! Form::text('to_address',null, ['class' => 'form-control', 'required']); !!}
						</div>
					</div>
				</div>

				<div class="col-sm-3 ">
					<div class="form-group">
						{!! Form::label('sender_name', __('Sender Name') . ':*') !!}
						<div class="input-group">
							<span class="input-group-addon">
								<i class="fa fa-user"></i>

							</span>
							{!! Form::text('sender_name',null, ['class' => 'form-control', 'required']); !!}
						</div>
					</div>
				</div>

		</div>
	@endcomponent

	@component('components.widget', ['class' => 'box-primary'])

		<div class="row">
			<div class="col-sm-12">
				<div class="table-responsive">
					<table class="table table-condensed table-bordered table-th-green text-center table-striped" id="purchase_entry_table">
						<thead>
                         <tr>
						 <th>Item Quantity</th>
						 <th>Item Name</th>
						 <th>weight</th>
						 <th>Amount</th>
                        <th><a href="#" class="addRow"><i class="glyphicon glyphicon-plus"></i></a></th>
                         </tr>
                     </thead>
                     <tbody id="TextBoxContainer">
         <tr>
         <td>{!! Form::number('item[' . 0 . '][item_quantity]', null, ['class' => 'form-control', 'required']); !!}</td>
         <td>{!! Form::text('item[' . 0 . '][item]', null, ['class' => 'form-control', 'required']); !!}</td>
		 <td>{!! Form::number('item[' . 0 . '][weight]', null, ['class' => 'form-control', 'required']); !!}</td>
		 <td>{!! Form::number('item[' . 0 . '][charges]', null, ['class' => 'form-control chargesamount', 'required']); !!}</td>
         </tr>

                     </tbody>


					</table>
				</div>
				<hr/>
				<div class="pull-right col-md-5">
					<table class="pull-right col-md-12">

						<tr>
							<th class="col-md-7 text-right">@lang( 'purchase.net_total_amount' ):</th>
							<td class="col-md-5 text-left">
								<span id="total_subtotal" class="display_currency"></span>
								<!-- This is total before purchase tax-->
								<input type="hidden" id="total_subtotal_input" value=0  name="amount">
							</td>
						</tr>

					</table>
				</div>

				<hr/>
				<div class="pull-right col-md-5">
					<table class="pull-right col-md-12">

						<tr>
							<td class="col-md-5 text-left">
								<button type="sumbit" id="submit_purchase_form" class="btn btn-primary pull-right btn-flat">@lang('messages.save')</button>

							</td>
						</tr>

					</table>
				</div>
			</div>
			</div>
		</div>
	@endcomponent








{!! Form::close() !!}
</section>

@endsection

@section('javascript')	<script >
//Quick add brand
$('.builty_date').datepicker({
	autoclose: true,
    format: datepicker_date_format,
});

$(document).on('change', '.chargesamount', function() {
	update_table_total();

});

function update_table_total() {

    var total_subtotal = 0;

    $('#TextBoxContainer')
        .find('tr')
        .each(function() {

            total_subtotal += __read_number($(this).find('.chargesamount'), true);
        });


    $('#total_subtotal').text(__currency_trans_from_en(total_subtotal, true, true));
    __write_number($('input#total_subtotal_input'), total_subtotal, true);
}

$(document).on('submit', 'form#quick_add_transport_form', function(e) {
    e.preventDefault();
    $(this)
        .find('button[type="submit"]')
        .attr('disabled', true);
    var data = $(this).serialize();

    $.ajax({
        method: 'POST',
        url: $(this).attr('action'),
        dataType: 'json',
        data: data,
        success: function(result) {
            if (result.success == true) {
                var newOption = new Option(result.data.name, result.data.id, true, true);
                // Append it to the select
                $('#transportcompany_id')
                    .append(newOption)
                    .trigger('change');
                $('div.view_modal').modal('hide');
                toastr.success(result.msg);
            } else {
                toastr.error(result.msg);
            }
        },
    });
});</script>

<script>
	$(document).ready(function() {


		  $(".addRow").bind("click", function () {
			var div = $("<tr />");
			div.html(GetDynamicTextBox(""));
			 $("#TextBoxContainer").append(div);



		});
		$("body").on("click", ".remove", function () {
			$(this).closest("tr").remove();
			update_table_total();
		});
	});

	function GetDynamicTextBox(value) {
		  var rowCount = $('#TextBoxContainer tr').length;
		  rowCount -=1;

		  rowCount +=1


			return '<td>{!! Form::number('item[' . '+rowCount+'. '][item_quantity]', null, ['class' => 'form-control', 'required']); !!}</td>' +
			'<td>{!! Form::text('item[' . '+rowCount+' . '][item]', null, ['class' => 'form-control', 'required']); !!}</td>' +
			'<td>{!! Form::number('item[' . '+rowCount+' . '][weight]', null, ['class' => 'form-control', 'required']); !!}</td>'+
			'<td>{!! Form::number('item[' . '+rowCount+' . '][charges]', null, ['class' => 'form-control chargesamount', 'required']); !!}</td>'+
			 '<td><a href="#" class="btn btn-danger remove"><i class="glyphicon glyphicon-remove"></i></a></td>'

	}
		</script>

@endsection
