@extends('layouts.app')
@section('title', 'Route: '.$route->name)

@section('content')
@php
    $d = json_decode($route->days ?? '[]', true) ?: [];
    $api_key = env('GOOGLE_MAP_API_KEY');
    // The POS keeps "0" or "-" when a customer has no mobile.
    $phone = fn ($m) => in_array(trim((string) $m), ['', '0', '-'], true) ? '' : trim($m);
    $pins = $shops->values()->map(function ($s, $i) use ($phone) {
        if (empty($s->position)) {
            return null;
        }
        [$lat, $lng] = array_map('floatval', explode(',', $s->position) + [0, 0]);
        return ['lat' => $lat, 'lng' => $lng, 'name' => $s->name, 'business' => $s->supplier_business_name, 'seq' => $i + 1,
            'contact_id' => $s->contact_id, 'mobile' => $phone($s->mobile), 'address' => trim($s->address_line_1.' '.$s->city),
            'type' => trim($s->outlet_type.($s->outlet_class ? ' · Class '.$s->outlet_class : '')),
            'photo' => $s->shop_photo ? asset($s->shop_photo) : null,
            'url' => action([\App\Http\Controllers\ContactController::class, 'show'], [$s->id])];
    })->filter()->values();
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
                    <div style="flex:1;"><select name="contact_ids[]" id="add_shops" class="form-control select2" multiple style="width:100%;"></select></div>
                    <button type="submit" class="tw-dw-btn tw-dw-btn-primary tw-text-white"><i class="fa fa-plus"></i> Add shops</button>
                </form>

                <form method="POST" id="shops_form" action="{{ action([\App\Http\Controllers\BookerRouteController::class, 'saveOrder'], [$route->id]) }}">
                    @csrf
                    <table class="table table-condensed table-bordered" id="shops_table">
                        <thead><tr style="background:#f5f5f5;"><th style="width:60px;">#</th><th>Shop</th><th style="min-width:160px;">Type</th><th style="min-width:80px;">Class</th><th>GPS</th><th></th></tr></thead>
                        <tbody>
                            @forelse ($shops as $s)
                                <tr>
                                    <td class="seq">
                                        <input type="hidden" name="order[]" value="{{ $s->id }}">
                                        <span class="seq-no">{{ $loop->iteration }}</span>
                                        <a href="#" class="move-up" title="Up">▲</a><a href="#" class="move-down" title="Down">▼</a>
                                    </td>
                                    <td><a href="{{ action([\App\Http\Controllers\ContactController::class, 'show'], [$s->id]) }}" target="_blank"><b>{{ $s->name }}</b></a>
                                        @if($s->supplier_business_name) <small>({{ $s->supplier_business_name }})</small>@endif
                                        <div class="small" style="margin-top:2px;">
                                            <span class="label label-default">ID {{ $s->contact_id ?: $s->id }}</span>
                                            @if ($phone($s->mobile)) <i class="fa fa-phone" style="margin-left:6px;"></i> {{ $phone($s->mobile) }} @else <span class="text-muted" style="margin-left:6px;">no mobile</span> @endif
                                        </div>
                                        @if (trim($s->address_line_1.' '.$s->city)) <div class="text-muted small"><i class="fa fa-map-marker-alt"></i> {{ trim($s->address_line_1.' '.$s->city) }}</div> @endif</td>
                                    <td><select name="outlet_type[{{ $s->id }}]" class="form-control select2" style="width:100%;"><option value="">—</option>@foreach ($outlet_types as $t)<option @if($s->outlet_type == $t) selected @endif>{{ $t }}</option>@endforeach</select></td>
                                    <td><select name="outlet_class[{{ $s->id }}]" class="form-control select2" style="width:100%;"><option value="">—</option>@foreach (['A','B','C'] as $c)<option @if($s->outlet_class == $c) selected @endif>{{ $c }}</option>@endforeach</select></td>
                                    <td>{!! $s->position ? '<span class="label label-success">✓</span>' : '<span class="label label-default" title="The booker sets it at the shop, or edit the customer">none</span>' !!}</td>
                                    <td><a href="#" class="text-danger remove-shop" data-href="{{ action([\App\Http\Controllers\BookerRouteController::class, 'removeShop'], [$route->id, $s->id]) }}" title="Remove from route">✕</a></td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-center text-muted">No shops yet. Search above and click <b>Add shops</b>.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                    @if ($shops->count())
                        <p class="text-muted small">▲▼ = the order the booker visits the shops. Moves, types and classes save by themselves.</p>
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
        // Every move / type / class change is saved at once (no Save button).
        function autosave() {
            $.post($('#shops_form').attr('action'), $('#shops_form').serialize())
                .done(function () { toastr.success('Saved'); })
                .fail(function () { toastr.error('Not saved — check the internet / login and try again'); });
        }
        $(document).on('click', '.move-up', function (e) { e.preventDefault(); var tr = $(this).closest('tr'); if (tr.prev().length) { tr.prev().before(tr); renumber(); autosave(); } });
        $(document).on('click', '.move-down', function (e) { e.preventDefault(); var tr = $(this).closest('tr'); if (tr.next().length) { tr.next().after(tr); renumber(); autosave(); } });
        $(document).on('change', '#shops_form select', autosave);
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
            var esc = function (t) { return $('<div>').text(t || '').html(); };
            var m = new google.maps.Marker({position: pos, map: map, label: String(p.seq), title: p.seq + '. ' + p.name + ' (ID ' + p.contact_id + ')' + (p.mobile ? ' · ' + p.mobile : '')});
            // Small card always shown at the pin (name, ID, mobile); click the pin for the full card.
            var small = '<div style="font-size:12px;line-height:1.35;">'
                + '<b>' + p.seq + '. ' + esc(p.name) + '</b>'
                + '<div>ID: <b>' + esc(p.contact_id) + '</b></div>'
                + '<div>📞 ' + (p.mobile ? esc(p.mobile) : '<span style="color:#999">no mobile</span>') + '</div></div>';
            var full = '<div style="min-width:200px;">'
                + (p.photo ? '<img src="' + esc(p.photo) + '" style="width:100%;max-height:120px;object-fit:cover;border-radius:6px;margin-bottom:6px;">' : '')
                + '<b>' + p.seq + '. <a href="' + esc(p.url) + '" target="_blank">' + esc(p.name) + '</a></b>'
                + (p.business ? '<div>' + esc(p.business) + '</div>' : '')
                + '<div>ID: <b>' + esc(p.contact_id) + '</b></div>'
                + '<div>📞 ' + (p.mobile ? '<a href="tel:' + esc(p.mobile) + '">' + esc(p.mobile) + '</a>' : '<span style="color:#999">no mobile</span>') + '</div>'
                + (p.address ? '<div>📍 ' + esc(p.address) + '</div>' : '')
                + (p.type ? '<div style="color:#666">' + esc(p.type) + '</div>' : '')
                + '<div><a href="https://www.google.com/maps/dir/?api=1&destination=' + p.lat + ',' + p.lng + '" target="_blank">Directions</a></div></div>';
            var info = new google.maps.InfoWindow({content: small, disableAutoPan: true});
            var big = false;
            info.open({map: map, anchor: m, shouldFocus: false});
            m.addListener('click', function () {
                big = ! big;
                info.setContent(big ? full : small);
                info.open({map: map, anchor: m, shouldFocus: false});
            });
            bounds.extend(pos);
        });
        if (routePins.length > 1) {
            map.fitBounds(bounds, {top: 100, right: 60, bottom: 30, left: 60});
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
