/*
 * Trade schemes on the sale screens (POS, Add Sale, their edit pages). Loaded after pos.js.
 *
 * Running schemes come from /trade-schemes/active (TradeSchemeController@active) for the sale's location. When a
 * line's quantity reaches a slab:
 *  - same product: the line gets a fixed discount worth the free quantity ("13 CTN, 1 CTN free" = 1 CTN off);
 *  - another product: a free line of that product is added at 100% discount and kept in step with the bought line.
 * Hidden fields products[i][trade_scheme_id] / [scheme_role] go with the form; the server checks them again and
 * records the free quantity (TradeSchemeUtil::applyToLines). Staff can drop the scheme from a line (×), and a
 * discount typed by hand replaces it.
 */
(function ($) {
    var TS = { byVariation: {}, byProduct: {}, busy: 0, loadedFor: null };

    function activeUrl() {
        return window.trade_scheme_active_url || '/trade-schemes/active';
    }

    function num(v) {
        v = parseFloat(v);
        return isNaN(v) ? 0 : v;
    }

    function fmtQty(v) {
        return __number_f(v, false, false, __quantity_precision).replace(/\.?0+$/, '');
    }

    // ---- loading ----------------------------------------------------------------------------------------------
    function load(force) {
        var location_id = $('input#location_id').val() || $('select#select_location_id').val() || '';
        if (!force && TS.loadedFor === location_id) {
            return;
        }
        TS.loadedFor = location_id;
        $.getJSON(activeUrl(), { location_id: location_id }, function (list) {
            TS.byVariation = {};
            TS.byProduct = {};
            $.each(list || [], function (i, s) {
                if (s.variation_id) {
                    TS.byVariation[s.variation_id] = s;
                } else {
                    TS.byProduct[s.product_id] = s;
                }
            });
            linkSavedFreeRows();
            $('#pos_table tbody tr.product_row').each(function () {
                apply($(this));
            });
        });
    }

    function schemeFor(tr) {
        var v = num(tr.find('input.row_variation_id').val());
        var p = num(tr.find('input.product_id').val());
        return TS.byVariation[v] || TS.byProduct[p] || null;
    }

    function schemeById(id) {
        var found = null;
        $.each([TS.byVariation, TS.byProduct], function (i, map) {
            $.each(map, function (k, s) {
                if (String(s.id) === String(id)) {
                    found = s;
                }
            });
        });
        return found;
    }

    // ---- helpers ----------------------------------------------------------------------------------------------
    function freeQty(s, qty) {
        var slabs = (s.slabs || []).slice().sort(function (a, b) { return num(b.buy_qty) - num(a.buy_qty); });
        var free = 0, left = qty + 0.00001;
        for (var i = 0; i < slabs.length; i++) {
            var buy = num(slabs[i].buy_qty);
            if (buy <= 0 || left < buy) {
                continue;
            }
            var times = s.repeat ? Math.floor(left / buy) : 1;
            free += times * num(slabs[i].free_qty);
            left -= times * buy;
            if (!s.repeat) {
                break;
            }
        }
        return Math.round(free * 10000) / 10000;
    }

    function rowIndex(tr) {
        return tr.attr('data-row_index');
    }

    function rowMultiplier(tr) {
        return num(tr.find('input.base_unit_multiplier').val()) || 1;
    }

    function setHidden(tr, s, role) {
        var idx = rowIndex(tr);
        var cell = tr.find('td:first');
        var id_input = tr.find('input.ts_trade_scheme_id');
        if (!id_input.length) {
            id_input = $('<input type="hidden" class="ts_trade_scheme_id">').attr('name', 'products[' + idx + '][trade_scheme_id]').appendTo(cell);
        }
        id_input.val(s.id);
        tr.find('input.ts_scheme_role').remove();
        if (role) {
            $('<input type="hidden" class="ts_scheme_role">').attr('name', 'products[' + idx + '][scheme_role]').val(role).appendTo(cell);
        }
    }

    function setLabel(tr, html, removable) {
        var label = tr.find('.ts_label');
        if (!html) {
            label.remove();
            return;
        }
        if (!label.length) {
            // under the product name (product_row.blade.php), else at the end of the first cell
            var slot = tr.find('.ts_label_slot').first();
            label = $('<div class="ts_label" style="margin:3px 0;"></div>').appendTo(slot.length ? slot : tr.find('td:first'));
        }
        label.html('<span class="label" style="background:#2e9e6a;white-space:normal;text-align:left;display:inline-block;">'
            + '<i class="fa fa-gift"></i> ' + html + '</span>'
            + (removable ? ' <i class="fa fa-times text-danger cursor-pointer ts_off" title="Remove the scheme from this line"></i>' : ''));
    }

    function clearRow(tr) {
        tr.find('input.ts_trade_scheme_id, input.ts_scheme_role').remove();
        setLabel(tr, null);
    }

    function setDiscount(tr, type, amount) {
        TS.busy++;
        tr.find('select.row_discount_type').val(type).trigger('change');
        __write_number(tr.find('input.row_discount_amount'), amount, false, 4);
        tr.find('input.row_discount_amount').trigger('change');
        TS.busy--;
        tr.data('ts_discount', amount);
    }

    // ---- same product: discount on the line ------------------------------------------------------------------
    function dropSameDiscount(tr) {
        if (tr.data('ts_discount') === undefined) {
            return;
        }
        var current = num(__read_number(tr.find('input.row_discount_amount')));
        if (Math.abs(current - num(tr.data('ts_discount'))) < 0.01) {
            setDiscount(tr, 'fixed', 0);
        }
        tr.removeData('ts_discount');
        clearRow(tr);
    }

    // ---- another product: a free line --------------------------------------------------------------------
    function freeRowOf(tr) {
        return $('#pos_table tbody tr.ts_free_row[data-ts_parent="' + rowIndex(tr) + '"]');
    }

    function removeFreeRow(tr) {
        var free = freeRowOf(tr);
        if (free.length) {
            free.remove();
            pos_total_row();
        }
    }

    function syncFreeRow(tr, s, free) {
        var row = freeRowOf(tr);
        if (free <= 0) {
            removeFreeRow(tr);
            clearRow(tr);
            return;
        }
        if (!row.length) {
            // add the free product as a new line (never merged into an existing line of the same product)
            var method = $('#item_addition_method');
            var keep = method.val();
            TS.busy++;
            method.val(0);
            var before = $('#pos_table tbody tr.product_row').length;
            pos_product_row(s.free_variation_id);
            method.val(keep);
            TS.busy--;
            if ($('#pos_table tbody tr.product_row').length === before) {
                return;
            }
            row = $('#pos_table tbody tr.product_row').last();
            row.addClass('ts_free_row').attr('data-ts_parent', rowIndex(tr));
        }
        TS.busy++;
        // free quantity is in the scheme's free unit; no free unit = base unit (multiplier 1), whatever the line opened in
        var unit = row.find('select.sub_unit');
        if (unit.length) {
            var target = s.free_unit_id && unit.find('option[value="' + s.free_unit_id + '"]').length
                ? String(s.free_unit_id)
                : String(unit.find('option').filter(function () { return num($(this).data('multiplier')) === 1; }).first().val() || '');
            if (target && String(unit.val()) !== target) {
                unit.val(target).trigger('change');
            }
        }
        var qty_input = row.find('input.pos_quantity');
        __write_number(qty_input, free);
        qty_input.prop('readonly', true).trigger('change');
        row.find('.quantity-up, .quantity-down').prop('disabled', true);
        TS.busy--;
        setDiscount(row, 'percentage', 100);
        setHidden(row, s, 'free');
        setLabel(row, 'FREE — ' + s.code + ' (' + slabText(s) + ')', false);
        setHidden(tr, s, '');
        var free_unit = s.free_unit_name || $.trim(row.find('select.sub_unit option:selected').text()) || '';
        setLabel(tr, s.code + ' (' + slabText(s) + '): ' + fmtQty(free) + (free_unit ? ' ' + free_unit : '') + ' ' + (s.free_name || '') + ' free', true);
    }

    function slabText(s) {
        return (s.slabs || []).map(function (x) { return fmtQty(x.buy_qty) + '+' + fmtQty(x.free_qty); }).join(', ');
    }

    // ---- main ------------------------------------------------------------------------------------------------
    function apply(tr) {
        if (!tr || !tr.length || tr.hasClass('ts_free_row') || !tr.closest('body').length) {
            return;
        }
        var s = schemeFor(tr);
        if (!s || tr.data('ts_off')) {
            dropSameDiscount(tr);
            removeFreeRow(tr);
            if (!s) {
                clearRow(tr);
            }
            return;
        }
        var qty = num(__read_number(tr.find('input.pos_quantity')));
        var base_qty = qty * rowMultiplier(tr);
        var free = freeQty(s, base_qty / (num(s.unit_mult) || 1));
        if (s.budget_left !== null && s.budget_left !== undefined) {
            free = Math.min(free, num(s.budget_left));
        }

        if (s.free_mode === 'other') {
            dropSameDiscount(tr);
            syncFreeRow(tr, s, free);
            return;
        }

        var free_base = Math.min(free * (num(s.free_unit_mult) || 1), base_qty);
        if (free_base <= 0 || base_qty <= 0) {
            dropSameDiscount(tr);
            return;
        }
        var price = num(__read_number(tr.find('input.pos_unit_price')));
        var per_unit = Math.round(price * free_base / base_qty * 10000) / 10000;
        setDiscount(tr, 'fixed', per_unit);
        setHidden(tr, s, '');
        setLabel(tr, s.code + ' (' + slabText(s) + '): ' + fmtQty(free) + ' ' + (s.free_unit_name || '') + ' free = '
            + __currency_trans_from_en(per_unit * qty, true) + ' off', true);
    }

    // rows saved with a scheme (edit, sales order → invoice): free lines find their bought line again
    function linkSavedFreeRows() {
        $('#pos_table tbody tr.product_row').each(function () {
            var tr = $(this);
            if (tr.find('input.ts_scheme_role').val() === 'free' && !tr.hasClass('ts_free_row')) {
                var id = tr.find('input.ts_trade_scheme_id').val();
                var parent = $('#pos_table tbody tr.product_row').filter(function () {
                    return $(this).find('input.ts_trade_scheme_id').val() === id && $(this).find('input.ts_scheme_role').val() !== 'free';
                }).first();
                if (parent.length) {
                    tr.addClass('ts_free_row').attr('data-ts_parent', rowIndex(parent));
                    tr.find('input.pos_quantity').prop('readonly', true);
                } else {
                    clearRow(tr);
                }
            } else if (tr.find('input.ts_trade_scheme_id').length && tr.data('ts_discount') === undefined) {
                tr.data('ts_discount', num(__read_number(tr.find('input.row_discount_amount'))));
            }
        });
    }

    $(document).ready(function () {
        if (!$('#pos_table').length) {
            return;
        }
        var tbody = $('#pos_table tbody');

        tbody.on('change', 'input.pos_quantity, select.sub_unit, input.pos_unit_price', function () {
            if (TS.busy) {
                return;
            }
            var tr = $(this).closest('tr');
            setTimeout(function () { apply(tr); }, 0);
        });

        // a discount typed by hand replaces the scheme on that line
        tbody.on('change', 'input.row_discount_amount, select.row_discount_type', function () {
            if (TS.busy) {
                return;
            }
            var tr = $(this).closest('tr');
            if (tr.find('input.ts_trade_scheme_id').length && !tr.hasClass('ts_free_row')) {
                tr.data('ts_off', true).removeData('ts_discount');
                removeFreeRow(tr);
                clearRow(tr);
            }
        });

        tbody.on('click', '.ts_off', function () {
            var tr = $(this).closest('tr');
            tr.data('ts_off', true);
            apply(tr);
        });

        // removing a bought line removes its free line
        tbody.on('click', 'i.pos_remove_row', function () {
            setTimeout(function () {
                tbody.find('tr.ts_free_row').each(function () {
                    var parent = tbody.find('tr.product_row[data-row_index="' + $(this).attr('data-ts_parent') + '"]');
                    if (!parent.length) {
                        $(this).remove();
                    }
                });
                pos_total_row();
            }, 0);
        });

        // new lines (search, barcode, sales order)
        var observer = new MutationObserver(function (mutations) {
            if (TS.busy) {
                return;
            }
            mutations.forEach(function (m) {
                $(m.addedNodes).filter('tr.product_row').each(function () {
                    var tr = $(this);
                    setTimeout(function () {
                        if (tr.find('input.ts_trade_scheme_id').length) {
                            linkSavedFreeRows();
                        }
                        apply(tr);
                    }, 0);
                });
            });
        });
        observer.observe(tbody[0], { childList: true });

        $(document).on('change', 'select#select_location_id', function () {
            setTimeout(function () { load(true); }, 300);
        });

        load(true);
    });
})(jQuery);
