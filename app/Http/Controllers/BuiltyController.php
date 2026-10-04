<?php

namespace App\Http\Controllers;

use App\AccountTransaction;
use App\Business;
use App\BusinessLocation;
use App\Contact;
use App\CustomerGroup;
use App\Product;
use App\PurchaseLine;
use App\TaxRate;
use App\Transaction;
use App\User;
use App\Utils\BusinessUtil;

use App\Utils\ModuleUtil;
use App\Utils\ProductUtil;
use App\Utils\TransactionUtil;

use App\Variation;
use App\TransportCompany;
use App\Builty;
use App\BuiltyItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Yajra\DataTables\Facades\DataTables;

class BuiltyController extends Controller
{
    /**
     * All Utils instance.
     *
     */
    protected $productUtil;
    protected $transactionUtil;
    protected $moduleUtil;

    /**
     * Constructor
     *
     * @param ProductUtils $product
     * @return void
     */
    public function __construct(ProductUtil $productUtil, TransactionUtil $transactionUtil, BusinessUtil $businessUtil, ModuleUtil $moduleUtil)
    {
        $this->productUtil = $productUtil;
        $this->transactionUtil = $transactionUtil;
        $this->businessUtil = $businessUtil;
        $this->moduleUtil = $moduleUtil;

        $this->dummyPaymentLine = ['method' => 'cash', 'amount' => 0, 'note' => '', 'card_transaction_number' => '', 'card_number' => '', 'card_type' => '', 'card_holder_name' => '', 'card_month' => '', 'card_year' => '', 'card_security' => '', 'cheque_number' => '', 'bank_account_number' => '',
        'is_return' => 0, 'transaction_no' => ''];
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        if (!auth()->user()->can('purchase.view') && !auth()->user()->can('purchase.create')) {
            abort(403, 'Unauthorized action.');
        }
        $business_id = request()->session()->get('user.business_id');
        if (request()->ajax()) {

            $builty = Builty::get();

            // if (!empty(request()->location_id)) {

            //     $builty->where('goodstransportcompany_id', request()->location_id);
            // }

            return Datatables::of($builty)
                ->addColumn('action', function ($row) {
                    $html = '<div class="btn-group">
                            <button type="button" class="btn btn-info dropdown-toggle btn-xs"
                                data-toggle="dropdown" aria-expanded="false">' .
                                __("messages.actions") .
                                '<span class="caret"></span><span class="sr-only">Toggle Dropdown
                                </span>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-right" role="menu">';
                    if (auth()->user()->can("purchase.view")) {
                        $html .= '<li><a href="#" data-href="' . action([\App\Http\Controllers\BuiltyController::class,'show'], [$row->id]) . '" class="btn-modal" data-container=".view_modal"><i class="fa fa-eye" aria-hidden="true"></i>' . __("messages.view") . '</a></li>';
                    }

                    if (auth()->user()->can("purchase.update")) {
                        $html .= '<li><a href="' . action([\App\Http\Controllers\BuiltyController::class,'edit'], [$row->id]) . '"><i class="glyphicon glyphicon-edit"></i>' . __("messages.edit") . '</a></li>';
                    }
                    if (auth()->user()->can("purchase.delete")) {
                        $html .= '<li><a href="' . action([\App\Http\Controllers\BuiltyController::class,'destroy'], [$row->id]) . '" class="delete-purchase"><i class="fa fa-trash"></i>' . __("messages.delete") . '</a></li>';
                    }


                    // if (auth()->user()->can("purchase.view") && !empty($row->document)) {
                    //     $document_name = !empty(explode("_", $row->document, 2)[1]) ? explode("_", $row->document, 2)[1] : $row->document ;
                    //     $html .= '<li><a href="' . url('uploads/documents/' . $row->document) .'" download="' . $document_name . '"><i class="fa fa-download" aria-hidden="true"></i>' . __("purchase.download_document") . '</a></li>';
                    //     if (isFileImage($document_name)) {
                    //         $html .= '<li><a href="#" data-href="' . url('uploads/documents/' . $row->document) .'" class="view_uploaded_document"><i class="fa fa-picture-o" aria-hidden="true"></i>' . __("lang_v1.view_document") . '</a></li>';
                    //     }
                    // }






                    $html .=  '</ul></div>';
                    return $html;
                })
                ->editColumn('goodstransportcompany_id', function ($row) {
                    $group=$row->goodstransportcompany_id;
                    $int=(int)$group;
                    $products = TransportCompany::find($int);
                    if(!empty($products)){
                    return  $products->name;
                    }else{
                        return '';
                    }
                })
                ->removeColumn('id','created_at','updated_at','created_by')
                ->rawColumns(['action'])
                ->make(true);

        }
        $TransportCompany = TransportCompany::forDropdown($business_id);

        return view('builty.index')
        ->with(compact('TransportCompany'));

    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        if (!auth()->user()->can('purchase.create')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');

        //Check if subscribed or not
        if (!$this->moduleUtil->isSubscribed($business_id)) {
            return $this->moduleUtil->expiredResponse();
        }

        $currency_details = $this->transactionUtil->purchaseCurrencyDetails($business_id);
        $TransportCompany = TransportCompany::forDropdown($business_id);
        return view('builty.create')
            ->with(compact('TransportCompany','currency_details'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        if (!auth()->user()->can('purchase.create')) {
            abort(403, 'Unauthorized action.');
        }

         try {
            $business_id = $request->session()->get('user.business_id');

            //Check if subscribed or not
            if (!$this->moduleUtil->isSubscribed($business_id)) {
                return $this->moduleUtil->expiredResponse(action('PurchaseController@index'));
            }
            $builty_data =  $request->only([ 'goodstransportcompany_id', 'biulty_number', 'recevied_date', 'biulty_date', 'from_address', 'to_address','sender_name', 'amount']);
            $request->validate([

                'document' => 'file|max:'. (config('constants.document_size_limit') / 1000)
            ]);

            $user_id = $request->session()->get('user.id');
            //upload document
            $builty_data['document'] = $this->transactionUtil->uploadFile($request, 'document', 'documents');
            $builty_data['created_by']=$user_id;
            $builty_data['biulty_date'] = $this->productUtil->uf_date($builty_data['biulty_date'], false);
            $builty_data['recevied_date'] = $this->productUtil->uf_date($builty_data['recevied_date'], true);
            $builty_data['amount']=$this->productUtil->num_uf($builty_data['amount'],false);


            $item = $request->input('item');
            DB::beginTransaction();



            $builty = Builty::create($builty_data);

           foreach ( $item as  $item){
                $items['builty_id']=$builty->id;
                $items['item']=$item['item'];
                $items['item_quantity']=$item['item_quantity'];
                $items['weight']=$item['weight'];
                $items['charges']=$item['charges'];

            $trans= BuiltyItem::create($items);

           }
            DB::commit();

            $output = ['success' => 1,
                            'msg' => __('Builty Add successfully')
                        ];
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());

            $output = ['success' => 0,
                            'msg' => __('messages.something_went_wrong')
                        ];
        }

        return redirect('builty')->with('status', $output);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        if (!auth()->user()->can('purchase.view')) {
            abort(403, 'Unauthorized action.');
        }


        $builty = Builty::where('id', $id)
                                ->with(
                                   'builtyitems',
                                   'transport'
                                )
                                ->firstOrFail();


        return view('builty.show')
                ->with(compact('builty'));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        if (!auth()->user()->can('purchase.update')) {
            abort(403, 'Unauthorized action.');
        }
        $business_id = request()->session()->get('user.business_id');

        $builty = Builty::where('id', $id)
        ->with(
           'builtyitems',
           'transport'
        )
        ->firstOrFail();

        $currency_details = $this->transactionUtil->purchaseCurrencyDetails($business_id);
        $TransportCompany = TransportCompany::forDropdown($business_id);

        return view('builty.edit')
            ->with(compact(
                'TransportCompany','currency_details',
                'builty'
            ));
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
        if (!auth()->user()->can('purchase.update')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $builty = Builty::findOrFail($id);

            //Validate document size
            $request->validate([
                'document' => 'file|max:'. (config('constants.document_size_limit') / 1000)
            ]);

            $builty_data =  $request->only([ 'goodstransportcompany_id', 'biulty_number', 'recevied_date', 'biulty_date', 'from_address', 'to_address','sender_name', 'amount']);
            $user_id = $request->session()->get('user.id');
            $builty_data['created_by']=$user_id;
            $builty_data['biulty_date'] = $this->productUtil->uf_date($builty_data['biulty_date'], false);
            $builty_data['recevied_date'] = $this->productUtil->uf_date($builty_data['recevied_date'], true);
            $builty_data['amount']=$this->productUtil->num_uf($builty_data['amount'], false);
            $item = $request->input('item');

            //upload document
            $document_name = $this->transactionUtil->uploadFile($request, 'document', 'documents');
            if (!empty($document_name)) {
                $builty_data['document'] = $document_name;
            }

            DB::beginTransaction();

            //update transaction
            $builty->update($builty_data);

            $builty_items = BuiltyItem::where('builty_id', $id)->first();
            $builty_items->delete();
            foreach ( $item as  $item){
                $items['builty_id']=$builty->id;
                $items['item']=$item['item'];
                $items['item_quantity']=$item['item_quantity'];
                $items['weight']=$item['weight'];
                $items['charges']=$item['charges'];

            $trans= BuiltyItem::create($items);
            }

            DB::commit();

            $output = ['success' => 1,
                            'msg' => __('Builty updated successfully')
                        ];
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());

            $output = ['success' => 0,
                            'msg' => $e->getMessage()
                        ];
            return back()->with('status', $output);
        }

        return redirect('builty')->with('status', $output);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        if (!auth()->user()->can('purchase.delete')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            if (request()->ajax()) {

                DB::beginTransaction();
                $builty = Builty::findOrFail($id);

                $builty->delete();


                $builty_items = BuiltyItem::where('builty_id', $id);
                $builty_items->delete();


                DB::commit();

                $output = ['success' => true,
                            'msg' => __('Builty deleted successfully')
                        ];
            }
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());

            $output = ['success' => false,
                            'msg' => $e->getMessage()
                        ];
        }

        return $output;
    }

