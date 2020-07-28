<?php

namespace App\Http\Controllers;

use App\Account;
use App\Brands;
use App\City;
use App\Business;
use App\BusinessLocation;
use App\Category;
use App\Contact;
use App\CustomerGroup;
use App\Media;
use App\Product;
use App\SellingPriceGroup;
use App\TaxRate;
use App\Transaction;
use App\TransactionSellLine;
use App\TypesOfService;
use App\User;
use App\Utils\BusinessUtil;
use App\Utils\CashRegisterUtil;
use App\Utils\ContactUtil;
use App\Utils\ModuleUtil;
use App\Utils\NotificationUtil;
use App\Utils\ProductUtil;
use App\Utils\TransactionUtil;
use App\Variation;
use App\Warranty;
use App\InvoiceLayout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Yajra\DataTables\Facades\DataTables;

class SellPosController extends Controller
{
    /**
     * All Utils instance.
     *
     */
    protected $contactUtil;
    protected $productUtil;
    protected $businessUtil;
    protected $transactionUtil;
    protected $cashRegisterUtil;
    protected $moduleUtil;
    protected $notificationUtil;

    /**
     * Constructor
     *
     * @param ProductUtils $product
     * @return void
     */
    public function __construct(
        ContactUtil $contactUtil,
        ProductUtil $productUtil,
        BusinessUtil $businessUtil,
        TransactionUtil $transactionUtil,
        CashRegisterUtil $cashRegisterUtil,
        ModuleUtil $moduleUtil,
        NotificationUtil $notificationUtil
    ) {
        $this->contactUtil = $contactUtil;
        $this->productUtil = $productUtil;
        $this->businessUtil = $businessUtil;
        $this->transactionUtil = $transactionUtil;
        $this->cashRegisterUtil = $cashRegisterUtil;
        $this->moduleUtil = $moduleUtil;
        $this->notificationUtil = $notificationUtil;

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
        if (!auth()->user()->can('sell.view') && !auth()->user()->can('sell.create')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');

        $business_locations = BusinessLocation::forDropdown($business_id, false);
        $customers = Contact::customersDropdown($business_id, false);
        
        $sales_representative = User::forDropdown($business_id, false, false, true);
        
        $is_cmsn_agent_enabled = request()->session()->get('business.sales_cmsn_agnt');
        $commission_agents = [];
        if (!empty($is_cmsn_agent_enabled)) {
            $commission_agents = User::forDropdown($business_id, false, true, true);
        }

        $is_tables_enabled = $this->transactionUtil->isModuleEnabled('tables');
        $is_service_staff_enabled = $this->transactionUtil->isModuleEnabled('service_staff');

        //Service staff filter
        $service_staffs = null;
        if ($is_service_staff_enabled) {
            $service_staffs = $this->productUtil->serviceStaffDropdown($business_id);
        }

        $is_types_service_enabled = $this->moduleUtil->isModuleEnabled('types_of_service');

        return view('sale_pos.index')
        ->with(compact('business_locations', 'customers', 'sales_representative', 'is_cmsn_agent_enabled', 'commission_agents', 'service_staffs', 'is_tables_enabled', 'is_service_staff_enabled', 'is_types_service_enabled'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $business_id = request()->session()->get('user.business_id');

        if (!(auth()->user()->can('superadmin') || auth()->user()->can('sell.create') || ($this->moduleUtil->hasThePermissionInSubscription($business_id, 'repair_module') && auth()->user()->can('repair.create')))) {
            abort(403, 'Unauthorized action.');
        }

        //Check if subscribed or not, then check for users quota
        if (!$this->moduleUtil->isSubscribed($business_id)) {
            return $this->moduleUtil->expiredResponse(action('HomeController@index'));
        } elseif (!$this->moduleUtil->isQuotaAvailable('invoices', $business_id)) {
            return $this->moduleUtil->quotaExpiredResponse('invoices', $business_id, action('SellPosController@index'));
        }
        
        //like:repair
        $sub_type = request()->get('sub_type');

        //Check if there is a open register, if no then redirect to Create Register screen.
        if ($this->cashRegisterUtil->countOpenedRegister() == 0) {
            return redirect()->action('CashRegisterController@create', ['sub_type' => $sub_type]);
        }

        $register_details = $this->cashRegisterUtil->getCurrentCashRegister(auth()->user()->id);

        $walk_in_customer = $this->contactUtil->getWalkInCustomer($business_id);
        
        $business_details = $this->businessUtil->getDetails($business_id);
        $taxes = TaxRate::forBusinessDropdown($business_id, true, true);

        $payment_lines[] = $this->dummyPaymentLine;

        $default_location = BusinessLocation::findOrFail($register_details->location_id);

        $payment_types = $this->productUtil->payment_types($default_location);

        //Shortcuts
        $shortcuts = json_decode($business_details->keyboard_shortcuts, true);
        $pos_settings = empty($business_details->pos_settings) ? $this->businessUtil->defaultPosSettings() : json_decode($business_details->pos_settings, true);
        
        $commsn_agnt_setting = $business_details->sales_cmsn_agnt;
        $commission_agent = [];
        if ($commsn_agnt_setting == 'user') {
            $commission_agent = User::forDropdown($business_id, false);
        } elseif ($commsn_agnt_setting == 'cmsn_agnt') {
            $commission_agent = User::saleCommissionAgentsDropdown($business_id, false);
        }

        //If brands, category are enabled then send else false.
        $categories = (request()->session()->get('business.enable_category') == 1) ? Category::catAndSubCategories($business_id) : false;
        $brands = (request()->session()->get('business.enable_brand') == 1) ? Brands::where('business_id', $business_id)
        ->pluck('name', 'id')
        ->prepend(__('lang_v1.all_brands'), 'all') : false;

        $change_return = $this->dummyPaymentLine;

        $types = Contact::getContactTypes();
        $customer_groups = CustomerGroup::forDropdown($business_id);

        //Accounts
        $accounts = [];
        if ($this->moduleUtil->isModuleEnabled('account')) {
            $accounts = Account::forDropdown($business_id, true, false, true);
        }
        
        //Selling Price Group Dropdown
        $price_groups = SellingPriceGroup::forDropdown($business_id);

        $default_price_group_id = !empty($default_location->selling_price_group_id) && array_key_exists($default_location->selling_price_group_id, $price_groups) ? $default_location->selling_price_group_id : null;

        //Types of service
        $types_of_service = [];
        if ($this->moduleUtil->isModuleEnabled('types_of_service')) {
            $types_of_service = TypesOfService::forDropdown($business_id);
        }

        $shipping_statuses = $this->transactionUtil->shipping_statuses();

        $default_datetime = $this->businessUtil->format_date('now', true);

        $featured_products = $default_location->getFeaturedProducts();

        //pos screen view from module
        $pos_module_data = $this->moduleUtil->getModuleData('get_pos_screen_view', $sub_type);
        $invoice_layouts = InvoiceLayout::forDropdown($business_id);

        return view('sale_pos.create')
        ->with('cities', $this->prepareCities())
        ->with('tipo', 'customer')
        
        ->with(compact(
            'business_details',
            'taxes',
            'payment_types',
            'walk_in_customer',
            'payment_lines',
            'default_location',
            'shortcuts',
            'commission_agent',
            'categories',
            'brands',
            'pos_settings',
            'change_return',
            'types',
            'customer_groups',
            'accounts',
            'price_groups',
            'types_of_service',
            'default_price_group_id',
            'shipping_statuses',
            'default_datetime',
            'featured_products',
            'sub_type',
            'pos_module_data',
            'invoice_layouts'
        ));
    }

    private function prepareCities(){
        $cities = City::all();
        $temp = [];
        foreach($cities as $c){
            // array_push($temp, $c->id => $c->nome);
            $temp[$c->id] = $c->nome . " ($c->uf)";
        }
        return $temp;
  }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        if (!auth()->user()->can('sell.create') && !auth()->user()->can('direct_sell.access')) {
            abort(403, 'Unauthorized action.');
        }

        $is_direct_sale = false;
        if (!empty($request->input('is_direct_sale'))) {
            $is_direct_sale = true;
        }

        //Check if there is a open register, if no then redirect to Create Register screen.
        if (!$is_direct_sale && $this->cashRegisterUtil->countOpenedRegister() == 0) {
            return redirect()->action('CashRegisterController@create');
        }

        try {
            $input = $request->except('_token');

            //Check Customer credit limit
            $is_credit_limit_exeeded = $this->transactionUtil->isCustomerCreditLimitExeeded($input);

            if ($is_credit_limit_exeeded !== false) {
                $credit_limit_amount = $this->transactionUtil->num_f($is_credit_limit_exeeded, true);
                $output = ['success' => 0,
                'msg' => __('lang_v1.cutomer_credit_limit_exeeded', ['credit_limit' => $credit_limit_amount])
            ];
            if (!$is_direct_sale) {
                return $output;
            } else {
                return redirect()
                ->action('SellController@index')
                ->with('status', $output);
            }
        }

        $input['is_quotation'] = 0;
            //status is send as quotation from Add sales screen.
        if ($input['status'] == 'quotation') {
            $input['status'] = 'draft';
            $input['is_quotation'] = 1;
        }


        if($request->natureza_id){
            $input['natureza_id'] = $request->natureza_id;
        }
        if($request->transportadora_id){
            $input['transportadora_id'] = $request->transportadora_id;
        }
        if($request->placa){
            $input['placa'] = $request->placa;
        }
        if($request->uf){
            $input['uf'] = $request->uf;
        }
        if($request->uf){
            $input['uf'] = $request->uf;
        }
        if($request->valor_frete){
            $input['valor_frete'] = $request->valor_frete;
        }
        if($request->tipo){
            $input['tipo'] = $request->tipo;
        }
        if($request->qtd_volumes){
            $input['qtd_volumes'] = $request->qtd_volumes;
        }
        if($request->numeracao_volumes){
            $input['numeracao_volumes'] = $request->numeracao_volumes;
        }
        if($request->especie){
            $input['especie'] = $request->especie;
        }
        if($request->peso_liquido){
            $input['peso_liquido'] = $request->peso_liquido;
        }
        if($request->peso_bruto){
            $input['peso_bruto'] = $request->peso_bruto;
        }

        if($request->cpf){
            $input['cpf_nota'] = $request->cpf;
        }

        $input['valor_recebido'] = $request->valor_recebido;

        // return redirect('/'.$input['cpf_nota']);


        if (!empty($input['products'])) {
            $business_id = $request->session()->get('user.business_id');

                //Check if subscribed or not, then check for users quota
            if (!$this->moduleUtil->isSubscribed($business_id)) {
                return $this->moduleUtil->expiredResponse();
            } elseif (!$this->moduleUtil->isQuotaAvailable('invoices', $business_id)) {
                return $this->moduleUtil->quotaExpiredResponse('invoices', $business_id, action('SellPosController@index'));
            }
            
            $user_id = $request->session()->get('user.id');

            $discount = ['discount_type' => $input['discount_type'],
            'discount_amount' => $input['discount_amount']
        ];
        $invoice_total = $this->productUtil->calculateInvoiceTotal($input['products'], $input['tax_rate_id'], $discount);


        DB::beginTransaction();

        if (empty($request->input('transaction_date'))) {
            $input['transaction_date'] =  \Carbon::now();
        } else {
            $input['transaction_date'] = $this->productUtil->uf_date($request->input('transaction_date'), true);
        }
        if ($is_direct_sale) {
            $input['is_direct_sale'] = 1;
        }

                //Set commission agent
        $input['commission_agent'] = !empty($request->input('commission_agent')) ? $request->input('commission_agent') : null;
        $commsn_agnt_setting = $request->session()->get('business.sales_cmsn_agnt');
        if ($commsn_agnt_setting == 'logged_in_user') {
            $input['commission_agent'] = $user_id;
        }

        if (isset($input['exchange_rate']) && $this->transactionUtil->num_uf($input['exchange_rate']) == 0) {
            $input['exchange_rate'] = 1;
        }

                //Customer group details
        $contact_id = $request->get('contact_id', null);
        $cg = $this->contactUtil->getCustomerGroup($business_id, $contact_id);
        $input['customer_group_id'] = (empty($cg) || empty($cg->id)) ? null : $cg->id;

                //set selling price group id
        $price_group_id = $request->has('price_group') ? $request->input('price_group') : null;

                //If default price group for the location exists
        $price_group_id = $price_group_id == 0 && $request->has('default_price_group') ? $request->input('default_price_group') : $price_group_id;

        $input['is_suspend'] = isset($input['is_suspend']) && 1 == $input['is_suspend']  ? 1 : 0;
        if ($input['is_suspend']) {
            $input['sale_note'] = !empty($input['additional_notes']) ? $input['additional_notes'] : null;
        }

                //Generate reference number
        if (!empty($input['is_recurring'])) {
                    //Update reference count
            $ref_count = $this->transactionUtil->setAndGetReferenceCount('subscription');
            $input['subscription_no'] = $this->transactionUtil->generateReferenceNumber('subscription', $ref_count);
        }

        if ($is_direct_sale) {
            $input['invoice_scheme_id'] = $request->input('invoice_scheme_id');
        }

                //Types of service
        if ($this->moduleUtil->isModuleEnabled('types_of_service')) {
            $input['types_of_service_id'] = $request->input('types_of_service_id');
            $price_group_id = !empty($request->input('types_of_service_price_group')) ? $request->input('types_of_service_price_group') : $price_group_id;
            $input['packing_charge'] = !empty($request->input('packing_charge')) ?
            $this->transactionUtil->num_uf($request->input('packing_charge')) : 0;
            $input['packing_charge_type'] = $request->input('packing_charge_type');
            $input['service_custom_field_1'] = !empty($request->input('service_custom_field_1')) ?
            $request->input('service_custom_field_1') : null;
            $input['service_custom_field_2'] = !empty($request->input('service_custom_field_2')) ?
            $request->input('service_custom_field_2') : null;
            $input['service_custom_field_3'] = !empty($request->input('service_custom_field_3')) ?
            $request->input('service_custom_field_3') : null;
            $input['service_custom_field_4'] = !empty($request->input('service_custom_field_4')) ?
            $request->input('service_custom_field_4') : null;
        }

        $input['selling_price_group_id'] = $price_group_id;

        $transaction = $this->transactionUtil->createSellTransaction($business_id, $input, $invoice_total, $user_id);

        $this->transactionUtil->createOrUpdateSellLines($transaction, $input['products'], $input['location_id']);
        
        if (!$is_direct_sale) {
                    //Add change return
            $change_return = $this->dummyPaymentLine;
            $change_return['amount'] = $input['change_return'];
            $change_return['is_return'] = 1;
            $input['payment'][] = $change_return;
        }

        $is_credit_sale = isset($input['is_credit_sale']) && $input['is_credit_sale'] == 1 ? true : false;

        if (!$transaction->is_suspend && !empty($input['payment']) && !$is_credit_sale) {
            $this->transactionUtil->createOrUpdatePaymentLines($transaction, $input['payment']);
        }

        $update_transaction = false;
        if ($this->transactionUtil->isModuleEnabled('tables')) {
            $transaction->res_table_id = request()->get('res_table_id');
            $update_transaction = true;
        }
        if ($this->transactionUtil->isModuleEnabled('service_staff')) {
            $transaction->res_waiter_id = request()->get('res_waiter_id');
            $update_transaction = true;
        }
        if ($update_transaction) {
            $transaction->save();
        }

                //Check for final and do some processing.
        if ($input['status'] == 'final') {
                    //update product stock
            foreach ($input['products'] as $product) {
                $decrease_qty = $this->productUtil
                ->num_uf($product['quantity']);
                if (!empty($product['base_unit_multiplier'])) {
                    $decrease_qty = $decrease_qty * $product['base_unit_multiplier'];
                }

                if ($product['enable_stock']) {
                    $this->productUtil->decreaseProductQuantity(
                        $product['product_id'],
                        $product['variation_id'],
                        $input['location_id'],
                        $decrease_qty
                    );
                }

                if ($product['product_type'] == 'combo') {
                            //Decrease quantity of combo as well.
                    $this->productUtil
                    ->decreaseProductQuantityCombo(
                        $product['combo'],
                        $input['location_id']
                    );
                }
            }

                    //Add payments to Cash Register
            if (!$is_direct_sale && !$transaction->is_suspend && !empty($input['payment']) && !$is_credit_sale) {
                $this->cashRegisterUtil->addSellPayments($transaction, $input['payment']);
            }

                    //Update payment status
            $this->transactionUtil->updatePaymentStatus($transaction->id, $transaction->final_total);

            if ($request->session()->get('business.enable_rp') == 1) {
                $redeemed = !empty($input['rp_redeemed']) ? $input['rp_redeemed'] : 0;
                $this->transactionUtil->updateCustomerRewardPoints($contact_id, $transaction->rp_earned, 0, $redeemed);
            }

                    //Allocate the quantity from purchase and add mapping of
                    //purchase & sell lines in
                    //transaction_sell_lines_purchase_lines table
            $business_details = $this->businessUtil->getDetails($business_id);
            $pos_settings = empty($business_details->pos_settings) ? $this->businessUtil->defaultPosSettings() : json_decode($business_details->pos_settings, true);

            $business = ['id' => $business_id,
            'accounting_method' => $request->session()->get('business.accounting_method'),
            'location_id' => $input['location_id'],
            'pos_settings' => $pos_settings
        ];
        $this->transactionUtil->mapPurchaseSell($business, $transaction->sell_lines, 'purchase');

                    //Auto send notification
        $this->notificationUtil->autoSendNotification($business_id, 'new_sale', $transaction, $transaction->contact);
    }

                //Set Module fields
    if (!empty($input['has_module_data'])) {
        $this->moduleUtil->getModuleData('after_sale_saved', ['transaction' => $transaction, 'input' => $input]);
    }

    Media::uploadMedia($business_id, $transaction, $request, 'documents');
    
    DB::commit();

    if ($request->input('is_save_and_print') == 1) {
        $url = $this->transactionUtil->getInvoiceUrl($transaction->id, $business_id);
        return redirect()->to($url . '?print_on_load=true');
    }

    $msg = '';
    $receipt = '';
    $invoice_layout_id = $request->input('invoice_layout_id');
    if ($input['status'] == 'draft' && $input['is_quotation'] == 0) {
        $msg = trans("sale.draft_added");
    } elseif ($input['status'] == 'draft' && $input['is_quotation'] == 1) {
        $msg = trans("lang_v1.quotation_added");
        if (!$is_direct_sale) {
            $receipt = $this->receiptContent($business_id, $input['location_id'], $transaction->id, null, false, true, $invoice_layout_id);
        } else {
            $receipt = '';
        }
    } elseif ($input['status'] == 'final') {
        $msg = trans("sale.pos_sale_added");
        if (!$is_direct_sale) {
            $receipt = $this->receiptContent($business_id, $input['location_id'], $transaction->id, null, false, true, $invoice_layout_id);
        } else {
            $receipt = '';
        }
    }
    $output = ['success' => 1, 'msg' => $msg, 'receipt' => $receipt, 'venda_id' => $transaction->id ];
} else {
    $output = ['success' => 0,
    'msg' => trans("messages.something_went_wrong")
];
}
} catch (\Exception $e) {
    DB::rollBack();
    \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());
    $msg = trans("messages.something_went_wrong");
    
    if (get_class($e) == \App\Exceptions\PurchaseSellMismatch::class) {
        $msg = $e->getMessage();
    }

    $output = ['success' => 0,
    'msg' => $msg
];
}

if (!$is_direct_sale) {
    return $output;
} else {
    if ($input['status'] == 'draft') {
        if (isset($input['is_quotation']) && $input['is_quotation'] == 1) {
            return redirect()
            ->action('SellController@getQuotations')
            ->with('status', $output);
        } else {
            return redirect()
            ->action('SellController@getDrafts')
            ->with('status', $output);
        }
    } else {
        if (!empty($input['sub_type']) && $input['sub_type'] == 'repair') {
            $redirect_url = $input['print_label'] == 1 ? action('\Modules\Repair\Http\Controllers\RepairController@printLabel', [$transaction->id]) : action('\Modules\Repair\Http\Controllers\RepairController@index');
            return redirect($redirect_url)
            ->with('status', $output);
        }
        return redirect()
        ->action('SellController@index')
        ->with('status', $output);
    }
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
        $printer_type = null,
        $is_package_slip = false,
        $from_pos_screen = true,
        $invoice_layout_id = null
    ) {
        $output = ['is_enabled' => false,
        'print_type' => 'browser',
        'html_content' => null,
        'printer_config' => [],
        'data' => []
    ];


    $business_details = $this->businessUtil->getDetails($business_id);
    $location_details = BusinessLocation::find($location_id);
    
    if ($from_pos_screen && $location_details->print_receipt_on_invoice != 1) {
        return $output;
    }
        //Check if printing of invoice is enabled or not.
        //If enabled, get print type.
    $output['is_enabled'] = true;

    $invoice_layout_id = !empty($invoice_layout_id) ? $invoice_layout_id : $location_details->invoice_layout_id;
    $invoice_layout = $this->businessUtil->invoiceLayout($business_id, $location_id, $invoice_layout_id);

        //Check if printer setting is provided.
    $receipt_printer_type = is_null($printer_type) ? $location_details->receipt_printer_type : $printer_type;

    $receipt_details = $this->transactionUtil->getReceiptDetails($transaction_id, $location_id, $invoice_layout, $business_details, $location_details, $receipt_printer_type);

    $currency_details = [
        'symbol' => $business_details->currency_symbol,
        'thousand_separator' => $business_details->thousand_separator,
        'decimal_separator' => $business_details->decimal_separator,
    ];
    $receipt_details->currency = $currency_details;
    
    if ($is_package_slip) {
        $output['html_content'] = view('sale_pos.receipts.packing_slip', compact('receipt_details'))->render();
        return $output;
    }
        //If print type browser - return the content, printer - return printer config data, and invoice format config
    if ($receipt_printer_type == 'printer') {
        $output['print_type'] = 'printer';
        $output['printer_config'] = $this->businessUtil->printerConfig($business_id, $location_details->printer_id);
        $output['data'] = $receipt_details;
    } else {
        $layout = !empty($receipt_details->design) ? 'sale_pos.receipts.' . $receipt_details->design : 'sale_pos.receipts.classic';

        $output['html_content'] = view($layout, compact('receipt_details'))->render();
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
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        $business_id = request()->session()->get('user.business_id');

        if (!(auth()->user()->can('superadmin') || auth()->user()->can('sell.update') || ($this->moduleUtil->hasThePermissionInSubscription($business_id, 'repair_module') && auth()->user()->can('repair.update')))) {
            abort(403, 'Unauthorized action.');
        }

        //Check if the transaction can be edited or not.
        $edit_days = request()->session()->get('business.transaction_edit_days');
        if (!$this->transactionUtil->canBeEdited($id, $edit_days)) {
            return back()
            ->with('status', ['success' => 0,
                'msg' => __('messages.transaction_edit_not_allowed', ['days' => $edit_days])]);
        }

        //Check if there is a open register, if no then redirect to Create Register screen.
        if ($this->cashRegisterUtil->countOpenedRegister() == 0) {
            return redirect()->action('CashRegisterController@create');
        }
        
        //Check if return exist then not allowed
        if ($this->transactionUtil->isReturnExist($id)) {
            return back()->with('status', ['success' => 0,
                'msg' => __('lang_v1.return_exist')]);
        }

        $walk_in_customer = $this->contactUtil->getWalkInCustomer($business_id);
        
        $business_details = $this->businessUtil->getDetails($business_id);

        $taxes = TaxRate::forBusinessDropdown($business_id, true, true);

        $transaction = Transaction::where('business_id', $business_id)
        ->where('type', 'sell')
        ->with(['price_group', 'types_of_service'])
        ->findorfail($id);

        $location_id = $transaction->location_id;
        $business_location = BusinessLocation::find($location_id);
        $payment_types = $this->productUtil->payment_types($business_location);
        $location_printer_type = $business_location->receipt_printer_type;
        $sell_details = TransactionSellLine::
        join(
            'products AS p',
            'transaction_sell_lines.product_id',
            '=',
            'p.id'
        )
        ->join(
            'variations AS variations',
            'transaction_sell_lines.variation_id',
            '=',
            'variations.id'
        )
        ->join(
            'product_variations AS pv',
            'variations.product_variation_id',
            '=',
            'pv.id'
        )
        ->leftjoin('variation_location_details AS vld', function ($join) use ($location_id) {
            $join->on('variations.id', '=', 'vld.variation_id')
            ->where('vld.location_id', '=', $location_id);
        })
        ->leftjoin('units', 'units.id', '=', 'p.unit_id')
        ->where('transaction_sell_lines.transaction_id', $id)
        ->with(['warranties'])
        ->select(
            DB::raw("IF(pv.is_dummy = 0, CONCAT(p.name, ' (', pv.name, ':',variations.name, ')'), p.name) AS product_name"),
            'p.id as product_id',
            'p.enable_stock',
            'p.name as product_actual_name',
            'p.type as product_type',
            'pv.name as product_variation_name',
            'pv.is_dummy as is_dummy',
            'variations.name as variation_name',
            'variations.sub_sku',
            'p.barcode_type',
            'p.enable_sr_no',
            'variations.id as variation_id',
            'units.short_name as unit',
            'units.allow_decimal as unit_allow_decimal',
            'transaction_sell_lines.tax_id as tax_id',
            'transaction_sell_lines.item_tax as item_tax',
            'transaction_sell_lines.unit_price as default_sell_price',
            'transaction_sell_lines.unit_price_before_discount as unit_price_before_discount',
            'transaction_sell_lines.unit_price_inc_tax as sell_price_inc_tax',
            'transaction_sell_lines.id as transaction_sell_lines_id',
            'transaction_sell_lines.id',
            'transaction_sell_lines.quantity as quantity_ordered',
            'transaction_sell_lines.sell_line_note as sell_line_note',
            'transaction_sell_lines.parent_sell_line_id',
            'transaction_sell_lines.lot_no_line_id',
            'transaction_sell_lines.line_discount_type',
            'transaction_sell_lines.line_discount_amount',
            'transaction_sell_lines.res_service_staff_id',
            'units.id as unit_id',
            'transaction_sell_lines.sub_unit_id',
            DB::raw('vld.qty_available + transaction_sell_lines.quantity AS qty_available')
        )
        ->get();
        if (!empty($sell_details)) {
            foreach ($sell_details as $key => $value) {

                //If modifier or combo sell line then unset
                if (!empty($sell_details[$key]->parent_sell_line_id)) {
                    unset($sell_details[$key]);
                } else {
                    if ($transaction->status != 'final') {
                        $actual_qty_avlbl = $value->qty_available - $value->quantity_ordered;
                        $sell_details[$key]->qty_available = $actual_qty_avlbl;
                        $value->qty_available = $actual_qty_avlbl;
                    }

                    $sell_details[$key]->formatted_qty_available = $this->productUtil->num_f($value->qty_available, false, null, true);

                    //Add available lot numbers for dropdown to sell lines
                    $lot_numbers = [];
                    if (request()->session()->get('business.enable_lot_number') == 1 || request()->session()->get('business.enable_product_expiry') == 1) {
                        $lot_number_obj = $this->transactionUtil->getLotNumbersFromVariation($value->variation_id, $business_id, $location_id);
                        foreach ($lot_number_obj as $lot_number) {
                            //If lot number is selected added ordered quantity to lot quantity available
                            if ($value->lot_no_line_id == $lot_number->purchase_line_id) {
                                $lot_number->qty_available += $value->quantity_ordered;
                            }

                            $lot_number->qty_formated = $this->productUtil->num_f($lot_number->qty_available);
                            $lot_numbers[] = $lot_number;
                        }
                    }
                    $sell_details[$key]->lot_numbers = $lot_numbers;
                    
                    if (!empty($value->sub_unit_id)) {
                        $value = $this->productUtil->changeSellLineUnit($business_id, $value);
                        $sell_details[$key] = $value;
                    }

                    $sell_details[$key]->formatted_qty_available = $this->productUtil->num_f($value->qty_available, false, null, true);

                    if ($this->transactionUtil->isModuleEnabled('modifiers')) {
                        //Add modifier details to sel line details
                        $sell_line_modifiers = TransactionSellLine::where('parent_sell_line_id', $sell_details[$key]->transaction_sell_lines_id)
                        ->where('children_type', 'modifier')
                        ->get();
                        $modifiers_ids = [];
                        if (count($sell_line_modifiers) > 0) {
                            $sell_details[$key]->modifiers = $sell_line_modifiers;
                            foreach ($sell_line_modifiers as $sell_line_modifier) {
                                $modifiers_ids[] = $sell_line_modifier->variation_id;
                            }
                        }
                        $sell_details[$key]->modifiers_ids = $modifiers_ids;

                        //add product modifier sets for edit
                        $this_product = Product::find($sell_details[$key]->product_id);
                        if (count($this_product->modifier_sets) > 0) {
                            $sell_details[$key]->product_ms = $this_product->modifier_sets;
                        }
                    }

                    //Get details of combo items
                    if ($sell_details[$key]->product_type == 'combo') {
                        $sell_line_combos = TransactionSellLine::where('parent_sell_line_id', $sell_details[$key]->transaction_sell_lines_id)
                        ->where('children_type', 'combo')
                        ->get()
                        ->toArray();
                        if (!empty($sell_line_combos)) {
                            $sell_details[$key]->combo_products = $sell_line_combos;
                        }

                        //calculate quantity available if combo product
                        $combo_variations = [];
                        foreach ($sell_line_combos as $combo_line) {
                            $combo_variations[] = [
                                'variation_id' => $combo_line['variation_id'],
                                'quantity' => $combo_line['quantity'] / $sell_details[$key]->quantity_ordered,
                                'unit_id' => null
                            ];
                        }
                        $sell_details[$key]->qty_available =
                        $this->productUtil->calculateComboQuantity($location_id, $combo_variations);
                        
                        if ($transaction->status == 'final') {
                            $sell_details[$key]->qty_available = $sell_details[$key]->qty_available + $sell_details[$key]->quantity_ordered;
                        }
                        
                        $sell_details[$key]->formatted_qty_available = $this->productUtil->num_f($sell_details[$key]->qty_available, false, null, true);
                    }
                }
            }
        }

        $payment_lines = $this->transactionUtil->getPaymentDetails($id);
        //If no payment lines found then add dummy payment line.
        if (empty($payment_lines)) {
            $payment_lines[] = $this->dummyPaymentLine;
        }

        $shortcuts = json_decode($business_details->keyboard_shortcuts, true);
        $pos_settings = empty($business_details->pos_settings) ? $this->businessUtil->defaultPosSettings() : json_decode($business_details->pos_settings, true);

        $commsn_agnt_setting = $business_details->sales_cmsn_agnt;
        $commission_agent = [];
        if ($commsn_agnt_setting == 'user') {
            $commission_agent = User::forDropdown($business_id, false);
        } elseif ($commsn_agnt_setting == 'cmsn_agnt') {
            $commission_agent = User::saleCommissionAgentsDropdown($business_id, false);
        }

        //If brands, category are enabled then send else false.
        $categories = (request()->session()->get('business.enable_category') == 1) ? Category::catAndSubCategories($business_id) : false;
        $brands = (request()->session()->get('business.enable_brand') == 1) ? Brands::where('business_id', $business_id)
        ->pluck('name', 'id')
        ->prepend(__('lang_v1.all_brands'), 'all') : false;

        $change_return = $this->dummyPaymentLine;

        $types = [];
        if (auth()->user()->can('supplier.create')) {
            $types['supplier'] = __('report.supplier');
        }
        if (auth()->user()->can('customer.create')) {
            $types['customer'] = __('report.customer');
        }
        if (auth()->user()->can('supplier.create') && auth()->user()->can('customer.create')) {
            $types['both'] = __('lang_v1.both_supplier_customer');
        }
        $customer_groups = CustomerGroup::forDropdown($business_id);

        //Accounts
        $accounts = [];
        if ($this->moduleUtil->isModuleEnabled('account')) {
            $accounts = Account::forDropdown($business_id, true, false, true);
        }

        $waiters = [];
        if ($this->productUtil->isModuleEnabled('service_staff') && !empty($pos_settings['inline_service_staff'])) {
            $waiters_enabled = true;
            $waiters = $this->productUtil->serviceStaffDropdown($business_id);
        }
        $redeem_details = [];
        if (request()->session()->get('business.enable_rp') == 1) {
            $redeem_details = $this->transactionUtil->getRewardRedeemDetails($business_id, $transaction->contact_id);

            $redeem_details['points'] += $transaction->rp_redeemed;
            $redeem_details['points'] -= $transaction->rp_earned;
        }

        $edit_discount = auth()->user()->can('edit_product_discount_from_pos_screen');
        $edit_price = auth()->user()->can('edit_product_price_from_pos_screen');
        $shipping_statuses = $this->transactionUtil->shipping_statuses();

        $warranties = $this->__getwarranties();
        $sub_type = request()->get('sub_type');

        //pos screen view from module
        $pos_module_data = $this->moduleUtil->getModuleData('get_pos_screen_view', $sub_type);

        $invoice_layouts = InvoiceLayout::forDropdown($business_id);

        return view('sale_pos.edit')
        ->with(compact('business_details', 'taxes', 'payment_types', 'walk_in_customer', 'sell_details', 'transaction', 'payment_lines', 'location_printer_type', 'shortcuts', 'commission_agent', 'categories', 'pos_settings', 'change_return', 'types', 'customer_groups', 'brands', 'accounts', 'waiters', 'redeem_details', 'edit_price', 'edit_discount', 'shipping_statuses', 'warranties', 'sub_type', 'pos_module_data', 'invoice_layouts'));
    }

    /**
     * Update the specified resource in storage.
     * TODO: Add edit log.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        if (!auth()->user()->can('sell.update') && !auth()->user()->can('direct_sell.access')) {
            abort(403, 'Unauthorized action.');
        }
        
        try {
            $input = $request->except('_token');

            //status is send as quotation from edit sales screen.
            $input['is_quotation'] = 0;
            if ($input['status'] == 'quotation') {
                $input['status'] = 'draft';
                $input['is_quotation'] = 1;
            }

            $is_direct_sale = false;
            if (!empty($input['products'])) {
                //Get transaction value before updating.
                $transaction_before = Transaction::find($id);
                $status_before =  $transaction_before->status;
                $rp_earned_before = $transaction_before->rp_earned;
                $rp_redeemed_before = $transaction_before->rp_redeemed;

                if ($transaction_before->is_direct_sale == 1) {
                    $is_direct_sale = true;
                }

                //Check Customer credit limit
                $is_credit_limit_exeeded = $this->transactionUtil->isCustomerCreditLimitExeeded($input, $id);

                if ($is_credit_limit_exeeded !== false) {
                    $credit_limit_amount = $this->transactionUtil->num_f($is_credit_limit_exeeded, true);
                    $output = ['success' => 0,
                    'msg' => __('lang_v1.cutomer_credit_limit_exeeded', ['credit_limit' => $credit_limit_amount])
                ];
                if (!$is_direct_sale) {
                    return $output;
                } else {
                    return redirect()
                    ->action('SellController@index')
                    ->with('status', $output);
                }
            }

                //Check if there is a open register, if no then redirect to Create Register screen.
            if (!$is_direct_sale && $this->cashRegisterUtil->countOpenedRegister() == 0) {
                return redirect()->action('CashRegisterController@create');
            }

            $business_id = $request->session()->get('user.business_id');
            $user_id = $request->session()->get('user.id');
            $commsn_agnt_setting = $request->session()->get('business.sales_cmsn_agnt');

            $discount = ['discount_type' => $input['discount_type'],
            'discount_amount' => $input['discount_amount']
        ];
        $invoice_total = $this->productUtil->calculateInvoiceTotal($input['products'], $input['tax_rate_id'], $discount);

        if (!empty($request->input('transaction_date'))) {
            $input['transaction_date'] = $this->productUtil->uf_date($request->input('transaction_date'), true);
        }

        $input['commission_agent'] = !empty($request->input('commission_agent')) ? $request->input('commission_agent') : null;
        if ($commsn_agnt_setting == 'logged_in_user') {
            $input['commission_agent'] = $user_id;
        }

        if (isset($input['exchange_rate']) && $this->transactionUtil->num_uf($input['exchange_rate']) == 0) {
            $input['exchange_rate'] = 1;
        }

                //Customer group details
        $contact_id = $request->get('contact_id', null);
        $cg = $this->contactUtil->getCustomerGroup($business_id, $contact_id);
        $input['customer_group_id'] = (empty($cg) || empty($cg->id)) ? null : $cg->id;
        
                //set selling price group id
        $price_group_id = $request->has('price_group') ? $request->input('price_group') : null;

        $input['is_suspend'] = isset($input['is_suspend']) && 1 == $input['is_suspend']  ? 1 : 0;
        if ($input['is_suspend']) {
            $input['sale_note'] = !empty($input['additional_notes']) ? $input['additional_notes'] : null;
        }

        if ($is_direct_sale && $status_before == 'draft') {
            $input['invoice_scheme_id'] = $request->input('invoice_scheme_id');
        }

                //Types of service
        if ($this->moduleUtil->isModuleEnabled('types_of_service')) {
            $input['types_of_service_id'] = $request->input('types_of_service_id');
            $price_group_id = !empty($request->input('types_of_service_price_group')) ? $request->input('types_of_service_price_group') : $price_group_id;
            $input['packing_charge'] = !empty($request->input('packing_charge')) ?
            $this->transactionUtil->num_uf($request->input('packing_charge')) : 0;
            $input['packing_charge_type'] = $request->input('packing_charge_type');
            $input['service_custom_field_1'] = !empty($request->input('service_custom_field_1')) ?
            $request->input('service_custom_field_1') : null;
            $input['service_custom_field_2'] = !empty($request->input('service_custom_field_2')) ?
            $request->input('service_custom_field_2') : null;
            $input['service_custom_field_3'] = !empty($request->input('service_custom_field_3')) ?
            $request->input('service_custom_field_3') : null;
            $input['service_custom_field_4'] = !empty($request->input('service_custom_field_4')) ?
            $request->input('service_custom_field_4') : null;
        }

        $input['selling_price_group_id'] = $price_group_id;

                //Begin transaction
        DB::beginTransaction();

        $transaction = $this->transactionUtil->updateSellTransaction($id, $business_id, $input, $invoice_total, $user_id);

                //Update Sell lines
        $deleted_lines = $this->transactionUtil->createOrUpdateSellLines($transaction, $input['products'], $input['location_id'], true, $status_before);

                //Update update lines
        $is_credit_sale = isset($input['is_credit_sale']) && $input['is_credit_sale'] == 1 ? true : false;

        if (!$is_direct_sale && !$transaction->is_suspend && !$is_credit_sale) {
                    //Add change return
            $change_return = $this->dummyPaymentLine;
            $change_return['amount'] = $input['change_return'];
            $change_return['is_return'] = 1;
            if (!empty($input['change_return_id'])) {
                $change_return['id'] = $input['change_return_id'];
            }
            $input['payment'][] = $change_return;

            $this->transactionUtil->createOrUpdatePaymentLines($transaction, $input['payment']);

                    //Update cash register
            $this->cashRegisterUtil->updateSellPayments($status_before, $transaction, $input['payment']);
        }

        if ($request->session()->get('business.enable_rp') == 1) {
            $this->transactionUtil->updateCustomerRewardPoints($contact_id, $transaction->rp_earned, $rp_earned_before, $transaction->rp_redeemed, $rp_redeemed_before);
        }

                //Update payment status
        $this->transactionUtil->updatePaymentStatus($transaction->id, $transaction->final_total);

                //Update product stock
        $this->productUtil->adjustProductStockForInvoice($status_before, $transaction, $input);

                //Allocate the quantity from purchase and add mapping of
                //purchase & sell lines in
                //transaction_sell_lines_purchase_lines table
        $business_details = $this->businessUtil->getDetails($business_id);
        $pos_settings = empty($business_details->pos_settings) ? $this->businessUtil->defaultPosSettings() : json_decode($business_details->pos_settings, true);

        $business = ['id' => $business_id,
        'accounting_method' => $request->session()->get('business.accounting_method'),
        'location_id' => $input['location_id'],
        'pos_settings' => $pos_settings
    ];
    $this->transactionUtil->adjustMappingPurchaseSell($status_before, $transaction, $business, $deleted_lines);
    
    if ($this->transactionUtil->isModuleEnabled('tables')) {
        $transaction->res_table_id = request()->get('res_table_id');
        $transaction->save();
    }
    if ($this->transactionUtil->isModuleEnabled('service_staff')) {
        $transaction->res_waiter_id = request()->get('res_waiter_id');
        $transaction->save();
    }
    $log_properties = [];
    if (isset($input['repair_completed_on'])) {
        $completed_on = !empty($input['repair_completed_on']) ? $this->transactionUtil->uf_date($input['repair_completed_on'], true) : null;
        if ($transaction->repair_completed_on != $completed_on) {
            $log_properties['completed_on_from'] = $transaction->repair_completed_on;
            $log_properties['completed_on_to'] = $completed_on;
        }
    }

                //Set Module fields
    if (!empty($input['has_module_data'])) {
        $this->moduleUtil->getModuleData('after_sale_saved', ['transaction' => $transaction, 'input' => $input]);
    }

    if (!empty($input['update_note'])) {
        $log_properties['update_note'] = $input['update_note'];
    }

    Media::uploadMedia($business_id, $transaction, $request, 'documents');

    activity()
    ->performedOn($transaction)
    ->withProperties($log_properties)
    ->log('edited');

    DB::commit();

    if ($request->input('is_save_and_print') == 1) {
        $url = $this->transactionUtil->getInvoiceUrl($id, $business_id);
        return redirect()->to($url . '?print_on_load=true');
    }
    
    $msg = '';
    $receipt = '';

    $invoice_layout_id = $request->input('invoice_layout_id');

    if ($input['status'] == 'draft' && $input['is_quotation'] == 0) {
        $msg = trans("sale.draft_added");
    } elseif ($input['status'] == 'draft' && $input['is_quotation'] == 1) {
        $msg = trans("lang_v1.quotation_updated");
        if (!$is_direct_sale) {
            $receipt = $this->receiptContent($business_id, $input['location_id'], $transaction->id, null, false, true, $invoice_layout_id);
        } else {
            $receipt = '';
        }
    } elseif ($input['status'] == 'final') {
        $msg = trans("sale.pos_sale_updated");
        if (!$is_direct_sale) {
            $receipt = $this->receiptContent($business_id, $input['location_id'], $transaction->id, null, false, true, $invoice_layout_id);
        } else {
            $receipt = '';
        }
    }

    $output = ['success' => 1, 'msg' => $msg, 'receipt' => $receipt ];
} else {
    $output = ['success' => 0,
    'msg' => trans("messages.something_went_wrong")
];
}
} catch (\Exception $e) {
    DB::rollBack();
    \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());
    $output = ['success' => 0,
    'msg' => __('messages.something_went_wrong')
];
}

if (!$is_direct_sale) {
    return $output;
} else {
    if ($input['status'] == 'draft') {
        if (isset($input['is_quotation']) && $input['is_quotation'] == 1) {
            return redirect()
            ->action('SellController@getQuotations')
            ->with('status', $output);
        } else {
            return redirect()
            ->action('SellController@getDrafts')
            ->with('status', $output);
        }
    } else {
        if (!empty($transaction->sub_type) && $transaction->sub_type == 'repair') {
            return redirect()
            ->action('\Modules\Repair\Http\Controllers\RepairController@index')
            ->with('status', $output);
        }

        return redirect()
        ->action('SellController@index')
        ->with('status', $output);
    }
}
}

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        if (!auth()->user()->can('sell.delete')) {
            abort(403, 'Unauthorized action.');
        }

        if (request()->ajax()) {
            try {
                $business_id = request()->session()->get('user.business_id');
                //Begin transaction
                DB::beginTransaction();

                $output = $this->transactionUtil->deleteSale($business_id, $id);
                
                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());

                $output['success'] = false;
                $output['msg'] = trans("messages.something_went_wrong");
            }

            return $output;
        }
    }

    /**
     * Returns the HTML row for a product in POS
     *
     * @param  int  $variation_id
     * @param  int  $location_id
     * @return \Illuminate\Http\Response
     */
    public function getProductRow($variation_id, $location_id)
    {
        $output = [];

        try {
            $row_count = request()->get('product_row');
            $row_count = $row_count + 1;
            $is_direct_sell = false;
            if (request()->get('is_direct_sell') == 'true') {
                $is_direct_sell = true;
            }

            $business_id = request()->session()->get('user.business_id');

            $business_details = $this->businessUtil->getDetails($business_id);
            $quantity = 1;

            //Check for weighing scale barcode
            $weighing_barcode = request()->get('weighing_scale_barcode');
            if ($variation_id == 'null' && !empty($weighing_barcode)) {
                $product_details = $this->__parseWeighingBarcode($weighing_barcode);
                if ($product_details['success']) {
                    $variation_id = $product_details['variation_id'];
                    $quantity = $product_details['qty'];
                } else {
                    $output['success'] = false;
                    $output['msg'] = $product_details['msg'];
                    return $output;
                }
            }

            $pos_settings = empty($business_details->pos_settings) ? $this->businessUtil->defaultPosSettings() : json_decode($business_details->pos_settings, true);

            $check_qty = !empty($pos_settings['allow_overselling']) ? false : true;
            $product = $this->productUtil->getDetailsFromVariation($variation_id, $business_id, $location_id, $check_qty);
            if (!isset($product->quantity_ordered)) {
                $product->quantity_ordered = $quantity;
            }

            $product->formatted_qty_available = $this->productUtil->num_f($product->qty_available, false, null, true);

            $sub_units = $this->productUtil->getSubUnits($business_id, $product->unit_id, false, $product->product_id);

            //Get customer group and change the price accordingly
            $customer_id = request()->get('customer_id', null);
            $cg = $this->contactUtil->getCustomerGroup($business_id, $customer_id);
            $percent = (empty($cg) || empty($cg->amount)) ? 0 : $cg->amount;
            $product->default_sell_price = $product->default_sell_price + ($percent * $product->default_sell_price / 100);
            $product->sell_price_inc_tax = $product->sell_price_inc_tax + ($percent * $product->sell_price_inc_tax / 100);

            $tax_dropdown = TaxRate::forBusinessDropdown($business_id, true, true);

            $enabled_modules = $this->transactionUtil->allModulesEnabled();

            //Get lot number dropdown if enabled
            $lot_numbers = [];
            if (request()->session()->get('business.enable_lot_number') == 1 || request()->session()->get('business.enable_product_expiry') == 1) {
                $lot_number_obj = $this->transactionUtil->getLotNumbersFromVariation($variation_id, $business_id, $location_id, true);
                foreach ($lot_number_obj as $lot_number) {
                    $lot_number->qty_formated = $this->productUtil->num_f($lot_number->qty_available);
                    $lot_numbers[] = $lot_number;
                }
            }
            $product->lot_numbers = $lot_numbers;

            $purchase_line_id = request()->get('purchase_line_id');

            $price_group = request()->input('price_group');
            if (!empty($price_group)) {
                $variation_group_prices = $this->productUtil->getVariationGroupPrice($variation_id, $price_group, $product->tax_id);
                
                if (!empty($variation_group_prices['price_inc_tax'])) {
                    $product->sell_price_inc_tax = $variation_group_prices['price_inc_tax'];
                    $product->default_sell_price = $variation_group_prices['price_exc_tax'];
                }
            }

            $warranties = $this->__getwarranties();

            $output['success'] = true;

            $waiters = [];
            if ($this->productUtil->isModuleEnabled('service_staff') && !empty($pos_settings['inline_service_staff'])) {
                $waiters_enabled = true;
                $waiters = $this->productUtil->serviceStaffDropdown($business_id, $location_id);
            }

            if (request()->get('type') == 'sell-return') {
                $output['html_content'] =  view('sell_return.partials.product_row')
                ->with(compact('product', 'row_count', 'tax_dropdown', 'enabled_modules', 'sub_units'))
                ->render();
            } else {
                $is_cg = !empty($cg->id) ? true : false;
                $is_pg = !empty($price_group) ? true : false;
                $discount = $this->productUtil->getProductDiscount($product, $business_id, $location_id, $is_cg, $is_pg);
                
                if ($is_direct_sell) {
                    $edit_discount = auth()->user()->can('edit_product_discount_from_sale_screen');
                    $edit_price = auth()->user()->can('edit_product_price_from_sale_screen');
                } else {
                    $edit_discount = auth()->user()->can('edit_product_discount_from_pos_screen');
                    $edit_price = auth()->user()->can('edit_product_price_from_pos_screen');
                }

                $output['html_content'] =  view('sale_pos.product_row')
                ->with(compact('product', 'row_count', 'tax_dropdown', 'enabled_modules', 'pos_settings', 'sub_units', 'discount', 'waiters', 'edit_discount', 'edit_price', 'purchase_line_id', 'warranties', 'quantity'))
                ->render();
            }
            
            $output['enable_sr_no'] = $product->enable_sr_no;

            if ($this->transactionUtil->isModuleEnabled('modifiers')  && !$is_direct_sell) {
                $this_product = Product::where('business_id', $business_id)
                ->find($product->product_id);
                if (count($this_product->modifier_sets) > 0) {
                    $product_ms = $this_product->modifier_sets;
                    $output['html_modifier'] =  view('restaurant.product_modifier_set.modifier_for_product')
                    ->with(compact('product_ms', 'row_count'))->render();
                }
            }
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());

            $output['success'] = false;
            $output['msg'] = __('lang_v1.item_out_of_stock');
        }

        return $output;
    }

    /**
     * Returns the HTML row for a payment in POS
     *
     * @param  \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function getPaymentRow(Request $request)
    {
        $business_id = request()->session()->get('user.business_id');
        
        $row_index = $request->input('row_index');
        $location_id = $request->input('location_id');
        $removable = true;
        $payment_types = $this->productUtil->payment_types($location_id);

        $payment_line = $this->dummyPaymentLine;

        //Accounts
        $accounts = [];
        if ($this->moduleUtil->isModuleEnabled('account')) {
            $accounts = Account::forDropdown($business_id, true, false, true);
        }

        return view('sale_pos.partials.payment_row')
        ->with(compact('payment_types', 'row_index', 'removable', 'payment_line', 'accounts'));
    }

    /**
     * Returns recent transactions
     *
     * @param  \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function getRecentTransactions(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $user_id = $request->session()->get('user.id');
        $transaction_status = $request->get('status');

        $register = $this->cashRegisterUtil->getCurrentCashRegister($user_id);

        $query = Transaction::where('business_id', $business_id)
        ->where('transactions.created_by', $user_id)
        ->where('transactions.type', 'sell')
        ->where('is_direct_sale', 0);

        if ($transaction_status == 'final') {
            if (!empty($register->id)) {
                $query->leftjoin('cash_register_transactions as crt', 'transactions.id', '=', 'crt.transaction_id')
                ->where('crt.cash_register_id', $register->id);
            }
        }

        if ($transaction_status == 'quotation') {
            $query->where('transactions.status', 'draft')
            ->where('is_quotation', 1);
        } elseif ($transaction_status == 'draft') {
            $query->where('transactions.status', 'draft')
            ->where('is_quotation', 0);
        } else {
            $query->where('transactions.status', $transaction_status);
        }

        $transaction_sub_type = $request->get('transaction_sub_type');
        if (!empty($transaction_sub_type)) {
            $query->where('transactions.sub_type', $transaction_sub_type);
        } else {
            $query->where('transactions.sub_type', null);
        }

        $transactions = $query->orderBy('transactions.created_at', 'desc')
        ->groupBy('transactions.id')
        ->select('transactions.*')
        ->with(['contact'])
        ->limit(10)
        ->get();

        return view('sale_pos.partials.recent_transactions')
        ->with(compact('transactions', 'transaction_sub_type'));
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
                $output = ['success' => 0,
                'msg' => trans("messages.something_went_wrong")
            ];

            $business_id = $request->session()->get('user.business_id');
            
            $transaction = Transaction::where('business_id', $business_id)
            ->where('id', $transaction_id)
            ->with(['location'])
            ->first();

            if (empty($transaction)) {
                return $output;
            }

            $printer_type = 'browser';
            if (!empty(request()->input('check_location')) && request()->input('check_location') == true) {
                $printer_type = $transaction->location->receipt_printer_type;
            }

            $is_package_slip = !empty($request->input('package_slip')) ? true : false;

            $receipt = $this->receiptContent($business_id, $transaction->location_id, $transaction_id, $printer_type, $is_package_slip, false);

            if (!empty($receipt)) {
                $output = ['success' => 1, 'receipt' => $receipt];
            }
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());
            
            $output = ['success' => 0,
            'msg' => trans("messages.something_went_wrong")
        ];
    }

    return $output;
}
}

    /**
     * Gives suggetion for product based on category
     *
     * @param  \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function getProductSuggestion(Request $request)
    {
        if ($request->ajax()) {
            $category_id = $request->get('category_id');
            $brand_id = $request->get('brand_id');
            $location_id = $request->get('location_id');
            $term = $request->get('term');

            $check_qty = false;
            $business_id = $request->session()->get('user.business_id');

            $products = Variation::join('products as p', 'variations.product_id', '=', 'p.id')
            ->join('product_locations as pl', 'pl.product_id', '=', 'p.id')
            ->leftjoin(
                'variation_location_details AS VLD',
                function ($join) use ($location_id) {
                    $join->on('variations.id', '=', 'VLD.variation_id');

                        //Include Location
                    if (!empty($location_id)) {
                        $join->where(function ($query) use ($location_id) {
                            $query->where('VLD.location_id', '=', $location_id);
                                //Check null to show products even if no quantity is available in a location.
                                //TODO: Maybe add a settings to show product not available at a location or not.
                            $query->orWhereNull('VLD.location_id');
                        });
                        ;
                    }
                }
            )
            ->where('p.business_id', $business_id)
            ->where('p.type', '!=', 'modifier')
            ->where('p.is_inactive', 0)
            ->where('p.not_for_selling', 0)
                        //Hide products not available in the selected location
            ->where(function ($q) use ($location_id) {
                $q->where('pl.location_id', $location_id);
            });

            //Include search
            if (!empty($term)) {
                $products->where(function ($query) use ($term) {
                    $query->where('p.name', 'like', '%' . $term .'%');
                    $query->orWhere('sku', 'like', '%' . $term .'%');
                    $query->orWhere('sub_sku', 'like', '%' . $term .'%');
                });
            }

            //Include check for quantity
            if ($check_qty) {
                $products->where('VLD.qty_available', '>', 0);
            }
            
            if (!empty($category_id) && ($category_id != 'all')) {
                $products->where(function ($query) use ($category_id) {
                    $query->where('p.category_id', $category_id);
                    $query->orWhere('p.sub_category_id', $category_id);
                });
            }
            if (!empty($brand_id) && ($brand_id != 'all')) {
                $products->where('p.brand_id', $brand_id);
            }

            if (!empty($request->get('is_enabled_stock'))) {
                $is_enabled_stock = 0;
                if ($request->get('is_enabled_stock') == 'product') {
                    $is_enabled_stock = 1;
                }

                $products->where('p.enable_stock', $is_enabled_stock);
            }

            if (!empty($request->get('repair_model_id'))) {
                $products->where('p.repair_model_id', $request->get('repair_model_id'));
            }

            $products = $products->select(
                'p.id as product_id',
                'p.name',
                'p.type',
                'p.enable_stock',
                'p.image as product_image',
                'variations.id',
                'variations.name as variation',
                'VLD.qty_available',
                'variations.default_sell_price as selling_price',
                'variations.sub_sku'
            )
            ->with(['media'])
            ->orderBy('p.name', 'asc')
            ->paginate(50);

            return view('sale_pos.partials.product_list')
            ->with(compact('products'));
        }
    }

    /**
     * Shows invoice url.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function showInvoiceUrl($id)
    {
        if (!auth()->user()->can('sell.update')) {
            abort(403, 'Unauthorized action.');
        }

        if (request()->ajax()) {
            $business_id = request()->session()->get('user.business_id');
            $transaction = Transaction::where('business_id', $business_id)
            ->findorfail($id);
            $url = $this->transactionUtil->getInvoiceUrl($id, $business_id);

            return view('sale_pos.partials.invoice_url_modal')
            ->with(compact('transaction', 'url'));
        }
    }

    /**
     * Shows invoice to guest user.
     *
     * @param  string  $token
     * @return \Illuminate\Http\Response
     */
    public function showInvoice($token)
    {
        $transaction = Transaction::where('invoice_token', $token)->with(['business'])->first();

        if (!empty($transaction)) {
            $receipt = $this->receiptContent($transaction->business_id, $transaction->location_id, $transaction->id, 'browser');

            $title = $transaction->business->name . ' | ' . $transaction->invoice_no;
            return view('sale_pos.partials.show_invoice')
            ->with(compact('receipt', 'title'));
        } else {
            die(__("messages.something_went_wrong"));
        }
    }

    /**
     * Display a listing of the recurring invoices.
     *
     * @return \Illuminate\Http\Response
     */
    public function listSubscriptions()
    {
        if (!auth()->user()->can('sell.view') && !auth()->user()->can('direct_sell.access')) {
            abort(403, 'Unauthorized action.');
        }

        if (request()->ajax()) {
            $business_id = request()->session()->get('user.business_id');

            $sells = Transaction::leftJoin('contacts', 'transactions.contact_id', '=', 'contacts.id')
            ->leftJoin('transaction_payments as tp', 'transactions.id', '=', 'tp.transaction_id')
            ->join(
                'business_locations AS bl',
                'transactions.location_id',
                '=',
                'bl.id'
            )
            ->where('transactions.business_id', $business_id)
            ->where('transactions.type', 'sell')
            ->where('transactions.status', 'final')
            ->where('transactions.is_recurring', 1)
            ->select(
                'transactions.id',
                'transactions.transaction_date',
                'transactions.is_direct_sale',
                'transactions.invoice_no',
                'contacts.name',
                'transactions.subscription_no',
                'bl.name as business_location',
                'transactions.recur_parent_id',
                'transactions.recur_stopped_on',
                'transactions.is_recurring',
                'transactions.recur_interval',
                'transactions.recur_interval_type',
                'transactions.recur_repetitions',
                'transactions.subscription_repeat_on'
            )->with(['subscription_invoices']);



            $permitted_locations = auth()->user()->permitted_locations();
            if ($permitted_locations != 'all') {
                $sells->whereIn('transactions.location_id', $permitted_locations);
            }

            if (!empty(request()->start_date) && !empty(request()->end_date)) {
                $start = request()->start_date;
                $end =  request()->end_date;
                $sells->whereDate('transactions.transaction_date', '>=', $start)
                ->whereDate('transactions.transaction_date', '<=', $end);
            }
            if (!empty(request()->contact_id)) {
                $sells->where('transactions.contact_id', request()->contact_id);
            }
            $datatable = Datatables::of($sells)
            ->addColumn(
                'action',
                function ($row) {
                    $html = '' ;

                    if ($row->is_recurring == 1 && auth()->user()->can("sell.update")) {
                        $link_text = !empty($row->recur_stopped_on) ? __('lang_v1.start_subscription') : __('lang_v1.stop_subscription');
                        $link_class = !empty($row->recur_stopped_on) ? 'btn-success' : 'btn-danger';

                        $html .= '<a href="' . action('SellPosController@toggleRecurringInvoices', [$row->id]) . '" class="toggle_recurring_invoice btn btn-xs ' . $link_class . '"><i class="fa fa-power-off"></i> ' . $link_text . '</a>';

                        if ($row->is_direct_sale == 0) {
                            $html .= '<a target="_blank" class="btn btn-xs btn-primary" href="' . action('SellPosController@edit', [$row->id]) . '"><i class="glyphicon glyphicon-edit"></i> ' . __("messages.edit") . '</a>';
                        } else {
                            $html .= '<a target="_blank" class="btn btn-xs btn-primary" href="' . action('SellController@edit', [$row->id]) . '"><i class="glyphicon glyphicon-edit"></i> ' . __("messages.edit") . '</a>';
                        }

                        if (auth()->user()->can("direct_sell.delete") || auth()->user()->can("sell.delete")) {
                            $html .= '&nbsp;<a href="' . action('SellPosController@destroy', [$row->id]) . '" class="delete-sale btn btn-xs btn-danger"><i class="fas fa-trash"></i> ' . __("messages.delete") . '</a>';
                        }
                    }

                    return $html;
                }
            )
            ->removeColumn('id')
            ->editColumn('transaction_date', '{{@format_date($transaction_date)}}')
            ->editColumn('recur_interval', function ($row) {
                $type = $row->recur_interval == 1 ? Str::singular(__('lang_v1.' . $row->recur_interval_type)) : __('lang_v1.' . $row->recur_interval_type);
                return $row->recur_interval . $type;
            })
            ->editColumn('recur_repetitions', function ($row) {
                return !empty($row->recur_repetitions) ? $row->recur_repetitions : '-';
            })
            ->addColumn('subscription_invoices', function ($row) {
                $invoices = [];
                if (!empty($row->subscription_invoices)) {
                    $invoices = $row->subscription_invoices->pluck('invoice_no')->toArray();
                }

                $html = '';
                $count = 0;
                if (!empty($invoices)) {
                    $imploded_invoices = '<span class="label bg-info">' . implode('</span>, <span class="label bg-info">', $invoices) . '</span>';
                    $count = count($invoices);
                    $html .= '<small>' . $imploded_invoices . '</small>';
                }
                if ($count > 0) {
                    $html .= '<br><small class="text-muted">' .
                    __('sale.total') . ': ' . $count . '</small>';
                }

                return $html;
            })
            ->addColumn('last_generated', function ($row) {
                if (!empty($row->subscription_invoices)) {
                    $last_generated_date = $row->subscription_invoices->max('created_at');
                }
                return !empty($last_generated_date) ? $last_generated_date->diffForHumans() : '';
            })
            ->addColumn('upcoming_invoice', function ($row) {
                if (empty($row->recur_stopped_on)) {
                    $last_generated = !empty(count($row->subscription_invoices)) ? \Carbon::parse($row->subscription_invoices->max('transaction_date')) : \Carbon::parse($row->transaction_date);
                    $last_generated_string = $last_generated->format('Y-m-d');
                    $last_generated = \Carbon::parse($last_generated_string);

                    if ($row->recur_interval_type == 'days') {
                        $upcoming_invoice = $last_generated->addDays($row->recur_interval);
                    } elseif ($row->recur_interval_type == 'months') {
                        if (!empty($row->subscription_repeat_on)) {
                            $last_generated_string = $last_generated->format('Y-m');
                            $last_generated = \Carbon::parse($last_generated_string . '-' . $row->subscription_repeat_on);
                        }

                        $upcoming_invoice = $last_generated->addMonths($row->recur_interval);
                    } elseif ($row->recur_interval_type == 'years') {
                        $upcoming_invoice = $last_generated->addYears($row->recur_interval);
                    }
                }
                return !empty($upcoming_invoice) ? $this->transactionUtil->format_date($upcoming_invoice) : '';
            })
            ->rawColumns(['action', 'subscription_invoices'])
            ->make(true);
            
            return $datatable;
        }
        return view('sale_pos.subscriptions');
    }

    /**
     * Starts or stops a recurring invoice.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function toggleRecurringInvoices($id)
    {
        if (!auth()->user()->can('sell.create')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $business_id = request()->session()->get('user.business_id');
            $transaction = Transaction::where('business_id', $business_id)
            ->where('type', 'sell')
            ->where('is_recurring', 1)
            ->findorfail($id);

            if (empty($transaction->recur_stopped_on)) {
                $transaction->recur_stopped_on = \Carbon::now();
            } else {
                $transaction->recur_stopped_on = null;
            }
            $transaction->save();

            $output = ['success' => 1,
            'msg' => trans("lang_v1.updated_success")
        ];
    } catch (\Exception $e) {
        \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());
        
        $output = ['success' => 0,
        'msg' => trans("messages.something_went_wrong")
    ];
}

return $output;
}

public function getRewardDetails(Request $request)
{
    if ($request->session()->get('business.enable_rp') != 1) {
        return '';
    }

    $business_id = request()->session()->get('user.business_id');
    
    $customer_id = $request->input('customer_id');

    $redeem_details = $this->transactionUtil->getRewardRedeemDetails($business_id, $customer_id);
    
    return json_encode($redeem_details);
}

public function placeOrdersApi(Request $request)
{
    try {
        $api_token = $request->header('API-TOKEN');
        $api_settings = $this->moduleUtil->getApiSettings($api_token);

        $business_id = $api_settings->business_id;
        $location_id = $api_settings->location_id;

        $input = $request->only(['products', 'customer_id', 'addresses']);

            //check if all stocks are available
        $variation_ids = [];
        foreach ($input['products'] as $product_data) {
            $variation_ids[] = $product_data['variation_id'];
        }

        $variations_details = $this->getVariationsDetails($business_id, $location_id, $variation_ids);
        $is_valid = true;
        $error_messages = [];
        $sell_lines = [];
        $final_total = 0;
        foreach ($variations_details as $variation_details) {
            if ($variation_details->product->enable_stock == 1) {
                if (empty($variation_details->variation_location_details[0]) || $variation_details->variation_location_details[0]->qty_available < $input['products'][$variation_details->id]['quantity']) {
                    $is_valid = false;
                    $error_messages[] = 'Only ' . $variation_details->variation_location_details[0]->qty_available . ' ' . $variation_details->product->unit->short_name . ' of '. $input['products'][$variation_details->id]['product_name'] . ' available';
                }
            }

                //Create product line array
            $sell_lines[] = [
                'product_id' => $variation_details->product->id,
                'unit_price_before_discount' => $variation_details->unit_price_inc_tax,
                'unit_price' => $variation_details->unit_price_inc_tax,
                'unit_price_inc_tax' => $variation_details->unit_price_inc_tax,
                'variation_id' => $variation_details->id,
                'quantity' => $input['products'][$variation_details->id]['quantity'],
                'item_tax' => 0,
                'enable_stock' => $variation_details->product->enable_stock,
                'tax_id' => null,
            ];

            $final_total += ($input['products'][$variation_details->id]['quantity'] * $variation_details->unit_price_inc_tax);
        }

        if (!$is_valid) {
            return $this->respond([
                'success' => false,
                'error_messages' => $error_messages
            ]);
        }

        $business = Business::find($business_id);
        $user_id = $business->owner_id;

        $business_data = [
            'id' => $business_id,
            'accounting_method' => $business->accounting_method,
            'location_id' => $location_id
        ];

        $customer = Contact::where('business_id', $business_id)
        ->whereIn('type', ['customer', 'both'])
        ->find($input['customer_id']);

        $order_data = [
            'business_id' => $business_id,
            'location_id' => $location_id,
            'contact_id' => $input['customer_id'],
            'final_total' => $final_total,
            'created_by' => $user_id,
            'status' => 'final',
            'payment_status' => 'due',
            'additional_notes' => '',
            'transaction_date' => \Carbon::now(),
            'customer_group_id' => $customer->customer_group_id,
            'tax_rate_id' => null,
            'sale_note' => null,
            'commission_agent' => null,
            'order_addresses' => json_encode($input['addresses']),
            'products' => $sell_lines,
            'is_created_from_api' => 1,
            'discount_type' => 'fixed',
            'discount_amount' => 0
        ];

        $invoice_total = [
            'total_before_tax' => $final_total,
            'tax' => 0,
        ];

        DB::beginTransaction();

        $transaction = $this->transactionUtil->createSellTransaction($business_id, $order_data, $invoice_total, $user_id, false);

            //Create sell lines
        $this->transactionUtil->createOrUpdateSellLines($transaction, $order_data['products'], $order_data['location_id'], false, null, [], false);

            //update product stock
        foreach ($order_data['products'] as $product) {
            if ($product['enable_stock']) {
                $this->productUtil->decreaseProductQuantity(
                    $product['product_id'],
                    $product['variation_id'],
                    $order_data['location_id'],
                    $product['quantity']
                );
            }
        }

        $this->transactionUtil->mapPurchaseSell($business_data, $transaction->sell_lines, 'purchase');
            //Auto send notification
        $this->notificationUtil->autoSendNotification($business_id, 'new_sale', $transaction, $transaction->contact);

        DB::commit();

        $receipt = $this->receiptContent($business_id, $transaction->location_id, $transaction->id);

        $output = [
            'success' => 1,
            'transaction' => $transaction,
            'receipt' => $receipt
        ];
    } catch (\Exception $e) {
        DB::rollBack();

        \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());
        $msg = trans("messages.something_went_wrong");
        
        if (get_class($e) == \App\Exceptions\PurchaseSellMismatch::class) {
            $msg = $e->getMessage();
        }

        $output = ['success' => 0,
        'error_messages' => [$msg]
    ];
}

return $this->respond($output);
}

private function getVariationsDetails($business_id, $location_id, $variation_ids)
{
    $variation_details = Variation::whereIn('id', $variation_ids)
    ->with([
        'product' => function ($q) use ($business_id) {
            $q->where('business_id', $business_id);
        },
        'product.unit',
        'variation_location_details' => function ($q) use ($location_id) {
            $q->where('location_id', $location_id);
        }
    ])->get();

    return $variation_details;
}

public function getTypesOfServiceDetails(Request $request)
{
    $location_id = $request->input('location_id');
    $types_of_service_id = $request->input('types_of_service_id');

    $business_id = $request->session()->get('user.business_id');

    $types_of_service = TypesOfService::where('business_id', $business_id)
    ->where('id', $types_of_service_id)
    ->first();

    $price_group_id = !empty($types_of_service->location_price_group[$location_id])
    ? $types_of_service->location_price_group[$location_id] : '';
    $price_group_name = '';
    
    if (!empty($price_group_id)) {
        $price_group = SellingPriceGroup::find($price_group_id);
        $price_group_name = $price_group->name;
    }

    $modal_html = view('types_of_service.pos_form_modal')
    ->with(compact('types_of_service'))->render();

    return $this->respond([
        'price_group_id' => $price_group_id,
        'packing_charge' => $types_of_service->packing_charge,
        'packing_charge_type' => $types_of_service->packing_charge_type,
        'modal_html' => $modal_html,
        'price_group_name' => $price_group_name
    ]);
}

private function __getwarranties()
{
    $business_id = session()->get('user.business_id');
    $common_settings = session()->get('business.common_settings');
    $is_warranty_enabled = !empty($common_settings['enable_product_warranty']) ? true : false;
    $warranties = $is_warranty_enabled ? Warranty::forDropdown($business_id) : [];
    return $warranties;
}

    /**
     * Parse the weighing barcode.
     *
     * @return array
     */
    private function __parseWeighingBarcode($scale_barcode)
    {
        $business_id = session()->get('user.business_id');

        $scale_setting = session()->get('business.weighing_scale_setting');

        $error_msg = trans("messages.something_went_wrong");

        //Check for prefix.
        if ((strlen($scale_setting['label_prefix']) == 0) || Str::startsWith($scale_barcode, $scale_setting['label_prefix'])) {
            $scale_barcode = substr($scale_barcode, strlen($scale_setting['label_prefix']));

            //Get product sku, trim left side 0
            $sku = ltrim(substr($scale_barcode, 0, $scale_setting['product_sku_length']+1), '0');

            //Get quantity integer
            $qty_int = substr($scale_barcode, $scale_setting['product_sku_length']+1, $scale_setting['qty_length']+1);

            //Get quantity decimal
            $qty_decimal = '0.' . substr($scale_barcode, $scale_setting['product_sku_length'] + $scale_setting['qty_length'] + 2, $scale_setting['qty_length_decimal']+1);

            $qty = (float)$qty_int + (float)$qty_decimal;

            //Find the variation id
            $result = $this->productUtil->filterProduct($business_id, $sku, null, false, null, [], ['sub_sku'], false)->first();

            if (!empty($result)) {
                return ['variation_id' => $result->variation_id,
                'qty' => $qty,
                'success' => true
            ];
        } else {
            $error_msg = trans("lang_v1.sku_not_match", ['sku' => $sku]);
        }
    } else {
        $error_msg = trans("lang_v1.prefix_did_not_match");
    }

    return [
        'success' => false,
        'msg' => $error_msg
    ];
}
}