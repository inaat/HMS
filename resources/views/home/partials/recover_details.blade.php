{{-- Dashboard > Total Recover Amount: the payments behind the number (HomeController::recoverDetails). --}}
@php
    $methods = ['cash' => 'Cash', 'cheque' => 'Cheque', 'bank_transfer' => 'Bank transfer', 'card' => 'Card', 'other' => 'Other', 'advance' => 'Advance'];
@endphp
<div class="modal-dialog modal-xl" role="document">
    <div class="modal-content">
        <div class="modal-header">
            <button type="button" class="close no-print" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            <h4 class="modal-title">
                <i class="fa fa-hand-holding-usd"></i> Total Recover Amount
                <small>{{ @format_date($start) }} – {{ @format_date($end) }} · {{ $payments->count() }} payment(s) on older invoices</small>
            </h4>
        </div>
        <div class="modal-body">
            <div class="table-responsive">
                <table class="table table-bordered table-striped table-condensed" id="recover_table">
                    <thead>
                        <tr style="background:#f5f5f5;">
                            <th>#</th>
                            <th>Paid on</th>
                            <th>Customer</th>
                            <th>Invoice</th>
                            <th>Invoice date</th>
                            <th>Method</th>
                            <th>Ref no</th>
                            <th>Received by</th>
                            <th class="text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($payments as $p)
                            <tr>
                                <td>{{ $loop->iteration }}</td>
                                <td>{{ @format_datetime($p->paid_on) }}</td>
                                <td>
                                    <a href="{{ action([\App\Http\Controllers\ContactController::class, 'show'], [$p->contact_id]) }}" target="_blank">{{ $p->name }}</a>
                                    @if ($p->supplier_business_name) <small>({{ $p->supplier_business_name }})</small> @endif
                                    @if (! in_array(trim((string) $p->mobile), ['', '0', '-'], true)) <div class="text-muted small">{{ $p->mobile }}</div> @endif
                                </td>
                                <td>
                                    @if ($p->type == 'opening_balance')
                                        <span class="label label-default">Opening balance</span>
                                    @else
                                        <a href="#" class="btn-modal" data-container=".view_modal"
                                            data-href="{{ action([\App\Http\Controllers\SellController::class, 'show'], [$p->transaction_id]) }}">{{ $p->invoice_no }}</a>
                                    @endif
                                </td>
                                <td>{{ @format_date($p->transaction_date) }}</td>
                                <td>{{ $methods[$p->method] ?? ucfirst(str_replace('_', ' ', (string) $p->method)) }}</td>
                                <td>{{ $p->payment_ref_no }}</td>
                                <td>{{ $p->received_by }}</td>
                                <td class="text-right"><span class="display_currency" data-currency_symbol="true">{{ $p->amount }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="text-center text-muted">No recovered payments in these dates.</td></tr>
                        @endforelse
                    </tbody>
                    @if ($payments->count())
                        <tfoot>
                            <tr style="background:#f5f5f5; font-weight:bold;">
                                <td colspan="8" class="text-right">Total</td>
                                <td class="text-right"><span class="display_currency" data-currency_symbol="true">{{ $payments->sum('amount') }}</span></td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>
        <div class="modal-footer no-print">
            <button type="button" class="tw-dw-btn tw-dw-btn-primary tw-text-white" onclick="$(this).closest('div.modal-content').printThis();"><i class="fa fa-print"></i> @lang('messages.print')</button>
            <button type="button" class="tw-dw-btn tw-dw-btn-neutral tw-text-white" data-dismiss="modal">@lang('messages.close')</button>
        </div>
    </div>
</div>
