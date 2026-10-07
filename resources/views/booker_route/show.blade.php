@extends('layouts.app')
@section('title', 'Route: '.$route->name)

@section('content')
@php
    $d = json_decode($route->days ?? '[]', true) ?: [];
    $api_key = env('GOOGLE_MAP_API_KEY');
    $pins = $shops->filter(fn ($s) => ! empty($s->position))->values()->map(function ($s) {
        [$lat, $lng] = array_map('floatval', explode(',', $s->position) + [0, 0]);
        return ['lat' => $lat, 'lng' => $lng, 'name' => $s->name, 'seq' => $s->visit_sequence];
    });
@endphp
<section class="content-header no-print">
    <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">{{ $route->name }}
        <small>
            {{ implode(', ', array_map(fn ($x) => $days[$x] ?? $x, $d)) ?: 'no days set' }} ·
            {{ $bookers[$route->booker_id] ?? 'no booker' }} ·
            <a href="{{ action([\App\Http\Controllers\BookerRouteController::class, 'index']) }}">all routes</a>
        </small>
    </h1>
</section>

<section class="content">
    <div class="no-print" style="display:flex; flex-wrap:wrap; gap:8px; margin-bottom:12px;">
        <button type="button" class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-outline tw-dw-btn-primary" data-toggle="modal" data-target="#route_modal"><i class="fa fa-edit"></i> Edit route</button>
        <form method="POST" action="{{ action([\App\Http\Controllers\BookerRouteController::class, 'destroy'], [$route->id]) }}" onsubmit="return confirm('Delete this route? Its shops stay, without a route.');">
            @csrf @method('DELETE')
            <button class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-outline tw-dw-btn-error"><i class="fa fa-trash"></i> Delete route</button>
        </form>
    </div>

    <div class="row">
        <div class="col-md-7">
            @component('components.widget', ['title' => 'Shops on this route ('.$shops->count().')'])
                <form method="POST" action="{{ action([\App\Http\Controllers\BookerRouteController::class, 'addShops'], [$route->id]) }}" style="display:flex; gap:8px; margin-bottom:12px;">
                    @csrf
                    <div style="flex:1;"><select name="contact_ids[]" id="add_shops" class="form-control" multiple style="width:100%;"></select></div>
                    <button type="submit" class="tw-dw-btn tw-dw-btn-primary tw-text-white"><i class="fa fa-plus"></i> Add shops</button>
                </form>

                <form method="POST" action="{{ action([\App\Http\Controllers\BookerRouteController::class, 'saveOrder'], [$route->id]) }}">
                    @csrf
                    <table class="table table-condensed table-bordered" id="shops_table">
                        <thead><tr style="background:#f5f5f5;"><th style="width:60px;">#</th><th>Shop</th><th>Type</th><th>Class</th><th>GPS</th><th></th></tr></thead>
                        <tbody>
                            @forelse ($shops as $s)
                                <tr>
                                    <td class="seq">
                                        <input type="hidden" name="order[]" value="{{ $s->id }}">
                                        <span class="seq-no">{{ $loop->iteration }}</span>
                                        <a href="#" class="move-up" title="Up">▲</a><a href="#" class="move-down" title="Down">▼</a>
                                    </td>
                                    <td><b>{{ $s->name }}</b>@if($s->supplier_business_name) <small>({{ $s->supplier_business_name }})</small>@endif
                                        <div class="text-muted small">{{ $s->mobile }} {{ $s->city }} {{ $s->address_line_1 }}</div></td>
                                    <td><select name="outlet_type[{{ $s->id }}]" class="form-control input-sm"><option value="">—</option>@foreach ($outlet_types as $t)<option @if($s->outlet_type == $t) selected @endif>{{ $t }}</option>@endforeach</select></td>
                                    <td><select name="outlet_class[{{ $s->id }}]" class="form-control input-sm" style="width:60px;"><option value="">—</option>@foreach (['A','B','C'] as $c)<option @if($s->outlet_class == $c) selected @endif>{{ $c }}</option>@endforeach</select></td>
                                    <td>{!! $s->position ? '<span class="label label-success">✓</span>' : '<span class="label label-default" title="The booker sets it at the shop, or edit the customer">none</span>' !!}</td>
                                    <td><a href="#" class="text-danger remove-shop" data-href="{{ action([\App\Http\Controllers\BookerRouteController::class, 'removeShop'], [$route->id, $s->id]) }}" title="Remove from route">✕</a></td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-center text-muted">No shops yet. Search above and click <b>Add shops</b>.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                    @if ($shops->count())
                        <button type="submit" class="tw-dw-btn tw-dw-btn-primary tw-text-white"><i class="fa fa-save"></i> Save order, types & classes</button>
                    @endif
                </form>
            @endcomponent
        </div>
        <div class="col-md-5">
            @component('components.widget', ['title' => 'Map ('.$pins->count().' of '.$shops->count().' shops have GPS)'])
                @if ($api_key)
                    <div id="route_map" style="height:520px; border-radius:8px;"></div>
                @else
                    <p class="text-muted">Set GOOGLE_MAP_API_KEY in .env to see the map.</p>
                @endif
            @endcomponent
        </div>
    </div>
