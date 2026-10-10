{{-- Accounting reports > Print: the same table as the screen, simple bordered layout. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }} - {{ $business->name }}</title>
    @include('ledger.partials.styles')
    <style>
        body { font-family: 'Roboto', Arial, sans-serif; font-size: 12px; color: #222; margin: 16px; }
        .head { width: 100%; margin-bottom: 10px; }
        .title { font-size: 16px; font-weight: bold; }
        .muted { color: #666; font-size: 11px; }
        .ledger-table th, .ledger-table td { border-color: #555; }
        .no-print { display: none; }
        tr { page-break-inside: avoid; }
        thead { display: table-header-group; }
        .noprint-bar { margin-bottom: 10px; }
        @media print { .noprint-bar { display: none; } body { margin: 0; } @page { size: A4; margin: 10mm; } }
    </style>
</head>
<body>
    <div class="noprint-bar">
        <button onclick="window.print()" style="padding:6px 14px;">🖨 Print</button>
        <button onclick="window.close()" style="padding:6px 14px;">Close</button>
    </div>
    <table class="head">
        <tr>
            <td>
                <div class="title">{{ $title }}</div>
                <div class="muted">{{ $subtitle }} · printed {{ \Carbon::now()->format((session('business.date_format') ?: 'd-m-Y').' H:i') }}</div>
            </td>
            <td style="text-align:right;"><div class="title">{{ $business->name }}</div></td>
        </tr>
    </table>
    @include('ledger.partials.table', ['print' => true])
</body>
</html>
