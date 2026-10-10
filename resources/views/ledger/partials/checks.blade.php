{{-- Update ledger screen: does the ledger match the POS's own numbers? --}}
<table class="ledger-table">
    <thead>
        <tr><th style="width:40px;"></th><th>Check</th><th class="right">Ledger</th><th class="right">POS</th><th>How the POS number is made</th></tr>
    </thead>
    <tbody>
        @foreach ($checks as $c)
            <tr>
                <td class="center">
                    @if (! empty($c['info']))
                        <i class="fa fa-info-circle" style="color:#d97706;"></i>
                    @elseif ($c['ok'])
                        <i class="fa fa-check-circle ledger-ok"></i>
                    @else
                        <i class="fa fa-times-circle ledger-bad"></i>
                    @endif
                </td>
                <td><b>{{ $c['label'] }}</b></td>
                <td class="right">{{ number_format($c['ledger'], 2) }}</td>
                <td class="right">{{ empty($c['info']) ? number_format($c['pos'], 2) : '' }}</td>
                <td class="muted" style="font-size:12px;">{{ $c['note'] }}
                    @if (empty($c['info']) && ! $c['ok']) <br><b class="ledger-bad">Difference {{ number_format($c['ledger'] - $c['pos'], 2) }}</b> — press Update ledger; if it stays, check the records listed below. @endif
                </td>
            </tr>
        @endforeach
    </tbody>
</table>

@if (! empty($last['issues']))
    <h5 style="margin-top:16px;"><b>Records whose total does not equal their parts</b>
        <small>(the difference went to "Rounding differences"; check these in the POS)</small></h5>
    <table class="ledger-table" style="width:auto; min-width:50%;">
        <thead><tr><th>Type</th><th>Ref / invoice</th><th class="right">Difference</th></tr></thead>
        <tbody>
            @foreach ($last['issues'] as $i)
                <tr>
                    <td>{{ \App\Utils\LedgerUtil::SOURCE_LABELS[$i['step']] ?? $i['step'] }}</td>
                    <td>{{ $i['ref'] ?: '#'.$i['id'] }}</td>
                    <td class="right">{{ number_format((float) $i['diff'], 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
