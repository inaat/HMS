<h3 class="text-muted mb-0">
    @lang('lang_v1.cogs') <span class="display_currency" data-currency_symbol="true"> {{ $data['cogs'] }}</span>
</h3>
    <small class="help-block">
        Purchase cost of the items sold (returns excluded) =
        <span class="display_currency" data-currency_symbol="true">{{ $data['cogs_from_purchases'] }}</span> from purchases
        @if($data['cogs_estimated'] > 0)
            + <span class="display_currency" data-currency_symbol="true">{{ $data['cogs_estimated'] }}</span>
            <span class="text-warning">estimated (sold without stock, see below)</span>
        @endif
    </small>
<h3 class="text-muted mb-0">
    {{ __('lang_v1.gross_profit') }}: 
    <span class="display_currency" data-currency_symbol="true">{{$data['gross_profit']}}</span>
</h3>
<small class="help-block">
    (@lang('lang_v1.total_sell_price') - @lang('lang_v1.total_purchase_price'))
    @if(!empty($data['gross_profit_label']))
        {{-- + {{$data['gross_profit_label']}} --}}
        @foreach ($data['gross_profit_label'] as $val)
            + {{$val}}
        @endforeach
    @endif
</small>

<h3 class="text-muted mb-0">
    {{ __('report.net_profit') }}: 
    <span class="display_currency" data-currency_symbol="true">{{$data['net_profit']}}</span>
</h3>
<small class="help-block">@lang('lang_v1.gross_profit') + (@lang('lang_v1.total_sell_shipping_charge') + @lang('lang_v1.sell_additional_expense') + @lang('report.total_stock_recovered') + @lang('lang_v1.total_purchase_discount') + @lang('lang_v1.total_sell_round_off') 
@foreach($data['right_side_module_data'] as $module_data)
    @if(!empty($module_data['add_to_net_profit']))
        + {{$module_data['label']}} 
    @endif
@endforeach
) <br> - (Total Builty  +  @lang('report.total_stock_adjustment') + @lang('report.total_expense') + @lang('lang_v1.total_purchase_shipping_charge') + @lang('lang_v1.total_transfer_shipping_charge') + @lang('lang_v1.purchase_additional_expense') + @lang('lang_v1.total_sell_discount') + @lang('lang_v1.total_reward_amount') 
@foreach($data['left_side_module_data'] as $module_data)
    @if(!empty($module_data['add_to_net_profit']))
        + {{$module_data['label']}}
    @endif 
@endforeach )</small>
@if($data['sold_without_stock']->isNotEmpty())
    @php
        $sws = $data['sold_without_stock'];
    @endphp
    <div class="alert alert-warning" style="margin-top: 15px;">
        <h4 style="margin-top: 0;"><i class="fa fa-exclamation-triangle"></i>
            {{ $sws->count() }} product(s) were sold without stock in this period
        </h4>
        Qty {{ @num_format($sws->sum('qty')) }}, sales
        <span class="display_currency" data-currency_symbol="true">{{ $sws->sum('sales') }}</span>.
        Their purchase cost is not recorded, so COGS uses each product's <strong>default purchase price</strong>
        (<span class="display_currency" data-currency_symbol="true">{{ $sws->sum('estimated_cost') }}</span>).
        Profit becomes exact once the missing purchases are added.
        <a href="#" class="toggle-sold-without-stock" style="margin-left: 8px;"
            onclick="$('#sold_without_stock_table').toggle(); return false;">Show products</a>
    </div>
    <div class="table-responsive" id="sold_without_stock_table" style="display: none;">
        <table class="table table-bordered table-striped table-condensed">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>SKU</th>
                    <th>Qty sold without stock</th>
                    <th>Sales</th>
                    <th>Default purchase price</th>
                    <th>Estimated cost</th>
                    <th>Current stock</th>
                </tr>
            </thead>
            <tbody>
                @foreach($sws as $row)
                    <tr>
                        <td>{{ $row->product }}@if($row->type == 'variable') - {{ $row->variation }}@endif</td>
                        <td>{{ $row->sub_sku }}</td>
                        <td>{{ @num_format($row->qty) }} {{ $row->unit }}</td>
                        <td><span class="display_currency" data-currency_symbol="true">{{ $row->sales }}</span></td>
                        <td><span class="display_currency" data-currency_symbol="true">{{ $row->default_purchase_price }}</span></td>
                        <td><span class="display_currency" data-currency_symbol="true">{{ $row->estimated_cost }}</span></td>
                        <td class="{{ $row->current_stock < 0 ? 'text-danger' : '' }}">{{ @num_format($row->current_stock) }} {{ $row->unit }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
