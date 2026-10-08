@extends('layouts.app')
@section('title', 'Zakat')

@section('content')
@php
    $m = \App\Utils\ZakatUtil::maslak($settings);
    $categories = \App\Utils\ZakatUtil::CATEGORIES;
    $locked = $year && $year->status === 'locked';
    $given_cash = $payments->where('kind', 'cash')->sum('amount_value');
    $given_goods = $payments->where('kind', 'goods')->sum('amount_value');
    $remaining = (float) $calc['zakat_due'] - $given_cash - $given_goods;
    $fd = function ($d) { return \Carbon::parse($d)->format(session('business.date_format', 'd-m-Y')); };
@endphp
<section class="content-header no-print">
    <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">Zakat
        <small>{{ $m['label'] }} · {{ $settings['year_type'] === 'solar' ? 'solar year 2.577%' : 'lunar year 2.5%' }}
            · <a href="{{ action([\App\Http\Controllers\BusinessController::class, 'getBusinessSettings']) }}">settings</a></small>
    </h1>
</section>

<section class="content no-print">
    @if (empty($settings['enabled']))
        <div class="alert alert-warning">Zakat is turned off. Turn it on in <b>Settings → Business Settings → Zakat</b> and choose your maslak.</div>
    @endif
    @if (! $m['obligatory'])
        <div class="alert alert-info">{{ $m['note'] }}</div>
    @endif

    @component('components.filters', ['title' => __('report.filters')])
        <div class="col-md-4">
            <div class="form-group">
                {!! Form::label('year_pick', 'Zakat year:') !!}
                <select id="year_pick" class="form-control select2" style="width:100%;">
                    @forelse ($years as $y)
                        <option value="{{ $y->id }}" @if ($year && $year->id == $y->id) selected @endif>
                            {{ $fd($y->zakat_date) }} — {{ $y->hijri_label }} ({{ $y->status }})</option>
                    @empty
                        <option value="">No year saved yet</option>
                    @endforelse
                </select>
            </div>
        </div>
    @endcomponent

    <div class="row">
        <div class="col-md-7">
            @component('components.widget', ['title' => 'Calculation — on '.$fd($date).' ('.\App\Utils\ZakatUtil::hijri($date).')'])
                <form method="POST" action="{{ action([\App\Http\Controllers\ZakatController::class, 'saveYear']) }}">
                    @csrf
                    <input type="hidden" name="year_id" value="{{ $year && ! $locked ? $year->id : '' }}">
                    <table class="table table-bordered table-condensed">
                        <tbody>
                            <tr><td>Cash & bank <small class="text-muted">({{ empty($settings['accounts']) ? 'all payment accounts' : 'chosen payment accounts' }})</small>
                                    @if (! empty($calc['cash_accounts']))
                                        <div class="small text-muted" style="margin-top:3px;">
                                            @foreach ($calc['cash_accounts'] as $acc)
                                                <div style="display:flex; justify-content:space-between; gap:12px; padding-left:12px;">
                                                    <span>{{ $acc['name'] }}</span><span class="display_currency" data-currency_symbol="true">{{ $acc['balance'] }}</span>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif</td>
                                <td class="text-right"><span class="display_currency" data-currency_symbol="true">{{ $calc['cash'] }}</span></td></tr>
                            <tr><td>Stock for sale <small class="text-muted">({{ $settings['stock_basis'] === 'sale' ? 'selling price' : 'purchase cost' }})</small></td>
                                <td class="text-right"><span class="display_currency" data-currency_symbol="true">{{ $calc['stock_value'] }}</span></td></tr>
                            <tr><td>Customer dues you expect to receive
                                    @if (! empty($calc['receivables_skipped'])) <br><small class="text-muted">skipped (inactive customers): <span class="display_currency" data-currency_symbol="true">{{ $calc['receivables_skipped'] }}</span></small> @endif</td>
                                <td class="text-right"><span class="display_currency" data-currency_symbol="true">{{ $calc['receivables'] }}</span></td></tr>
                            <tr><td>− Supplier dues the business owes @if (empty($settings['deduct_payables'])) <small class="text-muted">(not deducted for this maslak)</small> @endif</td>
                                <td class="text-right text-danger">− <span class="display_currency" data-currency_symbol="true">{{ $calc['payables'] }}</span></td></tr>
                            @foreach ($manual as $line)
                                <tr><td>{{ $line['label'] }} <small class="text-muted">(added by hand)</small></td>
                                    <td class="text-right"><span class="display_currency" data-currency_symbol="true">{{ $line['amount'] }}</span></td></tr>
                            @endforeach
                            @unless ($locked)
                                <tr class="no-print"><td colspan="2">
                                    <div class="row" id="manual_lines">
                                        @foreach (array_merge($manual, [['label' => '', 'amount' => '']]) as $line)
                                            <div class="col-xs-8"><input name="manual_label[]" class="form-control input-sm" value="{{ $line['label'] }}" placeholder="Other (e.g. cash at home, gold, loan given / taken)"></div>
                                            <div class="col-xs-4"><input name="manual_amount[]" class="form-control input-sm input_number" value="{{ $line['amount'] }}" placeholder="+ / − amount"></div>
                                        @endforeach
                                    </div>
                                </td></tr>
                            @endunless
                            <tr style="background:#f5f5f5;"><th>Net zakatable wealth</th>
                                <th class="text-right"><span class="display_currency" data-currency_symbol="true">{{ $calc['net_wealth'] }}</span></th></tr>
                            <tr><td>Nisab ({{ $settings['nisab_basis'] === 'gold' ? '87.48 g gold' : '612.36 g silver' }})
                                    @if ((float) $calc['nisab_value'] <= 0) <small class="text-muted">— not checked (no price per gram entered): zakat is {{ $calc['rate'] }}% of the net wealth</small> @endif</td>
                                <td class="text-right"><span class="display_currency" data-currency_symbol="true">{{ $calc['nisab_value'] }}</span></td></tr>
                            <tr style="background:#e8f5ee; font-size:16px;"><th>{{ $m['obligatory'] ? 'Zakat due' : 'Zakat (recommended)' }} ({{ $calc['rate'] }}%)</th>
                                <th class="text-right">@if ($calc['reaches_nisab'])<span class="display_currency" data-currency_symbol="true">{{ $calc['zakat_due'] }}</span>@else <span class="text-muted">below nisab — no zakat due</span>@endif</th></tr>
                        </tbody>
                    </table>
                    @unless ($locked)
                        <div class="row">
                            <div class="col-sm-6">
                                <div class="input-group">
                                    <span class="input-group-addon"><i class="fa fa-calendar"></i></span>
                                    <input type="text" name="zakat_date" id="zakat_calc_date" class="form-control" readonly value="{{ $fd($date) }}">
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <button class="tw-dw-btn tw-dw-btn-primary tw-text-white tw-w-full"><i class="fa fa-save"></i> Save calculation</button>
                            </div>
                        </div>
                    @endunless
                </form>
                @if ($year && ! $locked)
                    <form method="POST" action="{{ action([\App\Http\Controllers\ZakatController::class, 'lockYear'], [$year->id]) }}" style="margin-top:8px;"
                        onsubmit="return confirm('Lock this zakat year? Its numbers will be kept as they are now.');">
                        @csrf
                        <button class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-outline tw-dw-btn-error"><i class="fa fa-lock"></i> Lock this year</button>
                    </form>
                @endif
            @endcomponent
        </div>

        <div class="col-md-5">
            @component('components.widget', ['title' => 'This year'])
                <table class="table table-condensed">
                    <tr><td>{{ $m['obligatory'] ? 'Zakat due' : 'Zakat (recommended)' }}</td><td class="text-right"><b><span class="display_currency" data-currency_symbol="true">{{ $calc['reaches_nisab'] ? $calc['zakat_due'] : 0 }}</span></b></td></tr>
                    <tr><td>Given in cash</td><td class="text-right"><span class="display_currency" data-currency_symbol="true">{{ $given_cash }}</span></td></tr>
                    <tr><td>Given in products <small class="text-muted">(selling value)</small></td><td class="text-right"><span class="display_currency" data-currency_symbol="true">{{ $given_goods }}</span></td></tr>
                    <tr style="font-size:16px;"><th>Remaining</th><th class="text-right {{ $remaining > 0 ? 'text-danger' : 'text-success' }}"><span class="display_currency" data-currency_symbol="true">{{ $calc['reaches_nisab'] ? max(0, $remaining) : 0 }}</span></th></tr>
                </table>
                <button type="button" class="tw-dw-btn tw-dw-btn-success tw-text-white tw-w-full" data-toggle="modal" data-target="#zakat_cash_modal"><i class="fa fa-hand-holding-usd"></i> Record zakat given (cash, or given earlier)</button>
                @if (\App\Utils\ZakatUtil::goodsAllowed($settings))
                    <p class="help-block" style="margin-top:8px;"><i class="fa fa-info-circle"></i> To give products: open <a href="{{ action([\App\Http\Controllers\SellPosController::class, 'create']) }}">POS</a>, add the products, press <b>Zakat</b>.</p>
                @else
                    <p class="help-block" style="margin-top:8px;"><i class="fa fa-info-circle"></i> For your maslak, give zakat on trade goods as their value in cash.</p>
                @endif
            @endcomponent
        </div>
    </div>

    @component('components.widget', ['title' => 'Zakat given ('.$payments->count().')'])
        <div class="table-responsive">
            <table class="table table-bordered table-striped table-condensed">
                <thead><tr style="background:#f5f5f5;"><th>Date</th><th>Given as</th><th>Recipient</th><th>Category</th><th class="text-right">Zakat value</th><th class="text-right">Cost</th><th>From</th><th>By</th><th></th></tr></thead>
                <tbody>
                    @forelse ($payments as $p)
                        <tr>
                            <td>{{ \Carbon::parse($p->paid_on)->format(session('business.date_format', 'd-m-Y').' H:i') }}</td>
                            <td>@if ($p->kind == 'goods')<span class="label label-info">Products</span>@else<span class="label label-success">Cash</span>@endif</td>
                            <td>{{ $p->recipient_name }} @if ($p->recipient_mobile)<div class="text-muted small">{{ $p->recipient_mobile }}</div>@endif</td>
                            <td>{{ $categories[$p->category] ?? '' }}</td>
                            <td class="text-right"><span class="display_currency" data-currency_symbol="true">{{ $p->amount_value }}</span></td>
                            <td class="text-right">@if ($p->kind == 'goods')<span class="display_currency" data-currency_symbol="true">{{ $p->cost_value }}</span>@endif</td>
                            <td>{{ $p->kind == 'goods' ? 'Stock '.$p->ref_no : ($p->account_name ?: '—') }}</td>
                            <td>{{ $p->given_by }}</td>
                            <td style="white-space:nowrap;">
                                <a href="{{ action([\App\Http\Controllers\ZakatController::class, 'slip'], [$p->id]) }}" target="_blank" class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-primary"><i class="fa fa-print"></i> Slip</a>
                                @unless ($locked)
                                    <form method="POST" action="{{ action([\App\Http\Controllers\ZakatController::class, 'destroyPayment'], [$p->id]) }}" style="display:inline;" class="zakat-delete"
                                        data-msg="{{ $p->kind == 'goods' && $p->transaction_id ? 'Delete this zakat? The products go back into stock.' : ($p->account_transaction_id ?? null ? 'Delete this zakat? The amount goes back into the account.' : 'Delete this zakat entry?') }}">
                                        @csrf
                                        <button type="submit" class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-error"><i class="fa fa-trash"></i> Delete</button>
                                    </form>
                                @endunless
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="text-center text-muted">Nothing given yet this year.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endcomponent
</section>

