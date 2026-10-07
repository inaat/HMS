<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Sell > Booker routes: order bookers' routes (PJP — the shops a booker visits on given weekdays). A customer belongs
 * to one route (contacts.route_id) in an order (contacts.visit_sequence). Shop GPS is contacts.position ("lat,lng"),
 * shop type / class are contacts.outlet_type / outlet_class. The booker app shows today's route from this.
 */
class BookerRouteController extends Controller
{
    const OUTLET_TYPES = ['Kiryana', 'General store', 'Wholesale', 'Medical store', 'Bakery', 'Super store', 'Hotel / Restaurant', 'Other'];

    const DAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    private function authorizeAccess()
    {
        if (! auth()->user()->can('customer.update') && ! auth()->user()->can('customer.view')) {
            abort(403, 'Unauthorized action.');
        }
    }

    private function businessId(): int
    {
        return (int) request()->session()->get('user.business_id');
    }

    private function bookers(): array
    {
        return DB::table('users as u')
            ->join('model_has_roles as mr', function ($j) {
                $j->on('mr.model_id', '=', 'u.id')->where('mr.model_type', 'App\User');
            })
            ->join('roles as r', 'r.id', '=', 'mr.role_id')
            ->where('r.name', \App\Services\MobileSync\LocalSnapshot::BOOKER_ROLE.'#'.$this->businessId())
            ->whereNull('u.deleted_at')
            ->select('u.id', DB::raw("TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) as name"))
            ->pluck('name', 'u.id')->all();
    }

