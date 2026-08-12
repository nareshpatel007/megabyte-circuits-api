<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\PcbOrder;
use App\Models\PcbOrderMeta;
use App\Models\PcbOrderStatusHistory;

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
            }

            if ($request->has('status_id')) {
                $order->status_id = $request->status_id;
            }

            if ($request->has('delivery_date')) {
                $order->delivery_date = $request->delivery_date;
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

            $order->save();

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
                $adminUser = null;
                $effectiveAdminId = $adminId ?: 1;
                if (\Illuminate\Support\Facades\Schema::hasTable('admins')) {
                    $adminUser = \Illuminate\Support\Facades\DB::table('admins')->where('id', $effectiveAdminId)->first();
                }
                $adminName = $adminUser ? $adminUser->name : "Admin #{$effectiveAdminId}";

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

            // Generate unique sequential order number (e.g. M0005)
            $lastOrder = PcbOrder::withTrashed()->orderBy('id', 'desc')->first();
            $nextId = $lastOrder ? ($lastOrder->id + 1) : 1;
            $orderNumber = 'M' . str_pad($nextId, 4, '0', STR_PAD_LEFT);

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
}