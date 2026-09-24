<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\PcbOrder;
use App\Models\PcbOrderMeta;
use App\Models\PcbOrderStatusHistory;
use App\Services\OrderImportService;
use App\Services\OrderExportService;

class OrderController extends Controller
{
    public function store(Request $request)
    {
        // Validate the request
        $validator = Validator::make($request->all(), [
            'user_id' => 'nullable|integer',
            'board_name' => 'required|string|max:200',
            'user_mobile' => 'required|string',
            'user_email' => 'required|email|max:200',
            'customer_name' => 'nullable|string|max:200',
            'unit_price' => 'nullable|numeric|min:0',
            'order_value' => 'nullable|numeric|min:0',
            'delivery_date' => 'nullable|date',
            'gerber_file' => 'nullable|file|mimes:zip,gz,rar,7z|max:10240',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        if ($request->filled('delivery_date')) {
            $validation = \App\Services\DeliveryCalendarService::validateDeliveryDate($request->delivery_date);
            if (!$validation['valid']) {
                return response()->json([
                    'success' => false,
                    'message' => $validation['reason']
                ], 400);
            }
        }

        try {
            // Find or create PcbUser by email
            $userId = $request->user_id;
            if (!$userId && $request->user_email) {
                $user = \App\Models\PcbUser::where('email', $request->user_email)->first();
                if (!$user) {
                    $user = \App\Models\PcbUser::create([
                        'email' => $request->user_email,
                        'name' => $request->customer_name ?? strtok($request->user_email, '@'),
                        'mobile' => $request->user_mobile,
                        'gst_number' => $request->gst_number ?? null,
                        'status' => 'active',
                    ]);
                } else {
                    // Update mobile or name if missing
                    if (empty($user->mobile) && $request->user_mobile) {
                        $user->mobile = $request->user_mobile;
                        $user->save();
                    }
                }
                $userId = $user->id;
            }

            $reqSource = strtolower(trim((string)($request->input('quotation_source') ?? $request->input('order_type') ?? '')));
            if (empty($reqSource)) {
                $layersNum = (int) preg_replace('/[^0-9]/', '', (string)($request->input('layers') ?? '2'));
                $reqSource = ($layersNum > 2 || $request->filled('jlcpcb_file_key')) ? 'jlcpcb' : 'internal';
            }
            $reqOrderType = ($reqSource === 'jlcpcb') ? 'jlcpcb' : 'normal';
            $series = ($reqOrderType === 'jlcpcb') ? 'J' : 'M';
            $orderNumber = \App\Services\OrderNumberService::generateOrderNumber($series);

            // Handle file upload
            $gerberFileUrl = null;
            $gerberFileName = null;
            $gerberFileSize = null;

            if ($request->hasFile('gerber_file')) {
                $file = $request->file('gerber_file');
                $fileName = time() . '_' . Str::random(10) . '.' . $file->getClientOriginalExtension();
                $filePath = $file->storeAs('gerber-files', $fileName, 'public');
                
                $gerberFileUrl = Storage::url($filePath);
                $gerberFileName = $file->getClientOriginalName();
                $gerberFileSize = $this->formatFileSize($file->getSize());
            }

            // Determine C/G status based on customer GST number
            $customerGst = null;
            if ($userId) {
                $userRecord = \App\Models\PcbUser::find($userId);
                if ($userRecord && !empty($userRecord->gst_number)) {
                    $customerGst = trim($userRecord->gst_number);
                }
            }
            if (empty($customerGst) && !empty($request->gst_number)) {
                $customerGst = trim($request->gst_number);
            }
            if (empty($customerGst) && !empty($request->gstin)) {
                $customerGst = trim($request->gstin);
            }

            $cgStatus = (!empty($customerGst) && strtolower($customerGst) !== 'null' && strtolower($customerGst) !== 'undefined') ? 'GST' : 'Cash';

            // Create the main compact order record
            $order = PcbOrder::create([
                'user_id' => $userId,
                'order_number' => $orderNumber,
                'board_name' => $request->board_name,
                'customer_name' => $request->customer_name,
                'user_email' => $request->user_email,
                'user_mobile' => $request->user_mobile,
                'c_g' => $request->c_g ?? $cgStatus,
                'status' => 'pending',
                'unit_price' => $request->unit_price ?? 0,
                'order_value' => $request->order_value ?? 0,
                'delivery_date' => $request->delivery_date,
            ]);

            // Save all additional specification attributes into pcb_order_meta
            $metaData = $request->except([
                'user_id', 'order_number', 'board_name', 'customer_name', 'user_email', 'user_mobile',
                'status', 'unit_price', 'order_value', 'delivery_date', 'gerber_file'
            ]);

            if ($gerberFileUrl) {
                $metaData['gerber_file_url'] = $gerberFileUrl;
                $metaData['gerber_file_name'] = $gerberFileName;
                $metaData['gerber_file_size'] = $gerberFileSize;
            }

            $metaData['ip_address'] = $request->ip();
            $metaData['user_agent'] = $request->userAgent();

            foreach ($metaData as $key => $value) {
                if ($value !== null && $value !== '') {
                    PcbOrderMeta::create([
                        'pcb_order_id' => $order->id,
                        'meta_key' => $key,
                        'meta_value' => is_array($value) ? json_encode($value) : (string)$value,
                    ]);
                }
            }

            // Dispatch order_placed email notification
            \App\Services\EmailTemplateService::sendOrderEmail('order_placed', $order->id);

            return response()->json([
                'success' => true,
                'message' => 'Order submitted successfully',
                'data' => [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'status' => $order->status,
                    'total_value' => $order->order_value,
                    'delivery_date' => $order->delivery_date ? \Carbon\Carbon::parse($order->delivery_date)->format('M d, Y') : null,
                    'board_name' => $order->board_name,
                    'user_email' => $order->user_email,
                    'user_mobile' => $order->user_mobile,
                ]
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to submit order',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function formatFileSize($bytes)
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } elseif ($bytes > 1) {
            return $bytes . ' bytes';
        } elseif ($bytes == 1) {
            return '1 byte';
        } else {
            return '0 bytes';
        }
    }

    public function index(Request $request)
    {
        try {
            $hasStatusTable = \Illuminate\Support\Facades\Cache::rememberForever('schema_has_status_table', function () {
                return \Illuminate\Support\Facades\Schema::hasTable('pcb_order_statuses') || \Illuminate\Support\Facades\Schema::hasTable('pcb_statuses');
            });

            $hasBoardNameCol = \Illuminate\Support\Facades\Cache::rememberForever('schema_has_board_name_col', function () {
                return \Illuminate\Support\Facades\Schema::hasColumn('pcb_orders', 'board_name');
            });

            $hasPaymentTxTable = \Illuminate\Support\Facades\Cache::rememberForever('schema_has_payment_tx_table', function () {
                return \Illuminate\Support\Facades\Schema::hasTable('payment_transactions');
            });

            $allowedMetaKeys = [
                'gerber_file_name', 'gerber_name', 'file_name', 'gerber_file',
                'gerber_file_url', 'gerber_url', 'gerber_path',
                'payment_id', 'razorpay_payment_id', 'transaction_id',
                'payment_status', 'payment_mode', 'payment_method',
                'film_datetime', 'film_date',
                'qty', 'quantity', 'product_type',
                'pcb_color', 'solder_mask', 'layer', 'layers',
                'min_hole', 'min_hole_size', 'panel_size', 'dimensions',
                'cutting_size', 'material', 'base_material', 'board_thickness',
                'thickness', 'copper_thickness', 'copper_weight',
                'surface_finish', 'finish', 'legend_color', 'silkscreen',
                'silkscreen_side', 'legend_side', 'route', 'routing',
                'v_cut', 'fpt_program', 'second_stage', 'copper_area',
                'tool', 'quote_number', 'p_n', 'part_number', 'ups', 'panels',
                'jlcpcb_file_key', 'quotation_source', 'order_type', 'jlcpcb_price', 'jlcpcb_quote_id', 'jlcpcb_quotation_snapshot', 'jlcpcb_quote'
            ];

            $withRelations = [
                'metas' => function ($mq) use ($allowedMetaKeys) {
                    $mq->select(['id', 'pcb_order_id', 'meta_key', 'meta_value'])
                       ->whereIn('meta_key', $allowedMetaKeys);
                },
                'user'
            ];
            if ($hasStatusTable) {
                $withRelations[] = 'statusDetails';
            }

            $query = PcbOrder::with($withRelations);

            // Status Filter
            if ($request->filled('status')) {
                $statusParam = trim($request->input('status'));
                if (strtolower($statusParam) === 'in production') {
                    $excluded = ['pending', 'completed', 'shipped', 'delivered', 'cancelled', 'canceled'];
                    $query->whereNotIn('status', $excluded);
                } else if (strtolower($statusParam) !== 'all') {
                    $statusLower = strtolower($statusParam);
                    $query->whereRaw('LOWER(TRIM(status)) = ?', [$statusLower]);
                }
            }

            // Date Range Filtering
            if ($request->filled('start_date')) {
                $query->whereDate('created_at', '>=', $request->start_date);
            }
            if ($request->filled('end_date')) {
                $query->whereDate('created_at', '<=', $request->end_date);
            }

            // Search Filter (by Order #, Board Name, Email, Mobile, Customer Name, User/Company, Metas, Razorpay Payment IDs)
            if ($request->filled('search')) {
                $search = trim($request->input('search'));
                $query->where(function ($q) use ($search, $hasBoardNameCol, $hasPaymentTxTable) {
                    $q->where('order_number', 'LIKE', "%{$search}%")
                        ->orWhere('user_email', 'LIKE', "%{$search}%")
                        ->orWhere('user_mobile', 'LIKE', "%{$search}%")
                        ->orWhere('customer_name', 'LIKE', "%{$search}%");
                    if ($hasBoardNameCol) {
                        $q->orWhere('board_name', 'LIKE', "%{$search}%");
                    }
                    $q->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('name', 'LIKE', "%{$search}%")
                            ->orWhere('company_name', 'LIKE', "%{$search}%")
                            ->orWhere('email', 'LIKE', "%{$search}%")
                            ->orWhere('mobile', 'LIKE', "%{$search}%");
                    });
                    $q->orWhereHas('metas', function ($mq) use ($search) {
                        $mq->where('meta_value', 'LIKE', "%{$search}%");
                    });
                    if ($hasPaymentTxTable) {
                        $q->orWhereIn('transaction_id', function ($tq) use ($search) {
                            $tq->select('id')->from('payment_transactions')
                                ->where('transaction_number', 'LIKE', "%{$search}%")
                                ->orWhere('razorpay_payment_id', 'LIKE', "%{$search}%")
                                ->orWhere('razorpay_order_id', 'LIKE', "%{$search}%");
                        });
                    }
                });
            }

            // Sorting (Default: delivery_date desc)
            $sortBy = $request->input('sort_by', 'delivery_date');
            $sortOrder = strtolower($request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';

            if ($sortBy === 'delivery_date') {
                $query->orderByRaw("COALESCE(delivery_date, created_at) {$sortOrder}");
            } else {
                $query->orderBy($sortBy, $sortOrder);
            }

            if ($request->filled('limit')) {
                $query->take(max(1, intval($request->input('limit'))));
            } else if ($request->filled('per_page') && $request->input('per_page') !== 'all') {
                $query->take(max(1, intval($request->input('per_page'))));
            }

            $orders = $query->get();

            $orders->transform(function ($order) {
                if (isset($order->statusDetails) && !empty($order->statusDetails->name)) {
                    $order->status = $order->statusDetails->name;
                } else if (empty($order->status)) {
                    $order->status = 'Pending';
                }
                return $order;
            });

            return response()->json([
                'status' => true,
                'data' => $orders
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => false,
                'message' => $th->getMessage(),
                'data' => []
            ], 500);
        }
    }

    public function show($id)
    {
        $withRelations = ['metas'];
        if (\Illuminate\Support\Facades\Schema::hasTable('pcb_order_statuses') || \Illuminate\Support\Facades\Schema::hasTable('pcb_statuses')) {
            $withRelations[] = 'statusDetails';
        }
        if (\Illuminate\Support\Facades\Schema::hasTable('pcb_order_status_histories')) {
            $withRelations[] = 'statusHistories';
        }
        if (\Illuminate\Support\Facades\Schema::hasTable('users') || \Illuminate\Support\Facades\Schema::hasTable('pcb_users')) {
            $withRelations[] = 'user';
        }

        $orderQuery = PcbOrder::with($withRelations)
            ->leftJoin('user_addresses as ship', 'pcb_orders.shipping_address_id', '=', 'ship.id')
            ->leftJoin('user_addresses as bill', 'pcb_orders.billing_address_id', '=', 'bill.id')
            ->where(function ($q) use ($id) {
                if (is_numeric($id)) {
                    $q->where('pcb_orders.id', $id)->orWhere('pcb_orders.order_number', $id);
                } else {
                    $q->where('pcb_orders.order_number', $id);
                }
            })
            ->select(
                'pcb_orders.*',
                'ship.first_name as shipping_first_name',
                'ship.last_name as shipping_last_name',
                'ship.company_name as shipping_company',
                'ship.building_no as shipping_building_no',
                'ship.street_address as shipping_street',
                'ship.city as shipping_city',
                'ship.state as shipping_state',
                'ship.postal_code as shipping_postal',
                'ship.country as shipping_country',
                'ship.mobile as shipping_mobile',
                'bill.first_name as billing_first_name',
                'bill.last_name as billing_last_name',
                'bill.company_name as billing_company',
                'bill.building_no as billing_building_no',
                'bill.street_address as billing_street',
                'bill.city as billing_city',
                'bill.state as billing_state',
                'bill.postal_code as billing_postal',
                'bill.country as billing_country',
                'bill.mobile as billing_mobile'
            );

        $order = $orderQuery->firstOrFail();
        
        // Also load internal notes if table exists
        if (\Illuminate\Support\Facades\Schema::hasTable('pcb_order_notes')) {
            $order->notes = \Illuminate\Support\Facades\DB::table('pcb_order_notes')
                ->leftJoin('admins', 'pcb_order_notes.admin_id', '=', 'admins.id')
                ->where('pcb_order_notes.pcb_order_id', $id)
                ->select(
                    'pcb_order_notes.*',
                    'admins.name as admin_name',
                    'admins.username as admin_username'
                )
                ->orderBy('pcb_order_notes.created_at', 'desc')
                ->get();
        } else {
            $order->notes = [];
        }

        // Also load activity logs if table exists
        if (\Illuminate\Support\Facades\Schema::hasTable('pcb_order_logs')) {
            $logsQuery = \Illuminate\Support\Facades\DB::table('pcb_order_logs')
                ->where('pcb_order_logs.pcb_order_id', $id)
                ->orWhere('pcb_order_logs.order_number', $order->order_number);

            if (\Illuminate\Support\Facades\Schema::hasTable('admins')) {
                $logsQuery->leftJoin('admins', 'pcb_order_logs.admin_id', '=', 'admins.id');
            }
            if (\Illuminate\Support\Facades\Schema::hasTable('users')) {
                $logsQuery->leftJoin('users', 'pcb_order_logs.user_id', '=', 'users.id');
            }

            $order->logs = $logsQuery->select(
                'pcb_order_logs.*',
                \Illuminate\Support\Facades\DB::raw('COALESCE(admins.name, users.name, NULL) as resolved_user_name'),
                \Illuminate\Support\Facades\DB::raw('admins.name as admin_name'),
                \Illuminate\Support\Facades\DB::raw('users.name as user_name')
            )->orderBy('pcb_order_logs.created_at', 'desc')->get();
        } else {
            $order->logs = [];
        }

        $previewMeta = $order->metas->where('meta_key', 'preview_data')->first();
        if ($previewMeta) {
            $order->gerber_preview_data = $previewMeta->meta_value;
        } else if (!empty($order->gerber_file_id) && \Illuminate\Support\Facades\Schema::hasTable('gerber_files')) {
            $gf = \Illuminate\Support\Facades\DB::table('gerber_files')->where('id', $order->gerber_file_id)->first();
            if ($gf && !empty($gf->preview_data)) {
                $order->gerber_preview_data = $gf->preview_data;
            }
        }

        if (empty($order->bill_number)) {
            $order->bill_number = $order->getMeta('bill_number');
        }

        return response()->json([
            'status' => true,
            'data' => $order
        ]);
    }

    public function update(Request $request, $id)
    {
        try {
            $order = PcbOrder::where(function ($q) use ($id) {
                if (is_numeric($id)) {
                    $q->where('id', $id)->orWhere('order_number', $id);
                } else {
                    $q->where('order_number', $id);
                }
            })->firstOrFail();
            $adminId = $request->input('admin_id') ?: $request->attributes->get('admin_id');
            $remark = $request->input('remark', null);
            
            $hasCustomerNameCol = \Illuminate\Support\Facades\Schema::hasColumn('pcb_orders', 'customer_name');

            if ($request->has('user_id') && (string)$order->user_id !== (string)$request->user_id) {
                $oldUserId = $order->user_id ?? 'N/A';
                $order->user_id = $request->user_id ?: null;
                $changesLog[] = "Customer ID: '{$oldUserId}' → '{$request->user_id}'";
                if ($request->user_id && $hasCustomerNameCol) {
                    $userObj = \App\Models\PcbUser::find($request->user_id) ?: (\Illuminate\Support\Facades\Schema::hasTable('users') ? \App\Models\User::find($request->user_id) : null);
                    if ($userObj) {
                        $newCustName = $userObj->company_name ?: ($userObj->name ?: (isset($userObj->first_name) ? trim("{$userObj->first_name} {$userObj->last_name}") : null));
                        if ($newCustName && !$request->has('customer_name')) {
                            $order->customer_name = $newCustName;
                        }
                    }
                }
            }

            if ($hasCustomerNameCol && $request->has('customer_name') && (string)$order->customer_name !== (string)$request->customer_name) {
                $oldVal = $order->customer_name ?? 'N/A';
                $order->customer_name = $request->customer_name;
                $changesLog[] = "Customer: '{$oldVal}' → '{$request->customer_name}'";
            }

            $statusChangedToCompleted = false;
            $statusChangedFromPendingToProduction = false;
            $statusChanged = false;
            $previousStatusName = $order->status ?? 'Pending';

            if ($request->has('status') && (string)$order->status !== (string)$request->status) {
                $oldValStr = strtolower(trim((string)($order->status ?? 'Pending')));
                $newValStr = strtolower(trim((string)$request->status));
                $completedStatuses = ['completed', 'delivered', 'order completed', 'production completed'];

                if ($oldValStr !== $newValStr) {
                    $statusChanged = true;
                }

                if (!in_array($oldValStr, $completedStatuses) && in_array($newValStr, $completedStatuses)) {
                    $statusChangedToCompleted = true;
                }

                if ($oldValStr === 'pending' && $newValStr !== 'pending') {
                    $statusChangedFromPendingToProduction = true;
                }

                $oldVal = $order->status ?? 'Pending';
                $order->status = $request->status;
                $changesLog[] = "Status: '{$oldVal}' → '{$request->status}'";
                if (\Illuminate\Support\Facades\Schema::hasTable('pcb_order_statuses')) {
                    $st = \Illuminate\Support\Facades\DB::table('pcb_order_statuses')
                        ->whereRaw('LOWER(name) = ?', [strtolower($request->status)])
                        ->orWhereRaw('LOWER(label) = ?', [strtolower($request->status)])
                        ->first();
                    if ($st) {
                        $order->status_id = $st->id;
                    }
                }
            }

            if ($request->has('status_id')) {
                $order->status_id = $request->status_id;
            }

            if ($request->has('launch_date') && $order->launch_date !== $request->launch_date) {
                $order->launch_date = $request->launch_date;
            }

            if ($request->has('delivery_date') && $order->delivery_date !== $request->delivery_date) {
                $oldVal = $order->delivery_date ?? 'N/A';
                $order->delivery_date = $request->delivery_date;
                $changesLog[] = "Delivery Date: '{$oldVal}' → '{$request->delivery_date}'";
            }

            if ($request->has('bill_number') && (string)$order->bill_number !== (string)$request->bill_number) {
                $oldVal = $order->bill_number ?? 'N/A';
                $order->bill_number = $request->bill_number;
                $changesLog[] = "Bill No: '{$oldVal}' → '{$request->bill_number}'";
            }

            if ($request->has('q_no') && (string)$order->q_no !== (string)$request->q_no) {
                $oldVal = $order->q_no ?? 'N/A';
                $order->q_no = $request->q_no;
                $changesLog[] = "Q.No: '{$oldVal}' → '{$request->q_no}'";
            }

            if ($request->has('combo') && (string)$order->combo !== (string)$request->combo) {
                $oldVal = $order->combo ?? 'N/A';
                $order->combo = $request->combo;
                $changesLog[] = "Combo: '{$oldVal}' → '{$request->combo}'";
            }

            if ($request->has('c_g') && (string)$order->c_g !== (string)$request->c_g) {
                $order->c_g = $request->c_g;
            }

            if ($request->has('order_qty') && intval($order->order_qty) !== intval($request->order_qty)) {
                $oldVal = intval($order->order_qty);
                $order->order_qty = intval($request->order_qty);
                $changesLog[] = "Order Qty: {$oldVal} → {$order->order_qty} Pcs";
            }

            if ($request->has('launch_qty') && intval($order->launch_qty) !== intval($request->launch_qty)) {
                $oldVal = intval($order->launch_qty);
                $order->launch_qty = intval($request->launch_qty);
                $changesLog[] = "Launch Qty: {$oldVal} → {$order->launch_qty} Pcs";
            }

            if ($request->has('panel_qty') && intval($order->panel_qty) !== intval($request->panel_qty)) {
                $oldVal = intval($order->panel_qty);
                $order->panel_qty = intval($request->panel_qty);
                $changesLog[] = "Panel Qty: {$oldVal} → {$order->panel_qty} Pcs";
            }

            if ($request->has('ups_qty') && intval($order->ups_qty) !== intval($request->ups_qty)) {
                $oldVal = intval($order->ups_qty);
                $order->ups_qty = intval($request->ups_qty);
                $changesLog[] = "Ups Qty: {$oldVal} → {$order->ups_qty} Pcs";
            }

            if ($request->has('final_qty') && intval($order->final_qty) !== intval($request->final_qty)) {
                $oldVal = intval($order->final_qty);
                $order->final_qty = intval($request->final_qty);
                $changesLog[] = "Final Qty: {$oldVal} → {$order->final_qty} Pcs";
            }

            $oldCompletedQty = $order->completed_qty ?? 0;
            $qtyUpdated = false;
            if ($request->has('completed_qty')) {
                $newCompletedQty = intval($request->completed_qty);
                if ($newCompletedQty !== $oldCompletedQty) {
                    $order->completed_qty = $newCompletedQty;
                    $qtyUpdated = true;
                    $changesLog[] = "Completed Qty: {$oldCompletedQty} → {$newCompletedQty} Pcs";
                }
            }

            if ($request->has('failed_qty') && intval($order->failed_qty) !== intval($request->failed_qty)) {
                $oldVal = intval($order->failed_qty);
                $order->failed_qty = intval($request->failed_qty);
                $changesLog[] = "Failed Qty: {$oldVal} → {$order->failed_qty} Pcs";
                PcbOrderMeta::updateOrCreate(
                    ['pcb_order_id' => $order->id, 'meta_key' => 'failed_qty'],
                    ['meta_value' => (string)intval($request->failed_qty)]
                );
            }

            $order->save();

            // Dispatch order_status_updated email & in-app notification when status changes (previous != new)
            if ($statusChanged) {
                \App\Services\EmailTemplateService::sendOrderEmail('order_status_updated', $order->id, null, [
                    'previous_order_status' => $previousStatusName,
                ]);

                if ($order->user_id) {
                    $newStatusName = $order->status ?: 'Updated';
                    \App\Services\NotificationService::notifyUser($order->user_id, 'order.status_updated', [
                        'title' => "Order #{$order->order_number} Status Updated",
                        'message' => "Order #{$order->order_number} status changed to {$newStatusName}.",
                        'action_url' => "/orders/{$order->id}",
                        'entity_type' => 'order',
                        'entity_id' => $order->id,
                        'theme' => 'info',
                        'icon' => 'Clock',
                    ]);
                }
            }

            // Dispatch order_completed email & in-app notification if status transitioned to completed/delivered
            if ($statusChangedToCompleted) {
                \App\Services\EmailTemplateService::sendOrderEmail('order_completed', $order->id);

                if ($order->user_id) {
                    \App\Services\NotificationService::notifyUser($order->user_id, 'order.completed', [
                        'title' => "Order #{$order->order_number} Completed",
                        'message' => "Great news! Your order #{$order->order_number} has been completed and is ready.",
                        'action_url' => "/orders/{$order->id}",
                        'entity_type' => 'order',
                        'entity_id' => $order->id,
                        'theme' => 'success',
                        'icon' => 'CheckCircle2',
                    ]);
                }
            }

            // Dispatch order_production_film_not_applied email if transition was Pending -> Non-Pending and film_applied != 1
            if ($statusChangedFromPendingToProduction) {
                $freshFilmApplied = \Illuminate\Support\Facades\DB::table('pcb_orders')->where('id', $order->id)->value('film_applied');
                if ($freshFilmApplied === null && isset($order->film_applied)) {
                    $freshFilmApplied = $order->film_applied;
                }
                if ((int)$freshFilmApplied !== 1) {
                    \App\Services\EmailTemplateService::sendOrderEmail('order_production_film_not_applied', $order->id, null, [
                        'previous_order_status' => $previousStatusName,
                        'film_applied'          => 'No',
                    ]);
                }
            }

            // Handle updating order metas
            if ($request->has('metas') && is_array($request->input('metas'))) {
                foreach ($request->input('metas') as $mKey => $mValue) {
                    PcbOrderMeta::updateOrCreate(
                        ['pcb_order_id' => $order->id, 'meta_key' => $mKey],
                        ['meta_value' => (string)$mValue]
                    );
                }
            } elseif ($request->has('meta_key')) {
                PcbOrderMeta::updateOrCreate(
                    ['pcb_order_id' => $order->id, 'meta_key' => $request->input('meta_key')],
                    ['meta_value' => (string)$request->input('meta_value', '')]
                );
            }

            // 1. Create status change history log in pcb_order_status_histories
            if ($request->has('status') && \Illuminate\Support\Facades\Schema::hasTable('pcb_order_status_histories')) {
                PcbOrderStatusHistory::create([
                    'pcb_order_id' => $order->id,
                    'admin_id' => $adminId ?: 1,
                    'status_name' => $request->status,
                    'remark' => $remark,
                    'created_at' => now()
                ]);
            }

            // 2. Create activity log in pcb_order_logs with admin_id for status or field updates
            if (!empty($changesLog) && \Illuminate\Support\Facades\Schema::hasTable('pcb_order_logs')) {
                $statusName = $order->status ?? 'Pending';
                
                // Fetch admin user details for description and log record
                $effectiveAdminId = $request->input('admin_id') ?: ($request->attributes->get('admin_id') ?: null);
                $adminUser = null;
                if ($effectiveAdminId && \Illuminate\Support\Facades\Schema::hasTable('admins')) {
                    $adminUser = \Illuminate\Support\Facades\DB::table('admins')->where('id', $effectiveAdminId)->first();
                }
                
                $adminName = $adminUser ? $adminUser->name : ($request->attributes->get('admin_name') ?: ($request->input('admin_name') ?: ($effectiveAdminId ? "Admin #{$effectiveAdminId}" : "Admin")));

                $actionName = $request->has('status') ? "Status & Details Updated" : "Order Parameters Updated";
                $changesStr = implode(", ", $changesLog);
                $descText = "Updated by {$adminName}: {$changesStr}." . ($remark ? " Remark: {$remark}" : "");

                \Illuminate\Support\Facades\DB::table('pcb_order_logs')->insert([
                    'pcb_order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'user_id' => null, // Manual admin action
                    'admin_id' => $effectiveAdminId,
                    'status' => $statusName,
                    'action' => $actionName,
                    'description' => $descText,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }

            // Reload fresh order logs for response
            if (\Illuminate\Support\Facades\Schema::hasTable('pcb_order_logs')) {
                $logsQuery = \Illuminate\Support\Facades\DB::table('pcb_order_logs')
                    ->where('pcb_order_logs.pcb_order_id', $order->id)
                    ->orWhere('pcb_order_logs.order_number', $order->order_number);

                if (\Illuminate\Support\Facades\Schema::hasTable('admins')) {
                    $logsQuery->leftJoin('admins', 'pcb_order_logs.admin_id', '=', 'admins.id');
                }
                if (\Illuminate\Support\Facades\Schema::hasTable('users')) {
                    $logsQuery->leftJoin('users', 'pcb_order_logs.user_id', '=', 'users.id');
                }

                $order->logs = $logsQuery->select(
                    'pcb_order_logs.*',
                    \Illuminate\Support\Facades\DB::raw('COALESCE(admins.name, users.name, NULL) as resolved_user_name'),
                    \Illuminate\Support\Facades\DB::raw('admins.name as admin_name'),
                    \Illuminate\Support\Facades\DB::raw('users.name as user_name')
                )->orderBy('pcb_order_logs.created_at', 'desc')->get();
            }

            return response()->json([
                'status' => true,
                'message' => 'Order updated successfully',
                'data' => $order->load(['metas', 'statusDetails', 'statusHistories'])
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Order Internal Notes
    public function getNotes($id)
    {
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('pcb_order_notes')) {
                return response()->json(['status' => true, 'data' => []]);
            }

            $notes = \Illuminate\Support\Facades\DB::table('pcb_order_notes')
                ->leftJoin('admins', 'pcb_order_notes.admin_id', '=', 'admins.id')
                ->where('pcb_order_notes.pcb_order_id', $id)
                ->select(
                    'pcb_order_notes.*',
                    'admins.name as admin_name',
                    'admins.username as admin_username'
                )
                ->orderBy('pcb_order_notes.created_at', 'desc')
                ->get();

            return response()->json([
                'status' => true,
                'data' => $notes
            ]);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    public function addNote(Request $request, $id)
    {
        try {
            $noteText = $request->input('note');
            $adminId = $request->input('admin_id') ?: $request->attributes->get('admin_id');

            if (empty($noteText)) {
                return response()->json(['status' => false, 'message' => 'Note content cannot be empty.'], 400);
            }

            $noteId = \Illuminate\Support\Facades\DB::table('pcb_order_notes')->insertGetId([
                'pcb_order_id' => $id,
                'admin_id' => $adminId ?: 1,
                'note' => $noteText,
                'is_internal' => true,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Note added successfully.',
                'data' => ['id' => $noteId]
            ], 201);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    public function deleteNote($noteId)
    {
        try {
            \Illuminate\Support\Facades\DB::table('pcb_order_notes')->where('id', $noteId)->delete();
            return response()->json(['status' => true, 'message' => 'Note deleted.']);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    public function getLogs($id)
    {
        try {
            $order = PcbOrder::where(function ($q) use ($id) {
                if (is_numeric($id)) {
                    $q->where('id', $id)->orWhere('order_number', $id);
                } else {
                    $q->where('order_number', $id);
                }
            })->first();

            $orderDbId = $order ? $order->id : (is_numeric($id) ? $id : 0);

            if (!\Illuminate\Support\Facades\Schema::hasTable('pcb_order_logs')) {
                return response()->json(['status' => true, 'data' => []]);
            }

            $logs = \Illuminate\Support\Facades\DB::table('pcb_order_logs')
                ->leftJoin('admins', 'pcb_order_logs.admin_id', '=', 'admins.id')
                ->leftJoin('users', 'pcb_order_logs.user_id', '=', 'users.id')
                ->where('pcb_order_logs.pcb_order_id', $orderDbId)
                ->select(
                    'pcb_order_logs.*',
                    \Illuminate\Support\Facades\DB::raw('admins.name as admin_name'),
                    \Illuminate\Support\Facades\DB::raw('users.name as user_name')
                )
                ->orderBy('pcb_order_logs.created_at', 'desc')
                ->get();

            return response()->json([
                'status' => true,
                'data' => $logs
            ]);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    /**
     * Create a new PCB Order manually from Admin Panel
     */
    public function createAdminOrder(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'board_name' => 'required|string|max:200',
                'user_id' => 'nullable|integer',
                'customer_name' => 'nullable|string|max:200',
                'user_email' => 'nullable|email|max:200',
                'user_mobile' => 'nullable|string|max:50',
                'company_name' => 'nullable|string|max:200',
                'unit_price' => 'nullable|numeric|min:0',
                'order_value' => 'nullable|numeric|min:0',
                'delivery_date' => 'nullable|date',
                'payment_status' => 'nullable|string',
                'payment_method' => 'nullable|string',
                'gerber_file' => 'nullable|file|mimes:zip,gz,rar,7z|max:102400',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $userId = $request->input('user_id');
            if (empty($userId) || $userId == 0) {
                $userId = null;
            }

            $reqSource = strtolower(trim((string)($request->input('quotation_source') ?? $request->input('order_type') ?? '')));
            if (empty($reqSource)) {
                $layersNum = (int) preg_replace('/[^0-9]/', '', (string)($request->input('layers') ?? '2'));
                $reqSource = ($layersNum > 2 || $request->filled('jlcpcb_file_key')) ? 'jlcpcb' : 'internal';
            }
            $reqOrderType = ($reqSource === 'jlcpcb') ? 'jlcpcb' : 'normal';
            $series = ($reqOrderType === 'jlcpcb') ? 'J' : 'M';
            $orderNumber = \App\Services\OrderNumberService::generateOrderNumber($series);

            // Handle Gerber file upload
            $gerberFileId = null;
            $gerberFileUrl = null;
            $gerberFileName = null;
            $gerberFileSize = null;

            if ($request->hasFile('gerber_file')) {
                $file = $request->file('gerber_file');
                $originalName = $file->getClientOriginalName();
                $fileName = time() . '_' . Str::random(10) . '.' . $file->getClientOriginalExtension();
                $filePath = $file->storeAs('gerber-files', $fileName, 'public');
                $gerberFileUrl = Storage::url($filePath);
                $gerberFileSize = $this->formatFileSize($file->getSize());

                if (\Illuminate\Support\Facades\Schema::hasTable('gerber_files')) {
                    $gerberFileId = \Illuminate\Support\Facades\DB::table('gerber_files')->insertGetId([
                        'user_id' => $userId ?? 0, // store as client_id = 0 if guest/null
                        'original_name' => $originalName,
                        'file_name' => $fileName,
                        'file_path' => $filePath,
                        'file_url' => $gerberFileUrl,
                        'file_size' => $gerberFileSize,
                        'board_name' => $request->input('board_name', pathinfo($originalName, PATHINFO_FILENAME)),
                        'preview_data' => $request->input('preview_data', null),
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
                }
                $gerberFileName = $originalName;
            } else if ($request->filled('gerber_file_id')) {
                $gerberFileId = $request->input('gerber_file_id');
                if ($request->filled('preview_data') && \Illuminate\Support\Facades\Schema::hasTable('gerber_files')) {
                    \Illuminate\Support\Facades\DB::table('gerber_files')
                        ->where('id', $gerberFileId)
                        ->update([
                            'preview_data' => $request->input('preview_data'),
                            'updated_at' => date('Y-m-d H:i:s')
                        ]);
                }
            }

            // Handle Manual Payment
            $transactionId = null;
            $isPaid = strtolower($request->input('payment_status', 'pending')) === 'completed' || strtolower($request->input('payment_status', 'pending')) === 'paid';

            if ($isPaid && \Illuminate\Support\Facades\Schema::hasTable('payment_transactions')) {
                $orderVal = floatval($request->input('order_value', 0));
                $payMethod = $request->input('payment_method', 'Manual / Admin');
                $txnNum = 'TXN-MANUAL-' . strtoupper(Str::random(8));

                $transactionId = \Illuminate\Support\Facades\DB::table('payment_transactions')->insertGetId([
                    'user_id' => $userId,
                    'transaction_number' => $txnNum,
                    'amount' => $orderVal,
                    'currency' => 'INR',
                    'status' => 'success',
                    'payment_method' => $payMethod,
                    'payload' => json_encode(['note' => 'Manual payment recorded by admin', 'order_number' => $orderNumber]),
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            }

            // Find status id if available
            $statusId = null;
            if (\Illuminate\Support\Facades\Schema::hasTable('pcb_order_statuses')) {
                $st = \Illuminate\Support\Facades\DB::table('pcb_order_statuses')->where('name', 'Pending')->first();
                if ($st) {
                    $statusId = $st->id;
                }
            }

            // Create Order Record
            $orderData = [
                'user_id' => $userId,
                'order_number' => $orderNumber,
                'status_id' => $statusId,
                'gerber_file_id' => $gerberFileId,
                'transaction_id' => $transactionId,
                'unit_price' => floatval($request->input('unit_price', 0)),
                'order_value' => floatval($request->input('order_value', 0)),
                'delivery_date' => $request->input('delivery_date') ?: null,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ];

            if (\Illuminate\Support\Facades\Schema::hasColumn('pcb_orders', 'board_name')) {
                $orderData['board_name'] = $request->input('board_name');
            }
            if (\Illuminate\Support\Facades\Schema::hasColumn('pcb_orders', 'customer_name')) {
                $orderData['customer_name'] = $request->input('customer_name');
            }
            if (\Illuminate\Support\Facades\Schema::hasColumn('pcb_orders', 'user_email')) {
                $orderData['user_email'] = $request->input('user_email');
            }
            if (\Illuminate\Support\Facades\Schema::hasColumn('pcb_orders', 'user_mobile')) {
                $orderData['user_mobile'] = $request->input('user_mobile');
            }

            $orderId = \Illuminate\Support\Facades\DB::table('pcb_orders')->insertGetId($orderData);

            // Store Metadata
            $allParams = $request->all();
            unset($allParams['gerber_file']);

            if ($gerberFileUrl) {
                $allParams['gerber_file_url'] = $gerberFileUrl;
                $allParams['gerber_file_name'] = $gerberFileName;
                $allParams['gerber_file_size'] = $gerberFileSize;
            }

            foreach ($allParams as $key => $value) {
                if ($value !== null && $value !== '') {
                    PcbOrderMeta::create([
                        'pcb_order_id' => $orderId,
                        'meta_key' => $key,
                        'meta_value' => is_array($value) ? json_encode($value) : (string)$value,
                    ]);
                }
            }

            return response()->json([
                'status' => true,
                'message' => 'Order created successfully',
                'data' => [
                    'id' => $orderId,
                    'order_number' => $orderNumber,
                    'order_value' => $request->input('order_value'),
                    'transaction_id' => $transactionId
                ]
            ], 201);

        } catch (\Throwable $th) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to create order: ' . $th->getMessage()
            ], 500);
        }
    }

    /**
     * Reorder an existing PCB order.
     * Generates order_number based on original format:
     * e.g., M00001 -> M00001-1 -> M00001-2 ...
     */
    public function reorder(Request $request, $id)
    {
        try {
            $originalOrder = PcbOrder::with('metas')->where('id', $id)->orWhere('order_number', $id)->first();

            if (!$originalOrder) {
                return response()->json([
                    'status' => false,
                    'message' => 'Original order not found.'
                ], 444);
            }

            // Determine root order number prefix
            $origOrderNumber = $originalOrder->order_number;
            $rootOrderNumber = explode('-', $origOrderNumber)[0];

            // Find existing reorders matching the root prefix
            $existingOrders = PcbOrder::withTrashed()
                ->where('order_number', 'LIKE', $rootOrderNumber . '-%')
                ->pluck('order_number')
                ->toArray();

            $maxSuffix = 0;
            foreach ($existingOrders as $num) {
                $parts = explode('-', $num);
                if (count($parts) > 1 && is_numeric(end($parts))) {
                    $val = (int)end($parts);
                    if ($val > $maxSuffix) {
                        $maxSuffix = $val;
                    }
                }
            }

            $newOrderNumber = $rootOrderNumber . '-' . ($maxSuffix + 1);

            // Replicate order
            $newOrder = $originalOrder->replicate(['created_at', 'updated_at', 'deleted_at']);
            $newOrder->order_number = $newOrderNumber;
            $newOrder->status = 'Pending';
            $newOrder->completed_qty = 0;
            $newOrder->failed_qty = 0;
            $newOrder->launch_qty = 0;
            $newOrder->panel_qty = 0;
            $newOrder->ups_qty = 0;
            $newOrder->final_qty = 0;
            $newOrder->bill_number = null;
            $newOrder->launch_date = null;

            // Resolve C/G status based on customer GST number
            $reorderGst = null;
            if ($originalOrder->user_id) {
                $uRec = \App\Models\PcbUser::find($originalOrder->user_id);
                if ($uRec && !empty($uRec->gst_number)) {
                    $reorderGst = trim($uRec->gst_number);
                }
            }
            if (!empty($reorderGst)) {
                $newOrder->c_g = 'GST';
            } else if (empty($originalOrder->c_g)) {
                $newOrder->c_g = 'Cash';
            }

            // Handle custom order quantity if specified
            $reqQty = $request->input('order_qty', $request->input('quantity'));
            if ($reqQty !== null && is_numeric($reqQty) && (int)$reqQty > 0) {
                $newOrder->order_qty = (int)$reqQty;
            }

            // Handle custom delivery date if specified
            $reqDeliveryDate = $request->input('delivery_date', $request->input('deliveryDate'));
            if (!empty($reqDeliveryDate)) {
                $newOrder->delivery_date = $reqDeliveryDate;
            }

            // Recalculate order_value based on new order_qty if applicable
            if ($newOrder->unit_price && (float)$newOrder->unit_price > 0) {
                $newOrder->order_value = round((float)$newOrder->unit_price * $newOrder->order_qty, 2);
            } elseif ($originalOrder->order_qty > 0 && (float)$originalOrder->order_value > 0) {
                $unitCalc = (float)$originalOrder->order_value / (float)$originalOrder->order_qty;
                $newOrder->order_value = round($unitCalc * $newOrder->order_qty, 2);
            }

            $newOrder->created_at = now();
            $newOrder->updated_at = now();
            $newOrder->save();

            // Replicate metadata with updated quantity, delivery_date and order_value
            $hasQtyMeta = false;
            $hasDeliveryDateMeta = false;

            foreach ($originalOrder->metas as $meta) {
                // Skip film datetime if any, or retain original specifications
                if (in_array($meta->meta_key, ['film_datetime', 'film_date'])) {
                    continue;
                }

                $metaValue = $meta->meta_value;
                if ($meta->meta_key === 'quantity') {
                    $hasQtyMeta = true;
                    $metaValue = (string) $newOrder->order_qty;
                } elseif ($meta->meta_key === 'delivery_date') {
                    $hasDeliveryDateMeta = true;
                    if ($newOrder->delivery_date) {
                        $metaValue = (string) $newOrder->delivery_date;
                    }
                } elseif (in_array($meta->meta_key, ['order_value', 'total_price', 'total'])) {
                    if ($newOrder->order_value) {
                        $metaValue = (string) $newOrder->order_value;
                    }
                }

                PcbOrderMeta::create([
                    'pcb_order_id' => $newOrder->id,
                    'meta_key' => $meta->meta_key,
                    'meta_value' => $metaValue,
                ]);
            }

            if (!$hasQtyMeta && $newOrder->order_qty) {
                PcbOrderMeta::create([
                    'pcb_order_id' => $newOrder->id,
                    'meta_key' => 'quantity',
                    'meta_value' => (string) $newOrder->order_qty,
                ]);
            }

            if (!$hasDeliveryDateMeta && $newOrder->delivery_date) {
                PcbOrderMeta::create([
                    'pcb_order_id' => $newOrder->id,
                    'meta_key' => 'delivery_date',
                    'meta_value' => (string) $newOrder->delivery_date,
                ]);
            }

            // Log reorder event if log table exists
            if (\Illuminate\Support\Facades\Schema::hasTable('pcb_order_logs')) {
                $logData = [
                    'pcb_order_id' => $newOrder->id,
                    'order_number' => $newOrder->order_number,
                    'status' => 'Pending',
                    'action' => 'Reordered',
                    'created_at' => now(),
                    'updated_at' => now()
                ];
                if (\Illuminate\Support\Facades\Schema::hasColumn('pcb_order_logs', 'description')) {
                    $logData['description'] = "Reordered from original order #{$originalOrder->order_number}";
                }
                if (\Illuminate\Support\Facades\Schema::hasColumn('pcb_order_logs', 'details')) {
                    $logData['details'] = "Reordered from original order #{$originalOrder->order_number}";
                }
                if (\Illuminate\Support\Facades\Schema::hasColumn('pcb_order_logs', 'admin_id')) {
                    $logData['admin_id'] = $request->attributes->get('admin_id') ?: 1;
                }
                \Illuminate\Support\Facades\DB::table('pcb_order_logs')->insert($logData);
            }

            return response()->json([
                'status' => true,
                'message' => "Order #{$originalOrder->order_number} reordered successfully as #{$newOrderNumber}!",
                'data' => $newOrder
            ], 201);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to reorder: ' . $th->getMessage()
            ], 500);
        }
    }

    /**
     * Download sample manufacturer Excel sheet
     */
    public function importSample(OrderImportService $importService)
    {
        try {
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }

            $spreadsheet = $importService->generateSampleSheet();
            $fileName = 'sample_pcb_manufacturing_orders.xlsx';
            $tempFile = tempnam(sys_get_temp_dir(), 'pcb_sample_') . '.xlsx';

            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save($tempFile);

            return response()->download($tempFile, $fileName, [
                'Content-Type'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'max-age=0, no-cache, must-revalidate',
            ])->deleteFileAfterSend(true);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to generate sample sheet: ' . $th->getMessage()
            ], 500);
        }
    }

    /**
     * Preview manufacturer Excel file import
     */
    public function importPreview(Request $request, OrderImportService $importService)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:xlsx,xls|max:51200',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid file uploaded. Please upload a valid .xlsx or .xls file (max 50MB).',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $file = $request->file('file');
            $filePath = $file->getRealPath();
            $result = $importService->previewImport($filePath);

            return response()->json($result, $result['success'] ? 200 : 400);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to preview import file: ' . $th->getMessage()
            ], 500);
        }
    }

    /**
     * Execute manufacturer Excel file import (synchronous legacy fallback)
     */
    public function importExecute(Request $request, OrderImportService $importService)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:xlsx,xls|max:51200',
            'duplicate_action' => 'nullable|string|in:skip,update,create_new',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $file = $request->file('file');
            $duplicateAction = $request->input('duplicate_action', 'skip');
            $filePath = $file->getRealPath();

            $result = $importService->executeImport($filePath, $duplicateAction);

            return response()->json($result, $result['success'] ? 200 : 500);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to execute import: ' . $th->getMessage()
            ], 500);
        }
    }

    /**
     * Upload manufacturer Excel file and queue for background processing
     */
    public function uploadImport(Request $request, OrderImportService $importService)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:xlsx,xls|max:51200',
            'duplicate_action' => 'nullable|string|in:skip,update,create_new',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid file uploaded. Please upload a valid .xlsx or .xls file (max 50MB).',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $file = $request->file('file');
            $duplicateAction = $request->input('duplicate_action', 'skip');
            $adminId = $request->attributes->get('admin_id') ?: 1;

            $result = $importService->queueImportFile($file, $duplicateAction, $adminId);

            return response()->json($result, $result['success'] ? 200 : 400);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to queue import file: ' . $th->getMessage()
            ], 500);
        }
    }

    /**
     * Step 1: Upload & Stage Excel file without modifying production tables
     */
    public function uploadImportStaged(Request $request, OrderImportService $importService)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:xlsx,xls|max:51200',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Invalid file uploaded. Please upload a valid .xlsx or .xls file (max 50MB).',
                'errors'  => $validator->errors()
            ], 422);
        }

        try {
            @set_time_limit(0);
            @ini_set('memory_limit', '1024M');

            $file = $request->file('file');
            $originalName = $file->getClientOriginalName();
            $storedPath = $file->store('pcb_imports', 'local');
            $fullPath = storage_path('app/' . ltrim($storedPath, '/\\'));
            $adminId = $request->attributes->get('admin_id') ?: 1;

            $import = $importService->stageImportFile($fullPath, $originalName, $adminId);

            return response()->json([
                'status'  => true,
                'message' => 'File staged successfully for review.',
                'data'    => $import
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to stage import file: ' . $th->getMessage()
            ], 500);
        }
    }

    /**
     * Step 2: Get paginated staged rows for review page
     */
    public function getStagedRows(Request $request, $id, OrderImportService $importService)
    {
        try {
            $filters = $request->only(['validation_status', 'search']);
            $page = (int)$request->input('page', 1);
            $perPage = (int)$request->input('per_page', 50);

            $result = $importService->getStagedRows((int)$id, $filters, $page, $perPage);
            return response()->json($result);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to fetch staged rows: ' . $th->getMessage()
            ], 500);
        }
    }

    /**
     * Step 2: Live update single cell value in staged row
     */
    public function updateStagedRowCell(Request $request, $id, $rowId, OrderImportService $importService)
    {
        $validator = Validator::make($request->all(), [
            'field_key' => 'required|string',
            'value'     => 'nullable',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Invalid field_key or value.',
                'errors'  => $validator->errors()
            ], 422);
        }

        try {
            $fieldKey = $request->input('field_key');
            $value = $request->input('value');

            $result = $importService->updateStagedCell((int)$id, (int)$rowId, $fieldKey, $value);
            return response()->json($result);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to update staged cell: ' . $th->getMessage()
            ], 500);
        }
    }

    /**
     * Step 2 -> Step 3: Final validation & start background import
     */
    public function startStagedImport(Request $request, $id, OrderImportService $importService)
    {
        try {
            $duplicateAction = $request->input('duplicate_action', 'update');
            $importValidOnly = filter_var($request->input('import_valid_only', false), FILTER_VALIDATE_BOOLEAN);
            $result = $importService->startStagedImport((int)$id, $duplicateAction, $importValidOnly);

            return response()->json($result, $result['success'] ? 200 : 422);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to start import: ' . $th->getMessage()
            ], 500);
        }
    }

    /**
     * Get list of PCB import records with pagination & filtering
     */
    public function listImports(Request $request)
    {
        try {
            $query = \App\Models\PcbImport::orderBy('id', 'desc');

            if ($request->has('status') && $request->input('status') !== 'all') {
                $query->where('status', $request->input('status'));
            }

            $perPage = (int)$request->input('per_page', 15);
            $imports = $query->paginate($perPage);

            return response()->json([
                'status' => true,
                'data' => $imports
            ]);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    /**
     * Get detailed view of single import record including error log
     */
    public function showImport($id)
    {
        try {
            $import = \App\Models\PcbImport::with(['errors' => function ($q) {
                $q->orderBy('row_number', 'asc')->limit(100);
            }])->find($id);

            if (!$import) {
                return response()->json(['status' => false, 'message' => 'Import record not found.'], 404);
            }

            return response()->json([
                'status' => true,
                'data' => $import
            ]);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    /**
     * Process a chunk of import rows synchronously (for real-time progress page)
     */
    public function processChunk(Request $request, $id, OrderImportService $importService)
    {
        try {
            $import = \App\Models\PcbImport::find($id);
            if (!$import) {
                return response()->json(['status' => false, 'message' => 'Import session expired or completed.'], 404);
            }

            $batchSize = (int)$request->input('batch_size', 100);
            $result = $importService->processImportBatch($import, $batchSize);

            return response()->json([
                'status' => true,
                'data'   => $result
            ]);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => 'Chunk processing error: ' . $th->getMessage()], 500);
        }
    }

    /**
     * Retry an import session
     */
    public function retryImport(Request $request, $id)
    {
        try {
            $import = \App\Models\PcbImport::find($id);
            if (!$import) {
                return response()->json(['status' => false, 'message' => 'Import record not found.'], 404);
            }

            $stagedRowsExist = \App\Models\PcbImportRow::where('import_id', $import->id)->exists();

            if (!$stagedRowsExist && !empty($import->file_path)) {
                $fullPath = storage_path('app/' . ltrim($import->file_path, '/\\'));
                if (!file_exists($fullPath)) {
                    $fullPath = storage_path('app/public/' . ltrim($import->file_path, '/\\'));
                }

                if (!file_exists($fullPath)) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Original import file is no longer available. Please upload the file again.'
                    ], 400);
                }
            }

            // Reset status for page chunk processing
            $import->update([
                'status'        => 'queued',
                'error_message' => null,
                'failed_at'     => null,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Import ready for chunk processing.',
                'import' => $import
            ]);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    /**
     * Cancel a queued or processing import
     */
    public function cancelImport($id)
    {
        try {
            $import = \App\Models\PcbImport::find($id);
            if (!$import) {
                return response()->json(['status' => false, 'message' => 'Import record not found.'], 404);
            }

            if (in_array($import->status, ['completed', 'cancelled'], true)) {
                return response()->json(['status' => false, 'message' => "Import cannot be cancelled because it is already {$import->status}."], 400);
            }

            $import->update([
                'status' => 'cancelled',
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Import cancelled successfully.',
                'import' => $import
            ]);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    /**
     * Live preview table data for Export Modal before downloading
     */
    public function exportPreview(Request $request, OrderExportService $exportService)
    {
        try {
            $filters = [
                'start_date'    => $request->input('start_date'),
                'end_date'      => $request->input('end_date'),
                'date_field'    => $request->input('date_field', 'order_date'),
                'status'        => $request->input('status'),
                'customer_name' => $request->input('customer_name') ?? $request->input('customer'),
                'layer'         => $request->input('layer'),
                'mask'          => $request->input('mask'),
                'c_g'           => $request->input('c_g') ?? $request->input('cg'),
                'tool'          => $request->input('tool'),
                'combo'         => $request->input('combo'),
                'p_n'           => $request->input('p_n') ?? $request->input('pn'),
                'quote_number'  => $request->input('quote_number') ?? $request->input('quote_no'),
                'bill_number'   => $request->input('bill_number') ?? $request->input('bill_no'),
                'search'        => $request->input('search'),
                'order_ids'     => $request->input('order_ids'),
            ];
            $page = (int)$request->input('page', 1);
            $perPage = (int)$request->input('per_page', 10);

            $result = $exportService->previewFilteredRecords($filters, $page, $perPage);
            return response()->json($result);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to preview export query: ' . $th->getMessage()
            ], 500);
        }
    }

    /**
     * Export PCB Orders in exact manufacturer format (XLSX or CSV)
     */
    public function export(Request $request, OrderExportService $exportService)
    {
        try {
            $filters = [
                'start_date'    => $request->input('start_date'),
                'end_date'      => $request->input('end_date'),
                'date_field'    => $request->input('date_field', 'order_date'),
                'status'        => $request->input('status'),
                'customer_name' => $request->input('customer_name') ?? $request->input('customer'),
                'layer'         => $request->input('layer'),
                'mask'          => $request->input('mask'),
                'c_g'           => $request->input('c_g') ?? $request->input('cg'),
                'tool'          => $request->input('tool'),
                'combo'         => $request->input('combo'),
                'p_n'           => $request->input('p_n') ?? $request->input('pn'),
                'quote_number'  => $request->input('quote_number') ?? $request->input('quote_no'),
                'bill_number'   => $request->input('bill_number') ?? $request->input('bill_no'),
                'search'        => $request->input('search'),
                'order_ids'     => $request->input('order_ids'),
            ];
            $format = strtolower($request->input('format', 'xlsx'));

            if ($format === 'csv') {
                $csvContent = $exportService->generateCsvExport($filters);
                $fileName = 'pcb-manufacturing-export-' . date('Y-m-d') . '.csv';

                return response($csvContent, 200, [
                    'Content-Type' => 'text/csv; charset=UTF-8',
                    'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
                    'Cache-Control' => 'max-age=0, no-cache, must-revalidate',
                ]);
            } else {
                while (ob_get_level() > 0) {
                    @ob_end_clean();
                }

                $spreadsheet = $exportService->generateExport($filters);
                $fileName = 'pcb-manufacturing-export-' . date('Y-m-d') . '.xlsx';
                $tempFile = tempnam(sys_get_temp_dir(), 'pcb_export_') . '.xlsx';

                $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
                $writer->save($tempFile);

                return response()->download($tempFile, $fileName, [
                    'Content-Type'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'Cache-Control' => 'max-age=0, no-cache, must-revalidate',
                ])->deleteFileAfterSend(true);
            }
        } catch (\Throwable $th) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to export orders: ' . $th->getMessage()
            ], 500);
        }
    }

    /**
     * Repeat / Reorder an existing order safely.
     * Validates user ownership, restores complete original order configuration,
     * verifies Gerber file availability, and recalculates current price using latest pricing rules.
     */
    public function repeat(Request $request, $orderId = null)
    {
        try {
            $id = $orderId ?? $request->input('order_id') ?? $request->input('id');
            $userId = $request->input('user_id');

            if (!$id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order ID is required'
                ], 400);
            }

            // 1. Fetch Order
            $order = PcbOrder::with(['gerberFile'])->find($id);

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found'
                ], 404);
            }

            // 2. Validate Ownership (Authorization)
            if ($userId && (int)$order->user_id !== (int)$userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized access: You do not have permission to reorder this order.'
                ], 403);
            }

            // 3. Verify Gerber File Availability
            $gerberFile = null;
            if ($order->gerber_file_id) {
                $gerberFile = \Illuminate\Support\Facades\DB::table('gerber_files')
                    ->where('id', $order->gerber_file_id)
                    ->whereNull('deleted_at')
                    ->first();
            }

            if (!$gerberFile && $order->gerber_file_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'The Gerber file for this order is no longer available. Please upload the Gerber file again.'
                ], 400);
            }

            // 4. Fetch Order Specifications Meta
            $metas = \Illuminate\Support\Facades\DB::table('pcb_order_meta')
                ->where('pcb_order_id', $order->id)
                ->pluck('meta_value', 'meta_key')
                ->toArray();

            $boardName = $gerberFile->original_name ?? ($metas['board_name'] ?? ($metas['gerber_file_name'] ?? 'Standard PCB Order'));
            $gerberFileName = $gerberFile->original_name ?? ($metas['gerber_file_name'] ?? $boardName);
            $gerberFileId = $gerberFile ? $gerberFile->id : ($order->gerber_file_id ?? null);
            $gerberPreview = $gerberFile->preview_data ?? ($metas['preview_data'] ?? null);
            $gerberUrl = $gerberFile->file_url ?? ($metas['gerber_file_url'] ?? null);

            $layers = (int)($metas['layers'] ?? 2);
            $qty = (int)($metas['quantity'] ?? 5);
            $width = (float)($metas['dimensions_width'] ?? 100);
            $height = (float)($metas['dimensions_length'] ?? 100);
            $unit = $metas['dimension_unit'] ?? 'mm';
            $dimensions = $width . 'x' . $height . $unit;
            $thickness = $metas['thickness'] ?? '1.6mm';
            $pcbColor = $metas['pcb_color'] ?? 'Green';
            $surfaceFinish = $metas['surface_finish'] ?? 'HASL(Leaded)';
            $copperWeight = $metas['copper_weight'] ?? '1 oz';
            $baseMaterial = $metas['base_material'] ?? 'FR-4';
            $silkscreen = $metas['silkscreen'] ?? 'White';
            $orderType = $order->order_type ?? ($metas['order_type'] ?? 'normal');
            $quotationSource = $order->quotation_source ?? ($metas['quotation_source'] ?? ($orderType === 'jlcpcb' ? 'jlcpcb' : 'internal'));
            $productType = $metas['product_type'] ?? 'pcb';

            // 5. Recalculate CURRENT Pricing
            $currentPrice = 0;
            $jlcSnapshot = null;

            if ($quotationSource === 'jlcpcb' || $orderType === 'jlcpcb') {
                // JLCPCB Order: Recalculate using JLCPCB pricing engine
                $jlcFileKey = $order->jlcpcb_file_key ?? ($metas['jlcpcb_file_key'] ?? null);

                if (!empty($jlcFileKey) && class_exists(\App\Services\JlcpcbService::class)) {
                    try {
                        $jlcService = app(\App\Services\JlcpcbService::class);
                        $calcPayload = [
                            'fileKey' => $jlcFileKey,
                            'layer' => $layers,
                            'pcbLength' => $height,
                            'pcbWidth' => $width,
                            'quantity' => $qty,
                            'thickness' => $thickness,
                            'pcbColor' => $pcbColor,
                            'surfaceFinish' => $surfaceFinish,
                            'copperWeight' => $copperWeight,
                        ];
                        $calcResult = $jlcService->calculateQuotation($calcPayload);
                        if ($calcResult && !empty($calcResult['success']) && isset($calcResult['price'])) {
                            $currentPrice = (float)$calcResult['price'];
                            $jlcSnapshot = $calcResult['data'] ?? null;
                        }
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning("JLCPCB reorder recalculation API failed, falling back to pricing calculator: " . $e->getMessage());
                    }
                }

                if ($currentPrice <= 0 && class_exists(\App\Services\JLCPCBPriceCalculator::class)) {
                    $oldUsd = 10.0;
                    if (!empty($order->jlcpcb_quotation_snapshot)) {
                        $snap = is_array($order->jlcpcb_quotation_snapshot) ? $order->jlcpcb_quotation_snapshot : json_decode($order->jlcpcb_quotation_snapshot, true);
                        if (isset($snap['pcb_purchase_price_usd'])) {
                            $oldUsd = (float)$snap['pcb_purchase_price_usd'];
                        }
                    }
                    $calc = (new \App\Services\JLCPCBPriceCalculator())->calculate($oldUsd, null, $qty);
                    $currentPrice = (float)($calc['final_customer_price'] ?? 0);
                    $jlcSnapshot = $calc;
                }
            }

            if ($currentPrice <= 0) {
                // Regular PCB Order: Recalculate using current pricing formula
                $areaPerBoard = ($width * $height) / 1000000;
                $totalAreaSqM = $areaPerBoard * $qty;
                $areaInSqCm = $totalAreaSqM * 10000;

                $fixedCosts = [
                    '1' => 1400,
                    '2' => 1900,
                    '4' => 6000,
                    '6' => 7000,
                    '8' => 8000,
                    '10' => 9000
                ];

                $baseFixed = $fixedCosts[(string)$layers] ?? 1900;
                $variableCost = $areaInSqCm * ($layers > 2 ? 0.35 : 0.22);
                $colorMultiplier = strtolower(trim($pcbColor)) === 'green' ? 1.0 : 1.1;

                $currentPrice = round(($baseFixed + $variableCost) * $colorMultiplier);
            }

            if ($currentPrice <= 0) {
                $currentPrice = (float)($order->order_value ?? 1500);
            }

            $unitPrice = $qty > 0 ? round($currentPrice / $qty, 2) : $currentPrice;

            // 6. Build Clean Reorder Cart Item Payload
            $cartItem = [
                'id' => time() . rand(100, 999),
                'source_order_id' => $order->id,
                'source_order_number' => $order->order_number,
                'parent_order_number' => $order->order_number,
                'is_reorder' => true,
                'productType' => $productType,
                'order_type' => $orderType,
                'quotation_source' => $quotationSource,
                'boardName' => $boardName,
                'gerberFileName' => $gerberFileName,
                'gerber_file_id' => $gerberFileId,
                'gerberPreview' => $gerberPreview,
                'gerberUrl' => $gerberUrl,
                'layers' => $layers,
                'dimensions' => $dimensions,
                'width' => (string)$width,
                'height' => (string)$height,
                'unit' => $unit,
                'qty' => $qty,
                'thickness' => $thickness,
                'pcbColor' => $pcbColor,
                'surfaceFinish' => $surfaceFinish,
                'copperWeight' => $copperWeight,
                'baseMaterial' => $baseMaterial,
                'silkscreen' => $silkscreen,
                'buildTime' => $metas['build_time'] ?? '3-4 days',
                'differentDesign' => $metas['different_design'] ?? '1',
                'deliveryFormat' => $metas['delivery_format'] ?? 'Single PCB',
                'panelColumn' => $metas['panel_column'] ?? '',
                'panelRow' => $metas['panel_row'] ?? '',
                'viaCovering' => $metas['via_covering'] ?? 'Not Specified',
                'viaPlating' => $metas['via_plating'] ?? 'Not Specified',
                'minHole' => $metas['min_hole'] ?? '0.3mm/(0.4/0.45mm)',
                'confirmFile' => $metas['confirm_file'] ?? 'No',
                'markOnPcb' => $metas['mark_on_pcb'] ?? 'Remove Mark',
                'elecTest' => $metas['elec_test'] ?? 'Flying Probe Fully Test',
                'goldFingers' => $metas['gold_fingers'] ?? 'No',
                'castellated' => $metas['castellated'] ?? 'No',
                'edgePlating' => $metas['edge_plating'] ?? 'No',
                'blindSlots' => $metas['blind_slots'] ?? 'No',
                'ulMarking' => $metas['ul_marking'] ?? 'No',
                'humidity' => $metas['humidity'] ?? 'No',
                'kelvinTest' => $metas['kelvin_test'] ?? 'No',
                'paperBetween' => $metas['paper_between'] ?? 'No',
                'appearanceQuality' => $metas['appearance_quality'] ?? 'IPC Class 2 Standard',
                'silkscreenTech' => $metas['silkscreen_tech'] ?? 'Ink-jet Printing Silkscreen',
                'inspectionReport' => $metas['inspection_report'] ?? 'No',
                'pcbRemark' => $metas['pcb_remark'] ?? '',
                'price' => $currentPrice,
                'unitPrice' => $unitPrice,
                'jlcpcb_file_key' => $order->jlcpcb_file_key ?? ($metas['jlcpcb_file_key'] ?? null),
                'jlcpcb_quotation_snapshot' => $jlcSnapshot ?? $order->jlcpcb_quotation_snapshot ?? null,
            ];

            return response()->json([
                'success'   => true,
                'message'   => 'Order configuration loaded into cart with current pricing.',
                'cart_item' => $cartItem,
                'redirect'  => '/cart'
            ]);

        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to repeat order: ' . $th->getMessage()
            ], 500);
        }
    }
}