    public function index()
    {
        $this->authorizeAccess();
        $business_id = $this->businessId();

        $routes = DB::table('booker_routes as r')
            ->leftJoin('users as u', 'u.id', '=', 'r.booker_id')
            ->leftJoin('business_locations as l', 'l.id', '=', 'r.location_id')
            ->where('r.business_id', $business_id)
            ->select('r.*', 'l.name as location_name', DB::raw("TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) as booker_name"),
                DB::raw('(SELECT COUNT(*) FROM contacts c WHERE c.route_id = r.id AND c.deleted_at IS NULL) as shops'),
                DB::raw("(SELECT COUNT(*) FROM contacts c WHERE c.route_id = r.id AND c.deleted_at IS NULL AND c.position IS NOT NULL AND c.position != '') as with_gps"))
            ->orderBy('r.name')->get();

        $unassigned = DB::table('contacts')->where('business_id', $business_id)->whereIn('type', ['customer', 'both'])
            ->whereNull('deleted_at')->whereNull('route_id')->count();

        return view('booker_route.index', [
            'routes' => $routes, 'unassigned' => $unassigned, 'bookers' => $this->bookers(), 'days' => self::DAYS,
            'locations' => \App\BusinessLocation::forDropdown($business_id),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeAccess();
        $data = $this->validated($request);
        $data += ['business_id' => $this->businessId(), 'created_at' => now(), 'updated_at' => now()];
        $id = DB::table('booker_routes')->insertGetId($data);

        return redirect()->action([self::class, 'show'], [$id])->with('status', ['success' => 1, 'msg' => 'Route added. Now add its shops.']);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeAccess();
        $this->route($id);
        DB::table('booker_routes')->where('id', $id)->update($this->validated($request) + ['updated_at' => now()]);

        return redirect()->back()->with('status', ['success' => 1, 'msg' => 'Route saved']);
    }

    public function destroy($id)
    {
        $this->authorizeAccess();
        $this->route($id);
        DB::transaction(function () use ($id) {
            DB::table('contacts')->where('route_id', $id)->update(['route_id' => null, 'visit_sequence' => null]);
            DB::table('booker_routes')->where('id', $id)->delete();
        });

        return redirect()->action([self::class, 'index'])->with('status', ['success' => 1, 'msg' => 'Route deleted; its shops are now without a route']);
    }

    /** Route detail: its shops in visit order, add / remove / reorder, map. */
    public function show($id)
    {
        $this->authorizeAccess();
        $route = $this->route($id);
        $shops = DB::table('contacts')->where('route_id', $id)->whereNull('deleted_at')
            ->orderByRaw('visit_sequence IS NULL, visit_sequence')->orderBy('name')
            ->get(['id', 'contact_id', 'name', 'supplier_business_name', 'mobile', 'city', 'address_line_1', 'position', 'shop_photo', 'outlet_type', 'outlet_class', 'visit_sequence']);

        return view('booker_route.show', [
            'route' => $route, 'shops' => $shops, 'bookers' => $this->bookers(), 'days' => self::DAYS,
            'locations' => \App\BusinessLocation::forDropdown($this->businessId()), 'outlet_types' => self::OUTLET_TYPES,
        ]);
    }

    /** Add customers (one or many) to the route, at the end of its visit order. */
    public function addShops(Request $request, $id)
    {
        $this->authorizeAccess();
        $this->route($id);
        $ids = array_filter(array_map('intval', (array) $request->input('contact_ids', [])));
        $next = (int) DB::table('contacts')->where('route_id', $id)->max('visit_sequence');
        $moved = 0;
        foreach ($ids as $cid) {
            $moved += DB::table('contacts')->where('business_id', $this->businessId())->where('id', $cid)
                ->update(['route_id' => $id, 'visit_sequence' => ++$next]);
        }

        return redirect()->back()->with('status', ['success' => 1, 'msg' => $moved.' shop(s) added to the route']);
    }

    public function removeShop($id, $contact_id)
    {
        $this->authorizeAccess();
        $this->route($id);
        DB::table('contacts')->where('id', $contact_id)->where('route_id', $id)->update(['route_id' => null, 'visit_sequence' => null]);

        return redirect()->back()->with('status', ['success' => 1, 'msg' => 'Shop removed from the route']);
    }

    /** Save the visit order (ids in order) and each shop's type / class. */
    public function saveOrder(Request $request, $id)
    {
        $this->authorizeAccess();
        $this->route($id);
        foreach (array_values((array) $request->input('order', [])) as $i => $cid) {
            $update = ['visit_sequence' => $i + 1];
            $type = $request->input('outlet_type.'.$cid);
            $class = $request->input('outlet_class.'.$cid);
            $update['outlet_type'] = in_array($type, self::OUTLET_TYPES) ? $type : null;
            $update['outlet_class'] = in_array($class, ['A', 'B', 'C']) ? $class : null;
            DB::table('contacts')->where('id', (int) $cid)->where('route_id', $id)->update($update);
        }

        if ($request->ajax()) {
            return ['success' => 1, 'msg' => 'Saved'];
        }

        return redirect()->back()->with('status', ['success' => 1, 'msg' => 'Visit order saved']);
    }

    /**
     * Excel round-trip: every customer with route, type, class and GPS. Fill in Excel, upload it back.
     * Columns: customer_id, name, mobile, city, route, outlet_type, outlet_class, latitude, longitude.
     */
    public function exportSheet()
    {
        $this->authorizeAccess();
        $routes = DB::table('booker_routes')->where('business_id', $this->businessId())->pluck('name', 'id');
        $rows = DB::table('contacts')->where('business_id', $this->businessId())->whereIn('type', ['customer', 'both'])
            ->whereNull('deleted_at')->orderBy('name')
            ->get(['id', 'name', 'mobile', 'city', 'route_id', 'outlet_type', 'outlet_class', 'position']);

        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['customer_id', 'name', 'mobile', 'city', 'route', 'outlet_type', 'outlet_class', 'latitude', 'longitude']);
        foreach ($rows as $r) {
            [$lat, $lng] = array_pad(array_map('trim', explode(',', (string) $r->position)), 2, '');
            fputcsv($out, [$r->id, $r->name, $r->mobile, $r->city, $routes[$r->route_id] ?? '', $r->outlet_type, $r->outlet_class, $lat, $lng]);
        }
        rewind($out);

        return response(stream_get_contents($out), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="route-sheet-'.date('Y-m-d').'.csv"',
        ]);
    }

