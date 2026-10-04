<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title></title>
    
    <style>


    .ticket {
            width: 100%;
            max-width: 100%;
        }


    @page {
        margin: 0px;
        padding: 4px;
        font-size: 12px;
        font-weight: 700;
    }

    @media print {
           footer{page-break-after:always}
.top{
		font-size: 16px;
	
	}
        * {
            margin: 0px;
            padding: 4px;
            font-size: 12px;
            font-weight: 700;

        }

     

        .headings {
            font-size: 15px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .sub-headings {
            font-size: 15px;
            font-weight: 700;
        }

        .border-top {
            border-top: 1px solid #242424;
        }

        .border-bottom {
            border-bottom: 1px solid #242424;
        }

        .border-bottom-dotted {
            border-bottom: 1px dotted darkgray;
        }

        td.serial_number,
        th.serial_number {
            width: 5%;
            max-width: 5%;
        }

        td.description,
        th.description {
            width: 30%;
            max-width: 35%;
            word-break: break-all;
        }

        td.quantity,
        th.quantity {
            width: 20%;
            max-width: 20%;

        }

        td.unit_price,
        th.unit_price {
            width: 10%;
            max-width: 10%;

        }

        td.price,
        th.price {
            width: 10%;
            max-width: 10%;

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

    .table-info tr:first-child td,
    .table-info tr:first-child th {
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
        width: 35%;
        padding: 10px;
    }

    .text-with-image {
        float: left;
        width: 65%;
    }

    .text-box {
        width: 100%;
        height: auto;
    }



    </style>
</head>

<body >
@foreach($receipt_details_print as $transactions)
  <div class="ticket" style="  width: 100%;
		max-width: 100%;">
        
  <!-- Logo -->
        {{-- @if(!empty($transactions[0]['transactions']->logo))
        <img src="{{$transactions[0]['transactions']->logo}}" style=" width: 100%; " class="img center-block">
        <!-- <br/> -->
        @endif --}}


   <table class="table-info border-top">
            
          

         
            @if(!empty($transactions[0]['transactions']->table_label) || !empty($transactions[0]['transactions']->table))
            <tr>
                <th>
                    @if(!empty($transactions[0]['transactions']->table_label))
                    <b>{!! $transactions[0]['transactions']->table_label !!}</b>
                    @endif
                </th>
                <td>
                    {{$transactions[0]['transactions']->table}}
                </td>
            </tr>
            @endif

            <!-- customer info -->
            <tr>
                <th>
                    {{$transactions[0]['transactions']->customer_label}}
                </th>

                <td>
                    {{ $transactions[0]['transactions']->customer_name }}

                    {{--
		        		@if(!empty($transactions[0]['transactions']->customer_info))
							{!! $transactions[0]['transactions']->customer_info !!}
						@endif
						--}}
                </td>
            </tr>
            <tr>
                <th>
                    Customer Mobile
                </th>

                <td>
                    {{ $transactions[0]['transactions']->customer_mobile }}

                    {{--
		        		@if(!empty($transactions[0]['transactions']->customer_info))
							{!! $transactions[0]['transactions']->customer_info !!}
						@endif
						--}}
                </td>
            </tr>

            @if(!empty($transactions[0]['transactions']->client_id_label))
            <tr>
                <th>
                    {{ $transactions[0]['transactions']->client_id_label }}
                </th>
                <td>
                    {{ $transactions[0]['transactions']->client_id }}
                </td>
            </tr>
            @endif

            @if(!empty($transactions[0]['transactions']->customer_tax_label))
            <tr>
                <th>
                    {{ $transactions[0]['transactions']->customer_tax_label }}
                </th>
                <td>
                    {{ $transactions[0]['transactions']->customer_tax_number }}
                </td>
            </tr>
            @endif

            @if(!empty($transactions[0]['transactions']->customer_custom_fields))
            <tr>
                <td colspan="2">
                    {{ $transactions[0]['transactions']->customer_custom_fields }}
                </td>
            </tr>
            @endif

            @if(!empty($transactions[0]['transactions']->customer_rp_label))
            <tr>
                <th>
                    {{ $transactions[0]['transactions']->customer_rp_label }}
                </th>
                <td>
                    {{ $transactions[0]['transactions']->customer_total_rp }}
                </td>
            </tr>
             <tr>
                <th>{!! $transactions[0]['transactions']->date_label !!}</th>
                <td>
                    {{$transactions[0]['transactions']->invoice_date}}
                </td>
            </tr>

            @endif
        </table>
        @php
         $subtotal=0;  
         $discount=0; 
         $total=0; 
         $total_paid=0;
         $previous_due='';
         $all_due='';
        @endphp
@foreach($transactions as $receipt_details)
            @php
           $subtotal += unformat_currency($receipt_details['transactions']->subtotal);
           $discount += unformat_currency($receipt_details['transactions']->discount);
           $total += unformat_currency($receipt_details['transactions']->total);
           $total_paid += unformat_currency($receipt_details['transactions']->total_paid);
           
            @endphp
              <table class="table-info border-top">
            <tr>
                <th>{!! $receipt_details['transactions']->invoice_no_prefix !!}</th>
                <td>
                    {{$receipt_details['transactions']->invoice_no}}
                </td>
            </tr>
           
            @if(!empty($receipt_details['transactions']->due_date_label))
            <tr>
                <th>{{$receipt_details['transactions']->due_date_label}}</th>
                <td>{{$receipt_details['transactions']->due_date ?? ''}}</td>
            </tr>
            @endif

            @if(!empty($receipt_details['transactions']->sales_person_label))
            <tr>
                <th>{{$receipt_details['transactions']->sales_person_label}}</th>

                <td>{{$receipt_details['transactions']->sales_person}}</td>
            </tr>
            @endif
          
            <tr>
            
                <th>Order Book By</th>

                <td>{{ $receipt_details['transactions']->book_by }}</td>
            </tr>
          

         

        
        </table>


        <table style="padding-top: 5px !important" class="border-bottom width-100">
            <thead class="border-bottom-dotted">
                <tr style="">
                    <th class="serial_number">#</th>
                    <th class="description">
                        {{$receipt_details['transactions']->table_product_label}}
                    </th>
                    <th class="quantity text-right">
                        QTY
                    </th>
                    @if(empty($receipt_details['transactions']->hide_price))
                    <th class="unit_price text-right">
                        <!-- {{$receipt_details['transactions']->table_unit_price_label}} -->
                        U Price
                    </th>
                    <th class="unit_price text-right">
                        <!-- {{$receipt_details['transactions']->table_unit_price_label}} -->
                        {{$receipt_details['transactions']->line_discount_label}}
                    </th>

                    <th class="price text-right">{{$receipt_details['transactions']->table_subtotal_label}}</th>
                    @endif
                </tr>
            </thead>
            <tbody>

                @forelse($receipt_details['transactions']->lines as $line)
                <tr style="border: 2px solid #242424; ">
                    <td class="serial_number" style="border-bottom: 1px solid #242424; ">
                        {{$loop->iteration}}
                    </td>
                    <td class="description" style="border: 2px solid #242424;  ">

                        <b><span style="">@if(!empty($line['product_custom_fields'])) {{$line['product_custom_fields']}}</span><b>

                                @else
                                {{$line['name']}} {{$line['product_variation']}} {{$line['variation']}}
                                @if(!empty($line['sub_sku'])), {{$line['sub_sku']}} @endif @if(!empty($line['brand'])), {{$line['brand']}} @endif @if(!empty($line['cat_code'])), {{$line['cat_code']}}@endif
                                @if(!empty($line['sell_line_note']))({{$line['sell_line_note']}}) @endif
                                @if(!empty($line['lot_number']))<br> {{$line['lot_number_label']}}: {{$line['lot_number']}} @endif
                                @if(!empty($line['product_expiry'])), {{$line['product_expiry_label']}}: {{$line['product_expiry']}} @endif

                                @endif

                    </td>
                    <td style="border: 1px solid #242424; " class="quantity text-right">{{$line['quantity']}} {{$line['units']}}</td>
                    @if(empty($receipt_details['transactions']->hide_price))
                    <td style="border: 1px solid #242424; " class="unit_price text-right">{{$line['unit_price_before_discount']}}</td>
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
                    @if(empty($receipt_details['transactions']->hide_price))
                    <td class="text-right">{{$modifier['unit_price_inc_tax']}}</td>

                    <td class="text-right">{{$modifier['line_total']}}</td>
                    @endif
                </tr>
                @endforeach
                @endif
                @endforeach
                @if(!empty($receipt_details['transactions']->total_quantity_label))
                <tr class="">
                    <td colspan="2" class="text-right">
                        {!! $receipt_details['transactions']->total_quantity_label !!}
                    </td>
                    <td colspan="" class="text-right">
                        {{$receipt_details['transactions']->total_quantity}}
                    </td>
                </tr>
                @endif
                <tr>
                    <td colspan="5">&nbsp;</td>
                </tr>

            </tbody>
        </table>
  @if(!empty($receipt_details['transactions']->additional_notes))
        <p class="centered">
            {{$receipt_details['transactions']->additional_notes}}
        </p>
        @endif
        @if(!empty($receipt_details['transactions']->previous_due))
            @php
                           $previous_due= $receipt_details['transactions']->previous_due;

            @endphp
            
            @endif

            @if(!empty($receipt_details['transactions']->all_due))
          
                  @php
                           $all_due= $receipt_details['transactions']->all_due;

            @endphp
            @endif
@endforeach
<table class="border-bottom width-100">

            <tr>
                <th class="left text-right sub-headings">
                    {!! $transactions[0]['transactions']->subtotal_label !!}
                </th>
                <td class="width-50 text-right sub-headings">
                   @format_currency( $subtotal)
                </td>
            </tr>
                <!-- Discount -->
            @if( !empty($discount) )
            <tr>
                <td class="width-50 text-right">
                    {!! $transactions[0]['transactions']->discount_label !!}
                </td>

                <td class="width-50 text-right">
                    (-)  @format_currency($discount)
                </td>
            </tr>
            @endif
                   <tr>
                <th class="width-50 text-right sub-headings">
                    {!! $transactions[0]['transactions']->total_label !!}
                </th>
                <td class="width-50 text-right sub-headings">
                     @format_currency($total)
                </td>
            </tr>
                 <!-- Total Paid-->
            @if(!empty($total_paid))
            <tr>
                <td class="width-50 text-right sub-headings">
                    {!! $transactions[0]['transactions']->total_paid_label !!}
                </td>
                <td class="width-50 text-right sub-headings">
                  @format_currency($total_paid)
                </td>
            </tr>
             @endif
                      @if(!empty($transactions[0]['transactions']->previous_due))
            <tr>
                <td class="width-50 text-right sub-headings">
                    Previous Due
                </td>
                <td class="width-50 text-right sub-headings">
                    {{$previous_due}}
                </td>
            </tr>
            @endif
                 @if(!empty($transactions[0]['transactions']->all_due))
            <tr>
                <td class="width-50 text-right sub-headings">
                    {!! $transactions[0]['transactions']->all_bal_label !!}
                </td>
                <td class="width-50 text-right sub-headings">
                    {{$all_due}}
                </td>
            </tr>
           
            @endif
      
</table>
    </div>
    <!-- <button id="btnPrint" class="hidden-print">Print</button>
        <script src="script.js"></script> -->
    <p style=" text-align: center; font-size:10px;">Developed By Skyline WebSolution Contact US:03428927305</p>



<footer></footer>
@endforeach
  
</body>
</html>