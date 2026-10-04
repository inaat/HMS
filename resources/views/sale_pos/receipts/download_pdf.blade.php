<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta http-equiv="X-UA-Compatible" content="ie=edge">
        <title>Receipt - {{$data->invoice_no}}</title>
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
        <div class="ticket">
            <!-- Logo -->
            @if(!empty($data->logo))
            <div class="logo">
                <img src="{{$data->logo}}" alt="Company Logo">
            </div>
            @endif

            <!-- Header -->
        

            <!-- Invoice Info -->
            <table>
                <tr>
                    <th>{!! $data->invoice_no_prefix !!}</th>
                    <td>{{$data->invoice_no}}</td>
                </tr>
                <tr>
                    <th>{!! $data->date_label !!}</th>
                    <td>{{$data->invoice_date}}</td>
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
                <tr>
                    <th>{{$data->customer_label}}</th>
                    <td>{{ $data->customer_name }}</td>
                </tr>
                <tr>
                    <th>Customer Mobile</th>
                    <td>{{ $data->customer_mobile }}</td>
                </tr>
            </table>

            <!-- Product Details -->
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>{{$data->table_product_label}}</th>
                        <th class="text-right">QTY</th>
                        @if(empty($data->hide_price))
                        <th class="text-right">U Price</th>
                        <th class="text-right">{{$data->line_discount_label}}</th>
                        <th class="text-right">{{$data->table_subtotal_label}}</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach($data->lines as $line)
                    <tr>
                        <td>{{$loop->iteration}}</td>
                        <td>
                            {{$line['name']}} {{$line['product_variation']}} {{$line['variation']}}
                            @if(!empty($line['sub_sku'])), {{$line['sub_sku']}} @endif
                        </td>
                        <td class="text-right">{{$line['quantity']}} {{$line['units']}}</td>
                        @if(empty($data->hide_price))
                        <td class="text-right">{{$line['unit_price_before_discount']}}</td>
                        <td class="text-right">{{$line['total_line_discount'] ?? 0}}</td>
                        <td class="text-right">{{$line['line_total']}}</td>
                        @endif
                    </tr>
                    @endforeach
                </tbody>
            </table>

            <!-- Summary -->
            <table class="summary-table">
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


           
         
            <!-- Footer Notes -->
            @if(!empty($data->additional_notes))
            <p class="notes">{{$data->additional_notes}}</p>
            @endif

			@if(!empty($data->footer_text))
				<p class="centered">
					{!! $data->footer_text !!}
				</p>
			@endif
        


            <!-- Barcode -->
            @if($data->show_barcode)
            <div class="barcode">
                <img src="data:image/png;base64,{{DNS1D::getBarcodePNG($data->invoice_no, 'C128', 2, 30)}}" alt="Barcode">
            </div>
            @endif

            <!-- Footer -->
            <div class="footer">
                <p>Developed by Skyline WebSolution | Contact Us: 03428927305</p>
            </div>
        </div>
    </body>
</html>
