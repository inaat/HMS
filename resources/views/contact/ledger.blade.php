<!-- app css -->
@if(!empty($for_pdf))
	<style>
	/* Basic Reset */
* {
                   font-family: 'Roboto', sans-serif;

    box-sizing: border-box;
    margin: 1px;
}
 body {
                font-family: 'Roboto', sans-serif;
                margin: 0;
                padding: 0;
                color: #333;
                font-size: 12px;
                line-height: 1.6;
                background-color: #f9f9f9;
            }
/* Layout */
.width-100 { width: 100%; }
.width-50 { width: 50%; }
.f-left { float: left; }

/* Text Alignment */
.text-right { text-align: right; }
.text-center { text-align: center; }
.text-left { text-align: left; }
.align-right { text-align: right; }
.align-left { text-align: left; }

/* Spacing */
.p-4 { padding: 1rem; }
.mb-0 { margin-bottom: 0; }

/* Table Styles */
.table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 1rem;
    table-layout: fixed;
}

.table-pdf {
    font-size: 12px;
}

.table-pdf td,
.table-pdf th {
    padding: 5px;
    word-wrap: break-word;
    overflow-wrap: break-word;
    hyphens: auto;
    max-width: 1px; /* Forces cell to honor width constraints */
}

.td-border td {
    border: 0.5px solid #ddd;
}

.table-striped tr:nth-child(even) {
    background-color: #f9f9f9;
}

/* Row Styles */
.row-border {
    border-bottom: 0.5px solid #000;
}

/* Header Styles */
.blue-heading {
    background-color: #007bff;
    color: white;
    padding: 8px;
    margin: 1px;
}

/* Business Info Section */
.business-info {
    margin: 1px 1px 20px 1px;
}

/* Summary Box Styles */
.summary-box {
    border: 1px solid #000;
    padding: 10px;
    margin: 1px 1px 15px 1px;
}

/* Utility Classes */
.ws-nowrap {
    white-space: normal;
    word-break: break-word;
}

/* Print Specific */
@page {
    margin: 15px;
}

/* Custom Classes for Ledger */
.ledger-header {
    font-weight: bold;
    background-color: #f4f4f4;
}

.summary-section {
    margin: 20px 1px 1px 1px;
    page-break-inside: avoid;
}

/* Table Header Colors */
.table th.row-border.blue-heading {
    background-color: #007bff;
    color: white;
    font-weight: bold;
    padding: 8px;
    word-break: break-word;
}

/* Amount Columns */
.debit-amount,
.credit-amount,
.balance-amount {
    font-family: 'DejaVu Sans Mono', monospace;
    white-space: normal;
    word-break: break-word;
}

/* Status Colors */
.payment-status-paid {
    color: #28a745;
}

.payment-status-due {
    color: #dc3545;
}

/* Responsive Tables */
.table-responsive {
    overflow-x: auto;
    min-height: 0.01%;
    margin: 1px;
}

/* Helper Classes */
.no-border {
    border: none !important;
}

.odd {
    background-color: #f8f9fa;
}

/* Column-specific widths */
.table td:nth-child(1) { width: 18%; } /* Date */
.table td:nth-child(2) { width: 9%; }  /* Ref No */
.table td:nth-child(3) { width: 8%; }  /* Type */
.table td:nth-child(4) { width: 10%; } /* Location */
.table td:nth-child(5) { width: 5%; }  /* Payment Status */
.table td:nth-child(6) { width: 10%; } /* Debit */
.table td:nth-child(7) { width: 10%; } /* Credit */
.table td:nth-child(8) { width: 10%; } /* Balance */
.table td:nth-child(9) { width: 5%; }  /* Payment Method */
.table td:nth-child(10) { width: 15%; } /* Others */
	</style>
@endif
<div class="col-md-12 col-sm-12 @if(!empty($for_pdf)) width-100 align-right @endif">
        <p class="text-right align-right"><strong>{{$contact->business->name}}</strong>
        	<br>
        	@if(!empty($location))
        		{!! $location->location_address !!}
        	@else
        		{!! $contact->business->business_address !!}
        	@endif
        </p>
