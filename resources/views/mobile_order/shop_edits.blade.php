@extends('layouts.app')
@section('title', 'Shop edits')

@section('content')
@php
    $badge = ['waiting' => 'label-warning', 'applied' => 'label-success', 'rejected' => 'label-danger'];
    $labels = ['name' => 'Name', 'business_name' => 'Business name', 'mobile' => 'Mobile', 'address' => 'Address', 'city' => 'City',
        'position' => 'Location', 'route_id' => 'Route', 'outlet_type' => 'Shop type', 'outlet_class' => 'Class', 'photo' => 'Photo'];
    $current = ['name' => 'name', 'business_name' => 'supplier_business_name', 'mobile' => 'mobile', 'address' => 'address_line_1',
        'city' => 'city', 'position' => 'position', 'route_id' => 'route_id', 'outlet_type' => 'outlet_type', 'outlet_class' => 'outlet_class', 'photo' => 'shop_photo'];
@endphp
<section class="content-header no-print">
    <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">Shop edits
        <small>changes order bookers made to shops; nothing changes on a shop until you approve it</small>
    </h1>
</section>

<section class="content">
    <div class="no-print" style="margin-bottom: 12px; display: flex; flex-wrap: wrap; gap: 8px; align-items: center;">
        <a href="{{ action([\App\Http\Controllers\MobileOrderController::class, 'index']) }}?kind=order" class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-outline tw-dw-btn-primary">
            <i class="fa fa-shopping-cart"></i> Orders
            @if (! empty($counts['order'])) <span class="label label-warning">{{ $counts['order'] }}</span> @endif
        </a>
        <a href="{{ action([\App\Http\Controllers\MobileOrderController::class, 'index']) }}?kind=payment" class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-outline tw-dw-btn-primary">
            <i class="fa fa-money-bill-wave"></i> Payments
            @if (! empty($counts['payment'])) <span class="label label-warning">{{ $counts['payment'] }}</span> @endif
        </a>
        <a href="#" class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-primary tw-text-white">
            <i class="fa fa-store"></i> Shop edits
            @if (! empty($counts['shop_edit'])) <span class="label label-warning">{{ $counts['shop_edit'] }}</span> @endif
        </a>
        <form method="GET" style="margin-left: 12px;">
            <select name="status" class="form-control select2" style="width:200px;" onchange="this.form.submit()">
                @foreach (['all' => 'All', 'waiting' => 'Waiting for approval', 'applied' => 'Applied', 'rejected' => 'Rejected'] as $k => $v)
                    <option value="{{ $k }}" @if ($status == $k) selected @endif>{{ $v }}</option>
                @endforeach
            </select>
        </form>
    </div>

    @component('components.widget')
        @if ($rows->where('status', 'waiting')->count())
        {{-- Tick several edits and approve / reject them at once --}}
        <form method="POST" id="bulk_form" action="{{ action([\App\Http\Controllers\MobileOrderController::class, 'decideShopEditsBulk']) }}" class="no-print" style="display:flex; gap:8px; align-items:center; margin-bottom:10px;">
            @csrf <input type="hidden" name="decision" value="approve">
            <span class="text-muted"><span id="bulk_count">0</span> ticked</span>
            <button type="button" class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-success tw-text-white bulk_decide" data-decision="approve" disabled><i class="fa fa-check"></i> Approve ticked</button>
            <button type="button" class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-error tw-text-white bulk_decide" data-decision="reject" disabled><i class="fa fa-times"></i> Reject ticked</button>
        </form>
        @endif
        <div class="table-responsive">
            <table class="table table-bordered table-condensed">
                <thead>
                    <tr style="background:#f5f5f5;">
                        <th style="width:34px;" class="text-center"><input type="checkbox" id="tick_all" title="Tick all waiting"></th>
                        <th style="width:130px;">Date</th>
                        <th>Booker</th>
                        <th>Shop</th>
                        <th>Changes (now → booker's value)</th>
                        <th style="width:110px;">Status</th>
                        <th style="width:170px;"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $r)
                        @php
                            $wait = json_decode($r->fields ?? '[]', true) ?: [];
                            $accuracy = $wait['accuracy_m'] ?? null;
                            unset($wait['accuracy_m']);
                            $applied = json_decode($r->applied ?? '[]', true) ?: [];
                        @endphp
                        <tr>
                            <td class="text-center">
                                @if ($r->status == 'waiting')
                                    <input type="checkbox" class="tick_row" name="ids[]" value="{{ $r->id }}" form="bulk_form">
                                @endif
                            </td>
                            <td>{{ @format_datetime($r->created_at) }}</td>
                            <td>{{ $r->booker ?: '#'.$r->booker_id }}</td>
                            <td>
                                @if ($r->contact_id)
                                    <a href="{{ action([\App\Http\Controllers\ContactController::class, 'show'], [$r->contact_id]) }}" target="_blank"><b>{{ $r->name }}</b></a>
                                    @if ($r->supplier_business_name) <small>({{ $r->supplier_business_name }})</small> @endif
                                    <div class="text-muted small">{{ in_array(trim((string) $r->mobile), ["0", "-"]) ? "" : $r->mobile }} {{ $r->city }}</div>
                                @else
                                    <span class="text-muted">customer deleted</span>
                                @endif
                            </td>
                            <td>
                                @foreach (['wait' => $wait, 'applied' => $applied] as $group => $fields)
                                    @foreach ($fields as $key => $value)
                                        @php
                                            $old = $r->{$current[$key] ?? ''} ?? null;
                                            $show = function ($key, $v) use ($routes) {
                                                if ($v === null || $v === '') {
                                                    return '<span class="text-muted">empty</span>';
                                                }
                                                if ($key === 'route_id') {
                                                    return e($routes[$v] ?? '#'.$v);
                                                }
                                                if ($key === 'position') {
                                                    return '<a href="https://www.google.com/maps?q='.e($v).'" target="_blank"><i class="fa fa-map-marker-alt"></i> '.e($v).'</a>';
                                                }
                                                if ($key === 'photo') {
                                                    return '<a href="'.asset($v).'" target="_blank"><img src="'.asset($v).'" style="height:60px;border-radius:4px;"></a>';
                                                }

                                                return e($v);
                                            };
                                        @endphp
                                        <div style="margin-bottom:4px;">
                                            <b>{{ $labels[$key] ?? $key }}:</b>
                                            @if ($group == 'applied')
                                                {!! $show($key, $value) !!} <span class="label label-success">filled automatically</span>
                                            @else
                                                @if ($r->status == 'waiting') {!! $show($key, $old) !!} → @endif
                                                <b>{!! $show($key, $value) !!}</b>
                                            @endif
                                            @if ($key == 'position' && $accuracy) <small class="text-muted">(GPS ±{{ (int) $accuracy }} m)</small> @endif
                                        </div>
                                    @endforeach
                                @endforeach
                            </td>
                            <td>
                                <span class="label {{ $badge[$r->status] ?? 'label-default' }}">{{ $r->status == 'applied' && ! $r->decided_by ? 'Filled automatically' : ($r->status == 'applied' ? 'Approved' : ucfirst($r->status)) }}</span>
                                @if ($r->decided_by_name) <div class="text-muted small">{{ $r->decided_by_name }}</div> @endif
                            </td>
                            <td>
                                @if ($r->status == 'waiting')
                                    <form method="POST" action="{{ action([\App\Http\Controllers\MobileOrderController::class, 'decideShopEdit'], [$r->id]) }}" style="display:inline;">
                                        @csrf <input type="hidden" name="decision" value="approve">
                                        <button class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-success tw-text-white"><i class="fa fa-check"></i> Approve</button>
                                    </form>
                                    <form method="POST" action="{{ action([\App\Http\Controllers\MobileOrderController::class, 'decideShopEdit'], [$r->id]) }}" style="display:inline;" class="reject_one">
                                        @csrf <input type="hidden" name="decision" value="reject">
                                        <button class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-error tw-text-white">Reject</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted">Nothing here.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $rows->links() }}
    @endcomponent
