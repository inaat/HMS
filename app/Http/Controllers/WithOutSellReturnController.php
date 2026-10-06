<?php

namespace App\Http\Controllers;

use App\BusinessLocation;
use App\Transaction;
use App\Contact;
use App\ReturnSellLine;
use App\User;
use App\Utils\BusinessUtil;
use App\Utils\ContactUtil;

use App\Utils\ModuleUtil;
use App\Utils\ProductUtil;
use App\Utils\TransactionUtil;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Yajra\DataTables\Facades\DataTables;
use App\TransactionSellLine;
use App\Events\TransactionPaymentDeleted;
use Spatie\Activitylog\Models\Activity;

class WithOutSellReturnController extends Controller
{
    /**
     * All Utils instance.
     *
     */
    protected $productUtil;
    protected $transactionUtil;
    protected $contactUtil;
    protected $businessUtil;
    protected $moduleUtil;

    /**
     * Constructor
     *
     * @param ProductUtils $product
     * @return void
     */
    public function __construct(ProductUtil $productUtil, TransactionUtil $transactionUtil, ContactUtil $contactUtil, BusinessUtil $businessUtil, ModuleUtil $moduleUtil)
    {
        $this->productUtil = $productUtil;
        $this->transactionUtil = $transactionUtil;
        $this->contactUtil = $contactUtil;
        $this->businessUtil = $businessUtil;
        $this->moduleUtil = $moduleUtil;
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        if (!auth()->user()->can('access_sell_return') && !auth()->user()->can('access_own_sell_return')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');
        if (request()->ajax()) {
            $sells = Transaction::leftJoin('contacts', 'transactions.contact_id', '=', 'contacts.id')

                ->join(
                    'business_locations AS bl',
                    'transactions.location_id',
                    '=',
                    'bl.id'
                )
                // ->join(
                //     'transactions as T1',
                //     'transactions.return_parent_id',
                //     '=',
                //     'T1.id'
                // )
                ->leftJoin(
                    'transaction_payments AS TP',
                    'transactions.id',
                    '=',
                    'TP.transaction_id'
                )
                ->where('transactions.business_id', $business_id)
                ->where('transactions.type', 'sell_return')
                ->where('transactions.status', 'final')
                ->whereNull('transactions.return_parent_id')
                ->select(
                    'transactions.id',
                    'transactions.transaction_date',
                    'transactions.invoice_no',
                    'contacts.name',
                    'transactions.final_total',
                    'transactions.payment_status',
                    'bl.name as business_location',
                    DB::raw('SUM(TP.amount) as amount_paid')
                );

            $permitted_locations = auth()->user()->permitted_locations();
            if ($permitted_locations != 'all') {
                $sells->whereIn('transactions.location_id', $permitted_locations);
            }

            if (!auth()->user()->can('access_sell_return') && auth()->user()->can('access_own_sell_return')) {
                $sells->where('transactions.created_by', request()->session()->get('user.id'));
            }

            //Add condition for created_by,used in sales representative sales report
            if (request()->has('created_by')) {
                $created_by = request()->get('created_by');
                if (!empty($created_by)) {
                    $sells->where('transactions.created_by', $created_by);
                }
            }

            //Add condition for location,used in sales representative expense report
            if (request()->has('location_id')) {
                $location_id = request()->get('location_id');
                if (!empty($location_id)) {
                    $sells->where('transactions.location_id', $location_id);
                }
            }

            if (!empty(request()->customer_id)) {
                $customer_id = request()->customer_id;
                $sells->where('contacts.id', $customer_id);
            }
            if (!empty(request()->start_date) && !empty(request()->end_date)) {
                $start = request()->start_date;
                $end = request()->end_date;
                $sells->whereDate('transactions.transaction_date', '>=', $start)
                    ->whereDate('transactions.transaction_date', '<=', $end);
            }

            $sells->groupBy('transactions.id');

            return Datatables::of($sells)
            ->addColumn(
                'action',
                function ($row) {
                    $id = $row->id; // Assuming $row contains the data object
                    $payment_status = $row->payment_status; // Assuming $row contains the payment_status field
            
                    return '<div class="btn-group">
                                <button type="button" class="btn btn-info dropdown-toggle btn-xs" 
                                    data-toggle="dropdown" aria-expanded="false">' .
                                    __("messages.actions") .
                                '<span class="caret"></span><span class="sr-only">Toggle Dropdown</span>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-right" role="menu">
                                    <li>
                                        <a href="' . action('App\Http\Controllers\WithOutSellReturnController@destroy', [$id]) . '" 
                                            class="delete_sell_return" 
                                            data-method="delete"
                                            data-confirm="' . __("messages.confirm_delete") . '">
                                            <i class="fa fa-trash" aria-hidden="true"></i> ' . __("messages.delete") . '
                                        </a>
                                    </li>
                                    <li>
                                        <a href="#" 
                                            class="print-invoice" 
                                            data-href="' . action('App\Http\Controllers\WithOutSellReturnController@printInvoice', [$id]) . '">
                                            <i class="fa fa-print" aria-hidden="true"></i> ' . __("messages.print") . '
                                        </a>
                                    </li>
                                    ' . ($payment_status != "paid" ? 
                                    '<li>
                                        <a href="' . action('App\Http\Controllers\TransactionPaymentController@addPayment', [$id]) . '" 
                                            class="add_payment_modal">
                                            <i class="fas fa-money-bill-alt"></i> ' . __("purchase.add_payment") . '
                                        </a>
                                    </li>' : '') . '
                                    <li>
                                        <a href="' . action('App\Http\Controllers\TransactionPaymentController@show', [$id]) . '" 
                                            class="view_payment_modal">
                                            <i class="fas fa-money-bill-alt"></i> ' . __("purchase.view_payments") . '
                                        </a>
                                    </li>
                                </ul>
                            </div>';
                }
            )
            
            
                ->editColumn(
                    'final_total',
                    '<span class="display_currency final_total" data-currency_symbol="true" data-orig-value="{{$final_total}}">{{$final_total}}</span>'
                )

                ->editColumn('transaction_date', '{{@format_datetime($transaction_date)}}')
                ->editColumn(
                    'payment_status',
                    '<a href="{{ action([\App\Http\Controllers\TransactionPaymentController::class, \'show\'], [$id])}}" class="view_payment_modal payment-status payment-status-label" data-orig-value="{{$payment_status}}" data-status-name="{{__(\'lang_v1.\' . $payment_status)}}"><span class="label @payment_status($payment_status)">{{__(\'lang_v1.\' . $payment_status)}}</span></a>'
                )
                ->addColumn('payment_due', function ($row) {
                    $due = $row->final_total - $row->amount_paid;
                    return '<span class="display_currency payment_due" data-currency_symbol="true" data-orig-value="' . $due . '">' . $due . '</sapn>';
                })

                ->removeColumn('id')

                ->rawColumns(['final_total', 'action', 'payment_status', 'payment_due'])
                ->make(true);
        }
        $business_locations = BusinessLocation::forDropdown($business_id, false);
        $customers = Contact::customersDropdown($business_id, false);

        $sales_representative = User::forDropdown($business_id, false, false, true);

        return view('without-invoice-sell_return.index')->with(compact('business_locations', 'customers', 'sales_representative'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
  

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function add($id)
    {
        if (!auth()->user()->can('access_sell_return') && !auth()->user()->can('access_own_sell_return')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');
        //Check if subscribed or not
        if (!$this->moduleUtil->isSubscribed($business_id)) {
            return $this->moduleUtil->expiredResponse();
        }

        $sell = Transaction::where('business_id', $business_id)
            ->with(['sell_lines', 'location', 'return_parent', 'contact', 'tax', 'sell_lines.sub_unit', 'sell_lines.product', 'sell_lines.product.unit'])
            ->find($id);

        foreach ($sell->sell_lines as $key => $value) {
            if (!empty($value->sub_unit_id)) {
                $formated_sell_line = $this->transactionUtil->recalculateSellLineTotals($business_id, $value);
                $sell->sell_lines[$key] = $formated_sell_line;
            }

            $sell->sell_lines[$key]->formatted_qty = $this->transactionUtil->num_f($value->quantity, false, null, true);
        }

        return view('without-invoice-sell_return.add')
            ->with(compact('sell'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        if (!auth()->user()->can('access_sell_return') && !auth()->user()->can('access_own_sell_return')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $input = $request->except('_token');

            if (!empty($input['products'])) {
                $business_id = $request->session()->get('user.business_id');

               

                $user_id = $request->session()->get('user.id');

                DB::beginTransaction();

                $sell_return = $this->transactionUtil->addSellReturn($input, $business_id, $user_id);

                $receipt = $this->receiptContent($business_id, $sell_return->location_id, $sell_return->id);

                DB::commit();

                $output = [
                    'success' => 1,
                    'msg' => __('lang_v1.success'),
                    'receipt' => $receipt
                ];
            }
        } catch (\Exception $e) {
            DB::rollBack();

            if (get_class($e) == \App\Exceptions\PurchaseSellMismatch::class) {
                $msg = $e->getMessage();
            } else {
                \Log::emergency("File:" . $e->getFile() . "Line:" . $e->getLine() . "Message:" . $e->getMessage());
                $msg = __('messages.something_went_wrong');
            }

            $output = [
                'success' => 0,
                'msg' => $msg
            ];
        }

        return $output;
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        if (!auth()->user()->can('access_sell_return') && !auth()->user()->can('access_own_sell_return')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');
        $query = Transaction::where('business_id', $business_id)
            ->where('id', $id)
            ->with(
                'contact',
                'return_parent',
                'tax',
                'sell_lines',
                'sell_lines.product',
                'sell_lines.variations',
                'sell_lines.sub_unit',
                'sell_lines.product',
                'sell_lines.product.unit',
                'location'
            );

        if (!auth()->user()->can('access_sell_return') && auth()->user()->can('access_own_sell_return')) {
            $sells->where('created_by', request()->session()->get('user.id'));
        }
        $sell = $query->first();

        foreach ($sell->sell_lines as $key => $value) {
            if (!empty($value->sub_unit_id)) {
                $formated_sell_line = $this->transactionUtil->recalculateSellLineTotals($business_id, $value);
                $sell->sell_lines[$key] = $formated_sell_line;
            }
        }

        $sell_taxes = [];
        if (!empty($sell->return_parent->tax)) {
            if ($sell->return_parent->tax->is_tax_group) {
                $sell_taxes = $this->transactionUtil->sumGroupTaxDetails($this->transactionUtil->groupTaxDetails($sell->return_parent->tax, $sell->return_parent->tax_amount));
            } else {
                $sell_taxes[$sell->return_parent->tax->name] = $sell->return_parent->tax_amount;
            }
        }

        $total_discount = 0;
        if ($sell->return_parent->discount_type == 'fixed') {
            $total_discount = $sell->return_parent->discount_amount;
        } elseif ($sell->return_parent->discount_type == 'percentage') {
            $discount_percent = $sell->return_parent->discount_amount;
            if ($discount_percent == 100) {
                $total_discount = $sell->return_parent->total_before_tax;
            } else {
                $total_after_discount = $sell->return_parent->final_total - $sell->return_parent->tax_amount;
                $total_before_discount = $total_after_discount * 100 / (100 - $discount_percent);
                $total_discount = $total_before_discount - $total_after_discount;
            }
        }

        $activities = Activity::forSubject($sell->return_parent)
            ->with(['causer', 'subject'])
            ->latest()
            ->get();

        return view('without-invoice-sell_return.show')
            ->with(compact('sell', 'sell_taxes', 'total_discount', 'activities'));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        if (!auth()->user()->can('access_sell_return') && !auth()->user()->can('access_own_sell_return')) {
            abort(403, 'Unauthorized action.');
        }

        if (request()->ajax()) {
               try {
            $business_id = request()->session()->get('user.business_id');
            //Begin transaction
            DB::beginTransaction();
            $query = Transaction::where('id', $id)
                ->where('business_id', $business_id)
                ->where('type', 'sell_return')
                ->with(['payment_lines']);
            $sell_return = $query->first();
            if (!empty($sell_return)) {
                $transaction_payments = $sell_return->payment_lines;

            $sell_lines_return = ReturnSellLine::
                where('return_transaction_id', $sell_return->id)->get();
            // dd( $sell_lines_return);
            foreach ($sell_lines_return as $line) {
                $sell_line = TransactionSellLine::where(
                    'id',
                    $line->transaction_sell_id
                )
                    ->first();
                if ($sell_line->quantity_returned > 0) {
                    $quantity = 0;
                    $quantity_before = $this->transactionUtil->num_f($line->quantity);

                    $sell_line->quantity_returned = $sell_line->quantity_returned-$line->quantity;
                    $sell_line->save();
                    //update quantity sold in corresponding purchase lines
                    $this->transactionUtil->updateQuantitySoldFromSellLine($sell_line, 0, $quantity_before);

                    // Update quantity in variation location details
                    $this->productUtil->updateProductQuantity($sell_return->location_id, $sell_line->product_id, $sell_line->variation_id, 0, $quantity_before);
                }
            }
                //Invoices this return settled become due again
                $this->transactionUtil->removeSellReturnSettlement($sell_return->id);
                $transaction_payments = $transaction_payments->where('method', '!=', \App\Utils\TransactionUtil::RETURN_ADJUSTMENT_METHOD);

                  $sell_return->delete();
                foreach ($transaction_payments as $payment) {
                    event(new TransactionPaymentDeleted($payment));
                }
            }
     

            DB::commit();
            $output = [
                'success' => 1,
                'msg' => __('lang_v1.success'),
            ];
                    } catch (\Exception $e) {
                        DB::rollBack();

                        if (get_class($e) == \App\Exceptions\PurchaseSellMismatch::class) {
                            $msg = $e->getMessage();
                        } else {
                            \Log::emergency("File:" . $e->getFile() . "Line:" . $e->getLine() . "Message:" . $e->getMessage());
                            $msg = __('messages.something_went_wrong');
                        }

                        $output = [
                            'success' => 0,
                            'msg' => $msg
                        ];
                    }

                    return $output;
        }
    }

    /**
     * Returns the content for the receipt
     *
     * @param  int  $business_id
     * @param  int  $location_id
     * @param  int  $transaction_id
     * @param string $printer_type = null
     *
     * @return array
     */
    private function receiptContent(
        $business_id,
        $location_id,
        $transaction_id,
        $printer_type = null
    ) {
        $output = [
            'is_enabled' => false,
            'print_type' => 'browser',
            'html_content' => null,
            'printer_config' => [],
            'data' => []
        ];

        $business_details = $this->businessUtil->getDetails($business_id);
        $location_details = BusinessLocation::find($location_id);

        //Check if printing of invoice is enabled or not.
        if ($location_details->print_receipt_on_invoice == 1) {
            //If enabled, get print type.
            $output['is_enabled'] = true;

            $invoice_layout = $this->businessUtil->invoiceLayout($business_id, $location_id, $location_details->invoice_layout_id);

            //Check if printer setting is provided.
            $receipt_printer_type = is_null($printer_type) ? $location_details->receipt_printer_type : $printer_type;

            $receipt_details = Transaction::where('id', $transaction_id)
            ->where('business_id', $business_id)
            ->where('type', 'sell_return')
            ->with(['payment_lines','contact'])->first();//$this->transactionUtil->getReceiptDetails($transaction_id, $location_id, $invoice_layout, $business_details, $location_details, $receipt_printer_type);

            $sell_lines_return = ReturnSellLine::
                where('return_transaction_id', $receipt_details->id)
                ->with(['product'])->get();
                $paid_amount = $this->transactionUtil->getTotalPaid($receipt_details->id);
                $due = $receipt_details->final_total - $paid_amount;
            //If print type browser - return the content, printer - return printer config data, and invoice format config
            $output['print_title'] = $receipt_details->invoice_no;
            if ($receipt_printer_type == 'printer') {
                $output['print_type'] = 'printer';
                $output['printer_config'] = $this->businessUtil->printerConfig($business_id, $location_details->printer_id);
                $output['data'] = $receipt_details;

            } else {
                $output['html_content'] = view('without-invoice-sell_return.receipt', compact('receipt_details','sell_lines_return','paid_amount','due'))->render();
            }
        }

        return $output;
    }

    /**
     * Prints invoice for sell
     *
     * @param  \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function printInvoice(Request $request, $transaction_id)
    {
        if (request()->ajax()) {
            try {
                $output = [
                    'success' => 0,
                    'msg' => trans("messages.something_went_wrong")
                ];

                $business_id = $request->session()->get('user.business_id');

                $transaction = Transaction::where('business_id', $business_id)
                    ->where('id', $transaction_id)
                    ->first();

                if (empty($transaction)) {
                    return $output;
                }

                $receipt = $this->receiptContent($business_id, $transaction->location_id, $transaction_id, 'browser');

                if (!empty($receipt)) {
                    $output = ['success' => 1, 'receipt' => $receipt];
                }
            } catch (\Exception $e) {
                $output = [
                    'success' => 0,
                    'msg' => trans("messages.something_went_wrong")
                ];
            }

            return $output;
        }
    }

    public function AddReturn()
    {
        $business_id = request()->session()->get('user.business_id');
        $business_locations = BusinessLocation::forDropdown($business_id, false);

        return view('new_sell_return.index')
            ->with(compact('business_locations'));

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
            $location_id = request()->location_id;
            $contact_id = request()->contact_id;


            if (empty($term)) {
                return json_encode([]);
            }



            $query = TransactionSellLine::join('transactions as t', 't.id', '=', 'transaction_sell_lines.transaction_id')
                ->join('products as p', 'p.id', '=', 'transaction_sell_lines.product_id')
                ->join('variations as v', 'v.id', '=', 'transaction_sell_lines.variation_id')
                ->join('product_variations as pv', 'v.product_variation_id', '=', 'pv.id')
                ->whereIn('t.type', ['sell'])
                ->where('t.location_id', $location_id)
                ->where('t.contact_id', $contact_id)
                ->where(function ($query) use ($term) {
                    $query->where('p.name', 'like', '%' . $term . '%');
                    $query->orWhere('p.sku', 'like', '%' . $term . '%');
                    $query->orWhere('v.sub_sku', 'like', '%' . $term . '%');
                })
                ->select(
                    'p.name as product_name',
                    'p.id as product_id',
                    'v.name as variation_name',
                    'pv.name as product_variation_name',
                    'v.sub_sku',
                    'variation_id',
                    'unit_price_inc_tax',
                    DB::raw('CONCAT(p.name , " (", v.sub_sku, ")") as text'),
                    DB::raw('SUM(quantity) as sold_quantity'),
                    DB::raw('SUM(quantity_returned) as total_quantity_returned')

                )->groupBy('transaction_sell_lines.variation_id');



            return json_encode($query->get());
        }
    }


    /**
     * Retrieves products list.
     *
     * @return \Illuminate\Http\Response
     */
    public function getReturnEntryRow(Request $request)
    {
        if (request()->ajax()) {
            $product_id = $request->input('product_id');
            $variation_id = $request->input('variation_id');
            $business_id = request()->session()->get('user.business_id');
            $location_id = $request->input('location_id');
            $contact_id = $request->input('contact_id');
            $row_count = $request->input('row_count');
            //  dd($request->input());
            $product = TransactionSellLine::join('transactions as t', 't.id', '=', 'transaction_sell_lines.transaction_id')
                ->join('products as p', 'p.id', '=', 'transaction_sell_lines.product_id')
                ->join('variations as v', 'v.id', '=', 'transaction_sell_lines.variation_id')
                ->join('product_variations as pv', 'v.product_variation_id', '=', 'pv.id')
                ->whereIn('t.type', ['sell'])
                ->where('t.location_id', $location_id)
                ->where('t.contact_id', $contact_id)
                ->where('p.id', $product_id)
                ->where('v.id', $variation_id)
                
                ->select(
                    'p.name as product_name',
                    'p.id as product_id',
                    'v.name as variation_name',
                    'pv.name as product_variation_name',
                    'v.sub_sku',
                    'variation_id',
                    'unit_price_inc_tax',
                    DB::raw('CONCAT(p.name , " (", v.sub_sku, ")") as text'),
                    DB::raw('SUM(quantity) as sold_quantity'),
                    DB::raw('SUM(quantity_returned) as total_quantity_returned')

                )->groupBy('transaction_sell_lines.variation_id')->first();
            //dd($product);

            return view('new_sell_return.return_entry_row')
                ->with(
                    compact(
                        'row_count',
                        'variation_id',
                        'product'

                    )
                );
        }
    }


    public function SellReturnSave(Request $request)
    {
        // $yourArray = [
        //     [
        //         "id" => 42558,
        //         "quantity" => 100,
        //         "rquantity" => 0,
        //     ],
        //     [
        //         "id" => 37863,
        //         "quantity" => 50,
        //         "rquantity" => 0,

               
        //     ],
        //     [
        //         "id" => 37863,
        //         "quantity" => 144,
        //         "rquantity" => 0,

               
        //     ],
        //     // ... add more items as needed
        // ];
        
        // $globalVariable = 194;

        // // foreach ($yourArray as &$item) {
        // //     if($globalVariable>0){
        // //     $item["quantity"] = max(0, $item["quantity"] - $globalVariable);
        // //     $globalVariable = max(0, $globalVariable - $item["quantity"]);
        // //     }
        // // }
        // foreach ($yourArray as &$item) {
        //     if ($globalVariable > 0) {
        //         $quantityToSubtract = min($globalVariable, $item["quantity"]);
        //         $item["quantity"] -= $quantityToSubtract;
        //         $item["rquantity"] = $quantityToSubtract;
        //         $globalVariable -= $quantityToSubtract;
        //     }
        // }
        // // Output the updated array
        // dd($yourArray,$globalVariable );
        $location_id = $request->input('location_id');
        $contact_id = $request->input('contact_id');
        $input = $request->input();
        $business_id = request()->session()->get('user.business_id');
        $user_id = $request->session()->get('user.id');
       try {
            //Begin transaction
            DB::beginTransaction();
            $products = $request->input('purchases');
            if (!empty($products)) {
                $sell_return_data = [
                    'invoice_no' => null,
                    'total_before_tax' => $this->transactionUtil->num_uf($input['final_total']),
                    'final_total' => $this->transactionUtil->num_uf($input['final_total'])
                ];

                if (!empty($input['transaction_date'])) {
                    $sell_return_data['transaction_date'] = $this->transactionUtil->uf_date($input['transaction_date'], true);
                }

                //Generate reference number
                if (empty($sell_return_data['invoice_no']) && empty($sell_return)) {
                    //Update reference count
                    $ref_count = $this->transactionUtil->setAndGetReferenceCount('sell_return', $business_id);
                    $sell_return_data['invoice_no'] = $this->transactionUtil->generateReferenceNumber('sell_return', $ref_count, $business_id);
                }
                $sell_return_data['transaction_date'] = $sell_return_data['transaction_date'] ?? \Carbon::now();
                $sell_return_data['business_id'] = $business_id;
                $sell_return_data['location_id'] = $location_id;
                $sell_return_data['contact_id'] = $contact_id;
                $sell_return_data['type'] = 'sell_return';
                $sell_return_data['status'] = 'final';
                $sell_return_data['created_by'] = $user_id;
                $sell_return = Transaction::create($sell_return_data);
                //Update payment status
                $this->transactionUtil->updatePaymentStatus($sell_return->id, $sell_return->final_total);
                $data = [];
                $return_quantity=0;
                foreach ($products as $product) {
                    $return_quantity = $product['return_quantity'];

                    $transaction_sell = TransactionSellLine::join('transactions as t', 't.id', '=', 'transaction_sell_lines.transaction_id')
                        ->whereIn('t.type', ['sell'])
                        ->where('t.location_id', $location_id)
                        ->where('t.contact_id', $contact_id)
                        ->where('transaction_sell_lines.product_id', $product['product_id'])
                        ->where('transaction_sell_lines.quantity_returned', '=','0')
                        ->where('transaction_sell_lines.variation_id', $product['variation_id'])
                        ->select('transaction_sell_lines.*')->get();
                        if ($transaction_sell->isEmpty()) { // Use isEmpty() to check if the collection is empty
                            DB::rollBack();
        
                    $output = [
                        'success' => 0,
                        'msg' => "This product has already been returned in a sell return. You cannot perform a return without an invoice for product ID :".$product['product_id']
                    ];
                    return redirect('without-invoice-sell-return')->with('status', $output);

                   }
                    foreach ($transaction_sell as $t) {
                        $sell_line = TransactionSellLine::find($t->id);
                        $remain_quantity = $sell_line->quantity - $sell_line->quantity_returned;
                       
                        if ($return_quantity > 0 && $remain_quantity > 0) {

                            $quantityToSubtract = min($return_quantity, $remain_quantity);


                                $return_quantity -= $quantityToSubtract;
                                $return_quant = $quantityToSubtract ;
                               


                                $quantity_before = $sell_line->quantity_returned;

                                $sell_line->quantity_returned = $sell_line->quantity_returned+ $return_quant;
                                $sell_line->save();

                                //update quantity sold in corresponding purchase lines
                                $this->transactionUtil->updateQuantitySoldFromSellLine($sell_line, $return_quant, $quantity_before, false);

                                // Update quantity in variation location details
                                $this->productUtil->updateProductQuantity($location_id, $sell_line->product_id, $sell_line->variation_id, $return_quant, $quantity_before, null, false);
                                //
                                $data = [
                                    // 'qty'=> $return_quantity,
                                    // 'status'=>'inside_if',
                                    // 'tran'=>$sell_line,
                                    //  'before_return'=>$before_return,
                                    // 'after_return'=>$return_quantity,
                                    // 'return_qty'=>$return_quant,

                                    'transaction_id' => $sell_line->transaction_id,
                                    'return_transaction_id' => $sell_return->id,
                                    'transaction_sell_id' => $sell_line->id,
                                    'product_id' => $sell_line->product_id,
                                    'variation_id' => $sell_line->variation_id,
                                    'quantity' => $return_quant,
                                    'unit_price' => $this->transactionUtil->num_uf($product['return_unit_price'])
                                ];
                               ReturnSellLine::create($data);


                            
                        }

                    }

                }
               
            }

            //The return settles the customer's unpaid invoices, oldest first
            if (! empty($sell_return)) {
                $this->transactionUtil->settleSellReturn($sell_return->fresh());
            }

            DB::commit();
            $output = [
                'success' => 1,
                'msg' => __('lang_v1.success')
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency("File:" . $e->getFile() . "Line:" . $e->getLine() . "Message:" . $e->getMessage());

            $output = [
                'success' => 0,
                'msg' => __("messages.something_went_wrong")
            ];
          
        }
        return redirect('without-invoice-sell-return')->with('status', $output);
    }
}