    /**
     * Retrieves supliers list.
     *
     * @return \Illuminate\Http\Response
     */
    public function getSuppliers()
    {
        if (request()->ajax()) {
            $term = request()->q;
            if (empty($term)) {
                return json_encode([]);
            }

            $business_id = request()->session()->get('user.business_id');
            $user_id = request()->session()->get('user.id');

            $query = Contact::where('business_id', $business_id);

            $selected_contacts = User::isSelectedContacts($user_id);
            if ($selected_contacts) {
                $query->join('user_contact_access AS uca', 'contacts.id', 'uca.contact_id')
                ->where('uca.user_id', $user_id);
            }
            $suppliers = $query->where(function ($query) use ($term) {
                $query->where('name', 'like', '%' . $term .'%')
                                ->orWhere('supplier_business_name', 'like', '%' . $term .'%')
                                ->orWhere('contacts.contact_id', 'like', '%' . $term .'%');
            })
                        ->select('contacts.id', 'name as text', 'supplier_business_name as business_name', 'contact_id')
                        ->onlySuppliers()
                        ->get();
            return json_encode($suppliers);
        }
    }

    /**
     * Retrieves products list.
     *
     * @return \Illuminate\Http\Response
     */
    public function getProducts()
    {
        if (request()->ajax()) {
            $term = request()->term;

            $check_enable_stock = true;
            if (isset(request()->check_enable_stock)) {
                $check_enable_stock = filter_var(request()->check_enable_stock, FILTER_VALIDATE_BOOLEAN);
            }

            if (empty($term)) {
                return json_encode([]);
            }

            $business_id = request()->session()->get('user.business_id');
            $q = Product::leftJoin(
                'variations',
                'products.id',
                '=',
                'variations.product_id'
            )
                ->where(function ($query) use ($term) {
                    $query->where('products.name', 'like', '%' . $term .'%');
                    $query->orWhere('sku', 'like', '%' . $term .'%');
                    $query->orWhere('sub_sku', 'like', '%' . $term .'%');
                    $query->orWhere('product_custom_field1', 'like', '%' . $term .'%');

                })
                ->active()
                ->where('business_id', $business_id)
                ->whereNull('variations.deleted_at')
                ->select(
                    'products.id as product_id',
                    'products.name',
                    'products.type',
                    // 'products.sku as sku',
                    'variations.id as variation_id',
                    'variations.name as variation',
                    'variations.sub_sku as sub_sku'
                )
                ->groupBy('variation_id');

            if ($check_enable_stock) {
                $q->where('enable_stock', 1);
            }
            $products = $q->get();

            $products_array = [];
            foreach ($products as $product) {
                $products_array[$product->product_id]['name'] = $product->name;
                $products_array[$product->product_id]['sku'] = $product->sub_sku;
                $products_array[$product->product_id]['type'] = $product->type;
                $products_array[$product->product_id]['variations'][]
                = [
                        'variation_id' => $product->variation_id,
                        'variation_name' => $product->variation,
                        'sub_sku' => $product->sub_sku
                        ];
            }

            $result = [];
            $i = 1;
            $no_of_records = $products->count();
            if (!empty($products_array)) {
                foreach ($products_array as $key => $value) {
                    if ($no_of_records > 1 && $value['type'] != 'single') {
                        $result[] = [ 'id' => $i,
                                    'text' => $value['name'] . ' - ' . $value['sku'],
                                    'variation_id' => 0,
                                    'product_id' => $key
                                ];
                    }
                    $name = $value['name'];
                    foreach ($value['variations'] as $variation) {
                        $text = $name;
                        if ($value['type'] == 'variable') {
                            $text = $text . ' (' . $variation['variation_name'] . ')';
                        }
                        $i++;
                        $result[] = [ 'id' => $i,
                                            'text' => $text . ' - ' . $variation['sub_sku'],
                                            'product_id' => $key ,
                                            'variation_id' => $variation['variation_id'],
                                        ];
                    }
                    $i++;
                }
            }

            return json_encode($result);
        }
    }

