<?php

namespace App\Http\Controllers;

use App\BusinessLocation;
use App\Contact;
use App\Utils\TradeSchemeUtil;
use App\Utils\Util;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Products > Trade schemes: "buy 12 get 1 free" offers (scheme master with slabs). The rules are in TradeSchemeUtil;
 * the sale screens load the running schemes from active() and apply them (public/js/trade_scheme.js).
 */
class TradeSchemeController extends Controller
{
    protected $util;

    public function __construct(Util $util)
    {
        $this->util = $util;
    }

    /** Reports > Activity log: who created / changed / switched / deleted which scheme. */
    private function logActivity(string $action, $scheme_id, string $code)
    {
        try {
            $activity = activity()->causedBy(auth()->user())
                ->withProperties(['trade_scheme_id' => $scheme_id, 'code' => $code])
                ->log('Trade scheme '.$code.' '.$action);
            $activity->business_id = request()->session()->get('user.business_id');
            $activity->save();
        } catch (\Throwable $e) {
            // logging never blocks the change
        }
    }

    private function authorizeManage()
    {
        if (! auth()->user()->can('product.create') && ! auth()->user()->can('product.update')) {
            abort(403, 'Unauthorized action.');
        }
    }

    public function index(Request $request)
    {
        $this->authorizeManage();
        $business_id = $request->session()->get('user.business_id');

        $schemes = DB::table('trade_schemes as s')
            ->leftJoin('products as p', 'p.id', '=', 's.product_id')
            ->leftJoin('variations as v', 'v.id', '=', 's.variation_id')
            ->leftJoin('variations as fv', 'fv.id', '=', 's.free_variation_id')
            ->leftJoin('products as fp', 'fp.id', '=', 'fv.product_id')
            ->leftJoin('contacts as c', 'c.id', '=', 's.supplier_id')
            ->leftJoin('brands as b', 'b.id', '=', 's.brand_id')
            ->where('s.business_id', $business_id)
            ->orderByDesc('s.is_active')->orderByDesc('s.id')
            ->select('s.*', 'p.name as product_name', 'p.type as product_type', 'v.name as variation_name', 'fp.name as free_product_name', 'b.name as brand_name',
                'fv.name as free_variation_name', 'fp.type as free_product_type', DB::raw("COALESCE(NULLIF(c.supplier_business_name, ''), c.name) as supplier_name"))
            ->get();
        $slabs = DB::table('trade_scheme_slabs')->whereIn('trade_scheme_id', $schemes->pluck('id')->all() ?: [0])->orderBy('buy_qty')->get()->groupBy('trade_scheme_id');
        $used = TradeSchemeUtil::installed() ? TradeSchemeUtil::freeUsed($schemes->pluck('id')->all()) : [];
        $units = DB::table('units')->where('business_id', $business_id)->pluck('short_name', 'id');
        $locations = BusinessLocation::where('business_id', $business_id)->pluck('name', 'id');
        $today = now()->format('Y-m-d');

        foreach ($schemes as $s) {
            $s->slabs = $slabs->get($s->id, collect())->map(function ($x) {
                $x->class_percents = json_decode((string) ($x->class_percents ?? ''), true) ?: [];

                return $x;
            });
            $s->slab_text = TradeSchemeUtil::slabText($s);
            $s->group_count = count(json_decode((string) ($s->product_ids ?? ''), true) ?: []);
            $free_unit = $s->free_mode === 'same' ? ($s->free_unit_id ?: $s->unit_id) : $s->free_unit_id;
            $s->unit_name = $units[$s->unit_id] ?? '';
            $s->free_unit_name = $units[$free_unit] ?? '';
            $s->free_used = round((float) ($used[$s->id] ?? 0) / TradeSchemeUtil::multiplier($free_unit), 4);
            $s->status = ! $s->is_active ? 'off'
                : (($s->starts_at && $s->starts_at > $today) ? 'upcoming'
                : (($s->ends_at && $s->ends_at < $today) ? 'ended'
                : (($s->budget_qty !== null && $s->free_used >= (float) $s->budget_qty) ? 'budget used' : 'running')));
            $loc = array_map('intval', json_decode((string) $s->location_ids, true) ?: []);
            $s->location_text = empty($loc) ? 'All locations' : collect($loc)->map(fn ($id) => $locations[$id] ?? '#'.$id)->implode(', ');
        }

        return view('trade_scheme.index', compact('schemes'));
    }

