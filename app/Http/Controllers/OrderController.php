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

            // Generate unique sequential order number starting from M0001
            $lastOrder = PcbOrder::withTrashed()->orderBy('id', 'desc')->first();
            $nextId = $lastOrder ? ($lastOrder->id + 1) : 1;
            $orderNumber = 'M' . str_pad($nextId, 4, '0', STR_PAD_LEFT);

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

            // Create the main compact order record
            $order = PcbOrder::create([
                'user_id' => $userId,
                'order_number' => $orderNumber,
                'board_name' => $request->board_name,
                'customer_name' => $request->customer_name,
                'user_email' => $request->user_email,
                'user_mobile' => $request->user_mobile,
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
            $withRelations = ['metas'];
            if (\Illuminate\Support\Facades\Schema::hasTable('pcb_order_statuses') || \Illuminate\Support\Facades\Schema::hasTable('pcb_statuses')) {
                $withRelations[] = 'statusDetails';
            }

            $query = PcbOrder::with($withRelations);

            // Date Range Filtering
            if ($request->filled('start_date')) {
                $query->whereDate('created_at', '>=', $request->start_date);
            }
            if ($request->filled('end_date')) {
                $query->whereDate('created_at', '<=', $request->end_date);
            }

            // Search Filter (by Order #, Board Name, Email, Mobile, Customer Name, Metas, Razorpay Payment IDs)
            if ($request->filled('search')) {
                $search = trim($request->input('search'));
                $query->where(function ($q) use ($search) {
                    $q->where('order_number', 'LIKE', "%{$search}%")
                        ->orWhere('user_email', 'LIKE', "%{$search}%")
                        ->orWhere('user_mobile', 'LIKE', "%{$search}%")
                        ->orWhere('customer_name', 'LIKE', "%{$search}%");
                    if (\Illuminate\Support\Facades\Schema::hasColumn('pcb_orders', 'board_name')) {
                        $q->orWhere('board_name', 'LIKE', "%{$search}%");
                    }
                    $q->orWhereHas('metas', function ($mq) use ($search) {
                        $mq->where('meta_value', 'LIKE', "%{$search}%");
                    });
                    if (\Illuminate\Support\Facades\Schema::hasTable('payment_transactions')) {
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
            
            if ($request->has('status')) {
                $order->status = $request->status;
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

            if ($request->has('delivery_date')) {
                $order->delivery_date = $request->delivery_date;
            }

            if ($request->has('bill_number')) {
                $order->bill_number = $request->bill_number;
            }

            $oldCompletedQty = $order->completed_qty ?? 0;
            $qtyUpdated = false;
            if ($request->has('completed_qty')) {
                $newCompletedQty = intval($request->completed_qty);
                if ($newCompletedQty !== $oldCompletedQty) {
                    $order->completed_qty = $newCompletedQty;
                    $qtyUpdated = true;
                }
            }

            if ($request->has('failed_qty')) {
                PcbOrderMeta::updateOrCreate(
                    ['pcb_order_id' => $order->id, 'meta_key' => 'failed_qty'],
                    ['meta_value' => (string)intval($request->failed_qty)]
                );
            }

            $order->save();

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

            // 2. Create activity log in pcb_order_logs with admin_id for status or quantity updates
            if (($request->has('status') || $qtyUpdated) && \Illuminate\Support\Facades\Schema::hasTable('pcb_order_logs')) {
                $statusName = $order->status ?? 'Pending';
                
                // Fetch admin user details for description and log record
                $effectiveAdminId = $request->input('admin_id') ?: ($request->attributes->get('admin_id') ?: null);
                $adminUser = null;
                if ($effectiveAdminId && \Illuminate\Support\Facades\Schema::hasTable('admins')) {
                    $adminUser = \Illuminate\Support\Facades\DB::table('admins')->where('id', $effectiveAdminId)->first();
                }
                
                $adminName = $adminUser ? $adminUser->name : ($request->attributes->get('admin_name') ?: ($request->input('admin_name') ?: ($effectiveAdminId ? "Admin #{$effectiveAdminId}" : "Admin")));

                if ($qtyUpdated && $request->has('status')) {
                    $actionName = "Partial Delivery / Status Updated";
                    $descText = "Completed quantity updated from {$oldCompletedQty} to {$order->completed_qty} Pcs. Status set to '{$statusName}' by {$adminName}." . ($remark ? " Remark: {$remark}" : "");
                } elseif ($qtyUpdated) {
                    $actionName = "Quantity Updated";
                    $descText = "Completed quantity updated from {$oldCompletedQty} to {$order->completed_qty} Pcs by {$adminName}." . ($remark ? " Remark: {$remark}" : "");
                } else {
                    $actionName = "Status Updated: {$statusName}";
                    $descText = $remark ? "Order status updated to '{$statusName}' by {$adminName}. Remark: {$remark}" : "Order status updated to '{$statusName}' by {$adminName}.";
                }

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

            // Generate unique sequential order number (+1 of last generated order number, e.g. M00001 -> M00002)
            $lastOrder = \Illuminate\Support\Facades\DB::table('pcb_orders')
                ->where('order_number', 'LIKE', 'M%')
                ->where('order_number', 'NOT LIKE', '%-%')
                ->orderBy('id', 'desc')
                ->first();

            $nextNumber = 1;
            if ($lastOrder && !empty($lastOrder->order_number)) {
                $numericPart = (int) preg_replace('/[^0-9]/', '', $lastOrder->order_number);
                if ($numericPart > 0) {
                    $nextNumber = $numericPart + 1;
                } else {
                    $maxPcbId = \Illuminate\Support\Facades\DB::table('pcb_orders')->max('id') ?? 0;
                    $maxOrdersId = \Illuminate\Support\Facades\Schema::hasTable('orders') ? (\Illuminate\Support\Facades\DB::table('orders')->max('id') ?? 0) : 0;
                    $nextNumber = max($maxPcbId, $maxOrdersId) + 1;
                }
            } else {
                $maxPcbId = \Illuminate\Support\Facades\DB::table('pcb_orders')->max('id') ?? 0;
                $maxOrdersId = \Illuminate\Support\Facades\Schema::hasTable('orders') ? (\Illuminate\Support\Facades\DB::table('orders')->max('id') ?? 0) : 0;
                $nextNumber = max($maxPcbId, $maxOrdersId) + 1;
            }
            $orderNumber = 'M' . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);

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
            $newOrder->created_at = now();
            $newOrder->updated_at = now();
            $newOrder->save();

            // Replicate metadata
            foreach ($originalOrder->metas as $meta) {
                // Skip film datetime if any, or retain original specifications
                if (in_array($meta->meta_key, ['film_datetime', 'film_date'])) {
                    continue;
                }
                PcbOrderMeta::create([
                    'pcb_order_id' => $newOrder->id,
                    'meta_key' => $meta->meta_key,
                    'meta_value' => $meta->meta_value,
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
            $file = $request->file('file');
            $originalName = $file->getClientOriginalName();
            $storedPath = $file->store('pcb_imports', 'local');
            $fullPath = \Illuminate\Support\Facades\Storage::disk('local')->path($storedPath);
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
            $duplicateAction = $request->input('duplicate_action', 'skip');
            $result = $importService->startStagedImport((int)$id, $duplicateAction);
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
     * Retry a failed import
     */
    public function retryImport($id, OrderImportService $importService)
    {
        try {
            $import = \App\Models\PcbImport::find($id);
            if (!$import) {
                return response()->json(['status' => false, 'message' => 'Import record not found.'], 404);
            }

            $fullPath = \Illuminate\Support\Facades\Storage::disk('local')->path($import->file_path);
            if (!file_exists($fullPath)) {
                $fullPath = storage_path('app/' . $import->file_path);
            }

            if (!file_exists($fullPath)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Original import file is no longer available. Please upload the file again.'
                ], 400);
            }

            // Reset status & progress counters
            $import->update([
                'status'          => 'queued',
                'error_message'   => null,
                'failed_at'       => null,
                'started_at'      => null,
                'completed_at'    => null,
            ]);

            \App\Jobs\ProcessPcbImportJob::dispatch($import->id);

            return response()->json([
                'status' => true,
                'message' => 'Import queued for retry.',
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
}