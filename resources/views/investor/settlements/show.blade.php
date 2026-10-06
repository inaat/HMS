@extends('layouts.app')
@section('title', 'Settlement')

@section('content')
<section class="content-header">
    <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">Settlement
        <small>{{ @format_date($settlement->period_start) }} ~ {{ @format_date($settlement->period_end) }}</small>
    </h1>
</section>

<section class="content">
    <div class="no-print" style="margin-bottom: 12px; display: flex; flex-wrap: wrap; gap: 8px;">
        <a href="{{ action([\App\Http\Controllers\InvestorSettlementController::class, 'index']) }}" class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-outline"><i class="fa fa-arrow-left"></i> Settlements</a>
        <button type="button" class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-primary tw-text-white" onclick="window.print()"><i class="fa fa-print"></i> Print</button>
        @can('investor.settle')
            @if ($is_latest)
                <button type="button" class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-outline tw-dw-btn-error" id="unlock_settlement"><i class="fa fa-unlock"></i> Unlock</button>
            @endif
        @endcan
    </div>

    <div class="alert alert-success">
        <i class="fa fa-lock"></i> Locked {{ ! empty($settlement->locked_at) ? 'on '.@format_datetime($settlement->locked_at) : '' }}.
        These figures are frozen; later sale changes do not change them.
        @if (! empty($settlement->note)) <br>Note: {{ $settlement->note }} @endif
    </div>
    @if (! empty($changed))
        <div class="alert alert-warning">
            <i class="fa fa-exclamation-triangle"></i> Sales of this period were changed after locking, so the profit is different now for
            {{ count($changed) }} deal(s) (shown below). The locked figures stay as they are. To use the new figures, unlock and settle again
            (only possible for the latest settlement without payouts).
        </div>
    @endif

    @php
        $settlement_lines = $settlement->lines;
        foreach ($settlement_lines as $settlement_line) {
            $settlement_line->investor_name = optional($settlement_line->investor)->name;
        }
    @endphp
    @component('components.widget')
        @include('investor.settlements.partials.lines', ['lines' => $settlement_lines])
    @endcomponent
</section>
@endsection

@section('javascript')
<script type="text/javascript">
    $('#unlock_settlement').on('click', function() {
        swal({
            title: 'Unlock this settlement?',
            text: 'Its figures are deleted so the period can be settled again. Enter the reason:',
            content: 'input',
            icon: 'warning',
            buttons: true,
            dangerMode: true,
        }).then(function(reason) {
            if (reason === null || reason === false) {
                return;
            }
            $.ajax({
                method: 'POST',
                url: "{{ action([\App\Http\Controllers\InvestorSettlementController::class, 'unlock'], [$settlement->id]) }}",
                data: { _token: '{{ csrf_token() }}', reason: reason },
                dataType: 'json',
                success: function(result) {
                    if (result.success) {
                        toastr.success(result.msg);
                        window.location = "{{ action([\App\Http\Controllers\InvestorSettlementController::class, 'index']) }}";
                    } else {
                        toastr.error(result.msg);
                    }
                }
            });
        });
    });
</script>
@endsection
