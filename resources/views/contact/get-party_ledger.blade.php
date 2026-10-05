<!-- app css -->

 <div class="row">
 	<table style="margin: auto;">
		<tr><th class="text-center" style="border-top:hidden; font-size: 22px;">{{__('lang_v1.statement')}}</th></tr>
		<tr><th class="text-center">@lang('lang_v1.date')</th></tr>
		<tr><td class="text-center">{{$ledger_details['start_date']}} @lang('lang_v1.to') {{$ledger_details['end_date']}}</td></tr>
	</table>
     <div class="col-md-6 col-sm-6 col-xs-6  width-50 f-left " style="margin-top: 20px;">


	<table class="table">
		<tr>
			<th style="text-align: left;">@lang('lang_v1.to')</th>
		</tr>
		<tr>
			<td>
				<p><strong>{{$contact->name}}</strong><br> {!! $contact->contact_address !!} @if(!empty($contact->email)) <br>@lang('business.email'): {{$contact->email}} @endif
					<br>@lang('contact.mobile'): {{$contact->mobile}}
					@if(!empty($contact->tax_number)) <br>@lang('contact.tax_no'): {{$contact->tax_number}} @endif
				</p>
			</td>
		</tr>
	</table>	
</div>
<div class="col-md-6 col-sm-6 col-xs-6 @if(!empty($for_pdf)) width-50 f-right @endif" style="margin-top: 20px;">
			<div style="border: 1px solid #000; padding: 10px;">
		<b> @lang('lang_v1.overall_summary') </b>
		<table class="table table-condensed text-left align-left no-border @if(!empty($for_pdf)) table-pdf @endif">
		
			@if( $contact->type == 'supplier' || $contact->type == 'both')
				<tr>
					<td>@lang('report.total_purchase')</td>
					<td class="align-right">@format_currency($ledger_details['all_total_purchase'])</td>
				</tr>
			@endif

			@if( $contact->type == 'customer' || $contact->type == 'both')
				<tr>
					<td>@lang('lang_v1.total_invoice')</td>
					<td class="align-right">@format_currency($ledger_details['all_total_invoice'])</td>
				</tr>
			@endif

            @if( $contact->type == 'customer' || $contact->type == 'both')
                <tr>
                    <td>@lang('sale.total_paid')</td>
                    <td class="align-right">@format_currency($ledger_details['all_invoice_paid'])</td>
                </tr>
            @endif

            @if( $contact->type == 'supplier' || $contact->type == 'both')
                <tr>
                    <td>@lang('sale.total_paid')</td>
                    <td class="align-right">@format_currency($ledger_details['all_purchase_paid'])</td>
                </tr>
            @endif

			<tr >
				<td><strong>@lang('lang_v1.balance_due')</strong></td>
				<td class="align-right">@format_currency($ledger_details['all_balance_due'])</td>
			</tr>
		</table>
	</div>

</div>
        
</div>
<div class="col-md-12 col-sm-12  width-100 ">
	<div class="table-responsive">
	<table class="table table-striped @if(!empty($for_pdf)) table-pdf td-border @endif" id="ledger_table">
		<thead>
			<tr class="row-border blue-heading">
				<th width="18%" class="text-center">@lang('lang_v1.date')</th>
				<th width="9%" class="text-center">@lang('purchase.ref_no')</th>
				<th width="8%" class="text-center">@lang('lang_v1.type')</th>
				<th width="10%" class="text-center">@lang('sale.location')</th>
				<th width="5%" class="text-center">@lang('sale.payment_status')</th>
				{{--<th width="10%" class="text-center">@lang('sale.total')</th>--}}
				<th width="10%" class="text-center">@lang('account.debit')</th>
				<th width="10%" class="text-center">@lang('account.credit')</th>
				<th width="10%" class="text-center summary_hiddenjj">@lang('lang_v1.balance')</th>
				<th width="5%" class="text-center">@lang('lang_v1.payment_method')</th>
				<th width="15%" class="text-center">@lang('report.others')</th>
			</tr>
		</thead>
		<tbody>
			@foreach($ledger_details['ledger'] as $data)

                {{-- @if($data['type'] == 'Opening Balance') 
                    @continue
                @endif  --}}

				<tr @if(!empty($for_pdf) && $loop->iteration % 2 == 0) class="odd" @endif>
					<td class="row-border">{{@format_datetime($data['date'])}}</td>
					<td>{{$data['ref_no']}}</td>
					<td>{{$data['type']}}@if(!empty($data['type_badge']))<br>{!! $data['type_badge'] !!}@endif</td>
					<td>{{$data['location']}}</td>
					<td>{{$data['payment_status']}}</td>
					{{--<td class="ws-nowrap align-right">@if($data['total'] !== '') @format_currency($data['total']) @endif</td>--}}
					<td class="ws-nowrap align-right">@if($data['debit'] != '') @format_currency($data['debit']) @endif</td>
					<td class="ws-nowrap align-right">@if($data['credit'] != '') @format_currency($data['credit']) @endif</td>
					<td class="ws-nowrap align-right summary_hiddenkk">{{$data['balance']}}</td>
					<td>{{$data['payment_method']}}</td>
					<td>
						{!! $data['others'] !!}

						@if(!empty($is_admin) && !empty($data['transaction_id']) && $data['transaction_type'] == 'ledger_discount')
							<br>
							<button type="button" class="tw-dw-btn tw-dw-btn-outline tw-dw-btn-xs tw-dw-btn-error delete_ledger_discount" data-href="{{action([\App\Http\Controllers\LedgerDiscountController::class, 'destroy'], ['ledger_discount' => $data['transaction_id']])}}"><i class="fas fa-trash"></i></button>
							<button type="button" class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-primary btn-modal" data-href="{{action([\App\Http\Controllers\LedgerDiscountController::class, 'edit'], ['ledger_discount' => $data['transaction_id']])}}" data-container="#edit_ledger_discount_modal"><i class="fas fa-edit"></i></button>
						@endif
					</td>
				</tr>
			@endforeach
		</tbody>
	</table>
	</div>
</div>

<script>
	$(document).ready(function() {

		// Toggle visibility on button click
		$('.summary_hidden').hide();

		$('#show_info_btn').click(function() {
			$('.summary_hidden').toggle('slow');
		});
	});
	</script>