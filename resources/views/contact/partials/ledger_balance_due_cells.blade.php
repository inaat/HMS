{{-- "Balance due" label + amount with DR / CR like the ledger rows, never a negative number.
     Customer: positive = customer owes you (DR), negative = customer has credit (CR).
     Supplier: positive = you owe the supplier (CR), negative = supplier owes you (DR). --}}
@php
    $bd_amount = (float) $ledger_details['all_balance_due'];
    $bd_supplier = $contact->type == 'supplier';
    if (abs($bd_amount) < 0.005) {
        $bd_label = __('lang_v1.balance_due');
        $bd_side = '';
    } elseif ($bd_amount > 0) {
        $bd_label = __('lang_v1.balance_due');
        $bd_side = $bd_supplier ? __('lang_v1.cr') : __('lang_v1.dr');
    } else {
        $bd_label = $bd_supplier ? 'Supplier owes you' : 'Customer credit';
        $bd_side = $bd_supplier ? __('lang_v1.dr') : __('lang_v1.cr');
    }
@endphp
<td class="{{ $label_class ?? '' }}">{{ $bd_label }}</td>
<td class="{{ $amount_class ?? '' }}">@format_currency(abs($bd_amount)) {{ $bd_side }}</td>