    public function create(Request $request)
    {
        $this->authorizeManage();

        return $this->form($request, null);
    }

    public function edit(Request $request, $id)
    {
        $this->authorizeManage();
        $scheme = DB::table('trade_schemes')->where('business_id', $request->session()->get('user.business_id'))->where('id', $id)->first() ?? abort(404);

        return $this->form($request, $scheme);
    }

    /** Copy of a scheme as a new one (same rules, new code / dates). */
    public function copy(Request $request, $id)
    {
        $this->authorizeManage();
        $scheme = DB::table('trade_schemes')->where('business_id', $request->session()->get('user.business_id'))->where('id', $id)->first() ?? abort(404);
        $scheme->copy_of = $scheme->id;

        return $this->form($request, $scheme);
    }

    private function form(Request $request, $scheme)
    {
        $business_id = $request->session()->get('user.business_id');
        $locations = BusinessLocation::forDropdown($business_id);
        $suppliers = Contact::suppliersDropdown($business_id, false);
        $slabs = $scheme ? DB::table('trade_scheme_slabs')->where('trade_scheme_id', $scheme->copy_of ?? $scheme->id)->orderBy('buy_qty')->get() : collect();

        // the chosen products, shown in the select2 boxes
        $picked = function ($product_id, $variation_id) {
            if (! $product_id) {
                return null;
            }
            $row = DB::table('products as p')->leftJoin('variations as v', function ($join) use ($variation_id) {
                $join->on('v.product_id', '=', 'p.id')->where('v.id', (int) $variation_id);
            })->where('p.id', $product_id)->first(['p.id', 'p.name', 'p.type', 'p.sku', 'v.id as variation_id', 'v.name as variation', 'v.sub_sku']);

            return $row ? ['id' => $variation_id ? 'v'.$variation_id : 'p'.$product_id,
                'text' => $row->name.($variation_id && $row->type === 'variable' ? ' - '.$row->variation : '').' ('.($row->sub_sku ?: $row->sku).')'] : null;
        };
        $buy_pick = $scheme ? $picked($scheme->product_id, $scheme->variation_id) : null;
        $free_pick = $scheme && $scheme->free_variation_id
            ? $picked(DB::table('variations')->where('id', $scheme->free_variation_id)->value('product_id'), $scheme->free_variation_id) : null;
        $next_code = 'SCH-'.str_pad((string) ((int) DB::table('trade_schemes')->where('business_id', $business_id)->max('id') + 1), 3, '0', STR_PAD_LEFT);
        $brands = DB::table('brands')->where('business_id', $business_id)->whereNull('deleted_at')->orderBy('name')->pluck('name', 'id');
        // group of products: the tokens ("v12" / "p5") with their names, for the multi-select
        $group_picks = [];
        foreach (json_decode((string) ($scheme->product_ids ?? ''), true) ?: [] as $token) {
            if (preg_match('/^([vp])(\d+)$/', (string) $token, $m)) {
                $pid = $m[1] === 'v' ? DB::table('variations')->where('id', $m[2])->value('product_id') : (int) $m[2];
                $pick = $picked($pid, $m[1] === 'v' ? (int) $m[2] : null);
                if ($pick) {
                    $group_picks[] = ['id' => $token, 'text' => $pick['text']];
                }
            }
        }

        return view('trade_scheme.form', compact('scheme', 'locations', 'suppliers', 'slabs', 'buy_pick', 'free_pick', 'next_code', 'brands', 'group_picks'));
    }

    public function store(Request $request)
    {
        $this->authorizeManage();

        return $this->save($request, null);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeManage();
        DB::table('trade_schemes')->where('business_id', $request->session()->get('user.business_id'))->where('id', $id)->first() ?? abort(404);

        return $this->save($request, $id);
    }

