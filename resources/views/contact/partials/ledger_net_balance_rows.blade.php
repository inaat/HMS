{{-- Advance not yet used + net balance: the net balance is what the ledger's last row shows.
     Shown only when the contact has unused advance (Balance due alone would then look wrong). --}}
@php
    $nb_advance = (float) ($ledger_details['all_advance_balance'] ?? 0);
    $nb_net = (float) ($ledger_details['all_net_balance'] ?? $ledger_details['all_balance_due']);
    //Customer: positive = customer owes (DR); supplier: positive = we owe (CR)
    $nb_is_supplier = $contact->type == 'supplier';
    $nb_owed_by_contact = $nb_is_supplier ? $nb_net < 0 : $nb_net > 0;
    $nb_side = abs($nb_net) < 0.005 ? '' : ($nb_owed_by_contact ? __('lang_v1.dr') : __('lang_v1.cr'));
@endphp
@if(abs($nb_advance) >= 0.005)
    <tr>
        <td colspan="2"></td>
        <td>Advance (unused)</td>
        <td class="sl-amount">(-) @format_currency($nb_advance)</td>
    </tr>
    <tr>
        <td colspan="2" class="sl-muted">
            @if($nb_net < 0 && ! $nb_is_supplier) Customer has credit: use it on the next invoice or refund it. @endif
        </td>
        <td class="sl-due">Net balance</td>
        <td class="sl-amount sl-due">@format_currency(abs($nb_net)) {{ $nb_side }}</td>
    </tr>
@endif
