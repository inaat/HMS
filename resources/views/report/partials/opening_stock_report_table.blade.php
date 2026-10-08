<table class="table table-striped">
    <tr>
        <th>{{ __('report.opening_stock') }} <br><small class="text-muted">(@lang('lang_v1.by_purchase_price'))</small>:</th>
        <td>
            <span class="display_currency" data-currency_symbol="true">{{$data['opening_stock']}}</span>
        </td>
    </tr>
    <tr>
        <th>{{ __('report.opening_stock') }} <br><small class="text-muted">(@lang('lang_v1.by_sale_price'))</small>:</th>
        <td>
            @if(isset($stocks['opening_stock_by_sp']))
                <span class="display_currency" data-currency_symbol="true">{{ $stocks['opening_stock_by_sp'] }}</span>
            @else
                 <span id="opening_stock_by_sp"><i class="fa fa-sync fa-spin fa-fw "></i></span>
            @endif
        </td>
    </tr>
    <tr>
        <th>{{ __('home.total_purchase') }}:<br><small class="text-muted">(@lang('product.exc_of_tax'), @lang('sale.discount'))</small></th>
        <td>
            <span class="display_currency" data-currency_symbol="true">{{$data['total_purchase']}}</span>
        </td>
    </tr>
    <tr>
        <th>{{ __('report.total_stock_adjustment') }}:</th>
        <td>
            <span class="display_currency" data-currency_symbol="true">{{$data['total_adjustment']}}</span>
        </td>
    </tr>
    @if (! empty($data['total_zakat_goods']))
        {{-- Zakat given in goods: not a business loss (left out of the adjustment line above); stock is already lower by it --}}
        <tr>
            <th>Zakat given in goods (at cost, owner's share — not a loss):</th>
            <td>
                <span class="display_currency" data-currency_symbol="true">{{$data['total_zakat_goods']}}</span>
            </td>
        </tr>
    @endif
    <tr>
        <th>{{ __('report.total_expense') }}:</th>
        <td>
            <span class="display_currency" data-currency_symbol="true">{{$data['total_expense']}}</span>
            @if(! empty($data['commission_expensed']) && abs($data['commission_expensed']) >= 0.01)
                <br><small class="text-muted">commission payouts are under Sales commission below</small>
            @endif
        </td>
    </tr>
    @if(! empty($data['commission_earned']) && abs($data['commission_earned']) >= 0.01 || ! empty($data['commission_expensed']) && abs($data['commission_expensed']) >= 0.01)
    <tr>
        <th>Sales commission (agents):
            <br><small class="text-muted">earned on sales of these dates, returns taken off</small>
        </th>
        <td>
            <span class="display_currency" data-currency_symbol="true">{{ $data['commission_earned'] }}</span>
            <br><small class="text-muted">paid (expenses):</small> <small><span class="display_currency" data-currency_symbol="true">{{ $data['commission_expensed'] }}</span></small>
            <br><small class="{{ $data['commission_due'] > 0.009 ? 'text-danger' : 'text-muted' }}">due, not paid yet:</small>
            <small><span class="display_currency" data-currency_symbol="true">{{ $data['commission_due'] }}</span></small>
        </td>
    </tr>
    @endif
       <tr>
                <th>Total Builty:</th>
                <td>
                    <span class="display_currency" data-currency_symbol="true">{{$data['total_builty']}}</span>
                </td>
            </tr>
            @if(! empty($data['without_invoice_return_adjustment']) && abs($data['without_invoice_return_adjustment']) >= 0.01)
            <tr>
                <th>
                    Without-invoice return price difference:
                    <br><small class="text-muted">Sale price of returned items minus the return price credited (added to net profit)</small>
                </th>
                <td>
                    <span class="display_currency" data-currency_symbol="true">{{$data['without_invoice_return_adjustment']}}</span>
                </td>
            </tr>
            @endif
    <tr>
        <th>{{ __('lang_v1.total_purchase_shipping_charge') }}:</th>
        <td>
            <span class="display_currency" data-currency_symbol="true">{{$data['total_purchase_shipping_charge']}}</span>
        </td>
    </tr>
    <tr>
        <th>{{ __('lang_v1.purchase_additional_expense') }}:</th>
        <td>
            <span class="display_currency" data-currency_symbol="true">{{$data['total_purchase_additional_expense']}}</span>
        </td>
    </tr>
    <tr>
        <th>{{ __('lang_v1.total_transfer_shipping_charge') }}:</th>
        <td>
            <span class="display_currency" data-currency_symbol="true">{{$data['total_transfer_shipping_charges']}}</span>
        </td>
    </tr>
    <tr>
        <th>{{ __('lang_v1.total_sell_discount') }}:</th>
        <td>
            <span class="display_currency" data-currency_symbol="true">{{$data['total_sell_discount']}}</span>
        </td>
    </tr>
    <tr>
        <th>{{ __('lang_v1.total_reward_amount') }}:</th>
        <td>
            <span class="display_currency" data-currency_symbol="true">{{$data['total_reward_amount']}}</span>
        </td>
    </tr>
    <tr>
        <th>{{ __('lang_v1.total_sell_return') }}:</th>
        <td>
            <span class="display_currency" data-currency_symbol="true">{{$data['total_sell_return']}}</span>
        </td>
    </tr>
    @foreach($data['left_side_module_data'] as $module_data)
        <tr>
            <th>{{ $module_data['label'] }}:</th>
            <td>
                <span class="display_currency" data-currency_symbol="true">{{ $module_data['value'] }}</span>
            </td>
        </tr>
    @endforeach
</table>