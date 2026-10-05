<?php

namespace App\Http\Controllers;

use App\Brands;
use App\CommissionAgentRule;
use App\Product;
use App\User;
use App\Utils\Util;
use DataTables;
use DB;
use Illuminate\Http\Request;

class SalesCommissionAgentController extends Controller
{
    /**
     * Constructor
     *
     * @param  Util  $commonUtil
     * @return void
     */
    public function __construct(Util $commonUtil)
    {
        $this->commonUtil = $commonUtil;
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        if (! auth()->user()->can('user.view') && ! auth()->user()->can('user.create')) {
            abort(403, 'Unauthorized action.');
        }

        if (request()->ajax()) {
            $business_id = request()->session()->get('user.business_id');

            $users = User::where('business_id', $business_id)
                        ->where('is_cmmsn_agnt', 1)
                        ->select(['id',
                            DB::raw("CONCAT(COALESCE(surname, ''), ' ', COALESCE(first_name, ''), ' ', COALESCE(last_name, '')) as full_name"),
                            'email', 'contact_no', 'address', 'cmmsn_percent', ]);

            //Brand / product rules shown under the default commission %
            $rules = CommissionAgentRule::with(['brand', 'product'])
                        ->where('business_id', $business_id)
                        ->get()
                        ->groupBy('user_id');

            return Datatables::of($users)
                ->editColumn('cmmsn_percent', function ($row) use ($rules) {
                    $html = $this->commonUtil->num_f($row->cmmsn_percent).'%';
                    foreach ($rules[$row->id] ?? [] as $rule) {
                        $name = ! empty($rule->product_id) ? optional($rule->product)->name : optional($rule->brand)->name;
                        $value = $rule->type == 'fixed' ? $this->commonUtil->num_f($rule->value).' / unit' : $this->commonUtil->num_f($rule->value).'%';
                        $html .= '<br><small class="label bg-gray" style="font-weight: normal;">'.e($name).': '.$value.'</small>';
                    }

                    return $html;
                })
                ->addColumn(
                    'action',
                    '@can("user.update")
                    <button type="button" data-href="{{action(\'App\Http\Controllers\SalesCommissionAgentController@edit\', [$id])}}" data-container=".commission_agent_modal" class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline  btn-modal tw-dw-btn-primary"><i class="glyphicon glyphicon-edit"></i> @lang("messages.edit")</button>
                        &nbsp;
                        <button type="button" data-href="{{action(\'App\Http\Controllers\SalesCommissionAgentController@rules\', [$id])}}" data-container=".commission_agent_modal" class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline btn-modal tw-dw-btn-success"><i class="fa fa-percent"></i> Commission rules</button>
                        &nbsp;
                        @endcan
                        @can("user.delete")
                        <button data-href="{{action(\'App\Http\Controllers\SalesCommissionAgentController@destroy\', [$id])}}" class="tw-dw-btn tw-dw-btn-outline tw-dw-btn-xs tw-dw-btn-error delete_commsn_agnt_button"><i class="glyphicon glyphicon-trash"></i> @lang("messages.delete")</button>
                        &nbsp;
                        @endcan
                        @can("sales_representative.view")
                        <a href="{{action(\'App\Http\Controllers\ReportController@getCommissionAgentReport\')}}?commission_agent={{$id}}" class="tw-dw-btn tw-dw-btn-outline tw-dw-btn-xs tw-dw-btn-info"><i class="fa fa-file-alt"></i> Report</a>
                        @endcan'
                )
                ->filterColumn('full_name', function ($query, $keyword) {
                    $query->whereRaw("CONCAT(COALESCE(surname, ''), ' ', COALESCE(first_name, ''), ' ', COALESCE(last_name, '')) like ?", ["%{$keyword}%"]);
                })
                ->removeColumn('id')
                ->rawColumns(['action', 'cmmsn_percent'])
                ->make(true);
        }

        return view('sales_commission_agent.index');
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        if (! auth()->user()->can('user.create')) {
            abort(403, 'Unauthorized action.');
        }

        return view('sales_commission_agent.create');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        if (! auth()->user()->can('user.create')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $input = $request->only(['surname', 'first_name', 'last_name', 'email', 'address', 'contact_no', 'cmmsn_percent']);
            $input['cmmsn_percent'] = $this->commonUtil->num_uf($input['cmmsn_percent']);
            $business_id = $request->session()->get('user.business_id');
            $input['business_id'] = $business_id;
            $input['allow_login'] = 0;
            $input['is_cmmsn_agnt'] = 1;

            $user = User::create($input);

            $output = ['success' => true,
                'msg' => __('lang_v1.commission_agent_added_success'),
            ];
        } catch (\Exception $e) {
            \Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());

            $output = ['success' => false,
                'msg' => __('messages.something_went_wrong'),
            ];
        }