{{-- Give zakat in cash --}}
<div class="modal fade" id="zakat_cash_modal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <form method="POST" action="{{ action([\App\Http\Controllers\ZakatController::class, 'payCash']) }}" class="modal-content">
            @csrf
            <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title">Record zakat given</h4></div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-sm-12"><div class="form-group"><label>Given as</label>
                        {!! Form::select('given_as', ['cash' => 'Cash', 'earlier_goods' => 'Products — already given before (record only, stock not changed)'], 'cash', ['class' => 'form-control select2', 'style' => 'width:100%', 'id' => 'zakat_given_as']) !!}
                        <p class="help-block">Zakat given before you started using this screen: pick its date below — it counts toward the year without changing today's stock.</p></div></div>
                    <div class="col-sm-6"><div class="form-group"><label>Amount (value) *</label><input name="amount" class="form-control input_number" required></div></div>
                    <div class="col-sm-6"><div class="form-group"><label>Date</label>
                        <div class="input-group"><span class="input-group-addon"><i class="fa fa-calendar"></i></span>
                            <input name="paid_on" id="zakat_paid_on" class="form-control" readonly value="{{ $fd(now()) }}"></div></div></div>
                    <div class="col-sm-6"><div class="form-group"><label>Recipient name</label><input name="recipient_name" class="form-control"></div></div>
                    <div class="col-sm-6"><div class="form-group"><label>Mobile</label><input name="recipient_mobile" class="form-control"></div></div>
                    <div class="col-sm-6"><div class="form-group"><label>Category</label>
                        {!! Form::select('category', $categories, 'fuqara', ['class' => 'form-control select2', 'style' => 'width:100%']) !!}</div></div>
                    <div class="col-sm-6 zakat-cash-only"><div class="form-group"><label>Paid from account</label>
                        {!! Form::select('account_id', $accounts, null, ['class' => 'form-control select2', 'style' => 'width:100%', 'placeholder' => 'Not from an account']) !!}</div></div>
                    <div class="col-sm-12"><div class="form-group"><label>Note</label><input name="note" class="form-control"></div></div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="tw-dw-btn tw-dw-btn-success tw-text-white">Save</button>
                <button type="button" class="tw-dw-btn tw-dw-btn-neutral tw-text-white" data-dismiss="modal">Cancel</button>
            </div>
        </form>
    </div>
