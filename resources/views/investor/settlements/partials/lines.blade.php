{{-- Settlement lines grouped by investor. $lines: objects with investor_name / scope_label / profit_base / share_percent_used /
     avg_capital_used / share_amount / loss_brought_forward / loss_carried_forward / payable. $changed: deal_id => live profit. --}}
@php
    $groups = $lines->groupBy('investor_name');
@endphp
<div class="table-responsive">
    <table class="table table-bordered table-condensed">
        <thead>
            <tr style="background: #f4f8f6;">
                <th>Investor</th>
                <th>Share of</th>
                <th class="text-right">Profit</th>
                <th class="text-right">Share %</th>
                <th class="text-right">Share</th>
                <th class="text-right">Loss b/f</th>
                <th class="text-right">Loss c/f</th>
                <th class="text-right">Payable</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($groups as $investor_name => $rows)
                @foreach ($rows as $i => $line)
                    <tr>
                        @if ($i == 0)
                            <td rowspan="{{ $rows->count() + 1 }}" style="vertical-align: top;"><strong>{{ $investor_name }}</strong></td>
                        @endif
                        <td>
                            {{ $line->scope_label }}
                            @if (! empty($line->avg_capital_used) && $line->avg_capital_used > 0)
                                <br><small class="text-muted">Average capital: <span class="display_currency" data-currency_symbol="true">{{ $line->avg_capital_used }}</span></small>
                            @endif
                            @if (! empty($show_period) && ! empty($line->period_from))
                                <br><small class="text-muted">{{ @format_date($line->period_from) }} ~ {{ @format_date($line->period_to) }}</small>
                            @endif
                            @if (! empty($changed[$line->deal_id ?? 0]))
                                <br><small class="text-warning"><i class="fa fa-exclamation-triangle"></i> Profit is now <span class="display_currency" data-currency_symbol="true">{{ $changed[$line->deal_id] }}</span> (sales changed after locking)</small>
                            @endif
                        </td>
                        <td class="text-right {{ $line->profit_base < 0 ? 'text-danger' : '' }}"><span class="display_currency" data-currency_symbol="true">{{ $line->profit_base }}</span></td>
                        <td class="text-right">{{ @num_format($line->share_percent_used) }}%</td>
                        <td class="text-right {{ $line->share_amount < 0 ? 'text-danger' : '' }}"><span class="display_currency" data-currency_symbol="true">{{ $line->share_amount }}</span></td>
                        <td class="text-right">@if ($line->loss_brought_forward > 0)<span class="display_currency" data-currency_symbol="true">{{ $line->loss_brought_forward }}</span>@endif</td>
                        <td class="text-right text-danger">@if ($line->loss_carried_forward > 0)<span class="display_currency" data-currency_symbol="true">{{ $line->loss_carried_forward }}</span>@endif</td>
                        <td class="text-right"><strong><span class="display_currency" data-currency_symbol="true">{{ $line->payable }}</span></strong></td>
                    </tr>
                @endforeach
                <tr style="background: #f9fafb;">
                    <td class="text-right"><em>{{ $investor_name }} total</em></td>
                    <td colspan="5"></td>
                    <td class="text-right"><strong><span class="display_currency" data-currency_symbol="true">{{ $rows->sum('payable') }}</span></strong></td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr class="bg-gray">
                <th colspan="4">Total</th>
                <th class="text-right"><span class="display_currency" data-currency_symbol="true">{{ $lines->sum('share_amount') }}</span></th>
                <th colspan="2"></th>
                <th class="text-right"><span class="display_currency" data-currency_symbol="true">{{ $lines->sum('payable') }}</span></th>
            </tr>
        </tfoot>
    </table>
</div>
