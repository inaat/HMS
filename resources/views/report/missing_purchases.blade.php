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
                <button type="button" class="tw-dw-btn tw-dw-btn-success tw-text-white tw-dw-btn-sm" id="auto_purchase_selected" disabled style="margin-bottom: 10px; margin-left: 6px;">
                    <i class="fa fa-magic"></i> Create purchases automatically (<span class="selected_count_2">0</span>)
                </button>
                <span class="text-muted" style="margin-left: 8px;">Few products: <b>Create purchase</b> opens the purchase screen. Many products: <b>Create purchases automatically</b> saves one purchase per supplier.</span>

                {{-- Create purchases automatically: one purchase per supplier, with a progress bar --}}
                {{-- no tabindex: Bootstrap's focus trap fights the select2 search box (dropdown shakes and closes) --}}
                <div class="modal fade" id="auto_purchase_modal" role="dialog">
                    <div class="modal-dialog" role="document">
                        <div class="modal-content">
                            <div class="modal-header">
                                <button type="button" class="close" data-dismiss="modal">&times;</button>
                                <h4 class="modal-title"><i class="fa fa-magic"></i> Create purchases automatically</h4>
                            </div>
                            <div class="modal-body">
                                <p>Each ticked product is bought from the <b>supplier it was last purchased from</b> — change any row's supplier below.
                                    One purchase per supplier is saved as <b>received</b> with the missing quantity at the purchase price, dated just before
                                    the first sale that had no stock, so those sales are linked to it. The supplier's due goes up by the purchase
                                    (or uses the advance already paid to them). A row with no supplier is left out.</p>
                                {{-- options copied into every row's dropdown --}}
                                <select id="auto_supplier_options" style="display:none;">
                                    @foreach ($suppliers as $id => $name)
                                        <option value="{{ $id }}">{{ $name }}</option>
                                    @endforeach
                                </select>
                                <div id="auto_plan" style="min-height:120px;"></div>
                                <div id="auto_progress" style="display:none;">
                                    <div style="display:flex; justify-content:space-between;"><b id="auto_step">Starting…</b><span id="auto_count" class="text-muted"></span></div>
                                    <div class="progress" style="height:22px; margin:6px 0 0;">
                                        <div class="progress-bar progress-bar-success progress-bar-striped active" id="auto_bar" style="width:0%; min-width:2em; line-height:22px;">0%</div>
                                    </div>
                                    <ul id="auto_log" class="small" style="margin-top:8px; max-height:180px; overflow:auto; padding-left:18px;"></ul>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="tw-dw-btn tw-dw-btn-outline tw-dw-btn-primary" id="auto_check">Check suppliers</button>
                                <button type="button" class="tw-dw-btn tw-dw-btn-success tw-text-white" id="auto_go" disabled>Create purchases</button>
                                <button type="button" class="tw-dw-btn tw-dw-btn-neutral tw-text-white" data-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>

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
            $('#selected_count, .selected_count_2').text(count);
            $('#create_purchase_selected, #auto_purchase_selected').prop('disabled', count == 0);
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

        // Create purchases automatically: plan (one purchase per supplier) -> create them one by one with a progress bar
        var autoGroups = [];
        function tickedItems() {
            return $('.missing_row_check:checked').map(function() {
                return { product_id: $(this).data('product_id'), variation_id: $(this).data('variation_id'),
                    location_id: $(this).data('location_id'), qty: $(this).data('qty') };
            }).get();
        }
        // The modal sits inside the report box, which moves on hover: move it to <body> so it stays still
        $('#auto_purchase_modal').appendTo('body');

        // Each row of the plan has its own supplier dropdown (select2, inside the modal)
        var supplierOptions = $('#auto_supplier_options').html();
        function rowSelect(i, supplier_id) {
            var s = $('<select class="form-control auto-row-supplier" style="width:100%;"><option value="">— leave out —</option>' + supplierOptions + '</select>');
            s.attr('data-group', i).val(supplier_id ? String(supplier_id) : '');
            return s;
        }
        $('#auto_purchase_selected').on('click', function() {
            autoGroups = [];
            $('#auto_plan').html('<i class="fa fa-spinner fa-spin"></i> Checking suppliers…'); $('#auto_progress').hide(); $('#auto_log').empty();
            $('#auto_go').prop('disabled', true); $('#auto_check').prop('disabled', false);
            $('#auto_purchase_modal').modal('show');
            $('#auto_check').click();
        });
        $('#auto_check').on('click', function() {
            $.post('{{ action([\App\Http\Controllers\ReportController::class, 'missingPurchasesPlan']) }}', { items: tickedItems() }, function(r) {
                autoGroups = r.groups;
                var table = $('<table class="table table-bordered table-condensed" style="margin:0;"><thead><tr><th>Supplier</th><th class="text-right" style="width:90px;">Products</th><th class="text-right" style="width:130px;">Amount</th></tr></thead><tbody></tbody></table>');
                $.each(r.groups, function(i, g) {
                    var tr = $('<tr' + (g.supplier_id ? '' : ' style="background:#fef2f2;"') + '><td></td><td class="text-right">' + g.items.length + '</td><td class="text-right">' + __number_f(g.total) + '</td></tr>');
                    if (!g.supplier_id) {
                        tr.find('td').first().append('<div class="text-danger small" style="margin-bottom:4px;">Never purchased before: choose the supplier</div>');
                    }
                    tr.find('td').first().append(rowSelect(i, g.supplier_id));
                    table.find('tbody').append(tr);
                });
                $('#auto_plan').empty().append(table);
                var parent = $('#auto_purchase_modal .modal-content');
                $('#auto_plan .auto-row-supplier').each(function() { $(this).select2({ dropdownParent: parent, width: '100%' }); });
                $('#auto_go').prop('disabled', !r.groups.length);
            });
        });
        $(document).on('change', '.auto-row-supplier', function() {
            autoGroups[$(this).data('group')].supplier_id = $(this).val() || null;
        });
        $('#auto_go').on('click', function() {
            // rows with the same supplier (and location) become one purchase; rows left out are skipped
            var merged = {};
            $.each(autoGroups, function(i, g) {
                if (!g.supplier_id) { return; }
                var key = g.supplier_id + '-' + g.location_id;
                var name = $('#auto_supplier_options option[value="' + g.supplier_id + '"]').text() || g.supplier;
                merged[key] = merged[key] || { supplier_id: g.supplier_id, location_id: g.location_id, supplier: name, items: [] };
                merged[key].items = merged[key].items.concat(g.items);
            });
            autoGroups = Object.values(merged);
            if (!autoGroups.length) { toastr.error('Choose a supplier for at least one row'); return; }
            var btn = $(this).prop('disabled', true), i = 0, done = 0;
            $('#auto_check').prop('disabled', true);
            $('#auto_plan .auto-row-supplier').prop('disabled', true);
            $('#auto_progress').show();
            function next() {
                if (i >= autoGroups.length) {
                    $('#auto_bar').css('width', '100%').text('100%').removeClass('active');
                    $('#auto_step').text('Done: ' + done + ' purchase(s) saved');
                    toastr.success(done + ' purchase(s) saved');
                    $('#auto_purchase_modal').one('hidden.bs.modal', function() { location.reload(); });
                    return;
                }
                var g = autoGroups[i];
                $('#auto_step').text(g.supplier + '…');
                $('#auto_count').text((i + 1) + ' / ' + autoGroups.length);
                $.ajax({ method: 'POST', url: '{{ action([\App\Http\Controllers\ReportController::class, 'missingPurchasesCreate']) }}', dataType: 'json', timeout: 300000,
                    data: { supplier_id: g.supplier_id, location_id: g.location_id, items: g.items } })
                    .done(function(r) {
                        if (r.success) {
                            done++;
                            $('#auto_log').append('<li class="text-success">' + $('<div>').text(g.supplier).html() + ': purchase ' + r.ref_no + ' (' + r.count + ' products, ' + __number_f(r.total) + ')'
                                + (r.skipped.length ? ' — skipped: ' + $('<div>').text(r.skipped.join(', ')).html() : '') + '</li>');
                        } else {
                            $('#auto_log').append('<li class="text-danger">' + $('<div>').text(r.msg).html() + '</li>');
                        }
                    })
                    .fail(function() { $('#auto_log').append('<li class="text-danger">' + $('<div>').text(g.supplier).html() + ': failed (server error)</li>'); })
                    .always(function() {
                        i++;
                        var pct = Math.round(i * 100 / autoGroups.length);
                        $('#auto_bar').css('width', pct + '%').text(pct + '%');
                        next();
                    });
            }
            next();
        });
    });
</script>
@endsection