        return $output;
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        if (! auth()->user()->can('user.update')) {
            abort(403, 'Unauthorized action.');
        }

        $user = User::findOrFail($id);

        return view('sales_commission_agent.edit')
                    ->with(compact('user'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        if (! auth()->user()->can('user.update')) {
            abort(403, 'Unauthorized action.');
        }

        if (request()->ajax()) {
            try {
                $input = $request->only(['surname', 'first_name', 'last_name', 'email', 'address', 'contact_no', 'cmmsn_percent']);
                $input['cmmsn_percent'] = $this->commonUtil->num_uf($input['cmmsn_percent']);
                $business_id = $request->session()->get('user.business_id');

                $user = User::where('id', $id)
                            ->where('business_id', $business_id)
                            ->where('is_cmmsn_agnt', 1)
                            ->first();
                $user->update($input);

                $output = ['success' => true,
                    'msg' => __('lang_v1.commission_agent_updated_success'),
                ];
            } catch (\Exception $e) {
                \Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());

                $output = ['success' => false,
                    'msg' => __('messages.something_went_wrong'),
                ];
            }

            return $output;
        }
    }

    /**
     * Brand / product wise commission rules of an agent (modal)
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function rules($id)
    {
        if (! auth()->user()->can('user.update')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');
        $user = User::where('business_id', $business_id)->where('is_cmmsn_agnt', 1)->findOrFail($id);

        $rules = CommissionAgentRule::where('business_id', $business_id)
                    ->where('user_id', $user->id)
                    ->orderByRaw('product_id IS NOT NULL')
                    ->orderBy('id')
                    ->get();

        $brands = Brands::forDropdown($business_id);
        $products = Product::where('business_id', $business_id)
                    ->select('id', DB::raw("CONCAT(name, ' (', sku, ')') as name"))
                    ->orderBy('name')
                    ->pluck('name', 'id');

        return view('sales_commission_agent.rules')
                    ->with(compact('user', 'rules', 'brands', 'products'));
    }

    /**
     * Saves all brand / product wise commission rules of an agent (replaces the old ones)
     *
     * @param  int  $id
     * @return array
     */
    public function saveRules(Request $request, $id)
    {
        if (! auth()->user()->can('user.update')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $business_id = $request->session()->get('user.business_id');
            $user = User::where('business_id', $business_id)->where('is_cmmsn_agnt', 1)->findOrFail($id);

            $rows = [];
            $seen = [];
            foreach ((array) $request->input('rules', []) as $rule) {
                $applies_to = ($rule['applies_to'] ?? 'brand') == 'product' ? 'product' : 'brand';
                $target_id = $applies_to == 'product' ? ($rule['product_id'] ?? null) : ($rule['brand_id'] ?? null);
                if (empty($target_id)) {
                    continue;
                }

                //One rule per brand / product: the last one wins
                $seen[$applies_to.$target_id] = [
                    'business_id' => $business_id,
                    'user_id' => $user->id,
                    'brand_id' => $applies_to == 'brand' ? $target_id : null,
                    'product_id' => $applies_to == 'product' ? $target_id : null,
                    'type' => ($rule['type'] ?? 'percentage') == 'fixed' ? 'fixed' : 'percentage',
                    'value' => $this->commonUtil->num_uf($rule['value'] ?? 0),
                ];
            }
            $rows = array_values($seen);

            DB::beginTransaction();
            CommissionAgentRule::where('business_id', $business_id)->where('user_id', $user->id)->delete();
            foreach ($rows as $row) {
                CommissionAgentRule::create($row);
            }
            DB::commit();

            $output = ['success' => true, 'msg' => count($rows).' commission rule(s) saved'];
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());

            $output = ['success' => false, 'msg' => __('messages.something_went_wrong')];
        }

        return $output;
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        if (! auth()->user()->can('user.delete')) {
            abort(403, 'Unauthorized action.');
        }

        if (request()->ajax()) {
            try {
                $business_id = request()->session()->get('user.business_id');

                User::where('id', $id)
                    ->where('business_id', $business_id)
                    ->where('is_cmmsn_agnt', 1)
                    ->delete();

                $output = ['success' => true,
                    'msg' => __('lang_v1.commission_agent_deleted_success'),
                ];
            } catch (\Exception $e) {
                \Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());

                $output = ['success' => false,
                    'msg' => __('messages.something_went_wrong'),
                ];
            }

            return $output;
        }
    }
}
