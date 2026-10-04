<!-- business information here -->
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta http-equiv="X-UA-Compatible" content="ie=edge">
        <!-- <link rel="stylesheet" href="style.css"> -->
        <title>Receipt-{{$receipt_details->invoice_no}}</title>
    </head>
    <body>
        <div class="ticket" style="  width: 100%;
		max-width: 100%;">
			<!-- Logo -->
			@if(!empty($receipt_details->logo))
			<img src="{{$receipt_details->logo}}"  style="  width: 100%; " class="img center-block">
			<!-- <br/> -->
		@endif
              <!-- <h2 style="text-align:center">USMANIA MALL</h2> -->
			 <!-- <p style="text-align:center">BESHAM ROAD BABO K.KHELA NEAR PSO PUMP <br>
			 <span style="text-align:center">0946-744577/03419140661/03464229566<span></p> -->

			<table class="table-info border-top">
				<tr>
					<th>{!! $receipt_details->invoice_no_prefix !!}</th>
					<td>
						{{$receipt_details->invoice_no}}
					</td>
				</tr>
				<tr>
					<th>{!! $receipt_details->date_label !!}</th>
					<td>
						{{$receipt_details->invoice_date}}
					</td>
				</tr>

				@if(!empty($receipt_details->due_date_label))
					<tr>
						<th>{{$receipt_details->due_date_label}}</th>
						<td>{{$receipt_details->due_date ?? ''}}</td>
					</tr>
				@endif

				@if(!empty($receipt_details->sales_person_label))
					<tr>
						<th>{{$receipt_details->sales_person_label}}</th>
					
						<td>{{$receipt_details->sales_person}}</td>
					</tr>
				@endif

				@if(!empty($receipt_details->brand_label) || !empty($receipt_details->repair_brand))
					<tr>
						<th>{{$receipt_details->brand_label}}</th>
					
						<td>{{$receipt_details->repair_brand}}</td>
					</tr>
				@endif

				
				

				

	        
	     

		         <!-- customer info -->
                 <tr>
                <th>
                    {{$receipt_details->customer_label}}
                </th>

                <td>
                    @if(!empty($receipt_details->customer_info))
                    {!! $receipt_details->customer_info !!}
                    @endif
                </td>
            </tr>
            @if(!empty($receipt_details->customer_number ))
            {!! $receipt_details->customer_number !!}
             @endif
				@if(!empty($receipt_details->client_id_label))
					<tr>
						<th>
							{{ $receipt_details->client_id_label }}
						</th>
						<td>
							{{ $receipt_details->client_id }}
						</td>
					</tr>
				@endif
				
			

			
				
			
			</table>

				
            <table style="padding-top: 5px !important" class="border-bottom width-100">
                <thead class="border-bottom-dotted">
                    <tr style="border: 2px solid #242424;">
                        <th class="serial_number">#</th>
                        <th class="description">
                        	{{$receipt_details->table_product_label}}
                        </th>
                        <th class="quantity text-right">
                           QTY
                        </th>
                        @if(empty($receipt_details->hide_price))
                        <th class="unit_price text-right">
                        	{{$receipt_details->table_unit_price_label}}
                        </th>
						<td class="quantityy text-right">
						Dis%.
					</td>
                        <th class="price text-right">{{$receipt_details->table_subtotal_label}}</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
				
                	@forelse($receipt_details->lines as $line)
	                    <tr style="border-top: 2px solid #242424; ">
	                        <td class="serial_number" style="border-bottom: 1px solid #242424; ">
	                        	{{$loop->iteration}}
	                        </td>
	                        <td class="description" style="border-bottom: 1px solid #242424; ">
	                        	{{$line['name']}} {{$line['product_variation']}} {{$line['variation']}} 
	                        	@if(!empty($line['sub_sku'])), {{$line['sub_sku']}} @endif @if(!empty($line['brand'])), {{$line['brand']}} @endif @if(!empty($line['cat_code'])), {{$line['cat_code']}}@endif
	                        	@if(!empty($line['product_custom_fields'])), {{$line['product_custom_fields']}} @endif
	                        	@if(!empty($line['sell_line_note']))({{$line['sell_line_note']}}) @endif 
	                        	@if(!empty($line['lot_number']))<br> {{$line['lot_number_label']}}:  {{$line['lot_number']}} @endif 
	                        	@if(!empty($line['product_expiry'])), {{$line['product_expiry_label']}}:  {{$line['product_expiry']}} @endif
	                        </td>
	                        <td style="border-bottom: 1px solid #242424; " class="quantity text-right">{{$line['quantity']}}</td>
	                        @if(empty($receipt_details->hide_price))
	                        <td style="border-bottom: 1px solid #242424; "class="unit_price text-right">{{$line['unit_price_before_discount']}}</td>
							<td style="border-bottom: 1px solid #242424; " class="quantityy text-right">
							{{$line['line_discount']}}
						
						   </td>
	                        <td style="border-bottom: 1px solid #242424; " class="price text-right">{{$line['line_total']}}</td>
	                        @endif
	                    </tr>
	                    @if(!empty($line['modifiers']))
							@foreach($line['modifiers'] as $modifier)
								<tr>
									<td>
										&nbsp;
									</td>
									<td>
			                            {{$modifier['name']}} {{$modifier['variation']}} 
			                            @if(!empty($modifier['sub_sku'])), {{$modifier['sub_sku']}} @endif @if(!empty($modifier['cat_code'])), {{$modifier['cat_code']}}@endif
			                            @if(!empty($modifier['sell_line_note']))({{$modifier['sell_line_note']}}) @endif 
			                        </td>
									<td class="text-right">{{$modifier['quantity']}} {{$modifier['units']}} </td>
									@if(empty($receipt_details->hide_price))
									<td class="text-right">{{$modifier['unit_price_inc_tax']}}</td>
									<td class="text-right">{{$modifier['line_total']}}</td>
									@endif
								</tr>
							@endforeach
						@endif
					@endforeach
					@if(!empty($receipt_details->total_quantity_label))
					<tr class="" >
						<td colspan="2" class="text-right">
							{!! $receipt_details->total_quantity_label !!}
						</td>
						<td colspan=""class="text-right">
							{{$receipt_details->total_quantity}}
						</td>
					</tr>
				@endif
                    <tr>
                    	<td colspan="5">&nbsp;</td>
					</tr>
			 
                </tbody>
            </table>

            <table class="border-bottom width-100">
           
			
				@if(empty($receipt_details->hide_price))
	                <tr>
	                    <th class="left text-right sub-headings">
	                    	{!! $receipt_details->subtotal_label !!}
	                    </th>
	                    <td class="width-50 text-right sub-headings">
	                    	{{$receipt_details->subtotal}}
	                    </td>
	                </tr> 

	                <!-- Shipping Charges -->
					@if(!empty($receipt_details->shipping_charges))
						<tr>
							<td class="left text-right">
								{!! $receipt_details->shipping_charges_label !!}
							</td>
							<td class="width-50 text-right">
								{{$receipt_details->shipping_charges}}
							</td>
						</tr>
					@endif

					<!-- Discount -->
					@if(!empty($receipt_details->discount) )
						<tr>
							<td class="width-50 text-right sub-headings">
								Discount:
							</td>

							<td class="width-50 text-right sub-headings">
								(-) {{$receipt_details->discount}}
							</td>
						</tr>
					@endif

					@if(!empty($receipt_details->reward_point_label) )
						<tr>
							<td class="width-50 text-right sub-headings">
								{!! $receipt_details->reward_point_label !!}
							</td>

							<td class="width-50 text-right sub-headings">
								(-) {{$receipt_details->reward_point_amount}}
							</td>
						</tr>
					@endif

					@if( !empty($receipt_details->tax) )
						<tr>
							<td class="width-50 text-right sub-headings">
								{!! $receipt_details->tax_label !!}
							</td>
							<td class="width-50 text-right sub-headings">
								(+) {{$receipt_details->tax}}
							</td>
						</tr>
					@endif

					<!-- @if( !empty($receipt_details->round_off_label) )
						<tr>
							<td class="width-50 text-right sub-headings">
								{!! $receipt_details->round_off_label !!}
							</td>
							<td class="width-50 text-right sub-headings">
								{{$receipt_details->round_off}}
							</td>
						</tr>
					@endif -->

			

					<!-- @if(!empty($receipt_details->payments))
						@foreach($receipt_details->payments as $payment)
							<tr>
								<td class="width-50 text-right sub-headings">{{$payment['method']}} ({{$payment['date']}}) </td>
								<td class="width-50 text-right sub-headings">{{$payment['amount']}}</td>
							</tr>
						@endforeach
					@endif -->

					<!-- Total Paid-->
					{{-- @if(!empty($receipt_details->total_paid))
						<tr>
							<td class="width-50 text-right sub-headings">
								{!! $receipt_details->total_paid_label !!}
							</td>
							<td class="width-50 text-right sub-headings">
								{{$receipt_details->total_paid}}
							</td>
						</tr>
					@endif --}}
					@if( !empty($receipt_details->previous_due) )
				<tr class="">
						<td class="width-50 text-right sub-headings">
							 Previous Due (پچھلی کھاتارقم)
							 
						</td>
						<td class="width-50 text-right sub-headings">
						{{$receipt_details->previous_due}}
						
						</td>
					</tr>
					@endif
					
					@if(!empty($receipt_details->due_subtotal))
					<tr style="border-top: 2px solid #242424; ">
						<th class="width-50 text-right sub-headings">
							{!! $receipt_details->total_label !!}
						</th>
						<td class="width-50 text-right sub-headings">
							{{$receipt_details->due_subtotal}}
						</td>
					</tr>
					@endif
					
					{{-- <tr>
						<th class="width-50 text-right sub-headings">
							{!! $receipt_details->total_label !!}
						</th>
						<td class="width-50 text-right sub-headings">
							{{$receipt_details->total}}
						</td>
					</tr> --}}
						<!-- Total Paid-->
						@if(!empty($receipt_details->total_paid))
						<tr>
							<td class="width-50 text-right sub-headings">
								{!! $receipt_details->total_paid_label !!}
							</td>
							<td class="width-50 text-right sub-headings">
								{{$receipt_details->total_paid}}
							</td>
						</tr>
					@endif
					@if(!empty($receipt_details->total_due))
						<tr>
							<td class="width-50 text-right sub-headings">
								{!! $receipt_details->total_due_label !!}
							</td>
							<td class="width-50 text-right sub-headings">
								{{$receipt_details->total_due}}
							</td>
						</tr>
					@endif

					@if(!empty($receipt_details->all_due))
						<tr style="border-top: 2px solid #242424; ">
							<td class="width-50 text-right sub-headings">
								{!! $receipt_details->all_bal_label !!}
							</td>
							<td class="width-50 text-right sub-headings">
								{{$receipt_details->all_due}}
							</td>
						</tr>
					@endif
				@endif
            </table>
			
          
			@if(!empty($receipt_details->footer_text))
				<p class="centered">
					{!! $receipt_details->footer_text !!}
					<span style="text-align:center">Skyline Solutions Contact US:03428927305</span>
				</p>
			@endif
		
            @if(empty($receipt_details->hide_price))
	            <!-- tax -->
	            @if(!empty($receipt_details->taxes))
	            	<table class="border-bottom width-100">
	            		@foreach($receipt_details->taxes as $key => $val)
	            			<tr>
	            				<td class="left">{{$key}}</td>
	            				<td class="right">{{$val}}</td>
	            			</tr>
	            		@endforeach
	            	</table>
	            @endif
            @endif


            @if(!empty($receipt_details->additional_notes))
	            <p class="centered" >
	            	{{$receipt_details->additional_notes}}
	            </p>
            @endif

            {{-- Barcode --}}
			@if($receipt_details->show_barcode)
				<br/>
				<!-- <img class="center-block" src="data:image/png;base64,{{DNS1D::getBarcodePNG($receipt_details->invoice_no, 'C128', 2,30,array(39, 48, 54), true)}}"> -->
			@endif
		
	
		</p>
		
        </div>
        <!-- <button id="btnPrint" class="hidden-print">Print</button>
        <script src="script.js"></script> -->
    </body>