    private function save(Request $request, $id)
    {
        $business_id = $request->session()->get('user.business_id');
        $request->validate([
            'code' => 'required|string|max:40',
            'name' => 'required|string|max:191',
            'scope' => 'required|in:product,products,brand',
            'condition_type' => 'required|in:qty,value',
            'reward_type' => 'required|in:free,percent',
            'channel' => 'required|in:all,retail,wholesale',
            'free_mode' => 'required|in:same,other',
            'funded_by' => 'required|in:own,supplier',
        ]);
        if (DB::table('trade_schemes')->where('business_id', $business_id)->where('code', $request->input('code'))->when($id, fn ($q) => $q->where('id', '!=', $id))->exists()) {
            return back()->withInput()->with('status', ['success' => 0, 'msg' => 'Code '.$request->input('code').' is already used by another scheme']);
        }
        $scope = $request->input('scope');
        $reward = $request->input('reward_type');
        $condition = $request->input('condition_type');

        // what is bought
        $product_id = null;
        $variation_id = null;
        $product_ids = null;
        $brand_id = null;
        if ($scope === 'product') {
            [$product_id, $variation_id] = $this->item($request->input('buy_item'), $business_id);
            if (! $product_id) {
                return back()->withInput()->with('status', ['success' => 0, 'msg' => 'Choose the product that is bought']);
            }
        } elseif ($scope === 'products') {
            $tokens = array_values(array_filter((array) $request->input('group_items'), fn ($t) => preg_match('/^[vp]\d+$/', (string) $t)));
            if (count($tokens) < 1) {
                return back()->withInput()->with('status', ['success' => 0, 'msg' => 'Choose the products of the group']);
            }
            $product_ids = json_encode($tokens);
        } else {
            $brand_id = DB::table('brands')->where('business_id', $business_id)->where('id', $request->input('brand_id'))->value('id');
            if (! $brand_id) {
                return back()->withInput()->with('status', ['success' => 0, 'msg' => 'Choose the brand']);
            }
        }
        // free goods of the same product only make sense for one product counted by quantity
        $free_mode = $reward === 'free' && ($scope !== 'product' || $condition === 'value') ? 'other' : $request->input('free_mode');

        $free_variation_id = null;
        if ($reward === 'free' && $free_mode === 'other') {
            [$free_product_id, $free_variation_id] = $this->item($request->input('free_item'), $business_id);
            if (! $free_product_id) {
                return back()->withInput()->with('status', ['success' => 0, 'msg' => 'Choose the free product']);
            }
            // a whole product picked as free item: its first variation
            $free_variation_id = $free_variation_id ?: DB::table('variations')->where('product_id', $free_product_id)->whereNull('deleted_at')->orderBy('id')->value('id');
        }

        $slabs = [];
        $classes = ['A', 'B', 'C', 'D', 'E'];
        foreach ((array) $request->input('slab_buy') as $i => $buy) {
            $buy = (float) $this->util->num_uf((string) $buy);
            if ($buy <= 0) {
                continue;
            }
            if ($reward === 'percent') {
                $pct = $request->input('slab_percent')[$i] ?? '';
                $by_class = [];
                foreach ($classes as $c) {
                    $v = $request->input('slab_class_'.$c)[$i] ?? '';
                    if ($v !== '' && $v !== null) {
                        $by_class[$c] = (float) $this->util->num_uf((string) $v);
                    }
                }
                if ($pct === '' && empty($by_class)) {
                    continue;
                }
                $slabs[] = ['buy_qty' => $buy, 'free_qty' => 0, 'percent' => $pct === '' ? null : (float) $this->util->num_uf((string) $pct),
                    'class_percents' => empty($by_class) ? null : json_encode($by_class)];
            } else {
                $free = (float) $this->util->num_uf((string) ($request->input('slab_free')[$i] ?? 0));
                if ($free > 0) {
                    $slabs[] = ['buy_qty' => $buy, 'free_qty' => $free, 'percent' => null, 'class_percents' => null];
                }
            }
        }
        if (empty($slabs)) {
            return back()->withInput()->with('status', ['success' => 0, 'msg' => 'Add at least one slab, e.g. buy 12 → 1 free, or 2 boxes → 2%']);
        }

        $date = fn ($v) => empty($v) ? null : $this->util->uf_date($v);
        $data = [
            'business_id' => $business_id,
            'code' => trim($request->input('code')),
            'name' => trim($request->input('name')),
            'starts_at' => $date($request->input('starts_at')),
            'ends_at' => $date($request->input('ends_at')),
            'is_active' => $request->boolean('is_active') ? 1 : 0,
            'scope' => $scope,
            'product_id' => $product_id,
            'variation_id' => $variation_id,
            'product_ids' => $product_ids,
            'brand_id' => $brand_id,
            'condition_type' => $condition,
            'count_unit' => $scope === 'product' ? 'unit' : ($request->input('count_unit') === 'base' ? 'base' : 'big'),
            'reward_type' => $reward,
            'channel' => $request->input('channel'),
            'unit_id' => $scope === 'product' ? ($request->input('unit_id') ?: null) : null,
            'free_mode' => $free_mode,
            'free_variation_id' => $reward === 'free' ? $free_variation_id : null,
            'free_unit_id' => $reward === 'free' ? ($request->input('free_unit_id') ?: null) : null,
            'repeat' => $request->boolean('repeat') ? 1 : 0,
            'location_ids' => json_encode(array_values(array_map('intval', array_filter((array) $request->input('location_ids'))))),
            'funded_by' => $request->input('funded_by'),
            'supplier_id' => $request->input('funded_by') === 'supplier' ? ($request->input('supplier_id') ?: null) : null,
            'claim_type' => in_array($request->input('claim_type'), ['cash', 'credit_note', 'stock']) ? $request->input('claim_type') : 'credit_note',
            'budget_qty' => $request->filled('budget_qty') ? (float) $this->util->num_uf($request->input('budget_qty')) : null,
            'notes' => $request->input('notes'),
            'updated_at' => now(),
        ];

        DB::transaction(function () use (&$id, $data, $slabs) {
            if ($id) {
                DB::table('trade_schemes')->where('id', $id)->update($data);
                DB::table('trade_scheme_slabs')->where('trade_scheme_id', $id)->delete();
            } else {
                $id = DB::table('trade_schemes')->insertGetId($data + ['created_by' => auth()->id(), 'created_at' => now()]);
            }
            foreach ($slabs as $s) {
                DB::table('trade_scheme_slabs')->insert($s + ['trade_scheme_id' => $id]);
            }
        });

        $this->logActivity($request->isMethod('put') ? 'edited' : 'added', $id, $data['code']);

        return redirect()->action([self::class, 'index'])->with('status', ['success' => 1, 'msg' => 'Scheme '.$data['code'].' saved']);
    }

