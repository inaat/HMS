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

                <div class="table-responsive">
                    <table class="table table-bordered table-striped">
                        <thead>
                            <tr>
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
                                <tr>
                                    <td>{{ $row->product }}@if($row->type == 'variable') - {{ $row->variation }}@endif</td>
                                    <td>{{ $row->sub_sku }}</td>
                                    <td>{{ $row->location }}</td>
                                    <td class="{{ $row->qty_available < 0 ? 'text-danger' : '' }}">{{ @format_quantity($row->qty_available) }} {{ $row->unit }}</td>
                                    <td>{{ @format_quantity($row->sold_without_stock) }} {{ $row->unit }}</td>
                                    <td><span class="display_currency" data-currency_symbol="true">{{ $row->sold_without_stock * $row->default_purchase_price }}</span></td>
                                    <td>{{ ! empty($row->last_sold_without_stock) ? @format_datetime($row->last_sold_without_stock) : '' }}</td>
                                    <td>
                                        <a href="{{ action([\App\Http\Controllers\PurchaseController::class, 'create']) }}" class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-primary" target="_blank">
                                            <i class="fa fa-plus"></i> Add purchase
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="bg-gray font-17 footer-total">
                                <td colspan="4"><strong>Total ({{ $rows->count() }} products)</strong></td>
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