</html>

<style type="text/css">

@page{
	margin: 0px;
	padding: 1px;
	font-size:14px;
	font-weight: 700;
}
@media print {
	* {
		margin: 0px;
		padding: 1px;
    	font-size:14px;
		font-weight: 700;
    	
	}
	.ticket {
    width: 42px;
    max-width: 42px;
}

.headings{
	font-size: 10px;
	font-weight: 700;
	text-transform: uppercase;
}

.sub-headings{
	font-size: 14px;
	font-weight: 1000;
}

.border-top{
    border-top: 1px solid #242424;
}
.border-bottom{
	border-bottom: 1px solid #242424;
}

.border-bottom-dotted{
	border-bottom: 1px dotted darkgray;
}

td.serial_number, th.serial_number{
	width: 5%;
    max-width: 5%;
	font-weight: 700;
}

td.description,
th.description {
    width: 35%;
    max-width: 35%;
    word-break: break-all;
	font-weight: 700;
}

td.quantity,
th.quantity {
    width: 5%;
    max-width: 5%;
	font-weight: 700;
    
}
td.quantityy,
th.quantityy {
    width: 10%;
    max-width: 10%;
	font-weight: 700;
    
}
td.unit_price, th.unit_price{
	width: 10%;
    max-width: 10%;
	font-weight: 700;
    
}

td.price,
th.price {
    width: 10%;
    max-width: 10%;
    font-weight: 700;
}

.centered {
    text-align: center;
    align-content: center;
}



img {
    max-width: inherit;
    width: auto;
}

    .hidden-print,
    .hidden-print * {
        display: none !important;
    }
}
.table-info {
	width: 100%;
}
.table-info tr:first-child td, .table-info tr:first-child th {
	padding-top: 8px;
}
.table-info th {
	text-align: left;
}
.table-info td {
	text-align: right;
}
.logo {
	float: left;
	width:35%;
	padding: 10px;
}

.text-with-image {
	float: left;
	width:65%;
}
.text-box {
	width: 100%;
	height: auto;
}
</style>