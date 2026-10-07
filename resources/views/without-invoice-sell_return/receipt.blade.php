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
    <body style="">
      	
	
        <div class="ticket" style="">
		
        	<div class="text-box">
            {{-- The shop's own name and phone from Settings > Business Locations. --}}
            <h3 class="text-center">{{ optional($receipt_details->business)->name }}@if(optional($receipt_details->location)->name && optional($receipt_details->location)->name != optional($receipt_details->business)->name), {{ $receipt_details->location->name }}@endif</h3>
			@if(optional($receipt_details->location)->mobile)
				<h4 class="text-center">Contact: {{ $receipt_details->location->mobile }}</h4>
			@endif
             
			</div>
			
			<table class="table-info border-top">
				<tr>
					<th>Return  No</th>
					<td >
						<b>{{$receipt_details->invoice_no}}</b>
					</td>
				</tr>
				<tr>
					<th>Date</th>
					<td >
						<b>{{$receipt_details->transaction_date}}</b>
					</td>
				</tr>


				
				
				
				

		        <!-- customer info -->
		        <tr style="border: 2px solid #242424;">
		        	<th style="font-size: 15px;">
		        	  Customer Name
		        	</th>

		        	<td style="font-size: 15px;">
		        		{{ $receipt_details->contact->name }}
						</b>
		        	</td>
		        </tr>
				
	        
				
				
				
				
			</table>
		
			<div class="product_area" style="width:100%">
            <table style="padding-top: 5px !important;" class="border-bottom width-100" >
                <thead class="border-bottom-dotted">
                    <tr>
                        <th class="serial_number">#</th>
                        <th class="">
                        	Product
                        </th>
                        <th class="unit_price text-right" style="padding-right: 20px;">
                        	Qty
                        </th>
                        <th class="unit_price text-right" style="padding-right: 20px;">
                        	Price
                        </th>
                        <th class="unit_price text-right" style="padding-right: 20px;">
                        	Subtotal
                        </th>
                    </tr>
                </thead>
                <tbody>
					@forelse($sell_lines_return as $line)
	                    <tr style="background-color:#eee;border-bottom:solid #eee">
	                        <td class="serial_number" style="font-size: 15px;">
	                        	<b>{{$loop->iteration}}</b>
	                        </td>
	                        <td class=""style="font-size: 15px;">
	                        	<b>{{$line->product->name}}</b>
	                        </td>
							<td class="unit_price"style="font-size: 15px;">
								<b><span >{{@format_quantity($line->quantity)}}</span></b>
	                        </td>
							<td class="unit_price"style="font-size: 15px;">
								<b><span class="display_currency " data-currency_symbol="true">{{@num_format($line->unit_price)}}</span></b>
	                        </td>
							<td class="unit_price"style="font-size: 15px;">
								<b><span class="display_currency " data-currency_symbol="true">{{@num_format($line->quantity*$line->unit_price)}}</span></b>
	                        </td>
	                    </tr>
                    @endforeach
                    <tr>
                    	<td colspan="5">&nbsp;</td>
                    </tr>
                </tbody>
            </table>

             <table class="border-bottom width-100">
            
                <tr>
                    <th class="left text-right sub-headings">
                    	Subtotal Total
                    </th>
                    <td class="width-50 text-right sub-headings">
                    	<span class="display_currency " data-currency_symbol="true">{{$receipt_details->final_total}}</span>
                    </td>
                </tr>

               
			
                
			

			

				<!-- Total Paid-->
				@if(!empty($paid_amount))
					<tr style="border: 2px solid #242424; ">
						<td class="width-50 text-right" >
						<span style="font-size: 25px;">Total Paid</span>
						</td>
						<td class="width-50 text-right">
							<span style="font-size: 25px;" class="display_currency " data-currency_symbol="true">{{ @num_format($paid_amount) }}</span>
						</td>
					</tr>
				@endif

				<!-- Total Due-->
				 @if(!empty($due))
					<tr>
						<td class="width-50 text-right">
							Total Due
						</td>
						<td class="width-50 text-right">
							<b><span class="display_currency " data-currency_symbol="true">{{ @num_format($due)}}</span></b>
						</td>
					</tr>
				@endif 

		
            </table> 


		</div>
		</div>
		
		<p style=" text-align: center; font-size:10px;">Developed By Skyline WebSolution Contact US:03428927305</p>

    </body>
</html>

<style type="text/css">
@page {
    margin: 5mm;
	padding: 1mm;
	* {
    	font-size: 12px;
		
    	
	}
	 
}
@media print {
	* {
    	font-size: 12px;
     	
    	
	}
	table, th, td {
  border: 1px solid black;
  text-align:center;
}

.headings{
	font-size: 18px;
	font-weight: 700;
	text-transform: uppercase;
}

.sub-headings{
	font-size: 15px;
	font-weight: 700;
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
.border-bottom-body{
	border-bottom: 1px solid darkgray;
}
td.serial_number, th.serial_number{
	width: 5%;
    max-width: 5%;
}

td.description,
th.description {
    width: 35%;
    max-width: 35%;
   
}

td.quantity,
th.quantity {
    width: 15%;
    max-width: 15%;
   
}
td.unit_price, th.unit_price{
	width: 25%;
    max-width: 25%;
   
}

td.price,
th.price {
    width: 20%;
    max-width: 20%;
   
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
	font-size:20px;
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