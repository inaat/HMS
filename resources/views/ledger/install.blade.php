@extends('layouts.app')
@section('title', 'Accounting')

@section('content')
<section class="content-header no-print">
    <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">Accounting <small>double-entry books, like Zoho Books</small></h1>
</section>
<section class="content">
    @component('components.widget')
        <h4>Set up accounting</h4>
        <p>This adds the chart of accounts (Assets, Liabilities, Equity, Income, Expenses) to <b>Payment Accounts &gt; Account Types</b>,
            one expense account per expense category, and two new tables for the journals.
            Your sales, purchases, payments and reports are not changed.</p>
        <p>After this, press <b>Update ledger</b> once: it writes the journals of all your past records (with a progress bar).</p>
        <form method="POST" action="{{ action([\App\Http\Controllers\LedgerController::class, 'install']) }}" onsubmit="$(this).find('button').prop('disabled', true).html('<i class=\'fa fa-spinner fa-spin\'></i> Setting up…');">
            @csrf
            <button type="submit" class="tw-dw-btn tw-dw-btn-primary tw-text-white"><i class="fa fa-sitemap"></i> Set up chart of accounts</button>
        </form>
    @endcomponent
</section>
@endsection
