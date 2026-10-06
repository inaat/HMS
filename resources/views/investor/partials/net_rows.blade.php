{{-- Footer rows of the sales tabs: returns made in the period and the net (what the investor share is worked on) --}}
@php
    $n_qty = $t_qty - $rt_qty;
    $n_sales = $t_sales - $rt_value;
    $n_cost = $t_cost - $rt_cost;
    $n_profit = $t_profit - $rt_profit;
    $n_share = $t_share === null || $rt_share === null ? null : $t_share - $rt_share;
@endphp
@if ($all_returns->count())
    <tr style="color: #c0392b;">
        <td colspan="{{ $span }}">Less: returns made in this period ({{ $all_returns->pluck('return_id')->unique()->count() }} returns, see the Returns tab)</td>
        <td class="r">&minus;{{ @format_quantity($rt_qty) }}</td>
        <td></td>
        <td class="r">&minus;@format_currency($rt_value)</td>
        <td class="r">&minus;@format_currency($rt_cost)</td>
        <td class="r">&minus;@format_currency($rt_profit)</td>
        <td class="r">@if ($rt_share !== null) &minus;@format_currency($rt_share) @endif</td>
    </tr>
    <tr style="font-size: 14px;">
        <td colspan="{{ $span }}">Net for this period</td>
        <td class="r">{{ @format_quantity($n_qty) }}</td>
        <td></td>
        <td class="r">@format_currency($n_sales)</td>
        <td class="r">@format_currency($n_cost)</td>
        <td class="r">@format_currency($n_profit)</td>
        <td class="r">@if ($n_share !== null) @format_currency($n_share) @endif</td>
    </tr>
@endif
