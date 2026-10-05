@extends('layouts.app')
@section('title', 'Missing purchases')

@section('content')
    <section class="content-header">
        <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">Missing purchases
            <small>Negative stock and items sold without stock</small>
        </h1>
    </section>

    <section class="content">
        <div class="row no-print">
            <div class="col-md-4">
                <form method="GET" action="{{ action([\App\Http\Controllers\ReportController::class, 'missingPurchases']) }}">
                    <div class="input-group">
                        <span class="input-group-addon"><i class="fa fa-map-marker"></i></span>
                        <select name="location_id" class="form-control" onchange="this.form.submit()">
                            @foreach($business_locations as $id => $name)
                                <option value="{{ $id }}" @if($location_id == $id) selected @endif>{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                </form>
            </div>
        </div>
        <br>

        @component('components.widget')
            @if($rows->isEmpty())
                <div class="alert alert-success" style="margin: 0;">
                    <i class="fa fa-check-circle"></i> No missing purchases. No product has negative stock or sales without stock.
                </div>
            @else
                <div class="alert alert-info">
                    These products were sold more than was purchased. Add the missing <strong>purchase</strong> or
                    <strong>opening stock</strong> for the quantity shown: the sales are then linked to it automatically,
                    stock goes back to 0 or more and profit uses the real cost.
                </div>

                <button type="button" class="tw-dw-btn tw-dw-btn-primary tw-text-white tw-dw-btn-sm" id="create_purchase_selected" disabled style="margin-bottom: 10px;">
                    <i class="fa fa-plus"></i> Create purchase for selected (<span id="selected_count">0</span>)
                </button>
                <span class="text-muted" style="margin-left: 8px;">Select products of one location; they are added to a new purchase with the missing quantity.</span>

                <div class="table-responsive">
                    <table class="table table-bordered table-striped" id="missing_purchases_table">
                        <thead>
                            <tr>
                                <th style="width: 30px;"><input type="checkbox" id="select_all_missing" title="Select all"></th>
                                <th>Product</th>
                                <th>SKU</th>
                                <th>Location</th>
                                <th>Current stock</th>
                                <th>Sold without stock</th>
                                <th>Value (default purchase price)</th>
                                <th>Last sold without stock</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($rows as $row)
                                @php
                                    //Quantity to purchase: what was sold without stock, at least enough to bring negative stock to 0
                                    $missing_qty = max((float) $row->sold_without_stock, (float) $row->qty_available < 0 ? -(float) $row->qty_available : 0);
                                @endphp
                                <tr>
                                    <td>
                                        <input type="checkbox" class="missing_row_check"
                                            data-product_id="{{ $row->product_id }}" data-variation_id="{{ $row->variation_id }}"
                                            data-location_id="{{ $row->location_id }}" data-location="{{ $row->location }}" data-qty="{{ $missing_qty }}">
                                    </td>
                                    <td>{{ $row->product }}@if($row->type == 'variable') - {{ $row->variation }}@endif</td>
                                    <td>{{ $row->sub_sku }}</td>
                                    <td>{{ $row->location }}</td>
                                    <td class="{{ $row->qty_available < 0 ? 'text-danger' : '' }}">{{ @format_quantity($row->qty_available) }} {{ $row->unit }}</td>
                                    <td>{{ @format_quantity($row->sold_without_stock) }} {{ $row->unit }}</td>
                                    <td><span class="display_currency" data-currency_symbol="true">{{ $row->sold_without_stock * $row->default_purchase_price }}</span></td>
                                    <td>{{ ! empty($row->last_sold_without_stock) ? @format_datetime($row->last_sold_without_stock) : '' }}</td>
                                    <td>
                                        <a href="{{ action([\App\Http\Controllers\PurchaseController::class, 'create']) }}?location_id={{ $row->location_id }}&missing_items={{ $row->product_id }}:{{ $row->variation_id }}:{{ $missing_qty }}" class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-primary" target="_blank">
                                            <i class="fa fa-plus"></i> Add purchase
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="bg-gray font-17 footer-total">
                                <td colspan="5"><strong>Total ({{ $rows->count() }} products)</strong></td>
                                <td>{{ @format_quantity($rows->sum('sold_without_stock')) }}</td>
                                <td><span class="display_currency" data-currency_symbol="true">{{ $rows->sum(fn ($r) => $r->sold_without_stock * $r->default_purchase_price) }}</span></td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        @endcomponent
    </section>
@endsection

@section('javascript')
<script type="text/javascript">
    $(document).ready(function() {
        function update_selected() {
            var count = $('.missing_row_check:checked').length;
            $('#selected_count').text(count);
            $('#create_purchase_selected').prop('disabled', count == 0);
        }

        $('#select_all_missing').on('change', function() {
            $('.missing_row_check').prop('checked', $(this).is(':checked'));
            update_selected();
        });
        $(document).on('change', '.missing_row_check', update_selected);

        $('#create_purchase_selected').on('click', function() {
            var checked = $('.missing_row_check:checked');
            var locations = {};
            checked.each(function() {
                locations[$(this).data('location_id')] = $(this).data('location');
            });
            if (Object.keys(locations).length > 1) {
                toastr.error('Select products of one location only (selected: ' + Object.values(locations).join(', ') + ')');
                return;
            }

            var items = checked.map(function() {
                return $(this).data('product_id') + ':' + $(this).data('variation_id') + ':' + $(this).data('qty');
            }).get().join(',');

            window.open("{{ action([\App\Http\Controllers\PurchaseController::class, 'create']) }}" +
                '?location_id=' + Object.keys(locations)[0] + '&missing_items=' + items, '_blank');
        });
    });
</script>
@endsection