    /** "v123" = one variation, "p45" = a product (all its variations) → [product_id, variation_id|null] */
    private function item($value, $business_id): array
    {
        $value = (string) $value;
        if (preg_match('/^v(\d+)$/', $value, $m)) {
            $product_id = DB::table('variations as v')->join('products as p', 'p.id', '=', 'v.product_id')
                ->where('p.business_id', $business_id)->where('v.id', $m[1])->value('p.id');

            return [$product_id, $product_id ? (int) $m[1] : null];
        }
        if (preg_match('/^p(\d+)$/', $value, $m)) {
            return [DB::table('products')->where('business_id', $business_id)->where('id', $m[1])->value('id'), null];
        }

        return [null, null];
    }

    public function toggle(Request $request, $id)
    {
        $this->authorizeManage();
        $business_id = $request->session()->get('user.business_id');
        $scheme = DB::table('trade_schemes')->where('business_id', $business_id)->where('id', $id)->first() ?? abort(404);
        DB::table('trade_schemes')->where('id', $id)->update(['is_active' => $scheme->is_active ? 0 : 1, 'updated_at' => now()]);
        $this->logActivity($scheme->is_active ? 'switched off' : 'switched on', $id, $scheme->code);

        return back()->with('status', ['success' => 1, 'msg' => 'Scheme '.$scheme->code.' switched '.($scheme->is_active ? 'off' : 'on')]);
    }

    /** Delete only a scheme that was never used on a sale; a used one is switched off instead (reports keep it). */
    public function destroy(Request $request, $id)
    {
        $this->authorizeManage();
        $business_id = $request->session()->get('user.business_id');
        $scheme = DB::table('trade_schemes')->where('business_id', $business_id)->where('id', $id)->first() ?? abort(404);
        if (DB::table('transaction_sell_lines')->where('trade_scheme_id', $id)->exists()) {
            DB::table('trade_schemes')->where('id', $id)->update(['is_active' => 0, 'updated_at' => now()]);

            return back()->with('status', ['success' => 1, 'msg' => 'Scheme '.$scheme->code.' is used on sales, so it was switched off instead of deleted']);
        }
        DB::transaction(function () use ($id) {
            DB::table('trade_scheme_slabs')->where('trade_scheme_id', $id)->delete();
            DB::table('trade_schemes')->where('id', $id)->delete();
        });
        $this->logActivity('deleted', $id, $scheme->code);

        return back()->with('status', ['success' => 1, 'msg' => 'Scheme '.$scheme->code.' deleted']);
    }