    public function importSheet(Request $request)
    {
        $this->authorizeAccess();
        $request->validate(['sheet' => 'required|file|max:5120']);
        $routes = DB::table('booker_routes')->where('business_id', $this->businessId())->pluck('id', 'name')
            ->mapWithKeys(function ($id, $name) {
                return [mb_strtolower(trim($name)) => $id];
            });

        $fh = fopen($request->file('sheet')->getRealPath(), 'r');
        $header = array_map(function ($h) {
            return strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h)));
        }, (array) fgetcsv($fh));
        $col = array_flip($header);
        if (! isset($col['customer_id'])) {
            return redirect()->back()->with('status', ['success' => 0, 'msg' => 'The sheet needs a customer_id column (download the route sheet first)']);
        }

        $updated = 0;
        $errors = [];
        $line = 1;
        $next_seq = [];
        while (($row = fgetcsv($fh)) !== false) {
            $line++;
            $get = function ($name) use ($row, $col) {
                return isset($col[$name]) ? trim((string) ($row[$col[$name]] ?? '')) : null;
            };
            $id = (int) $get('customer_id');
            if (! $id) {
                continue;
            }
            $update = [];
            $route = $get('route');
            if ($route !== null) {
                if ($route === '') {
                    $update['route_id'] = null;
                } elseif (isset($routes[mb_strtolower($route)])) {
                    $update['route_id'] = $routes[mb_strtolower($route)];
                } else {
                    $errors[] = "line $line: route \"$route\" does not exist";
                    continue;
                }
            }
            $type = $get('outlet_type');
            if ($type !== null) {
                $update['outlet_type'] = $type === '' ? null : mb_substr($type, 0, 50);
            }
            $class = strtoupper((string) $get('outlet_class'));
            if ($get('outlet_class') !== null) {
                $update['outlet_class'] = in_array($class, ['A', 'B', 'C']) ? $class : null;
            }
            $lat = $get('latitude');
            $lng = $get('longitude');
            if ($lat !== null && $lng !== null && $lat !== '' && $lng !== '') {
                if (! is_numeric($lat) || ! is_numeric($lng) || abs($lat) > 90 || abs($lng) > 180) {
                    $errors[] = "line $line: bad latitude / longitude";
                    continue;
                }
                $update['position'] = round((float) $lat, 7).','.round((float) $lng, 7);
            }
            if (empty($update)) {
                continue;
            }
            $current = DB::table('contacts')->where('business_id', $this->businessId())->where('id', $id)->first(['route_id']);
            if (empty($current)) {
                $errors[] = "line $line: customer $id not found";
                continue;
            }
            if (array_key_exists('route_id', $update) && $update['route_id'] && $update['route_id'] != $current->route_id) {
                $rid = $update['route_id'];
                $next_seq[$rid] = $next_seq[$rid] ?? (int) DB::table('contacts')->where('route_id', $rid)->max('visit_sequence');
                $update['visit_sequence'] = ++$next_seq[$rid];
            }
            $updated += DB::table('contacts')->where('id', $id)->update($update) ? 1 : 0;
        }
        fclose($fh);

        $msg = $updated.' customer(s) updated'.(empty($errors) ? '' : '. Skipped: '.implode('; ', array_slice($errors, 0, 10)).(count($errors) > 10 ? ' …' : ''));

        return redirect()->back()->with('status', ['success' => empty($errors) ? 1 : (int) ($updated > 0), 'msg' => $msg]);
    }

    /**
     * Sell > Booker visits: who visited which shop and when, how far from the shop's saved location, with photo and
     * result; per booker the day's route coverage (planned shops visited) and strike rate (visits with an order).
     */
    public function visits(Request $request)
    {
        $this->authorizeAccess();
        $business_id = $this->businessId();
        $date = $request->input('date') && strtotime($request->input('date')) ? date('Y-m-d', strtotime($request->input('date'))) : date('Y-m-d');
        $booker = (int) $request->input('booker_id');
        $bookers = $this->bookers();

        $visits = DB::table('booker_visits as v')
            ->leftJoin('contacts as c', 'c.id', '=', 'v.contact_id')
            ->leftJoin('booker_routes as r', 'r.id', '=', 'v.route_id')
            ->where('v.business_id', $business_id)
            ->whereDate('v.started_at', $date)
            ->when($booker, function ($q) use ($booker) {
                $q->where('v.booker_id', $booker);
            })
            ->select('v.*', 'c.name as shop', 'c.supplier_business_name', 'c.position as shop_position', 'r.name as route_name')
            ->orderBy('v.booker_id')->orderBy('v.started_at')
            ->get();

        // Order value of each visit (orders the booker made during it).
        $uuids = $visits->flatMap(function ($v) {
            return (json_decode($v->order_uuids ?? '', true) ?: [])['orders'] ?? [];
        })->all();
        $totals = $uuids ? DB::table('mobile_inbox')->whereIn('uuid', $uuids)->pluck('total', 'uuid') : collect();
        foreach ($visits as $v) {
            $ids = (json_decode($v->order_uuids ?? '', true) ?: [])['orders'] ?? [];
            $v->order_total = collect($ids)->sum(function ($u) use ($totals) {
                return (float) ($totals[$u] ?? 0);
            });
            $v->minutes = $v->ended_at ? max(0, round((strtotime($v->ended_at) - strtotime($v->started_at)) / 60)) : null;
        }

        // Per booker: planned shops = shops on their routes for this weekday.
        $weekday = (int) date('N', strtotime($date));
        $summary = [];
        foreach ($visits->groupBy('booker_id') as $id => $rows) {
            $summary[$id] = ['visits' => $rows->count()];
        }
        foreach (array_keys($bookers) as $id) {
            if ($booker && $booker != $id) {
                continue;
            }
            $route_ids = DB::table('booker_routes')->where('business_id', $business_id)->where('booker_id', $id)->where('is_active', 1)->get(['id', 'days'])
                ->filter(function ($r) use ($weekday) {
                    return in_array($weekday, json_decode($r->days ?? '[]', true) ?: []);
                })->pluck('id');
            $planned = $route_ids->isEmpty() ? collect() : DB::table('contacts')->whereIn('route_id', $route_ids)->whereNull('deleted_at')->pluck('id');
            if ($planned->isEmpty() && empty($summary[$id])) {
                continue;
            }
            $rows = $visits->where('booker_id', $id);
            $visited = $rows->pluck('contact_id')->filter()->unique();
            $summary[$id] = [
                'name' => $bookers[$id],
                'planned' => $planned->count(),
                'planned_visited' => $visited->intersect($planned)->count(),
                'visits' => $rows->count(),
                'orders' => $rows->where('outcome', 'order')->count(),
                'sale' => $rows->sum('order_total'),
                'far' => $rows->where('within_range', 0)->count(),
                'no_gps' => $rows->whereNull('lat')->count(),
                'first' => $rows->min('started_at'),
                'last' => $rows->max('ended_at'),
            ];
        }
        foreach ($summary as $id => $s) {
            if (! isset($s['name'])) {
                unset($summary[$id]);
            }
        }

        return view('booker_route.visits', [
            'visits' => $visits, 'summary' => $summary, 'bookers' => $bookers, 'date' => $date, 'booker' => $booker,
            'radius' => (int) config('mobile_sync.visit_radius_m', 100),
        ]);
    }

    /** Customers search for "Add shops" (select2 ajax): not on this route yet. */
    public function searchCustomers(Request $request, $id)
    {
        $this->authorizeAccess();
        $q = trim((string) $request->input('q'));
        $routes = DB::table('booker_routes')->where('business_id', $this->businessId())->pluck('name', 'id');

        return DB::table('contacts')->where('business_id', $this->businessId())->whereIn('type', ['customer', 'both'])
            ->whereNull('deleted_at')
            ->where(function ($w) use ($id) {
                $w->whereNull('route_id')->orWhere('route_id', '!=', $id);
            })
            ->when($q !== '', function ($w) use ($q) {
                $w->where(function ($x) use ($q) {
                    $x->where('name', 'like', "%$q%")->orWhere('mobile', 'like', "%$q%")->orWhere('city', 'like', "%$q%")
                        ->orWhere('supplier_business_name', 'like', "%$q%")->orWhere('contact_id', 'like', "%$q%");
                });
            })
            ->orderBy('name')->limit(50)->get(['id', 'name', 'mobile', 'city', 'route_id'])
            ->map(function ($c) use ($routes) {
                return ['id' => $c->id, 'text' => trim($c->name.' '.($c->mobile ? '· '.$c->mobile : '').' '.($c->city ? '· '.$c->city : ''))
                    .($c->route_id ? ' [now on '.($routes[$c->route_id] ?? 'another route').']' : '')];
            });
    }

    private function route($id)
    {
        $route = DB::table('booker_routes')->where('business_id', $this->businessId())->where('id', $id)->first();
        abort_if(empty($route), 404);

        return $route;
    }

    private function validated(Request $request): array
    {
        $request->validate(['name' => 'required|string|max:191']);
        $days = array_values(array_intersect(array_map('intval', (array) $request->input('days', [])), array_keys(self::DAYS)));

        return [
            'name' => trim($request->input('name')),
            'location_id' => $request->filled('location_id') ? (int) $request->input('location_id') : null,
            'booker_id' => $request->filled('booker_id') ? (int) $request->input('booker_id') : null,
            'days' => json_encode($days),
            'is_active' => $request->has('is_active') ? 1 : (int) ! $request->has('_has_active'),
        ];
    }
}