    /**
     * Retrieves products list.
     *
     * @return \Illuminate\Http\Response
     */
    public function getPurchaseEntryRow(Request $request)
    {
        if (request()->ajax()) {
            $product_id = $request->input('product_id');
            $variation_id = $request->input('variation_id');
            $business_id = request()->session()->get('user.business_id');

            $hide_tax = 'hide';
            if ($request->session()->get('business.enable_inline_tax') == 1) {
                $hide_tax = '';
            }

            $currency_details = $this->transactionUtil->purchaseCurrencyDetails($business_id);

            if (!empty($product_id)) {
                $row_count = $request->input('row_count');
                $product = Product::where('id', $product_id)
                                    ->with(['unit'])
                                    ->first();

                $sub_units = $this->productUtil->getSubUnits($business_id, $product->unit->id);

                $query = Variation::where('product_id', $product_id)
                                        ->with(['product_variation']);
                if ($variation_id !== '0') {
                    $query->where('id', $variation_id);
                }

                $variations =  $query->get();

                $taxes = TaxRate::where('business_id', $business_id)
                            ->get();

                return view('purchase.partials.purchase_entry_row')
                    ->with(compact(
                        'product',
                        'variations',
                        'row_count',
                        'variation_id',
                        'taxes',
                        'currency_details',
                        'hide_tax',
                        'sub_units'
                    ));
            }
        }
    }

