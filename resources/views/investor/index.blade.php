@extends('layouts.app')
@section('title', 'Investors')

@section('content')
<section class="content-header">
    <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">Investors
        <small>Profit share by brand, product or the whole business</small>
    </h1>
</section>

<section class="content">
    @php
        $total = ['capital' => 0, 'earned' => 0, 'paid' => 0, 'balance' => 0];
        foreach ($balances as $b) {
            foreach ($total as $k => $v) {
                $total[$k] += $b[$k];
            }
        }
        $cards = [
            ['Total capital', 'capital', 'fa-piggy-bank', 'tw-text-sky-600'],
            ['Profit earned', 'earned', 'fa-chart-line', 'tw-text-green-600'],
            ['Paid to investors', 'paid', 'fa-hand-holding-usd', 'tw-text-indigo-600'],
            ['Balance due', 'balance', 'fa-wallet', 'tw-text-orange-600'],
        ];
    @endphp
    <div class="row">
        @foreach ($cards as [$label, $key, $icon, $color])
            <div class="col-md-3 col-sm-6">
                @component('components.widget')
                    <div class="tw-flex tw-items-center tw-gap-3">
                        <i class="fa {{ $icon }} fa-2x {{ $color }}"></i>
                        <div>
                            <div class="tw-text-sm tw-text-gray-500">{{ $label }}</div>
                            <div class="tw-text-xl tw-font-bold"><span class="display_currency" data-currency_symbol="true">{{ $total[$key] }}</span></div>
                        </div>
                    </div>
                @endcomponent
            </div>
        @endforeach
    </div>

    @component('components.widget', ['title' => 'All investors'])
        @can('investor.create')
            @slot('tool')
                <div class="box-tools">
                    <a class="tw-dw-btn tw-dw-btn-primary tw-text-white tw-dw-btn-sm btn-modal pull-right"
                        data-href="{{ action([\App\Http\Controllers\InvestorController::class, 'create']) }}" data-container=".investor_modal">
                        <i class="fa fa-plus"></i> Add investor
                    </a>
                </div>
            @endslot
        @endcan

        <div class="table-responsive">
            <table class="table table-bordered table-striped" id="investors_table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Mobile</th>
                        <th>Active deals</th>
                        <th>Capital</th>
                        <th>Profit earned</th>
                        <th>Paid</th>
                        <th>Balance due</th>
                        <th>Status</th>
                        <th>@lang('messages.action')</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($investors as $investor)
                        @php
                            $b = $balances[$investor->id] ?? ['capital' => 0, 'earned' => 0, 'paid' => 0, 'balance' => 0];
                        @endphp
                        <tr>
                            <td><a href="{{ action([\App\Http\Controllers\InvestorController::class, 'statement'], [$investor->id]) }}"><strong>{{ $investor->name }}</strong></a></td>
                            <td>{{ $investor->mobile }}</td>
                            <td>{{ $investor->deals_count }}</td>
                            <td><span class="display_currency" data-currency_symbol="true">{{ $b['capital'] }}</span></td>
                            <td><span class="display_currency" data-currency_symbol="true">{{ $b['earned'] }}</span></td>
                            <td><span class="display_currency" data-currency_symbol="true">{{ $b['paid'] }}</span></td>
                            <td class="{{ $b['balance'] > 0 ? 'text-danger' : '' }}"><strong><span class="display_currency" data-currency_symbol="true">{{ $b['balance'] }}</span></strong></td>
                            <td>
                                @if ($investor->is_active)
                                    <span class="label label-success">Active</span>
                                @else
                                    <span class="label label-default">Inactive</span>
                                @endif
                            </td>
                            <td style="white-space: nowrap;">
                                <a class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-primary btn-modal" data-href="{{ action([\App\Http\Controllers\InvestorController::class, 'deals'], [$investor->id]) }}" data-container=".investor_modal"><i class="fa fa-handshake"></i> Deals</a>
                                <a class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-info btn-modal" data-href="{{ action([\App\Http\Controllers\InvestorController::class, 'capital'], [$investor->id]) }}" data-container=".investor_modal"><i class="fa fa-piggy-bank"></i> Capital</a>
                                @can('investor.payout')
                                    <a class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-success btn-modal" data-href="{{ action([\App\Http\Controllers\InvestorController::class, 'pay'], [$investor->id]) }}" data-container=".investor_modal"><i class="fa fa-money-bill-wave"></i> Pay</a>
                                @endcan
                                <a class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-accent" href="{{ action([\App\Http\Controllers\InvestorController::class, 'statement'], [$investor->id]) }}"><i class="fa fa-file-alt"></i> Statement</a>
                                @can('investor.update')
                                    <a class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-neutral btn-modal" data-href="{{ action([\App\Http\Controllers\InvestorController::class, 'edit'], [$investor->id]) }}" data-container=".investor_modal" title="Edit"><i class="fa fa-edit"></i></a>
                                @endcan
                                @can('investor.delete')
                                    <a class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-error investor-delete" data-href="{{ action([\App\Http\Controllers\InvestorController::class, 'destroy'], [$investor->id]) }}" data-confirm="Delete investor {{ $investor->name }}?" title="Delete"><i class="fa fa-trash"></i></a>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="text-center text-muted">No investors yet. Click "Add investor".</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endcomponent
</section>
<div class="modal fade investor_modal" tabindex="-1" role="dialog"></div>
@endsection

@section('javascript')
    @include('investor.partials.js')
@endsection