    /** Schemes running now at a location, for the sale screens (public/js/trade_scheme.js). */
    public function active(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $schemes = TradeSchemeUtil::activeFor($business_id, $request->input('location_id'), $request->input('date') ?: null, $request->input('transaction_id'));

        return response()->json($schemes->map(fn ($s) => [
            'id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'label' => $s->label,
            'scope' => $s->scope, 'p_ids' => $s->p_ids, 'v_ids' => $s->v_ids,
            'condition_type' => $s->condition_type, 'count_unit' => $s->count_unit, 'reward_type' => $s->reward_type, 'channel' => $s->channel,
            'product_id' => (int) $s->product_id, 'variation_id' => $s->variation_id ? (int) $s->variation_id : null,
            'unit_mult' => $s->unit_mult, 'unit_name' => $s->unit_name, 'repeat' => (bool) $s->repeat,
            'slabs' => $s->slabs, 'free_mode' => $s->free_mode,
            'free_variation_id' => $s->free_variation_id ? (int) $s->free_variation_id : null, 'free_name' => $s->free_name,
            'free_unit_id' => $s->free_mode === 'same' ? ($s->free_unit_id ?: $s->unit_id) : $s->free_unit_id,
            'free_unit_mult' => $s->free_unit_mult, 'free_unit_name' => $s->free_unit_name, 'budget_left' => $s->budget_left,
        ])->values());
    }

    /** The sale's customer for the schemes: class (Outlet class) and channel (retail / wholesale). */
    public function customer(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $id = DB::table('contacts')->where('business_id', $business_id)->where('id', $request->input('contact_id'))->value('id');

        return response()->json(TradeSchemeUtil::customer($id));
    }

    /** Product search for the scheme form: products and their variations, select2 format. */
    public function products(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $term = trim((string) $request->input('q'));
        $rows = DB::table('products as p')->join('variations as v', 'v.product_id', '=', 'p.id')
            ->where('p.business_id', $business_id)->whereNull('v.deleted_at')->where('p.type', '!=', 'modifier')
            ->when($term !== '', function ($q) use ($term) {
                $q->where(function ($q) use ($term) {
                    $q->where('p.name', 'like', '%'.$term.'%')->orWhere('p.sku', 'like', '%'.$term.'%')->orWhere('v.sub_sku', 'like', '%'.$term.'%');
                });
            })
            ->orderBy('p.name')->limit(40)
            ->get(['p.id', 'p.name', 'p.type', 'p.sku', 'v.id as variation_id', 'v.name as variation', 'v.sub_sku']);

        $results = [];
        foreach ($rows->groupBy('id') as $product_id => $variations) {
            $first = $variations->first();
            if ($first->type === 'variable') {
                $results[] = ['id' => 'p'.$product_id, 'text' => $first->name.' — all variations ('.$first->sku.')'];
                foreach ($variations as $v) {
                    $results[] = ['id' => 'v'.$v->variation_id, 'text' => $first->name.' - '.$v->variation.' ('.$v->sub_sku.')'];
                }
            } else {
                $results[] = ['id' => 'v'.$first->variation_id, 'text' => $first->name.' ('.($first->sub_sku ?: $first->sku).')'];
            }
        }

        return response()->json(['results' => $results]);
    }

    /** Units a product is sold in (base + sub units), for the scheme form's unit boxes. */
    public function units(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $value = (string) $request->input('item');
        $product_id = preg_match('/^v(\d+)$/', $value, $m) ? DB::table('variations')->where('id', $m[1])->value('product_id')
            : (preg_match('/^p(\d+)$/', $value, $m) ? (int) $m[1] : null);
        $product = $product_id ? DB::table('products')->where('business_id', $business_id)->where('id', $product_id)->first(['id', 'unit_id']) : null;
        if (! $product || ! $product->unit_id) {
            return response()->json([]);
        }
        $units = $this->util->getSubUnits($business_id, $product->unit_id, true, $product->id);

        return response()->json(collect($units)->map(fn ($u, $id) => ['id' => $id, 'text' => $u['name'].((float) $u['multiplier'] != 1 ? ' ('.(float) $u['multiplier'].')' : '')])->values());
    }
}
