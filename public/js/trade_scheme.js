/*
 * Trade schemes on the sale screens (POS, Add Sale, their edit pages). Loaded after pos.js.
 *
 * Running schemes come from /trade-schemes/active (TradeSchemeController@active) and the customer's class / channel
 * from /trade-schemes/customer. After every change of a line or the customer the whole bill is worked out again,
 * the same way as TradeSchemeUtil::evaluate on the server:
 *  - one product, free goods of the same product: a line discount worth the free quantity ("13 CTN, 1 CTN free");
 *  - a group / brand / bill value reaching a slab: a free line of the free product (100% discount), or
 *  - a % discount on the matching lines (by customer class when the slab has class %).
 * Discounts of several schemes on one line are combined into one fixed discount per unit. Hidden fields
 * products[i][trade_scheme_ids] (bought lines) and products[i][trade_scheme_id] + [scheme_role]=free (free lines) go
 * with the form; the server checks them again and records what each line got. Staff can drop the schemes of a line
 * (×), and a discount typed by hand replaces them.
 */
(function ($) {
    var TS = { schemes: [], customer: { 'class': null, channel: 'retail' }, busy: 0, timer: null };

    function num(v) {
        v = parseFloat(v);
        return isNaN(v) ? 0 : v;
    }

    function fmtQty(v) {
        return __number_f(v, false, false, __quantity_precision).replace(/\.?0+$/, '');
    }

    // ---- loading ----------------------------------------------------------------------------------------------
    function load() {
        var location_id = $('input#location_id').val() || $('select#select_location_id').val() || '';
        $.getJSON(window.trade_scheme_active_url || '/trade-schemes/active', { location_id: location_id }, function (list) {
            TS.schemes = list || [];
            linkSavedRows();
            schedule();
        });
    }

    function loadCustomer() {
        var id = $('select#customer_id').val();
        if (!id) {
            TS.customer = { 'class': null, channel: 'retail' };
            schedule();
            return;
        }
        $.getJSON(window.trade_scheme_customer_url || '/trade-schemes/customer', { contact_id: id }, function (c) {
            TS.customer = c || { 'class': null, channel: 'retail' };
            schedule();
        });
    }

    function schedule() {
        clearTimeout(TS.timer);
        TS.timer = setTimeout(applyAll, 60);
    }

    // ---- the rules (TradeSchemeUtil) --------------------------------------------------------------------------
    function matches(s, product_id, variation_id) {
        if ((s.scope || 'product') === 'product') {
            return num(product_id) === num(s.product_id) && (!s.variation_id || num(variation_id) === num(s.variation_id));
        }
        return (s.p_ids || []).indexOf(num(product_id)) >= 0 || (s.v_ids || []).indexOf(num(variation_id)) >= 0;
    }

    function freeQty(slabs, qty, repeat) {
        slabs = (slabs || []).slice().sort(function (a, b) { return num(b.buy_qty) - num(a.buy_qty); });
        var free = 0, left = qty + 0.00001;
        for (var i = 0; i < slabs.length; i++) {
            var buy = num(slabs[i].buy_qty);
            if (buy <= 0 || left < buy) {
                continue;
            }
            var times = repeat ? Math.floor(left / buy) : 1;
            free += times * num(slabs[i].free_qty);
            left -= times * buy;
            if (!repeat) {
                break;
            }
        }
        return Math.round(free * 10000) / 10000;
    }

    function bestSlab(slabs, measure) {
        var best = null;
        $.each(slabs || [], function (i, s) {
            if (num(s.buy_qty) > 0 && measure + 0.00001 >= num(s.buy_qty) && (!best || num(s.buy_qty) > num(best.buy_qty))) {
                best = s;
            }
        });
        return best;
    }

    function slabPercent(slab, cls) {
        var byClass = {}, any = false;
        $.each(slab.class_percents || {}, function (k, v) {
            if (v !== null && v !== '') { byClass[k] = num(v); any = true; }
        });
        if (any) {
            if (cls && byClass.hasOwnProperty(cls)) { return byClass[cls]; }
            return Math.min.apply(null, $.map(byClass, function (v) { return v; }));
        }
        return num(slab.percent);
    }

    function evaluate(lines) {
        var out = { lines: {}, free: {} };
        $.each(TS.schemes, function (i, s) {
            if (s.channel && s.channel !== 'all' && s.channel !== TS.customer.channel) {
                return;
            }
            var hit = lines.filter(function (l) { return matches(s, l.product_id, l.variation_id); });
            if (!hit.length) {
                return;
            }
            var add = function (idx, e) { (out.lines[idx] = out.lines[idx] || []).push(e); };
            if (s.reward_type !== 'percent' && s.free_mode === 'same') {
                $.each(hit, function (j, l) {
                    var free = freeQty(s.slabs, l.base_qty / (num(s.unit_mult) || 1), s.repeat);
                    if (s.budget_left !== null && s.budget_left !== undefined) { free = Math.min(free, num(s.budget_left)); }
                    var free_base = Math.min(free * (num(s.free_unit_mult) || 1), l.base_qty);
                    if (free_base > 0) { add(l.idx, { s: s, type: 'same', free: free, free_base: free_base, pct: 0 }); }
                });
                return;
            }
            var measure = 0;
            $.each(hit, function (j, l) {
                if (s.condition_type === 'value') {
                    measure += l.value;
                } else {
                    var factor = (s.scope || 'product') === 'product' ? (num(s.unit_mult) || 1) : (s.count_unit === 'base' ? 1 : l.big);
                    measure += l.base_qty / (factor || 1);
                }
            });
            if (s.reward_type === 'percent') {
                var slab = bestSlab(s.slabs, measure), pct = slab ? slabPercent(slab, TS.customer['class']) : 0;
                if (pct > 0) {
                    $.each(hit, function (j, l) { add(l.idx, { s: s, type: 'percent', free_base: 0, pct: pct }); });
                }
            } else {
                var free = freeQty(s.slabs, measure, s.repeat);
                if (s.budget_left !== null && s.budget_left !== undefined) { free = Math.min(free, num(s.budget_left)); }
                if (free > 0 && s.free_variation_id) {
                    out.free[s.id] = { s: s, qty: free };
                    $.each(hit, function (j, l) { add(l.idx, { s: s, type: 'earn', free_base: 0, pct: 0 }); });
                }
            }
        });
        return out;
    }

    // ---- rows -------------------------------------------------------------------------------------------------
    function rows() {
        return $('#pos_table tbody tr.product_row');
    }

    function bigMultiplier(tr) {
        var max = 1;
        tr.find('select.sub_unit option').each(function () { max = Math.max(max, num($(this).data('multiplier'))); });
        return max;
    }

    function setDiscount(tr, type, amount) {
        TS.busy++;
        tr.find('select.row_discount_type').val(type).trigger('change');
        __write_number(tr.find('input.row_discount_amount'), amount, false, 4);
        tr.find('input.row_discount_amount').trigger('change');
        TS.busy--;
        tr.data('ts_discount', amount);
    }

    function hidden(tr, cls, field, value) {
        var input = tr.find('input.' + cls);
        if (value === null) {
            input.remove();
            return;
        }
        if (!input.length) {
            input = $('<input type="hidden">').addClass(cls).attr('name', 'products[' + tr.attr('data-row_index') + '][' + field + ']').appendTo(tr.find('td:first'));
        }
        input.val(value);
    }

    function setLabel(tr, html, removable) {
        var label = tr.find('.ts_label');
        if (!html) {
            label.remove();
            return;
        }
        if (!label.length) {
            var slot = tr.find('.ts_label_slot').first();
            label = $('<div class="ts_label" style="margin:3px 0;"></div>').appendTo(slot.length ? slot : tr.find('td:first'));
        }
        label.html('<span class="label" style="background:#2e9e6a;white-space:normal;text-align:left;display:inline-block;">'
            + '<i class="fa fa-gift"></i> ' + html + '</span>'
            + (removable ? ' <i class="fa fa-times text-danger cursor-pointer ts_off" title="Remove the schemes from this line"></i>' : ''));
    }

    function clearRow(tr) {
        if (tr.data('ts_discount') !== undefined) {
            var current = num(__read_number(tr.find('input.row_discount_amount')));
            if (Math.abs(current - num(tr.data('ts_discount'))) < 0.01) {
                setDiscount(tr, 'fixed', 0);
            }
            tr.removeData('ts_discount');
        }
        hidden(tr, 'ts_ids', 'trade_scheme_ids', null);
        setLabel(tr, null);
    }

    function slabText(s) {
        return $.map(s.slabs || [], function (x) {
            var buy = s.condition_type === 'value' ? 'Rs ' + __number_f(x.buy_qty, false, false, 0) : fmtQty(x.buy_qty);
            return s.reward_type === 'percent' ? buy + '→%' : buy + (s.condition_type === 'value' ? '→' : '+') + fmtQty(x.free_qty);
        }).join(', ');
    }

    // ---- free lines of another product (one per scheme) --------------------------------------------------------
    function syncFreeRow(s, qty) {
        var row = $('#pos_table tbody tr.ts_free_row[data-ts_parent="S' + s.id + '"]');
        if (!row.length) {
            var method = $('#item_addition_method'), keep = method.val(), before = rows().length;
            TS.busy++;
            method.val(0);
            pos_product_row(s.free_variation_id);
            method.val(keep);
            TS.busy--;
            if (rows().length === before) {
                return;
            }
            row = rows().last();
            row.addClass('ts_free_row').attr('data-ts_parent', 'S' + s.id);
        }
        TS.busy++;
        var unit = row.find('select.sub_unit');
        if (unit.length) {
            var target = s.free_unit_id && unit.find('option[value="' + s.free_unit_id + '"]').length
                ? String(s.free_unit_id)
                : String(unit.find('option').filter(function () { return num($(this).data('multiplier')) === 1; }).first().val() || '');
            if (target && String(unit.val()) !== target) {
                unit.val(target).trigger('change');
            }
        }
        var q = row.find('input.pos_quantity');
        if (Math.abs(num(__read_number(q)) - qty) > 0.00001) {
            __write_number(q, qty);
            q.trigger('change');
        }
        q.prop('readonly', true);
        row.find('.quantity-up, .quantity-down').prop('disabled', true);
        TS.busy--;
        if (row.find('select.row_discount_type').val() !== 'percentage' || num(__read_number(row.find('input.row_discount_amount'))) !== 100) {
            setDiscount(row, 'percentage', 100);
        }
        hidden(row, 'ts_free_id', 'trade_scheme_id', s.id);
        hidden(row, 'ts_role', 'scheme_role', 'free');
        setLabel(row, 'FREE — ' + s.code + ' (' + slabText(s) + ')', false);
    }

    // ---- main ------------------------------------------------------------------------------------------------
    function applyAll() {
        if (TS.busy || !$('#pos_table').length) {
            return;
        }
        var lines = [];
        rows().not('.ts_free_row').each(function () {
            var tr = $(this), qty = num(__read_number(tr.find('input.pos_quantity')));
            lines.push({
                idx: tr.attr('data-row_index'), tr: tr,
                product_id: num(tr.find('input.product_id').val()), variation_id: num(tr.find('input.row_variation_id').val()),
                base_qty: qty * (num(tr.find('input.base_unit_multiplier').val()) || 1),
                value: qty * num(__read_number(tr.find('input.pos_unit_price'))),
                big: bigMultiplier(tr)
            });
        });
        var result = evaluate(lines);

        $.each(lines, function (i, l) {
            var tr = l.tr, effects = result.lines[l.idx] || [];
            if (tr.data('ts_off') || !effects.length) {
                clearRow(tr);
                return;
            }
            var fs = 0, pct = 0, ids = [], texts = [];
            $.each(effects, function (j, e) {
                ids.push(e.s.id);
                if (e.type === 'same') {
                    fs = Math.max(fs, l.base_qty > 0 ? e.free_base / l.base_qty : 0);
                    texts.push(e.s.code + ' (' + slabText(e.s) + '): ' + fmtQty(e.free) + ' ' + (e.s.free_unit_name || '') + ' free');
                } else if (e.type === 'percent') {
                    pct += e.pct;
                    texts.push(e.s.code + ' ' + fmtQty(e.pct) + '%');
                } else {
                    texts.push(e.s.code + ': ' + (e.s.free_name || 'free goods') + ' earned');
                }
            });
            var d = 1 - (1 - fs) * (1 - pct / 100);
            var price = num(__read_number(tr.find('input.pos_unit_price')));
            var per_unit = Math.round(price * d * 10000) / 10000;
            if (d > 0) {
                var current = num(__read_number(tr.find('input.row_discount_amount')));
                if (tr.find('select.row_discount_type').val() !== 'fixed' || Math.abs(current - per_unit) > 0.00009) {
                    setDiscount(tr, 'fixed', per_unit);
                } else {
                    tr.data('ts_discount', per_unit);
                }
            } else if (tr.data('ts_discount') !== undefined) {
                // the line only earns a free item now: take back the discount the schemes had put on it
                var was = num(__read_number(tr.find('input.row_discount_amount')));
                if (Math.abs(was - num(tr.data('ts_discount'))) < 0.01) {
                    setDiscount(tr, 'fixed', 0);
                }
                tr.removeData('ts_discount');
            }
            hidden(tr, 'ts_ids', 'trade_scheme_ids', ids.join(','));
            var qty = num(__read_number(tr.find('input.pos_quantity')));
            setLabel(tr, texts.join(' · ') + (d > 0 ? ' = ' + __currency_trans_from_en(per_unit * qty, true) + ' off' : ''), true);
        });

        // free lines: add / update the earned ones, remove the rest
        $.each(result.free, function (id, f) { syncFreeRow(f.s, f.qty); });
        $('#pos_table tbody tr.ts_free_row').each(function () {
            var id = String($(this).attr('data-ts_parent') || '').replace('S', '');
            if (!result.free[id]) {
                $(this).remove();
            }
        });
        pos_total_row();
    }

    // rows saved with schemes (edit, sales order → invoice)
    function linkSavedRows() {
        rows().each(function () {
            var tr = $(this);
            if (tr.find('input.ts_role').val() === 'free' && !tr.hasClass('ts_free_row')) {
                tr.addClass('ts_free_row').attr('data-ts_parent', 'S' + tr.find('input.ts_free_id').val());
                tr.find('input.pos_quantity').prop('readonly', true);
            } else if (tr.find('input.ts_ids').length && tr.data('ts_discount') === undefined) {
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
            if (!TS.busy) { schedule(); }
        });

        // a discount typed by hand replaces the schemes of that line
        tbody.on('change', 'input.row_discount_amount, select.row_discount_type', function () {
            if (TS.busy) {
                return;
            }
            var tr = $(this).closest('tr');
            if (tr.find('input.ts_ids').length && !tr.hasClass('ts_free_row')) {
                tr.data('ts_off', true).removeData('ts_discount');
                hidden(tr, 'ts_ids', 'trade_scheme_ids', null);
                setLabel(tr, null);
                schedule();
            }
        });

        tbody.on('click', '.ts_off', function () {
            $(this).closest('tr').data('ts_off', true);
            schedule();
        });

        tbody.on('click', 'i.pos_remove_row', function () { setTimeout(schedule, 0); });

        // new lines (search, barcode, sales order)
        new MutationObserver(function () {
            if (!TS.busy) {
                linkSavedRows();
                schedule();
            }
        }).observe(tbody[0], { childList: true });

        $(document).on('change', 'select#customer_id', loadCustomer);
        $(document).on('change', 'select#select_location_id', function () { setTimeout(load, 300); });

        load();
        loadCustomer();
    });
})(jQuery);