</div>
@endsection

@section('javascript')
<script>
    $(function () {
        $('#zakat_calc_date, #zakat_paid_on').datepicker({autoclose: true});
        // Delete a zakat entry: POS confirm dialog, then the stock / account is put back
        $(document).on('submit', 'form.zakat-delete', function (e) {
            var form = this;
            if ($(form).data('ok')) { return; }
            e.preventDefault();
            swal({ title: $(form).data('msg'), icon: 'warning', buttons: ['Cancel', 'Delete'], dangerMode: true }).then(function (ok) {
                if (ok) { $(form).data('ok', true); form.submit(); }
            });
        });
        $('#year_pick').on('change', function () {
            if (this.value) { window.location = '{{ action([\App\Http\Controllers\ZakatController::class, 'index']) }}?year_id=' + this.value; }
        });
        $(document).on('change', '#zakat_given_as', function () { $('.zakat-cash-only').toggle(this.value === 'cash'); });
        $('#zakat_cash_modal').on('shown.bs.modal', function () {
            $(this).find('.select2').select2({dropdownParent: $('#zakat_cash_modal')});
        });
        @if (session('zakat_slip'))
            window.open('{{ action([\App\Http\Controllers\ZakatController::class, 'slip'], [session('zakat_slip')]) }}', '_blank');
        @endif
    });
</script>
@endsection
