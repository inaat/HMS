@extends('layouts.app')
@section('title', 'Booker visits')

@section('content')
@php
    $api_key = env('GOOGLE_MAP_API_KEY');
    $outcome = ['order' => ['Order', 'label-success'], 'payment' => ['Payment', 'label-info'], 'closed' => ['Shop closed', 'label-default'], 'no_order' => ['No order', 'label-warning'], 'other' => ['Other', 'label-default']];
    $pct = function ($a, $b) { return $b > 0 ? round($a * 100 / $b) : 0; };
    $points = $visits->filter(fn ($v) => $v->lat !== null)->values()->map(function ($v, $i) {
        return ['lat' => (float) $v->lat, 'lng' => (float) $v->lng, 'n' => $i + 1, 'booker' => $v->booker_id, 'shop' => $v->shop,
            'time' => substr($v->started_at, 11, 5), 'ok' => $v->within_range === null ? null : (bool) $v->within_range];
    });
@endphp
<section class="content-header no-print">
    <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">Booker visits
        <small>which shops each booker visited, when, how far from the shop, and the result</small>
    </h1>
</section>

<section class="content">
    <div class="row no-print">
        <div class="col-md-12">
            @component('components.filters', ['title' => __('report.filters')])
                {!! Form::open(['url' => action([\App\Http\Controllers\BookerRouteController::class, 'visits']), 'method' => 'get', 'id' => 'visits_filter_form']) !!}
                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('visit_date', __('lang_v1.date') . ':') !!}
                        <div class="input-group">
                            <span class="input-group-addon"><i class="fa fa-calendar"></i></span>
                            {!! Form::text('date', @format_date($date), ['class' => 'form-control', 'id' => 'visit_date', 'readonly']) !!}
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('booker_id', 'Order booker:') !!}
                        {!! Form::select('booker_id', $bookers, $booker ?: null, ['class' => 'form-control select2', 'style' => 'width:100%', 'placeholder' => __('lang_v1.all'), 'id' => 'booker_id']) !!}
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label>&nbsp;</label><br>
                        <a href="{{ action([\App\Http\Controllers\BookerRouteController::class, 'visits']) }}?date={{ date('Y-m-d', strtotime($date.' -1 day')) }}&booker_id={{ $booker ?: '' }}" class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-outline tw-dw-btn-primary">‹ Previous day</a>
                        <a href="{{ action([\App\Http\Controllers\BookerRouteController::class, 'visits']) }}?date={{ date('Y-m-d') }}&booker_id={{ $booker ?: '' }}" class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-outline tw-dw-btn-primary">Today</a>
                        <a href="{{ action([\App\Http\Controllers\BookerRouteController::class, 'visits']) }}?date={{ date('Y-m-d', strtotime($date.' +1 day')) }}&booker_id={{ $booker ?: '' }}" class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-outline tw-dw-btn-primary">Next day ›</a>
                    </div>
                </div>
                {!! Form::close() !!}
            @endcomponent
        </div>
    </div>

    @component('components.widget', ['title' => 'Summary — '.\Carbon\Carbon::parse($date)->format('l d M Y')])
        <div class="table-responsive">
            <table class="table table-bordered table-condensed">
                <thead><tr style="background:#f5f5f5;">
                    <th>Booker</th><th>First check-in</th><th>Last check-out</th>
                    <th title="Shops on the booker's routes for this weekday">Planned shops</th>
                    <th title="Planned shops visited">Route coverage</th>
                    <th>Visits</th><th title="Visits with an order">Strike rate</th><th class="text-right">Booked</th>
                    <th title="Checked in more than {{ $radius }} m from the shop's saved location">Far from shop</th><th>No GPS</th>
                </tr></thead>
                <tbody>
                    @forelse ($summary as $s)
                        <tr>
                            <td><b>{{ $s['name'] }}</b></td>
                            <td>{{ $s['first'] ? substr($s['first'], 11, 5) : '—' }}</td>
                            <td>{{ $s['last'] ? substr($s['last'], 11, 5) : '—' }}</td>
                            <td>{{ $s['planned'] }}</td>
                            <td>@if ($s['planned']) <b>{{ $pct($s['planned_visited'], $s['planned']) }}%</b> <small class="text-muted">({{ $s['planned_visited'] }} of {{ $s['planned'] }})</small> @else — @endif</td>
                            <td>{{ $s['visits'] }}</td>
                            <td><b>{{ $pct($s['orders'], $s['visits']) }}%</b> <small class="text-muted">({{ $s['orders'] }})</small></td>
                            <td class="text-right">@format_currency($s['sale'])</td>
                            <td>@if ($s['far']) <span class="label label-danger">{{ $s['far'] }}</span> @else 0 @endif</td>
                            <td>@if ($s['no_gps']) <span class="label label-danger">{{ $s['no_gps'] }}</span> @else 0 @endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="text-center text-muted">No visits and no planned routes on this day.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endcomponent

    <div class="row">
        <div class="{{ $api_key && $points->count() ? 'col-md-7' : 'col-md-12' }}">
            @component('components.widget', ['title' => 'Visits ('.$visits->count().')'])
                <div class="table-responsive">
                    <table class="table table-bordered table-condensed">
                        <thead><tr style="background:#f5f5f5;">
                            <th>#</th><th>Booker</th><th>Shop</th><th>In – out</th><th>Distance</th><th>Result</th><th>Photo</th>
                        </tr></thead>
                        <tbody>
                            @php $n = 0; @endphp
                            @forelse ($visits as $v)
                                <tr>
                                    <td>@if ($v->lat !== null) {{ ++$n }} @endif</td>
                                    <td>{{ $bookers[$v->booker_id] ?? '#'.$v->booker_id }}</td>
                                    <td>@if ($v->contact_id)<a href="{{ action([\App\Http\Controllers\ContactController::class, 'show'], [$v->contact_id]) }}" target="_blank"><b>{{ $v->shop }}</b></a>@else <span class="text-muted">—</span> @endif
                                        @if ($v->route_name) <div class="text-muted small">{{ $v->route_name }}</div> @endif</td>
                                    <td>{{ substr($v->started_at, 11, 5) }} – {{ $v->ended_at ? substr($v->ended_at, 11, 5) : '…' }}
                                        @if ($v->minutes !== null) <div class="text-muted small">{{ $v->minutes }} min</div> @endif</td>
                                    <td>
                                        @if ($v->lat === null)
                                            <span class="label label-danger">no GPS</span>
                                        @elseif ($v->distance_m === null)
                                            <span class="label label-default" title="The shop had no saved location">shop has no location</span>
                                        @else
                                            <span class="label {{ $v->within_range ? 'label-success' : 'label-danger' }}">{{ number_format($v->distance_m) }} m</span>
                                        @endif
                                        @if ($v->lat !== null) <a href="https://www.google.com/maps?q={{ $v->lat }},{{ $v->lng }}" target="_blank" title="Where the booker was"><i class="fa fa-map-marker-alt"></i></a> @endif
                                        @if ($v->accuracy_m) <div class="text-muted small">GPS ±{{ (int) $v->accuracy_m }} m</div> @endif
                                    </td>
                                    <td><span class="label {{ $outcome[$v->outcome][1] ?? 'label-default' }}">{{ $outcome[$v->outcome][0] ?? $v->outcome }}</span>
                                        @if ($v->order_total > 0) <b>@format_currency($v->order_total)</b> @endif
                                        @if ($v->reason && $v->outcome != 'closed') <div class="small">{{ $v->reason }}</div> @endif
                                        @if ($v->note) <div class="text-muted small">{{ $v->note }}</div> @endif</td>
                                    <td>@if ($v->photo)<a href="{{ asset($v->photo) }}" target="_blank"><img src="{{ asset($v->photo) }}" style="height:48px;border-radius:4px;"></a>@endif</td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="text-center text-muted">No visits on this day.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endcomponent
        </div>
        @if ($api_key && $points->count())
            <div class="col-md-5">
                @component('components.widget', ['title' => 'Day map (numbers = visit order)'])
                    <div id="visit_map" style="height:560px; border-radius:8px;"></div>
                    <p class="text-muted small" style="margin-top:6px;">Green = at the shop · Red = far from the shop · Grey = shop has no saved location</p>
                @endcomponent
            </div>
        @endif
    </div>
