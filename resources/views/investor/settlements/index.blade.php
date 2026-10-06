@extends('layouts.app')
@section('title', 'Profit settlements')

@section('content')
<section class="content-header">
    <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">Profit settlements
        <small>Work out each investor's share for a period, then lock it</small>
    </h1>
</section>

<section class="content">
    @component('components.widget', ['title' => 'New settlement'])
        <div class="row">
            <div class="col-md-3 col-sm-6 form-group">
                <label>From</label>
                <input type="text" id="settle_start" class="form-control settle-date" value="{{ $next_start->format(session('business.date_format')) }}" readonly>
            </div>
            <div class="col-md-3 col-sm-6 form-group">
                <label>To</label>
                <input type="text" id="settle_end" class="form-control settle-date" value="{{ $next_end->format(session('business.date_format')) }}" readonly>
            </div>
            <div class="col-md-6 form-group">
                <label>&nbsp;</label><br>
                <button type="button" class="tw-dw-btn tw-dw-btn-primary tw-text-white" id="settle_preview"><i class="fa fa-calculator"></i> Calculate (preview)</button>
                <span class="text-muted" style="margin-left: 8px;">Nothing is saved until you click "Lock".</span>
            </div>
        </div>
        <div id="settle_preview_area"></div>
    @endcomponent

    @component('components.widget', ['title' => 'Locked settlements'])
        <div class="table-responsive">
            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>Period</th>
                        <th class="text-right">Total share</th>
                        <th class="text-right">Total payable</th>
                        <th>Locked</th>
                        <th>Note</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($settlements as $settlement)
                        <tr>
                            <td><strong>{{ @format_date($settlement->period_start) }} ~ {{ @format_date($settlement->period_end) }}</strong></td>
                            <td class="text-right"><span class="display_currency" data-currency_symbol="true">{{ $settlement->lines_sum_share_amount ?? 0 }}</span></td>
                            <td class="text-right"><strong><span class="display_currency" data-currency_symbol="true">{{ $settlement->lines_sum_payable ?? 0 }}</span></strong></td>
                            <td><i class="fa fa-lock text-success"></i> {{ ! empty($settlement->locked_at) ? @format_datetime($settlement->locked_at) : '' }}</td>
                            <td>{{ $settlement->note }}</td>
                            <td><a class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-primary" href="{{ action([\App\Http\Controllers\InvestorSettlementController::class, 'show'], [$settlement->id]) }}"><i class="fa fa-eye"></i> View</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted">No settlements yet</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endcomponent
</section>
@endsection

@section('javascript')
<script type="text/javascript">
    $('.settle-date').datepicker({ autoclose: true, format: datepicker_date_format });

    $('#settle_preview').on('click', function() {
        var $btn = $(this).prop('disabled', true);
        $('#settle_preview_area').html('<p class="text-muted"><i class="fa fa-spinner fa-spin"></i> Calculating profit for every deal...</p>');
        $.ajax({
            url: "{{ action([\App\Http\Controllers\InvestorSettlementController::class, 'preview']) }}",
            data: { start: $('#settle_start').val(), end: $('#settle_end').val() },
            dataType: 'html',
            success: function(html) {
                $('#settle_preview_area').html(html);
                __currency_convert_recursively($('#settle_preview_area'));
            },
            error: function() {
                $('#settle_preview_area').html('');
                toastr.error(@json(__('messages.something_went_wrong')));
            },
            complete: function() {
                $btn.prop('disabled', false);
            }
        });
    });

    $(document).on('click', '#settle_lock', function() {
        var $btn = $(this);
        swal({
            title: LANG.sure,
            text: 'Lock this period? The figures are frozen and the investors\' balances are updated.',
            icon: 'warning',
            buttons: true,
        }).then(function(ok) {
            if (!ok) {
                return;
            }
            $btn.prop('disabled', true);
            $.ajax({
                method: 'POST',
                url: "{{ action([\App\Http\Controllers\InvestorSettlementController::class, 'lock']) }}",
                data: { _token: '{{ csrf_token() }}', start: $('#settle_start').val(), end: $('#settle_end').val(), note: $('#settle_note').val() },
                dataType: 'json',
                success: function(result) {
                    if (result.success) {
                        toastr.success(result.msg);
                        window.location = result.redirect;
                    } else {
                        toastr.error(result.msg);
                        $btn.prop('disabled', false);
                    }
                },
                error: function() {
                    toastr.error(@json(__('messages.something_went_wrong')));
                    $btn.prop('disabled', false);
                }
            });
        });
    });
</script>
@endsection