</section>
@endsection

@section('javascript')
<script>
    $(function () {
        function refreshBulk() {
            var n = $('.tick_row:checked').length;
            $('#bulk_count').text(n);
            $('.bulk_decide').prop('disabled', n === 0);
            $('#tick_all').prop('checked', n > 0 && n === $('.tick_row').length);
        }
        $('#tick_all').on('change', function () {
            $('.tick_row').prop('checked', this.checked);
            refreshBulk();
        });
        $(document).on('change', '.tick_row', refreshBulk);

        $('.bulk_decide').on('click', function () {
            var decision = $(this).data('decision');
            var n = $('.tick_row:checked').length;
            swal({
                title: (decision === 'approve' ? 'Approve ' : 'Reject ') + n + ' shop edit(s)?',
                text: decision === 'approve' ? 'The shops will be updated with the booker\x27s values.' : 'The shops stay as they are.',
                icon: decision === 'approve' ? 'info' : 'warning',
                buttons: ['Cancel', decision === 'approve' ? 'Approve' : 'Reject'],
                dangerMode: decision !== 'approve',
            }).then(function (ok) {
                if (!ok) { return; }
                $('#bulk_form input[name=decision]').val(decision);
                $('.bulk_decide').prop('disabled', true);
                $('#bulk_form').submit();
            });
        });

        $('form.reject_one').on('submit', function (e) {
            var form = this;
            if ($(form).data('confirmed')) { return true; }
            e.preventDefault();
            swal({ title: 'Reject this change?', text: 'The shop stays as it is.', icon: 'warning', buttons: ['Cancel', 'Reject'], dangerMode: true })
                .then(function (ok) { if (ok) { $(form).data('confirmed', true); form.submit(); } });
        });
    });
</script>
@endsection
