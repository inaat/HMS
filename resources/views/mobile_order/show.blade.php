@extends('layouts.app')
@section('title', ($row->kind == 'order' ? 'Mobile order ' : 'Mobile receipt ').$row->number)

@section('content')
<section class="content-header no-print">
    <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">
        {{ $row->kind == 'order' ? 'Mobile order' : 'Mobile receipt' }} {{ $row->number }}
        <small>
            <a href="{{ action([\App\Http\Controllers\MobileOrderController::class, 'index']) }}?kind={{ $row->kind }}">&larr; back to list</a>
        </small>
    </h1>
</section>

<section class="content">
    @component('components.widget')
        <div class="row">
            <div class="col-sm-3"><b>Booker:</b> {{ $row->booker ?: '#'.$row->booker_id }}</div>
            <div class="col-sm-3"><b>Date:</b> {{ @format_datetime($row->booked_at) }}</div>
            <div class="col-sm-3">
                <b>Customer:</b> {{ $row->customer ?? 'not arrived yet' }}
                @if ($row->customer_uuid) <span class="label label-default">added by booker</span> @endif
                @if ($row->customer_mobile) <br><small>{{ $row->customer_mobile }}</small> @endif
            </div>
            <div class="col-sm-3">
                <b>Status:</b> {{ $row->status == 'waiting' ? 'waiting approval' : $row->status }}
                @if ($row->ref) <br><b>{{ $row->kind == 'order' ? 'Sales order' : 'Payment ref' }}:</b> {{ $row->ref }} @endif
                @if ($row->invoice_no) <br><b>Invoice:</b> {{ $row->invoice_no }} @endif
                @if ($row->reject_reason) <br><b>Reason:</b> {{ $row->reject_reason }} @endif
            </div>
        </div>
        @if (! empty($data['note']))
            <p style="margin-top: 10px;"><b>Note:</b> {{ $data['note'] }}</p>
        @endif
    @endcomponent

    @if ($row->kind == 'order')
        @component('components.widget')
            <table class="table table-bordered table-condensed">
                <thead>
                    <tr style="background: #f5f5f5;">
                        <th>Product</th>
                        <th class="text-right">Qty</th>
                        <th>Unit</th>
                        <th class="text-right">Price / unit</th>
                        <th class="text-right">Price now</th>
                        <th class="text-right">Line total</th>
                        <th class="text-right">Base qty</th>
                        <th class="text-right">Stock now</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($lines as $l)
                        @php
                            $price_changed = $l['current_price'] !== null && abs($l['current_price'] - $l['sub_unit_price']) > 0.001;
                            $short = $l['enable_stock'] && $l['quantity'] > $l['stock'];
                        @endphp
                        <tr>
                            <td>{{ $l['name'] }} <small class="text-muted">{{ $l['sku'] }}</small></td>
                            <td class="text-right">{{ @format_quantity($l['sub_unit_qty']) }}</td>
                            <td>{{ $l['unit_name'] }}</td>
                            <td class="text-right"><span class="display_currency" data-currency_symbol="false">{{ $l['sub_unit_price'] }}</span></td>
                            <td class="text-right" @if ($price_changed) style="background: #fff3cd;" title="Price changed since the booker's last sync" @endif>
                                @if ($l['current_price'] !== null)<span class="display_currency" data-currency_symbol="false">{{ $l['current_price'] }}</span>@endif
                            </td>
                            <td class="text-right"><span class="display_currency" data-currency_symbol="false">{{ $l['line_total'] }}</span></td>
                            <td class="text-right">{{ @format_quantity($l['quantity']) }}</td>
                            <td class="text-right" @if ($short) style="background: #f8d7da;" title="Not enough stock" @endif>{{ @format_quantity($l['stock']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="5" class="text-right">Total</th>
                        <th class="text-right"><span class="display_currency" data-currency_symbol="true">{{ $row->total }}</span></th>
                        <th colspan="2"></th>
                    </tr>
                </tfoot>
            </table>
            <p class="text-muted">Yellow = price changed since the booker's last sync. Red = not enough stock now. After approving you can still edit the sales order before invoicing.</p>
        @endcomponent
    @else
        @component('components.widget')
            <div class="row">
                <div class="col-sm-3"><b>Amount:</b> <span class="display_currency" data-currency_symbol="true">{{ $row->total }}</span></div>
                <div class="col-sm-3"><b>Method:</b> {{ str_replace('_', ' ', $data['method'] ?? 'cash') }}</div>
                <div class="col-sm-3">
                    @if (! empty($data['cheque_number'])) <b>Cheque no:</b> {{ $data['cheque_number'] }} @endif
                    @if (! empty($data['bank_ref'])) <b>Bank ref:</b> {{ $data['bank_ref'] }} @endif
                </div>
            </div>
            <p style="margin-top: 10px;"><b>Pays:</b>
                @if (empty($data['allocations']))
                    oldest dues first
                @else
                    @foreach ($data['allocations'] as $a)
                        invoice {{ $invoices[$a['invoice_id']] ?? '#'.$a['invoice_id'] }}: <span class="display_currency" data-currency_symbol="false">{{ $a['amount'] }}</span>@if (! $loop->last), @endif
                    @endforeach
                    ; any rest goes to the oldest dues
                @endif
            </p>
            <p class="text-muted">Approving records the payment on the customer, into the account "Cash with {{ $row->booker }}". When the booker hands the cash over, do a Fund transfer from that account to your shop cash.</p>
        @endcomponent
    @endif

    @if ($row->status == 'waiting')
        <div class="no-print" style="display: flex; gap: 12px; align-items: flex-start;">
            <form method="POST" action="{{ action([\App\Http\Controllers\MobileOrderController::class, 'approve'], [$row->id]) }}"
                  onsubmit="return confirm('{{ $row->kind == 'order' ? 'Create the sales order?' : 'Post this payment to the customer?' }}');">
                @csrf
                <button type="submit" class="tw-dw-btn tw-dw-btn-success tw-text-white" @if (empty($row->contact_id)) disabled title="Customer not arrived yet" @endif>
                    <i class="fa fa-check"></i> {{ $row->kind == 'order' ? 'Approve → Sales order' : 'Approve → Post payment' }}</button>
            </form>
            <form method="POST" action="{{ action([\App\Http\Controllers\MobileOrderController::class, 'reject'], [$row->id]) }}" style="display: flex; gap: 6px;">
                @csrf
                <input type="text" name="reason" class="form-control" placeholder="Reason for the booker" required maxlength="191" style="width: 260px;">
                <button type="submit" class="tw-dw-btn tw-dw-btn-error tw-text-white"><i class="fa fa-times"></i> Reject</button>
            </form>
        </div>
    @elseif ($row->kind == 'order' && $row->status == 'approved')
        <div class="no-print" style="display: flex; gap: 12px;">
            <a href="{{ action([\App\Http\Controllers\SellController::class, 'create']) }}?mobile_so={{ $row->transaction_id }}" class="tw-dw-btn tw-dw-btn-success tw-text-white">
                <i class="fa fa-file-invoice"></i> Make invoice</a>
            <a href="{{ action([\App\Http\Controllers\SellController::class, 'edit'], [$row->transaction_id]) }}" class="tw-dw-btn tw-dw-btn-outline tw-dw-btn-primary">
                <i class="fa fa-edit"></i> Edit sales order</a>
        </div>
    @endif
</section>
@endsection

@section('javascript')
<script>
    $(document).ready(function () { __currency_convert_recursively($('.content')); });
</script>
@endsection