</section>
@endsection

@section('javascript')
<script>
    $(function () {
        // POS date picker (business date format); any change reloads the report.
        $('#visit_date').datepicker({autoclose: true, endDate: 'today'}).on('changeDate', function () { $('#visits_filter_form').submit(); });
        $('#booker_id').on('change', function () { $('#visits_filter_form').submit(); });
    });
</script>
@if ($api_key && $points->count())
<script>
    var visitPoints = {!! json_encode($points) !!};
    function initVisitMap() {
        var map = new google.maps.Map(document.getElementById('visit_map'), {zoom: 14, center: {lat: visitPoints[0].lat, lng: visitPoints[0].lng}});
        var bounds = new google.maps.LatLngBounds();
        var paths = {};
        visitPoints.forEach(function (p) {
            var pos = {lat: p.lat, lng: p.lng};
            var color = p.ok === null ? '#888' : (p.ok ? '#2e9e6a' : '#d9534f');
            var m = new google.maps.Marker({position: pos, map: map, label: {text: String(p.n), color: '#fff', fontSize: '12px'},
                icon: {path: google.maps.SymbolPath.CIRCLE, scale: 12, fillColor: color, fillOpacity: 1, strokeColor: '#fff', strokeWeight: 2}});
            var info = new google.maps.InfoWindow({content: '<b>' + $('<div>').text(p.shop || '').html() + '</b><br>' + p.time});
            m.addListener('click', function () { info.open(map, m); });
            bounds.extend(pos);
            (paths[p.booker] = paths[p.booker] || []).push(pos);
        });
        Object.keys(paths).forEach(function (b) {
            new google.maps.Polyline({path: paths[b], map: map, strokeColor: '#3b82f6', strokeOpacity: 0.6, strokeWeight: 3});
        });
        if (visitPoints.length > 1) { map.fitBounds(bounds); }
    }
</script>
<script async defer src="https://maps.googleapis.com/maps/api/js?key={{ $api_key }}&callback=initVisitMap"></script>
@endif
@endsection