    /**
     * Checks if ref_number and supplier combination already exists.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function checkRefNumber(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $contact_id = $request->input('contact_id');
        $ref_no = $request->input('ref_no');
        $purchase_id = $request->input('purchase_id');

        $count = 0;
        if (!empty($contact_id) && !empty($ref_no)) {
            //check in transactions table
            $query = Transaction::where('business_id', $business_id)
                            ->where('ref_no', $ref_no)
                            ->where('contact_id', $contact_id);
            if (!empty($purchase_id)) {
                $query->where('id', '!=', $purchase_id);
            }
            $count = $query->count();
        }
        if ($count == 0) {
            echo "true";
            exit;
        } else {
            echo "false";
            exit;
        }
    }

    /**
     * Checks if ref_number and supplier combination already exists.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function printInvoice($id)
    {
        try {
            $business_id = request()->session()->get('user.business_id');
            $taxes = TaxRate::where('business_id', $business_id)
                                ->pluck('name', 'id');
            $purchase = Transaction::where('business_id', $business_id)
                                    ->where('id', $id)
                                    ->with(
                                        'contact',
                                        'purchase_lines',
                                        'purchase_lines.product',
                                        'purchase_lines.variations',
                                        'purchase_lines.variations.product_variation',
                                        'location',
                                        'payment_lines'
                                    )
                                    ->first();
            $payment_methods = $this->productUtil->payment_types();

            $output = ['success' => 1, 'receipt' => []];
            $output['receipt']['html_content'] = view('purchase.partials.show_details', compact('taxes', 'purchase', 'payment_methods'))->render();
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());

            $output = ['success' => 0,
                            'msg' => __('messages.something_went_wrong')
                        ];
        }

        return $output;
    }
}
