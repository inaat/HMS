<?php

namespace App\Http\Controllers;

use App\AccountType;
use Illuminate\Http\Request;

class AccountTypeController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        if (! auth()->user()->can('account.access')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = session()->get('user.business_id');

        $account_types = AccountType::where('business_id', $business_id)
                                     ->whereNull('parent_account_type_id')
                                     ->get();

        return view('account_types.create')
                ->with(compact('account_types'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        if (! auth()->user()->can('account.access')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $input = $request->only(['name', 'parent_account_type_id']);
            $input['business_id'] = $request->session()->get('user.business_id');
            //Chart of accounts: every account sits under a main group and gets its type and normal side
            if ($this->hasChart()) {
                $parent = AccountType::where('business_id', $input['business_id'])->whereNull('parent_account_type_id')
                    ->find($request->input('parent_account_type_id'));
                if (empty($parent)) {
                    return redirect()->back()->with('status', ['success' => false, 'msg' => 'Choose the group (Assets, Liabilities, Equity, Income or Expenses)']);
                }
                $input = LedgerController::chartFields($request, $parent) + ['business_id' => $input['business_id']];
            }

            AccountType::create($input);
            $output = ['success' => true,
                'msg' => __('lang_v1.added_success'),
            ];
        } catch (\Exception $e) {
            \Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());

            $output = ['success' => false,
                'msg' => __('messages.something_went_wrong'),
            ];
        }

        return redirect()->back()->with('status', $output);
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\AccountType  $accountType
     * @return \Illuminate\Http\Response
     */
    public function show(AccountType $accountType)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\AccountType  $accountType
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        if (! auth()->user()->can('account.access')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = session()->get('user.business_id');

        $account_type = AccountType::where('business_id', $business_id)
                                     ->findOrFail($id);

        $account_types = AccountType::where('business_id', $business_id)
                                     ->whereNull('parent_account_type_id')
                                     ->get();

        return view('account_types.edit')
                ->with(compact('account_types', 'account_type'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\AccountType  $accountType
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        if (! auth()->user()->can('account.access')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $input = $request->only(['name', 'parent_account_type_id']);
            $business_id = $request->session()->get('user.business_id');

            $account_type = AccountType::where('business_id', $business_id)
                                     ->findOrFail($id);

            if ($this->hasChart()) {
                $fixed = empty($account_type->parent_account_type_id) || ! empty($account_type->system_key) || ! empty($account_type->expense_category_id);
                $parent = $fixed ? null : AccountType::where('business_id', $business_id)->whereNull('parent_account_type_id')
                    ->find($request->input('parent_account_type_id'));
                //groups and accounts the books post to: name / code only
                $account_type->update($parent ? LedgerController::chartFields($request, $parent)
                    : ['name' => trim($request->input('name')), 'code' => trim((string) $request->input('code')) ?: null]);

                return redirect()->back()->with('status', ['success' => true, 'msg' => __('lang_v1.updated_success')]);
            }

            //Account type is changed to subtype update all its sub type's parent type
            if (empty($account_type->parent_account_type_id) && ! empty($input['parent_account_type_id'])) {
                AccountType::where('business_id', $business_id)
                        ->where('parent_account_type_id', $account_type->id)
                        ->update(['parent_account_type_id' => $input['parent_account_type_id']]);
            }

            $account_type->update($input);

            $output = ['success' => true,
                'msg' => __('lang_v1.updated_success'),
            ];
        } catch (\Exception $e) {
            \Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());

            $output = ['success' => false,
                'msg' => __('messages.something_went_wrong'),
            ];
        }

        return redirect()->back()->with('status', $output);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\AccountType  $accountType
     * @return \Illuminate\Http\Response
     */
    private function hasChart(): bool
    {
        return \Schema::hasColumn('account_types', 'system_key');
    }

    public function destroy($id)
    {
        if (! auth()->user()->can('account.access')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = session()->get('user.business_id');

        //Chart of accounts: an account the books post to, or used by payment accounts / sub accounts, stays
        $type = AccountType::where('business_id', $business_id)->where('id', $id)->first();
        $in_use = ! empty($type) && (
            ! empty($type->system_key) || ! empty($type->expense_category_id)
            || AccountType::where('parent_account_type_id', $id)->exists()
            || \App\Account::where('account_type_id', $id)->exists()
            || (\Schema::hasTable('ledger_lines') && \DB::table('ledger_lines')->where('account_type_id', $id)->exists())
        );
        if ($in_use) {
            return redirect()->back()->with('status', ['success' => false,
                'msg' => 'This account is used by the books or by payment accounts and cannot be deleted (you can rename it)']);
        }

        AccountType::where('business_id', $business_id)
                                     ->where('id', $id)
                                     ->delete();

        //Upadete parent account if set
        AccountType::where('business_id', $business_id)
                 ->where('parent_account_type_id', $id)
                 ->update(['parent_account_type_id' => null]);

        $output = ['success' => true,
            'msg' => __('lang_v1.deleted_success'),
        ];

        return redirect()->back()->with('status', $output);
    }
}