</div>
<div class="col-md-6 col-sm-6 col-xs-6 @if(!empty($for_pdf)) width-50 f-left @endif">
	<p class="blue-heading p-4 width-50">@lang('lang_v1.to'):</p>
	<p><strong>{{$contact->name}}</strong><br> {!! $contact->contact_address !!} @if(!empty($contact->email)) <br>@lang('business.email'): {{$contact->email}} @endif
	<br>@lang('contact.mobile'): {{$contact->mobile}}
	@if(!empty($contact->tax_number)) <br>@lang('contact.tax_no'): {{$contact->tax_number}} @endif
</p>
</div>

<div class="col-md-6 col-sm-6 col-xs-6 text-right align-right @if(!empty($for_pdf)) width-50 f-left @endif">
		<h3 class="mb-0 blue-heading p-4">@lang('lang_v1.account_summary')</h3>
	<div style="border: 1px solid #000; padding: 10px;">
		<i id="show_info_btn" class="fa fa-info-circle text-info" style="margin-right: 10px; margin-top:4px;"></i>
		<b>{{$ledger_details['start_date']}} @lang('lang_v1.to') {{$ledger_details['end_date']}}</b>
		<table class="table table-condensed text-left align-left no-border @if(!empty($for_pdf)) table-pdf @endif">
        
        {{-- All summary_hidden class is commented --}}
        {{-- <tr class="summary_hidden">
				<td>@lang('lang_v1.opening_balance')</td>
				<td class="align-right">@format_currency($ledger_details['beginning_balance'])</td>
			</tr> --}}
		@if( $contact->type == 'supplier' || $contact->type == 'both')
			<tr>
				<td>@lang('report.total_purchase')</td>
				<td class="align-right">@format_currency($ledger_details['total_purchase'])</td>
			</tr>
		@endif
		@if( $contact->type == 'customer' || $contact->type == 'both')
			<tr>
				<td>@lang('lang_v1.total_invoice')</td>
				<td class="align-right">@format_currency($ledger_details['total_invoice'])</td>
			</tr>
		@endif
		<tr>
			<td>@lang('sale.total_paid')</td>
			<td class="align-right">@format_currency($ledger_details['total_paid'])</td>
		</tr>
		{{-- <tr class="summary_hidden">
			<td>@lang('lang_v1.advance_balance')</td>
			<td class="align-right">@format_currency($contact->balance - $ledger_details['total_reverse_payment'])</td>
		</tr> --}}
		@if($ledger_details['ledger_discount'] > 0)
			<tr>
				<td>@lang('lang_v1.ledger_discount')</td>
				<td class="align-right">@format_currency($ledger_details['ledger_discount'])</td>
			</tr>
		@endif
		{{-- <tr class="summary_hidden">
			<td><strong>@lang('lang_v1.balance_due')</strong></td>
			<td class="align-right">@format_currency($ledger_details['balance_due'] - $ledger_details['ledger_discount'])</td>
		</tr> --}}
		</table>
	</div>

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


<div class="col-md-12 col-sm-12 @if(!empty($for_pdf)) width-100 @endif">
	<p class="text-center" style="text-align: center;"><strong>@lang('lang_v1.ledger_table_heading', ['start_date' => $ledger_details['start_date'], 'end_date' => $ledger_details['end_date']])</strong></p>
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
				<th width="10%" class="text-center summary_hiddend">@lang('lang_v1.balance')</th>
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
					<td>{{$data['type']}}</td>
					<td>{{$data['location']}}</td>
					<td>{{$data['payment_status']}}</td>
					{{--<td class="ws-nowrap align-right">@if($data['total'] !== '') @format_currency($data['total']) @endif</td>--}}
					<td class="ws-nowrap align-right">@if($data['debit'] != '') @format_currency($data['debit']) @endif</td>
					<td class="ws-nowrap align-right">@if($data['credit'] != '') @format_currency($data['credit']) @endif</td>
					<td class="ws-nowrap align-right summary_hiddendd">{{$data['balance']}}</td>
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