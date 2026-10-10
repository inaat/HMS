{{-- Accounting screens: one row of buttons, the open one filled --}}
@php
    $L = \App\Http\Controllers\LedgerController::class;
    $tabs = [
        'index' => ['Update ledger & checks', 'fa-sync'],
        'chart' => ['Chart of accounts', 'fa-sitemap'],
        'journals' => ['Journals', 'fa-book'],
        'generalLedger' => ['General ledger', 'fa-list'],
        'trialBalance' => ['Trial balance', 'fa-balance-scale'],
        'balanceSheet' => ['Balance sheet', 'fa-landmark'],
        'profitLoss' => ['Profit & loss', 'fa-chart-line'],
    ];
@endphp
<div class="no-print" style="display:flex; flex-wrap:wrap; gap:8px; margin-bottom:12px;">
    <a href="{{ action([\App\Http\Controllers\AccountController::class, 'index']) }}" class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-outline tw-dw-btn-primary">
        <i class="fa fa-wallet"></i> Payment accounts
    </a>
    @foreach ($tabs as $method => [$label, $icon])
        <a href="{{ action([$L, $method]) }}" class="tw-dw-btn tw-dw-btn-sm {{ ($active ?? '') == $method ? 'tw-dw-btn-primary tw-text-white' : 'tw-dw-btn-outline tw-dw-btn-primary' }}">
            <i class="fa {{ $icon }}"></i> {{ $label }}
        </a>
    @endforeach
</div>
