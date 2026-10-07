@extends('layouts.app')
@section('title', 'Booker routes')

@section('content')
<section class="content-header no-print">
    <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">Booker routes
        <small>which shops each order booker visits, on which days</small>
    </h1>
</section>

<section class="content">
    <div class="no-print" style="display:flex; flex-wrap:wrap; gap:8px; margin-bottom:12px; align-items:center;">
        <button type="button" class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-primary tw-text-white" data-toggle="modal" data-target="#route_modal"><i class="fa fa-plus"></i> Add route</button>
        <a href="{{ action([\App\Http\Controllers\BookerRouteController::class, 'exportSheet']) }}" class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-outline tw-dw-btn-primary"><i class="fa fa-download"></i> Download route sheet (Excel)</a>
        <form method="POST" action="{{ action([\App\Http\Controllers\BookerRouteController::class, 'importSheet']) }}" enctype="multipart/form-data" style="display:inline-flex; gap:6px; align-items:center;">
            @csrf
            <input type="file" name="sheet" accept=".csv" required class="form-control input-sm" style="width:230px;">
            <button type="submit" class="tw-dw-btn tw-dw-btn-sm tw-dw-btn-outline tw-dw-btn-primary"><i class="fa fa-upload"></i> Upload route sheet</button>
        </form>
        <span class="text-muted" style="margin-left:auto;">{{ $unassigned }} customer(s) not on any route</span>
    </div>

    @component('components.widget')
        <table class="table table-bordered table-hover">
            <thead><tr style="background:#f5f5f5;"><th>Route</th><th>Days</th><th>Booker</th><th>Location</th><th class="text-right">Shops</th><th class="text-right">With GPS</th><th>Status</th><th></th></tr></thead>
            <tbody>
                @forelse ($routes as $r)
                    @php $d = json_decode($r->days ?? '[]', true) ?: []; @endphp
                    <tr>
                        <td><a href="{{ action([\App\Http\Controllers\BookerRouteController::class, 'show'], [$r->id]) }}"><b>{{ $r->name }}</b></a></td>
                        <td>{{ implode(', ', array_map(fn ($x) => $days[$x] ?? $x, $d)) ?: '—' }}</td>
                        <td>{{ $r->booker_name ?: '—' }}</td>
                        <td>{{ $r->location_name ?: '—' }}</td>
                        <td class="text-right">{{ $r->shops }}</td>
                        <td class="text-right">{{ $r->with_gps }} @if($r->shops) <small class="text-muted">({{ round($r->with_gps * 100 / $r->shops) }}%)</small>@endif</td>
                        <td>{!! $r->is_active ? '<span class="label label-success">active</span>' : '<span class="label label-default">off</span>' !!}</td>
                        <td><a href="{{ action([\App\Http\Controllers\BookerRouteController::class, 'show'], [$r->id]) }}" class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-primary"><i class="fa fa-map-marked-alt"></i> Shops & map</a></td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted">No routes yet. Click <b>Add route</b>, e.g. "Dir Bazar — Monday".</td></tr>
                @endforelse
            </tbody>
        </table>
        <p class="text-muted">Route sheet: download it, fill the <b>route</b>, <b>outlet_type</b>, <b>outlet_class</b> (A/B/C), <b>latitude</b>, <b>longitude</b> columns in Excel, save as CSV and upload. Route names must already exist here.</p>
    @endcomponent
</section>

@include('booker_route.partials.route_modal', ['route' => null])
@endsection
