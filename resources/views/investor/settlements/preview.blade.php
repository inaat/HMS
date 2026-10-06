{{-- Live calculation of a period (nothing saved). Loaded into the New settlement box. --}}
@php
    $preview_lines = collect($lines)->map(function ($l) {
        return (object) $l;
    });
@endphp
@include('investor.settlements.partials.lines', ['lines' => $preview_lines, 'show_period' => true])

@if (! empty($error))
    <div class="alert alert-danger"><i class="fa fa-exclamation-triangle"></i> {{ $error }}</div>
@elseif (collect($lines)->isEmpty())
    <div class="alert alert-warning">No active deals in this period. Add deals to investors first.</div>
@else
    @can('investor.settle')
        <div class="row">
            <div class="col-md-6 form-group">
                <input type="text" id="settle_note" class="form-control" placeholder="Note (optional)">
            </div>
            <div class="col-md-6">
                <button type="button" class="tw-dw-btn tw-dw-btn-success tw-text-white" id="settle_lock"><i class="fa fa-lock"></i> Lock this period</button>
            </div>
        </div>
    @endcan
@endif
