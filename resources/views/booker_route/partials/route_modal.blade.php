{{-- Add / edit a booker route: name, weekdays, booker, location. --}}
<div class="modal fade contains_select2" id="route_modal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <form method="POST" class="modal-content" action="{{ $route ? action([\App\Http\Controllers\BookerRouteController::class, 'update'], [$route->id]) : action([\App\Http\Controllers\BookerRouteController::class, 'store']) }}">
            @csrf
            @if ($route) @method('PUT') @endif
            <input type="hidden" name="_has_active" value="1">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title">{{ $route ? 'Edit route' : 'Add route' }}</h4>
            </div>
            <div class="modal-body">
                @php $sel = $route ? (json_decode($route->days ?? '[]', true) ?: []) : []; @endphp
                <div class="form-group">
                    <label>Route name *</label>
                    <input type="text" name="name" class="form-control" required maxlength="191" value="{{ $route->name ?? '' }}" placeholder="e.g. Dir Bazar — Monday">
                </div>
                <div class="form-group">
                    <label>Visit days</label><br>
                    @foreach ($days as $n => $label)
                        <label style="margin-right:12px; font-weight:normal;"><input type="checkbox" name="days[]" value="{{ $n }}" @if(in_array($n, $sel)) checked @endif> {{ $label }}</label>
                    @endforeach
                </div>
                <div class="form-group">
                    <label>Order booker</label>
                    <select name="booker_id" class="form-control select2" style="width:100%;">
                        <option value="">— none —</option>
                        @foreach ($bookers as $id => $name)
                            <option value="{{ $id }}" @if(($route->booker_id ?? null) == $id) selected @endif>{{ $name }}</option>
                        @endforeach
                    </select>
                    @if (empty($bookers)) <p class="help-block">No order bookers yet (User Management → Users, role "Order Booker").</p> @endif
                </div>
                <div class="form-group">
                    <label>Location</label>
                    <select name="location_id" class="form-control select2" style="width:100%;">
                        <option value="">— any —</option>
                        @foreach ($locations as $id => $name)
                            <option value="{{ $id }}" @if(($route->location_id ?? null) == $id) selected @endif>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <label style="font-weight:normal;"><input type="checkbox" name="is_active" value="1" @if(! $route || $route->is_active) checked @endif> Active</label>
            </div>
            <div class="modal-footer">
                <button type="submit" class="tw-dw-btn tw-dw-btn-primary tw-text-white">Save</button>
                <button type="button" class="tw-dw-btn tw-dw-btn-neutral tw-text-white" data-dismiss="modal">Cancel</button>
            </div>
        </form>
    </div>
</div>
