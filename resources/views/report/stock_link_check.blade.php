@extends('layouts.app')
@section('title', 'Stock link check')

@section('content')
    <section class="content-header">
        <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">Stock link check
            <small>Sell lines vs purchase links</small>
        </h1>
    </section>

    <section class="content">
        @component('components.widget')
            @php
                $extra = $issues->filter(fn ($i) => $i->linked_qty > $i->quantity);
            @endphp
            @if($can_repair && ($issues->isNotEmpty() || $overlinked->isNotEmpty() || $stock_mismatches->isNotEmpty() || $payment_mismatches->isNotEmpty() || $orphans->isNotEmpty()))
                <button type="button" class="tw-dw-btn tw-dw-btn-warning tw-text-white tw-dw-btn-sm" id="repair_stock_links" style="margin-bottom: 10px;">
                    <i class="fa fa-wrench"></i> Repair
                </button>
            @endif

            @if($orphans->isNotEmpty())
                @php $orphan_groups = $orphans->groupBy(fn ($o) => $o->product.'|'.$o->location); @endphp
                <h4>Purchases still used by deleted sales ({{ $orphans->count() }} links, {{ $orphan_groups->count() }} products)</h4>
                <p class="text-muted">These sales lines were deleted, but their purchases were left marked as sold. POS shows the stock,
                    but selling it fails with "Mismatch between sold and purchase quantity". Repair frees these purchases, links any
                    "sold without stock" sales to them, and checks the stock.</p>
                <div class="table-responsive">
                    <table class="table table-bordered table-striped table-condensed">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>SKU</th>
                                <th>Location</th>
                                <th>Purchases</th>
                                <th>Qty held by deleted sales</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($orphan_groups as $rows)
                                @php $first = $rows->first(); $held = $rows->sum(fn ($o) => $o->quantity - $o->qty_returned); @endphp
                                <tr>
                                    <td>{{ $first->product }}</td>
                                    <td>{{ $first->sub_sku }}</td>
                                    <td>{{ $first->location }}</td>
                                    <td>{{ $rows->pluck('ref_no')->unique()->implode(', ') }}</td>
                                    <td class="text-danger">{{ @format_quantity($held) }} {{ $first->unit }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if($stock_mismatches->isNotEmpty())
                <h4>Stock does not match purchases &minus; sales ({{ $stock_mismatches->count() }})</h4>
                <p class="text-muted">"In stock" is what POS shows. When it is higher than the real stock, selling fails with
                    "Mismatch between sold and purchase quantity". Repair sets it to the real stock.</p>
                <div class="table-responsive">
                    <table class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>SKU</th>
                                <th>Location</th>
                                <th>In stock (shown in POS)</th>
                                <th>Real stock (purchases &minus; sales &minus; adjustments)</th>
                                <th>Difference</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($stock_mismatches as $row)
                                <tr>
                                    <td>{{ $row->product }}</td>
                                    <td>{{ $row->sub_sku }}</td>
                                    <td>{{ $row->location }}</td>
                                    <td>{{ @format_quantity($row->qty_available) }} {{ $row->unit }}</td>
                                    <td>{{ @format_quantity($row->calculated_qty) }} {{ $row->unit }}</td>
                                    <td class="text-danger">{{ $row->qty_available > $row->calculated_qty ? '+' : '' }}{{ @format_quantity($row->qty_available - $row->calculated_qty) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if($overlinked->isNotEmpty())
                <h4>Purchase lines linked to more than they contain ({{ $overlinked->count() }})</h4>
                <p class="text-muted">Sales were linked to these purchases beyond their quantity. Repair moves the extra to "sold without stock"
                    and links it again to any purchase that still has free stock.</p>
                <div class="table-responsive">
                    <table class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Purchase</th>
                                <th>Product</th>
                                <th>Purchased</th>
                                <th>Linked to sales</th>
                                <th>Extra</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($overlinked as $line)
                                <tr>
                                    <td>{{ @format_datetime($line->transaction_date) }}</td>
                                    <td>{{ $line->type == 'purchase' ? $line->ref_no : ucfirst(str_replace('_', ' ', $line->type)) }}</td>
                                    <td>{{ $line->product }}</td>
                                    <td>{{ @format_quantity($line->available_qty) }} {{ $line->unit }}</td>
                                    <td>{{ @format_quantity($line->linked_qty) }} {{ $line->unit }}</td>
                                    <td class="text-danger">+{{ @format_quantity($line->linked_qty - $line->available_qty) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if($payment_mismatches->isNotEmpty())
                <h4>Payments recorded for the wrong customer ({{ $payment_mismatches->count() }})</h4>
                <p class="text-muted">The invoice's customer was changed after it was paid (e.g. Walk-In &rarr; real customer), but the
                    payment stayed with the old customer. The customer's ledger then misses these payments and shows a too high balance.
                    Repair moves each payment to its invoice's customer.</p>
                <div class="table-responsive">
                    <table class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                <th>Paid on</th>
                                <th>Payment</th>
                                <th>Invoice</th>
                                <th>Amount</th>
                                <th>Recorded for</th>
                                <th>Should be (invoice customer)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($payment_mismatches as $payment)
                                <tr>
                                    <td>{{ @format_datetime($payment->paid_on) }}</td>
                                    <td>{{ $payment->payment_ref_no }}</td>
                                    <td>
                                        <a href="#" class="btn-modal" data-href="{{ action([\App\Http\Controllers\SellController::class, 'show'], [$payment->transaction_id]) }}" data-container=".view_modal">{{ $payment->invoice_no }}</a>
                                    </td>
                                    <td><span class="display_currency" data-currency_symbol="true">{{ $payment->amount }}</span></td>
                                    <td class="text-danger">{{ $payment->recorded_for ?? '-' }}</td>
                                    <td class="text-success">{{ $payment->customer }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="bg-gray">
                                <td colspan="3"><strong>Total</strong></td>
                                <td><span class="display_currency" data-currency_symbol="true">{{ $payment_mismatches->sum('amount') }}</span></td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif

            @if($issues->isEmpty())
                <div class="alert alert-success" style="margin: 0;">
                    <i class="fa fa-check-circle"></i> All sell lines match their purchase links.
                </div>
            @else
                <div class="alert alert-warning">
                    <h4 style="margin-top: 0;"><i class="fa fa-exclamation-triangle"></i>
                        {{ $issues->count() }} sell line(s) do not match their purchase links
                    </h4>
                    Each sold quantity should be linked once to the purchase it came from. These lines are linked to more
                    (or less) than they sold, mostly duplicate links created when an invoice was edited. This makes purchase
                    stock look used up and cost / profit wrong.
                    <br><strong>Repair</strong> removes extra linked quantity, links missing quantity to purchases with free stock
                    (or marks it "sold without stock") and recalculates the purchase lines. Invoices are not changed.
                </div>

                <div class="table-responsive">
                    <table class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Invoice</th>
                                <th>Product</th>
                                <th>Sold</th>
                                <th>Linked</th>
                                <th>Problem</th>
                                <th>Link rows</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($issues as $issue)
                                <tr>
                                    <td>{{ @format_datetime($issue->transaction_date) }}</td>
                                    <td>
                                        <a href="#" class="btn-modal" data-href="{{ action([\App\Http\Controllers\SellController::class, 'show'], [$issue->transaction_id]) }}" data-container=".view_modal">{{ $issue->invoice_no }}</a>
                                    </td>
                                    <td>{{ $issue->product }}</td>
                                    <td>{{ @format_quantity($issue->quantity) }} {{ $issue->unit }}</td>
                                    <td>{{ @format_quantity($issue->linked_qty) }} {{ $issue->unit }}</td>
                                    <td class="{{ $issue->linked_qty > $issue->quantity ? 'text-danger' : 'text-warning' }}">
                                        @if($issue->linked_qty > $issue->quantity)
                                            {{ @format_quantity($issue->linked_qty - $issue->quantity) }} {{ $issue->unit }} linked extra
                                        @else
                                            {{ @format_quantity($issue->quantity - $issue->linked_qty) }} {{ $issue->unit }} not linked
                                        @endif
                                    </td>
                                    <td>{{ $issue->link_rows }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @endcomponent
    </section>
    <div class="modal fade view_modal" tabindex="-1" role="dialog"></div>
@endsection

@section('javascript')
<script type="text/javascript">
    $('#repair_stock_links').click(function() {
        var btn = $(this);
        swal({
            title: LANG.sure,
            text: 'Repair purchase links and stock?',
            icon: 'warning',
            buttons: true,
            dangerMode: true,
        }).then(function(ok) {
            if (!ok) {
                return;
            }
            var btn_html = btn.html();
            btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Repairing... please wait, this can take a few minutes');
            $.ajax({
                method: 'POST',
                url: '/reports/stock-link-check/repair',
                dataType: 'json',
                data: { _token: '{{ csrf_token() }}' },
                success: function(result) {
                    if (result.success) {
                        toastr.success(result.msg);
                        setTimeout(function() { location.reload(); }, 1200);
                    } else {
                        btn.prop('disabled', false).html(btn_html);
                        toastr.error(result.msg);
                    }
                },
                error: function() {
                    btn.prop('disabled', false).html(btn_html);
                }
            });
        });
    });
</script>
@endsection
