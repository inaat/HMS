<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta http-equiv="X-UA-Compatible" content="ie=edge">
        <title>Receipt - {{$data->invoice_no}}</title>
        <style>
            @page {
                margin: 0;
                font-size: 12px;
                font-weight: 700;
            }
            body {
                font-family: 'Arial', sans-serif;
                margin: 0;
                padding: 0;
                background: #f5f7fa;
                color: #333;
            }
            .ticket {
                width: 100%;
                max-width: 800px;
                margin: 20px auto;
                padding: 20px;
                background: #ffffff;
                box-shadow: 0px 4px 10px rgba(0, 0, 0, 0.1);
                border-radius: 8px;
            }
            h1, h2, h3, h4, h5, h6 {
                margin: 0;
                padding: 0;
            }
            table {
                width: 100%;
                border-collapse: collapse;
                margin: 20px 0;
            }
            th, td {
                padding: 12px;
                text-align: left;
                font-size: 14px;
                color: #555;
                border: 1px solid #eaeaea;
            }
            th {
                background-color: #f8f9fc;
                font-weight: bold;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            .text-right {
                text-align: right;
            }
            .text-center {
                text-align: center;
            }
            .bordered {
                border: 1px solid #444;
            }
            .logo {
                text-align: center;
                margin-bottom: 20px;
            }
            .header-title {
                font-size: 28px;
                font-weight: bold;
                color: #444;
                text-align: center;
                margin-bottom: 10px;
            }
            .sub-headings {
                font-size: 14px;
                font-weight: bold;
                background-color: #f8f9fc;
                border: 1px solid #ddd;
                padding: 8px;
                margin-bottom: 10px;
            }
            .footer-text {
                text-align: center;
                font-size: 12px;
                margin-top: 20px;
                color: #888;
            }
            .total-row {
                background-color: #f4f4f8;
                font-weight: bold;
            }
            .highlight {
                background-color: #fffcf0;
                border-left: 4px solid #f7c844;
                padding: 10px;
                margin-bottom: 15px;
                font-size: 14px;
                color: #666;
            }
            .notes {
                padding: 12px;
                font-size: 14px;
                background: #fefcf3;
                border-left: 4px solid #ffd166;
                border-radius: 4px;
                margin-top: 20px;
                color: #5a5a5a;
            }
            .summary-table {
                margin: 20px 0;
            }
            .summary-table td {
                font-size: 16px;
                font-weight: bold;
            }
            .button {
                display: inline-block;
                padding: 10px 20px;
                font-size: 14px;
                font-weight: bold;
                text-transform: uppercase;
                background-color: #007bff;
                color: #ffffff;
                border-radius: 4px;
                text-align: center;
                text-decoration: none;
                margin-top: 15px;
            }
            .button:hover {
                background-color: #0056b3;
            }
        </style>
    </head>
    <body>
        <div class="ticket">
            <!-- Logo Section -->
            @if(!empty($data->logo))
                <div class="logo">
                    <img src="{{$data->logo}}" alt="Company Logo" style=" width: auto;">
                </div>
            @endif

      

            <!-- Invoice Details -->
            <table>
                <tr>
                    <th>{{$data->invoice_no_prefix}}</th>
                    <td>{{$data->invoice_no}}</td>
                </tr>
                <tr>
                    <th>{{$data->date_label}}</th>
                    <td>{{$data->invoice_date}}</td>
                </tr>
                @if(!empty($data->due_date_label))
                    <tr>
                        <th>{{$data->due_date_label}}</th>
                        <td>{{$data->due_date}}</td>
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

            <!-- Items Table -->
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
                    @forelse($data->lines as $line)
                        <tr>
                            <td>{{$loop->iteration}}</td>
                            <td>{{$line['name']}} {{$line['product_variation']}} {{$line['variation']}}</td>
                            <td class="text-right">{{$line['quantity']}} {{$line['units']}}</td>
                            @if(empty($data->hide_price))
                                <td class="text-right">{{$line['unit_price_before_discount']}}</td>
                                <td class="text-right">{{$line['total_line_discount'] ?? 0}}</td>
                                <td class="text-right">{{$line['line_total']}}</td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center">No items found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            <!-- Summary -->
            <table class="summary-table">
                <tr>
                    <td class="text-right">Subtotal</td>
                    <td class="text-right">{{$data->subtotal}}</td>
                </tr>
                @if(!empty($data->tax))
                    <tr>
                        <td class="text-right">Tax</td>
                        <td class="text-right">{{$data->tax}}</td>
                    </tr>
                @endif
                <tr class="total-row">
                    <td class="text-right">Total</td>
                    <td class="text-right">{{$data->total}}</td>
                </tr>
            </table>

            <!-- Additional Notes -->
            @if(!empty($data->additional_notes))
                <div class="notes">
                    {{$data->additional_notes}}
                </div>
            @endif

            <!-- Footer -->
            <p class="footer-text">
                Developed by Skyline WebSolution | Contact: 0342-8927305
            </p>
        </div>
    </body>
</html>
