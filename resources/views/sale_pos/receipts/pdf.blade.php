<!-- business information here -->
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta http-equiv="X-UA-Compatible" content="ie=edge">
        <!-- <link rel="stylesheet" href="style.css"> -->
        <title>Receipt-{{$data->invoice_no}}</title>
   
   <style>
            @page {
                margin: 0px;
                padding: 0px;
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
            .ticket {
                width: 100%;
                max-width: 800px;
                margin: 20px auto;
                padding: 15px;
                box-sizing: border-box;
                background: #ffffff;
                border: 1px solid #ddd;
                border-radius: 8px;
                box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            }
            .logo {
                text-align: center;
                margin-bottom: 15px;
            }
            .logo img {
                max-height: 120px;
            }
            .header {
                text-align: center;
                margin-bottom: 20px;
            }
            .header p {
                margin: 0;
                font-size: 14px;
                color: #555;
            }
            .header span {
                font-size: 22px;
                font-weight: 700;
                color: #000;
            }
            table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 15px;
            }
            th, td {
                text-align: left;
                padding: 8px;
                border: 1px solid #ddd;
            }
            th {
                background-color: #f4f4f4;
                font-weight: 600;
            }
            .text-right {
                text-align: right;
            }
            .text-center {
                text-align: center;
            }
            .sub-headings {
                font-size: 14px;
                font-weight: bold;
                background-color: #f9f9f9;
            }
            .summary-table th, .summary-table td {
                padding: 10px;
            }
            .footer {
                text-align: center;
                font-size: 10px;
                color: #555;
                margin-top: 20px;
            }
            .barcode {
                margin-top: 15px;
                text-align: center;
            }
            .notes {
                margin-top: 10px;
                text-align: center;
                font-size: 12px;
                color: #555;
            }
        </style>
    </head>
    <body>
        <div class="ticket" style="  width: 100%;
		max-width: 100%;">
			<!-- Logo -->
			@if(!empty($data->logo))
			<img src="{{$data->logo}}"  style=" width: 100%; " class="img center-block">
			<!-- <br/> -->
		@else
			{{-- No logo: the shop's own name, address and phone from Settings > Business Locations. --}}
			<h2 style="text-align:center; margin:0 0 4px;">{{ $data->display_name }}</h2>
			<p style="text-align:center; margin:0 0 6px;">
				@if(!empty($data->address)){!! $data->address !!}<br>@endif
				@if(!empty($data->contact)){!! $data->contact !!}@endif
			</p>
		@endif

			
	
						

		    
        
			<table class="table-info border-top">
				<tr>
					<th>{!! $data->invoice_no_prefix !!}</th>
					<td>
						{{$data->invoice_no}}
					</td>
				</tr>
				<tr>
					<th>{!! $data->date_label !!}</th>
					<td>
						{{$data->invoice_date}}
					</td>
				</tr>

				@if(!empty($data->due_date_label))
					<tr>
						<th>{{$data->due_date_label}}</th>
						<td>{{$data->due_date ?? ''}}</td>
					</tr>
				@endif

				@if(!empty($data->sales_person_label))
					<tr>
						<th>{{$data->sales_person_label}}</th>
					
						<td>{{$data->sales_person}}</td>
					</tr>
				@endif

				@if(!empty($data->commission_agent))
					<tr>
						<th>{{$data->commission_agent_label}}</th>
						<td>{{$data->commission_agent}}</td>
					</tr>
				@endif

				@if(!empty($data->serial_no_label) || !empty($data->repair_serial_no))
					<tr>
						<th>{{$data->serial_no_label}}</th>
					
						<td>{{$data->repair_serial_no}}</td>
					</tr>
				@endif

				@if(!empty($data->repair_status_label) || !empty($data->repair_status))
					<tr>
						<th>
							{!! $data->repair_status_label !!}
						</th>
						<td>
							{{$data->repair_status}}
						</td>
					</tr>
	        	@endif

	        	@if(!empty($data->repair_warranty_label) || !empty($data->repair_warranty))
		        	<tr>
		        		<th>
		        			{!! $data->repair_warranty_label !!}
		        		</th>
		        		<td>
		        			{{$data->repair_warranty}}
		        		</td>
		        	</tr>
	        	@endif

	        	<!-- Waiter info -->
				@if(!empty($data->service_staff_label) || !empty($data->service_staff))
		        	<tr>
		        		<th>
		        			{!! $data->service_staff_label !!}
		        		</th>
		        		<td>
		        			{{$data->service_staff}}
						</td>
		        	</tr>
		        @endif

		        @if(!empty($data->table_label) || !empty($data->table))
		        	<tr>
		        		<th>
		        			@if(!empty($data->table_label))
								<b>{!! $data->table_label !!}</b>
							@endif
		        		</th>
		        		<td>
		        			{{$data->table}}
		        		</td>
		        	</tr>
		        @endif

		        <!-- customer info -->
		        <tr>
		        	<th>
		        		{{$data->customer_label}}
		        	</th>

		        	<td>
		        		{{ $data->customer_name }}

		        		{{-- 
		        		@if(!empty($data->customer_info))
							{!! $data->customer_info !!}
						@endif
						--}}
		        	</td>
		        </tr>
		        <tr>
		        	<th>
					Customer Mobile
		        	</th>

		        	<td>
		        		{{ $data->customer_mobile }}

		        		{{-- 
		        		@if(!empty($data->customer_info))
							{!! $data->customer_info !!}
						@endif
						--}}
		        	</td>
		        </tr>
				
				@if(!empty($data->client_id_label))
					<tr>
						<th>
							{{ $data->client_id_label }}
						</th>
						<td>
							{{ $data->client_id }}
						</td>
					</tr>
				@endif
				
				@if(!empty($data->customer_tax_label))
					<tr>
						<th>
							{{ $data->customer_tax_label }}
						</th>
						<td>
							{{ $data->customer_tax_number }}
						</td>
					</tr>
				@endif

				@if(!empty($data->customer_custom_fields))
					<tr>
						<td colspan="2">
							{{ $data->customer_custom_fields }}
						</td>
					</tr>
				@endif
				
				@if(!empty($data->customer_rp_label))
					<tr>
						<th>
							{{ $data->customer_rp_label }}
						</th>
						<td>
							{{ $data->customer_total_rp }}
						</td>
					</tr>
				@endif
			</table>

				
            <table style="padding-top: 5px !important" class="border-bottom width-100">
                <thead class="border-bottom-dotted">
                    <tr style="">
                        <th class="serial_number">#</th>
                        <th class="description" >
                        	{{$data->table_product_label}}
                        </th>
                        <th class="quantity text-right">
                           QTY
                        </th>
                        @if(empty($data->hide_price))
                        <th class="unit_price text-right">
                        	<!-- {{$data->table_unit_price_label}} -->
							U Price
                        </th>
                        <th class="unit_price text-right">
                        	<!-- {{$data->table_unit_price_label}} -->
							{{$data->line_discount_label}}
                        </th>
					
                        <th class="price text-right">{{$data->table_subtotal_label}}</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
				
                	@forelse($data->lines as $line)
	                    <tr style="border: 2px solid #242424; ">
	                        <td class="serial_number" style="border-bottom: 1px solid #242424; ">
	                        	{{$loop->iteration}}
	                        </td>
	                        <td class="description" style="border: 2px solid #242424;  " >
	                        	
	                        	<b><span style="">@if(!empty($line['product_custom_fields'])) {{$line['product_custom_fields']}}</span><b>
								
								@else
								{{$line['name']}} {{$line['product_variation']}} {{$line['variation']}} 
	                        	@if(!empty($line['sub_sku'])), {{$line['sub_sku']}} @endif @if(!empty($line['brand'])), {{$line['brand']}} @endif @if(!empty($line['cat_code'])), {{$line['cat_code']}}@endif
								@if(!empty($line['sell_line_note']))({{$line['sell_line_note']}}) @endif 
	                        	@if(!empty($line['lot_number']))<br> {{$line['lot_number_label']}}:  {{$line['lot_number']}} @endif 
	                        	@if(!empty($line['product_expiry'])), {{$line['product_expiry_label']}}:  {{$line['product_expiry']}} @endif

								@endif
	                        	
	                        </td>
	                        <td style="border: 1px solid #242424; " class="quantity text-right">{{$line['quantity']}} {{$line['units']}}</td>
	                        @if(empty($data->hide_price))
	                        <td style="border: 1px solid #242424; "class="unit_price text-right">{{$line['unit_price_before_discount']}}</td>
							<td class="text-right">
										{{$line['total_line_discount'] ?? 0}}
										@if(!empty($line['line_discount_percent']))
											({{$line['line_discount_percent']}}%)
										@endif
									</td>
	                        <td style="border: 1px solid #242424; " class="price text-right">{{$line['line_total']}}</td>
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
									@if(empty($data->hide_price))
									<td class="text-right">{{$modifier['unit_price_inc_tax']}}</td>
									
									<td class="text-right">{{$modifier['line_total']}}</td>
									@endif
								</tr>
							@endforeach
						@endif
					@endforeach
					@if(!empty($data->total_quantity_label))
					<tr class="" >
						<td colspan="2" class="text-right">
							{!! $data->total_quantity_label !!}
						</td>
						<td colspan="2"class="text-right">
							{{$data->total_quantity}}
						</td>
						<td colspan="2"class="text-right">
							
						</td>
						<td colspan="2"class="text-right">
							
						</td>
						<td colspan="2"class="text-right">
						
						</td>
					</tr>
				@endif
               
			 
                </tbody>
            </table>

            <table class="border-bottom width-100" >
            
                <tr>
                    <th class="left text-right sub-headings">
                    	{!! $data->subtotal_label !!}
                    </th>
                    <td class="width-50 text-right sub-headings">
                    	{{$data->subtotal}}
                    </td>
                </tr>

                <!-- Shipping Charges -->
				@if(!empty($data->shipping_charges))
					<tr>
						<td class="left text-right">
							{!! $data->shipping_charges_label !!}
						</td>
						<td class="width-50 text-right">
							{{$data->shipping_charges}}
						</td>
					</tr>
				@endif

				<!-- Discount -->
				@if( !empty($data->discount) )
					<tr>
						<td class="width-50 text-right">
							{!! $data->discount_label !!}
						</td>

						<td class="width-50 text-right">
							(-) {{$data->discount}}
						</td>
					</tr>
				@endif

				@if(!empty($data->reward_point_label) )
					<tr>
						<td class="width-50 text-right">
							{!! $data->reward_point_label !!}
						</td>

						<td class="width-50 text-right">
							(-) {{$data->reward_point_amount}}
						</td>
					</tr>
				@endif

				@if( !empty($data->tax) )
					<tr>
						<td class="width-50 text-right">
							{!! $data->tax_label !!}
						</td>
						<td class="width-50 text-right">
							(+) {{$data->tax}}
						</td>
					</tr>
				@endif

		

				<tr>
					<th class="width-50 text-right sub-headings">
						{!! $data->total_label !!}
					</th>
					<td class="width-50 text-right sub-headings">
						{{$data->total}}
					</td>
				</tr>

				{{-- @if(!empty($data->payments))
					@foreach($data->payments as $payment)
						<tr>
							<td class="width-50 text-right">{{$payment['method']}} ({{$payment['date']}}) </td>
							<td class="width-50 text-right">{{$payment['amount']}}</td>
						</tr>
					@endforeach
				@endif --}}

				<!-- Total Paid-->
				@if(!empty($data->total_paid))
					<tr>
						<td class="width-50 text-right sub-headings">
							{!! $data->total_paid_label !!}
						</td>
						<td class="width-50 text-right sub-headings">
							{{$data->total_paid}}
						</td>
					</tr>
				@endif

				<!-- Total Due-->
				@if(!empty($data->total_due))
					<tr>
						<td class="width-50 text-right sub-headings">
							{!! $data->total_due_label !!}
						</td>
						<td class="width-50 text-right sub-headings">
							{{$data->total_due}}
						</td>
					</tr>
				@endif
				@if(!empty($data->previous_due))
					<tr>
						<td class="width-50 text-right sub-headings">
							Previous Due
						</td>
						<td class="width-50 text-right sub-headings">
							{{$data->previous_due}}
						</td>
					</tr>
				@endif

				@if(!empty($data->all_due))
					<tr>
						<td class="width-50 text-right sub-headings">
							{!! $data->all_bal_label !!}
						</td>
						<td class="width-50 text-right sub-headings">
							{{$data->all_due}}
						</td>
					</tr>
				@endif
            </table>

            <!-- tax -->
            @if(!empty($data->taxes))
            	<table class="border-bottom width-100">
            		@foreach($data->taxes as $key => $val)
            			<tr>
            				<td class="left">{{$key}}</td>
            				<td class="right">{{$val}}</td>
            			</tr>
            		@endforeach
            	</table>
            @endif


            @if(!empty($data->additional_notes))
	            <p class="centered" >
	            	{{$data->additional_notes}}
	            </p>
            @endif

            {{-- Barcode --}}
			@if($data->show_barcode)
				<br/>
				<img class="center-block" src="data:image/png;base64,{{DNS1D::getBarcodePNG($data->invoice_no, 'C128', 2,30,array(39, 48, 54), true)}}">
			@endif

			@if(!empty($data->footer_text))
				<p class="centered">
					{!! $data->footer_text !!}
				</p>
			@endif
        </div>
        <!-- <button id="btnPrint" class="hidden-print">Print</button>
        <script src="script.js"></script> -->
		<p style=" text-align: center; font-size:10px;">Developed By Skyline WebSolution Contact US:03428927305</p>

    </body>
</html>