</section>

<form method="POST" id="remove_form">@csrf</form>
@include('booker_route.partials.route_modal', ['route' => $route])
@endsection

@section('javascript')
<script>
    $(function () {
        $('#add_shops').select2({
            placeholder: 'Search customers by name, mobile or city…',
            minimumInputLength: 1,
            ajax: {
                url: '{{ action([\App\Http\Controllers\BookerRouteController::class, 'searchCustomers'], [$route->id]) }}',
                dataType: 'json', delay: 250,
                data: function (p) { return {q: p.term}; },
                processResults: function (data) { return {results: data}; }
            }
        });

        // Reorder rows; numbers follow. "Save order" stores it.
        function renumber() { $('#shops_table tbody tr').each(function (i) { $(this).find('.seq-no').text(i + 1); }); }
        $(document).on('click', '.move-up', function (e) { e.preventDefault(); var tr = $(this).closest('tr'); tr.prev().before(tr); renumber(); });
        $(document).on('click', '.move-down', function (e) { e.preventDefault(); var tr = $(this).closest('tr'); tr.next().after(tr); renumber(); });
        $(document).on('click', '.remove-shop', function (e) {
            e.preventDefault();
            if (confirm('Remove this shop from the route?')) { $('#remove_form').attr('action', $(this).data('href')).submit(); }
        });
    });

    @if ($api_key)
    var routePins = {!! json_encode($pins) !!};
    function initRouteMap() {
        var center = routePins.length ? {lat: routePins[0].lat, lng: routePins[0].lng} : {lat: 34.8, lng: 71.9};
        var map = new google.maps.Map(document.getElementById('route_map'), {zoom: 14, center: center});
        var bounds = new google.maps.LatLngBounds();
        routePins.forEach(function (p, i) {
            var pos = {lat: p.lat, lng: p.lng};
            var m = new google.maps.Marker({position: pos, map: map, label: String(p.seq || i + 1), title: p.name});
            var info = new google.maps.InfoWindow({content: '<b>' + $('<div>').text(p.name).html() + '</b>'});
            m.addListener('click', function () { info.open(map, m); });
            bounds.extend(pos);
        });
        if (routePins.length > 1) {
            map.fitBounds(bounds);
            new google.maps.Polyline({path: routePins.map(function (p) { return {lat: p.lat, lng: p.lng}; }), map: map, strokeColor: '#2e9e6a', strokeOpacity: 0.7, strokeWeight: 3});
        }
    }
    @endif
</script>
@if ($api_key)
    <script async defer src="https://maps.googleapis.com/maps/api/js?key={{ $api_key }}&callback=initRouteMap"></script>
@endif
<style>.seq a { margin-left: 4px; color: #888; text-decoration: none; } .seq a:hover { color: #2e9e6a; }</style>
@endsection
