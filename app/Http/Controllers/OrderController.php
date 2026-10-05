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
                'board_name' => 'nullable|string|max:200',
                'pn_number' => 'nullable|string|max:255',
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

                $sourceResolution = \App\Services\OrderPricingService::resolveOrderSource($request->all());
                $reqSource = $sourceResolution['quotation_source'];
                $reqOrderType = $sourceResolution['order_type'];
                $series = $sourceResolution['series'];
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

                $cgStatus = (!empty($customerGst) && strtolower($customerGst) !== 'null' && strtolower($customerGst) !== 'undefined') ? 'GST' : 'CASH';

                $pnNumber = $request->filled('pn_number') ? trim($request->input('pn_number')) : ($gerberFileName ?: null);

                $defaultStatus = \App\Services\OrderStatusResolver::getDefaultStatus();
                $initialStatusName = $defaultStatus ? $defaultStatus->name : 'Pending';
                $initialStatusId = $defaultStatus ? $defaultStatus->id : null;

                // Create the main compact order record
                $order = PcbOrder::create([
                    'user_id' => $userId,
                    'order_number' => $orderNumber,
                    'board_name' => $request->board_name ?: ($pnNumber ?: 'Standard PCB'),
                    'pn_number' => $pnNumber,
                    'customer_name' => $request->customer_name,
                    'user_email' => $request->user_email,
                    'user_mobile' => $request->user_mobile,
                    'c_g' => $request->c_g ?? $cgStatus,
                    'status' => $initialStatusName,
                    'status_id' => $initialStatusId,
                    'order_type' => $reqOrderType,
                    'quotation_source' => $reqSource,
                    'jlcpcb_file_key' => ($reqOrderType === 'jlcpcb') ? ($request->input('jlcpcb_file_key') ?: null) : null,
                    'jlcpcb_quotation_snapshot' => ($reqOrderType === 'jlcpcb') ? ($request->input('jlcpcb_quotation_snapshot') ?: null) : null,
                    'unit_price' => $request->unit_price ?? 0,
                    'order_value' => $request->order_value ?? 0,
                    'delivery_date' => $request->delivery_date,
                ]);

                // Save all additional specification attributes into pcb_order_meta
                $metaData = $request->except([
                    'user_id', 'order_number', 'board_name', 'pn_number', 'customer_name', 'user_email', 'user_mobile',
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

                $hasUserEmailCol = \Illuminate\Support\Facades\Cache::rememberForever('schema_has_user_email_col', function () {
                    return \Illuminate\Support\Facades\Schema::hasColumn('pcb_orders', 'user_email');
                });

                $hasUserMobileCol = \Illuminate\Support\Facades\Cache::rememberForever('schema_has_user_mobile_col', function () {
                    return \Illuminate\Support\Facades\Schema::hasColumn('pcb_orders', 'user_mobile');
                });

                $hasCustomerNameCol = \Illuminate\Support\Facades\Cache::rememberForever('schema_has_customer_name_col', function () {
                    return \Illuminate\Support\Facades\Schema::hasColumn('pcb_orders', 'customer_name');
                });

                $hasPaymentTxTable = \Illuminate\Support\Facades\Cache::rememberForever('schema_has_payment_tx_table', function () {
                    return \Illuminate\Support\Facades\Schema::hasTable('payment_transactions');
                });

                $hasUsersName = \Illuminate\Support\Facades\Cache::rememberForever('schema_users_has_name', function () {
                    return \Illuminate\Support\Facades\Schema::hasColumn('users', 'name');
                });
                $hasUsersCompanyName = \Illuminate\Support\Facades\Cache::rememberForever('schema_users_has_company_name', function () {
                    return \Illuminate\Support\Facades\Schema::hasColumn('users', 'company_name');
                });
                $hasUsersEmail = \Illuminate\Support\Facades\Cache::rememberForever('schema_users_has_email', function () {
                    return \Illuminate\Support\Facades\Schema::hasColumn('users', 'email');
                });
                $hasUsersMobile = \Illuminate\Support\Facades\Cache::rememberForever('schema_users_has_mobile', function () {
                    return \Illuminate\Support\Facades\Schema::hasColumn('users', 'mobile');
                });
                $hasUsersPhoneNumber = \Illuminate\Support\Facades\Cache::rememberForever('schema_users_has_phone_number', function () {
                    return \Illuminate\Support\Facades\Schema::hasColumn('users', 'phone_number');
                });
                $hasUsersPhone = \Illuminate\Support\Facades\Cache::rememberForever('schema_users_has_phone', function () {
                    return \Illuminate\Support\Facades\Schema::hasColumn('users', 'phone');
                });

                $allowedMetaKeys = [
                    'gerber_file_name', 'gerber_name', 'file_name', 'gerber_file',
                    'gerber_file_url', 'gerber_url', 'gerber_path',
                    'payment_id', 'razorpay_payment_id', 'transaction_id',
                    'payment_status', 'payment_mode', 'payment_method',
                    'film_datetime', 'film_date', 'film_applied',
                    'qty', 'quantity', 'product_type',
                    'pcb_color', 'solder_mask', 'layer', 'layers',
                    'min_hole', 'min_hole_size', 'panel_size', 'dimensions',
                    'cutting_size', 'material', 'base_material', 'board_thickness',
                    'thickness', 'copper_thickness', 'copper_weight',
                    'surface_finish', 'finish', 'legend_color', 'silkscreen',
                    'silkscreen_side', 'legend_side', 'route', 'routing',
                    'v_cut', 'fpt_program', 'second_stage', 'copper_area',
                    'tool', 'quote_number', 'p_n', 'pn_number', 'part_number', 'board_name', 'gerber_file_id', 'ups', 'panels',
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
                if (\Illuminate\Support\Facades\Schema::hasTable('pcb_order_combos')) {
                    $withRelations[] = 'comboOrders';
                }
                if (\Illuminate\Support\Facades\Schema::hasTable('pcb_order_old_orders')) {
                    $withRelations[] = 'oldOrders';
                }
                if (\Illuminate\Support\Facades\Schema::hasTable('gerber_files')) {
                    $withRelations[] = 'gerberFile';
                }

                $query = PcbOrder::with($withRelations);

                // Status Filter (Main order status only)
                if ($request->filled('status')) {
                    $statusParam = trim($request->input('status'));
                    if (strtolower($statusParam) === 'in production') {
                        $excluded = ['pending', 'completed', 'shipped', 'delivered', 'cancelled', 'canceled'];
                        $query->where(function ($q) use ($excluded) {
                            $q->where(function ($sq) use ($excluded) {
                                $sq->whereNotIn(\Illuminate\Support\Facades\DB::raw('LOWER(TRIM(pcb_orders.status))'), $excluded);
                                if (\Illuminate\Support\Facades\Schema::hasTable('pcb_order_statuses') || \Illuminate\Support\Facades\Schema::hasTable('pcb_statuses')) {
                                    $sq->whereDoesntHave('statusDetails');
                                }
                            });
                            if (\Illuminate\Support\Facades\Schema::hasTable('pcb_order_statuses') || \Illuminate\Support\Facades\Schema::hasTable('pcb_statuses')) {
                                $q->orWhereHas('statusDetails', function ($stq) use ($excluded) {
                                    $stq->whereNotIn(\Illuminate\Support\Facades\DB::raw('LOWER(TRIM(name))'), $excluded)
                                        ->whereNotIn(\Illuminate\Support\Facades\DB::raw('LOWER(TRIM(slug))'), $excluded);
                                });
                            }
                        });
                    } else if (strtolower($statusParam) !== 'all') {
                        $statusLower = strtolower($statusParam);
                        $query->where(function ($q) use ($statusLower) {
                            $q->where(function ($sq) use ($statusLower) {
                                $sq->whereRaw('LOWER(TRIM(pcb_orders.status)) = ?', [$statusLower]);
                                if (\Illuminate\Support\Facades\Schema::hasTable('pcb_order_statuses') || \Illuminate\Support\Facades\Schema::hasTable('pcb_statuses')) {
                                    $sq->whereDoesntHave('statusDetails');
                                }
                            });
                            if (\Illuminate\Support\Facades\Schema::hasTable('pcb_order_statuses') || \Illuminate\Support\Facades\Schema::hasTable('pcb_statuses')) {
                                $q->orWhereHas('statusDetails', function ($stq) use ($statusLower) {
                                    $stq->whereRaw('LOWER(TRIM(name)) = ?', [$statusLower])
                                        ->orWhereRaw('LOWER(TRIM(slug)) = ?', [$statusLower]);
                                });
                            }
                        });
                    }
                }

                // Date Range Filtering
                if ($request->filled('start_date')) {
                    $query->whereDate('created_at', '>=', $request->start_date);
                }
                if ($request->filled('end_date')) {
                    $query->whereDate('created_at', '<=', $request->end_date);
                }

                // C/G Filter
                if ($request->filled('c_g') && strtolower($request->c_g) !== 'all') {
                    $cgFilterVal = strtoupper(trim($request->c_g));
                    $query->where('c_g', $cgFilterVal);
                }

                // Search Filter (by Order #, Board Name, Email, Mobile, Customer Name, User/Company, Metas, Razorpay Payment IDs)
                if ($request->filled('search')) {
                    $search = trim($request->input('search'));
                    $query->where(function ($q) use (
                        $search,
                        $hasBoardNameCol,
                        $hasUserEmailCol,
                        $hasUserMobileCol,
                        $hasCustomerNameCol,
                        $hasPaymentTxTable,
                        $hasUsersName,
                        $hasUsersCompanyName,
                        $hasUsersEmail,
                        $hasUsersMobile,
                        $hasUsersPhoneNumber,
                        $hasUsersPhone
                    ) {
                        $q->where('order_number', 'LIKE', "%{$search}%");
                        $q->orWhere('bill_number', 'LIKE', "%{$search}%");
                        $q->orWhere('pcb_orders.pn_number', 'LIKE', "%{$search}%");
                        if ($hasUserEmailCol) {
                            $q->orWhere('user_email', 'LIKE', "%{$search}%");
                        }
                        if ($hasUserMobileCol) {
                            $q->orWhere('user_mobile', 'LIKE', "%{$search}%");
                        }
                        if ($hasCustomerNameCol) {
                            $q->orWhere('customer_name', 'LIKE', "%{$search}%");
                        }
                        if ($hasBoardNameCol) {
                            $q->orWhere('board_name', 'LIKE', "%{$search}%");
                        }
                        if (\Illuminate\Support\Facades\Schema::hasTable('gerber_files')) {
                            $q->orWhereHas('gerberFile', function ($gq) use ($search) {
                                $gq->where('original_name', 'LIKE', "%{$search}%")
                                   ->orWhere('file_name', 'LIKE', "%{$search}%");
                            });
                        }
                        $q->orWhereHas('user', function ($uq) use (
                            $search,
                            $hasUsersName,
                            $hasUsersCompanyName,
                            $hasUsersEmail,
                            $hasUsersMobile,
                            $hasUsersPhoneNumber,
                            $hasUsersPhone
                        ) {
                            $hasUserClause = false;
                            if ($hasUsersName) {
                                $uq->where('name', 'LIKE', "%{$search}%");
                                $hasUserClause = true;
                            }
                            if ($hasUsersCompanyName) {
                                $hasUserClause ? $uq->orWhere('company_name', 'LIKE', "%{$search}%") : $uq->where('company_name', 'LIKE', "%{$search}%");
                                $hasUserClause = true;
                            }
                            if ($hasUsersEmail) {
                                $hasUserClause ? $uq->orWhere('email', 'LIKE', "%{$search}%") : $uq->where('email', 'LIKE', "%{$search}%");
                                $hasUserClause = true;
                            }
                            if ($hasUsersMobile) {
                                $hasUserClause ? $uq->orWhere('mobile', 'LIKE', "%{$search}%") : $uq->where('mobile', 'LIKE', "%{$search}%");
                                $hasUserClause = true;
                            }
                            if ($hasUsersPhoneNumber) {
                                $hasUserClause ? $uq->orWhere('phone_number', 'LIKE', "%{$search}%") : $uq->where('phone_number', 'LIKE', "%{$search}%");
                                $hasUserClause = true;
                            }
                            if ($hasUsersPhone) {
                                $hasUserClause ? $uq->orWhere('phone', 'LIKE', "%{$search}%") : $uq->where('phone', 'LIKE', "%{$search}%");
                                $hasUserClause = true;
                            }
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

                // Calculate total counts and summary stats before applying sorting & pagination limit/offset
                $totalRecords = PcbOrder::count();
                $totalFiltered = (clone $query)->count();

                // Compute aggregated summary stats across ALL filtered orders matching the query & status
                $statsBuilder = (clone $query)->getQuery();
                $statsBuilder->orders = null;

                $hasStatusesTable = \Illuminate\Support\Facades\Schema::hasTable('pcb_order_statuses');
                $statusExpr = $hasStatusesTable
                    ? "LOWER(TRIM(COALESCE((SELECT name FROM pcb_order_statuses WHERE pcb_order_statuses.id = pcb_orders.status_id LIMIT 1), pcb_orders.status, 'pending')))"
                    : "LOWER(TRIM(COALESCE(pcb_orders.status, 'pending')))";

                $statsAgg = $statsBuilder->selectRaw("
                    COUNT(*) as total_orders,
                    COALESCE(SUM(CASE WHEN {$statusExpr} NOT IN ('completed', 'shipped', 'delivered', 'cancelled', 'canceled') THEN 1 ELSE 0 END), 0) as active_orders,
                    COALESCE(SUM(CASE WHEN {$statusExpr} IN ('completed', 'shipped', 'delivered') THEN 1 ELSE 0 END), 0) as completed_orders,
                    COALESCE(SUM(order_value), 0) as total_value,
                    COALESCE(SUM(
                        CASE WHEN (
                            pcb_orders.order_type = 'part' 
                            OR EXISTS (SELECT 1 FROM pcb_order_meta WHERE pcb_order_meta.pcb_order_id = pcb_orders.id AND pcb_order_meta.meta_key = 'product_type' AND LOWER(pcb_order_meta.meta_value) = 'part')
                        ) THEN 0 
                        ELSE COALESCE(NULLIF(order_qty, 0), (SELECT CAST(meta_value AS SIGNED) FROM pcb_order_meta WHERE pcb_order_id = pcb_orders.id AND meta_key IN ('qty', 'quantity') LIMIT 1), 0)
                        END
                    ), 0) as total_qty,
                    COALESCE(SUM(
                        CASE WHEN (
                            pcb_orders.order_type = 'part' 
                            OR EXISTS (SELECT 1 FROM pcb_order_meta WHERE pcb_order_meta.pcb_order_id = pcb_orders.id AND pcb_order_meta.meta_key = 'product_type' AND LOWER(pcb_order_meta.meta_value) = 'part')
                        ) THEN 0 
                        ELSE COALESCE(NULLIF(launch_qty, 0), (SELECT CAST(meta_value AS SIGNED) FROM pcb_order_meta WHERE pcb_order_id = pcb_orders.id AND meta_key = 'launch_qty' LIMIT 1), NULLIF(order_qty, 0), (SELECT CAST(meta_value AS SIGNED) FROM pcb_order_meta WHERE pcb_order_id = pcb_orders.id AND meta_key IN ('qty', 'quantity') LIMIT 1), 0)
                        END
                    ), 0) as launch_qty,
                    COALESCE(SUM(
                        CASE WHEN (
                            pcb_orders.order_type = 'part' 
                            OR EXISTS (SELECT 1 FROM pcb_order_meta WHERE pcb_order_meta.pcb_order_id = pcb_orders.id AND pcb_order_meta.meta_key = 'product_type' AND LOWER(pcb_order_meta.meta_value) = 'part')
                        ) THEN 0 
                        ELSE COALESCE(NULLIF(final_qty, 0), NULLIF(completed_qty, 0), (SELECT CAST(meta_value AS SIGNED) FROM pcb_order_meta WHERE pcb_order_id = pcb_orders.id AND meta_key IN ('final_qty', 'completed_qty') LIMIT 1), CASE WHEN {$statusExpr} IN ('completed', 'shipped', 'delivered') THEN COALESCE(NULLIF(order_qty, 0), (SELECT CAST(meta_value AS SIGNED) FROM pcb_order_meta WHERE pcb_order_id = pcb_orders.id AND meta_key IN ('qty', 'quantity') LIMIT 1), 0) ELSE 0 END)
                        END
                    ), 0) as final_qty,
                    COALESCE(SUM(
                        CASE WHEN (
                            pcb_orders.order_type = 'part' 
                            OR EXISTS (SELECT 1 FROM pcb_order_meta WHERE pcb_order_meta.pcb_order_id = pcb_orders.id AND pcb_order_meta.meta_key = 'product_type' AND LOWER(pcb_order_meta.meta_value) = 'part')
                        ) THEN 0 
                        ELSE COALESCE(NULLIF(failed_qty, 0), (SELECT CAST(meta_value AS SIGNED) FROM pcb_order_meta WHERE pcb_order_id = pcb_orders.id AND meta_key IN ('failed_qty', 'fail_qty') LIMIT 1), 0)
                        END
                    ), 0) as failed_qty
                ")->first();

                $statsTotalOrders = (int) ($statsAgg->total_orders ?? $totalFiltered);
                $statsActiveOrders = (int) ($statsAgg->active_orders ?? 0);
                $statsCompletedOrders = (int) ($statsAgg->completed_orders ?? 0);
                $statsTotalValue = (float) ($statsAgg->total_value ?? 0);
                $statsTotalQty = (int) ($statsAgg->total_qty ?? 0);
                $statsLaunchQty = (int) ($statsAgg->launch_qty ?? 0);
                $statsFinalQty = (int) ($statsAgg->final_qty ?? 0);
                $statsFailedQty = (int) ($statsAgg->failed_qty ?? 0);

                // Sorting (Default: created_at desc - latest placed orders first)
                $sortBy = $request->input('sort_by', 'created_at');
                $sortOrder = strtolower($request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';

                if ($sortBy === 'delivery_date') {
                    $query->orderByRaw("COALESCE(delivery_date, created_at) {$sortOrder}");
                } else if ($sortBy === 'created_at' || $sortBy === 'order_date') {
                    $query->orderBy('created_at', $sortOrder)->orderBy('id', $sortOrder);
                } else if ($sortBy === 'customer_name' || $sortBy === 'customer') {
                    $query->orderBy('customer_name', $sortOrder)->orderBy('user_email', $sortOrder);
                } else if ($sortBy === 'order_number' || $sortBy === 'number') {
                    $query->orderBy('order_number', $sortOrder);
                } else if ($sortBy === 'status') {
                    $query->orderBy('status', $sortOrder);
                } else if ($sortBy === 'layers' || $sortBy === 'layer') {
                    $query->orderBy('layers', $sortOrder);
                } else if ($sortBy === 'film_applied' || $sortBy === 'film') {
                    $query->orderBy('film_applied', $sortOrder);
                } else if ($sortBy === 'order_qty' || $sortBy === 'qty') {
                    $query->orderBy('order_qty', $sortOrder);
                } else if ($sortBy === 'c_g') {
                    $query->orderBy('c_g', $sortOrder);
                } else {
                    $query->orderBy($sortBy, $sortOrder);
                }

                $page = max(1, intval($request->input('page', 1)));
                $perPageInput = $request->input('per_page', $request->input('limit', 10));

                if ($perPageInput === 'all' && $request->boolean('allow_all', false)) {
                    $perPage = min(500, max(1, $totalFiltered));
                    $orders = $query->take($perPage)->get();
                } else {
                    $perPageVal = intval($perPageInput);
                    if ($perPageVal <= 0) {
                        $perPageVal = 10;
                    }
                    // Cap per page items to a maximum of 100 to ensure fast paginated delivery
                    $perPage = min(100, max(5, $perPageVal));
                    $orders = $query->skip(($page - 1) * $perPage)->take($perPage)->get();
                }

                $orders->transform(function ($order) {
                    if (empty($order->status) && isset($order->statusDetails) && !empty($order->statusDetails->name)) {
                        $order->status = $order->statusDetails->name;
                    } elseif (empty($order->status)) {
                        $order->status = 'Pending';
                    }
                    if (empty($order->pn_number)) {
                        $gf = $order->gerberFile;
                        if (!$gf && !empty($order->getMeta('gerber_file_id')) && \Illuminate\Support\Facades\Schema::hasTable('gerber_files')) {
                            $gf = \App\Models\GerberFile::find($order->getMeta('gerber_file_id'));
                        }
                        $order->pn_number = $order->getMeta('p_n')
                            ?: $order->getMeta('part_number')
                            ?: $order->getMeta('gerber_file_name')
                            ?: $order->getMeta('gerber_name')
                            ?: ($gf ? ($gf->original_name ?: $gf->file_name) : null)
                            ?: $order->getMeta('board_name')
                            ?: $order->board_name;
                    }
                    return $order;
                });

                return response()->json([
                    'status' => true,
                    'data' => $orders,
                    'total' => $totalFiltered,
                    'total_records' => $totalRecords,
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'last_page' => (int) ceil($totalFiltered / ($perPage > 0 ? $perPage : 1)),
                    'stats' => [
                        'total_orders'     => $statsTotalOrders,
                        'total_records'    => (int) $totalRecords,
                        'active_orders'    => $statsActiveOrders,
                        'completed_orders' => $statsCompletedOrders,
                        'total_value'      => $statsTotalValue,
                        'total_qty'        => $statsTotalQty,
                        'ordered_qty'      => $statsTotalQty,
                        'launch_qty'       => $statsLaunchQty,
                        'final_qty'        => $statsFinalQty,
                        'completed_qty'    => $statsFinalQty,
                        'failed_qty'       => $statsFailedQty,
                    ]
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
            if (\Illuminate\Support\Facades\Schema::hasTable('pcb_order_combos')) {
                $withRelations[] = 'comboOrders';
            }
            if (\Illuminate\Support\Facades\Schema::hasTable('pcb_order_old_orders')) {
                $withRelations[] = 'oldOrders';
            }
            if (\Illuminate\Support\Facades\Schema::hasTable('gerber_files')) {
                $withRelations[] = 'gerberFile';
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
                $notesQuery = \Illuminate\Support\Facades\DB::table('pcb_order_notes')
                    ->where('pcb_order_notes.pcb_order_id', $id);

                if (\Illuminate\Support\Facades\Schema::hasTable('admins')) {
                    $hasCreatedBy = \Illuminate\Support\Facades\Schema::hasColumn('pcb_order_notes', 'created_by');
                    $hasAdminId = \Illuminate\Support\Facades\Schema::hasColumn('pcb_order_notes', 'admin_id');

                    if ($hasCreatedBy && $hasAdminId) {
                        $joinCol = \Illuminate\Support\Facades\DB::raw('COALESCE(pcb_order_notes.created_by, pcb_order_notes.admin_id)');
                    } elseif ($hasCreatedBy) {
                        $joinCol = 'pcb_order_notes.created_by';
                    } elseif ($hasAdminId) {
                        $joinCol = 'pcb_order_notes.admin_id';
                    } else {
                        $joinCol = null;
                    }

                    if ($joinCol) {
                        $notesQuery->leftJoin('admins', function($join) use ($joinCol) {
                            $join->on('admins.id', '=', $joinCol);
                        });
                    }
                }

                if (\Illuminate\Support\Facades\Schema::hasColumn('pcb_order_notes', 'deleted_at')) {
                    $notesQuery->whereNull('pcb_order_notes.deleted_at');
                }

                $adminNameSql = 'COALESCE(admins.name, admins.username, "Admin")';

                $order->notes = $notesQuery->select(
                        'pcb_order_notes.*',
                        \Illuminate\Support\Facades\DB::raw("{$adminNameSql} as admin_name"),
                        \Illuminate\Support\Facades\DB::raw('COALESCE(admins.username, NULL) as admin_username')
                    )
                    ->orderBy('pcb_order_notes.created_at', 'desc')
                    ->get();

                // Fallback to active admin from order logs if admin_name is null
                $logAdminName = null;
                if (\Illuminate\Support\Facades\Schema::hasTable('pcb_order_logs')) {
                    $latestLog = \Illuminate\Support\Facades\DB::table('pcb_order_logs')
                        ->where(function($q) use ($id, $order) {
                            $q->where('pcb_order_id', $id)->orWhere('order_number', $order->order_number);
                        });
                    if (\Illuminate\Support\Facades\Schema::hasTable('admins')) {
                        $latestLog->leftJoin('admins', 'pcb_order_logs.admin_id', '=', 'admins.id');
                    }
                    $logRow = $latestLog->whereNotNull('admins.name')->select('admins.name')->latest('pcb_order_logs.id')->first();
                    if ($logRow && !empty($logRow->name)) {
                        $logAdminName = $logRow->name;
                    }
                }
                if (!$logAdminName && \Illuminate\Support\Facades\Schema::hasTable('admins')) {
                    $firstAdm = \Illuminate\Support\Facades\DB::table('admins')->where('id', '>', 0)->orderBy('id')->first();
                    if ($firstAdm) $logAdminName = $firstAdm->name ?? ($firstAdm->username ?? 'Admin');
                }

                $order->notes = $order->notes->map(function($n) use ($logAdminName) {
                    if (empty($n->admin_name) && empty($n->admin_username)) {
                        $n->admin_name = $logAdminName ?: 'Admin';
                    }
                    return $n;
                });
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

            // Verify if actual physical Gerber file exists on disk or valid URL
            $hasActualGerber = false;
            $gerberFileObj = $order->gerberFile;
            if (!$gerberFileObj && !empty($order->getMeta('gerber_file_id')) && \Illuminate\Support\Facades\Schema::hasTable('gerber_files')) {
                $gerberFileObj = \App\Models\GerberFile::find($order->getMeta('gerber_file_id'));
                if ($gerberFileObj) {
                    $order->setRelation('gerberFile', $gerberFileObj);
                }
            }
            if ($gerberFileObj) {
                $filePath = $gerberFileObj->file_path;
                $fileUrl = $gerberFileObj->file_url;
                if (!empty($filePath) && (
                    \Illuminate\Support\Facades\Storage::disk('public')->exists($filePath) ||
                    \Illuminate\Support\Facades\Storage::disk('local')->exists($filePath) ||
                    file_exists(storage_path('app/' . $filePath)) ||
                    file_exists(storage_path('app/public/' . $filePath)) ||
                    file_exists(public_path($filePath))
                )) {
                    $hasActualGerber = true;
                } elseif (!empty($fileUrl) && (
                    str_starts_with($fileUrl, 'http://') ||
                    str_starts_with($fileUrl, 'https://') ||
                    file_exists(public_path(ltrim($fileUrl, '/\\')))
                )) {
                    $hasActualGerber = true;
                }
            } else {
                $rawUrl = $order->getMeta('gerber_file_url') ?: $order->getMeta('gerber_url') ?: $order->getMeta('gerber_path');
                if ($rawUrl && !str_contains($rawUrl, 'null') && $rawUrl !== 'N/A') {
                    if (str_starts_with($rawUrl, 'http://') || str_starts_with($rawUrl, 'https://')) {
                        $hasActualGerber = true;
                    } elseif (file_exists(public_path(ltrim($rawUrl, '/\\'))) || file_exists(storage_path('app/public/' . ltrim($rawUrl, '/\\')))) {
                        $hasActualGerber = true;
                    }
                }
            }
            $order->has_actual_gerber = $hasActualGerber;

            // Pre-fill / default P/N number from Gerber file name or board name if empty
            if (empty($order->pn_number)) {
                $defaultPn = $order->getMeta('p_n')
                    ?: $order->getMeta('part_number')
                    ?: $order->getMeta('gerber_file_name')
                    ?: $order->getMeta('gerber_name')
                    ?: $order->getMeta('board_name')
                    ?: ($gerberFileObj ? ($gerberFileObj->original_name ?: $gerberFileObj->file_name) : null);
                if (!empty($defaultPn)) {
                    $order->pn_number = $defaultPn;
                }
            }

            if (empty($order->bill_number)) {
                $order->bill_number = $order->getMeta('bill_number');
            }

            if (empty($order->order_qty)) {
                $order->order_qty = (int)($order->getMeta('order_qty') ?: $order->getMeta('qty') ?: $order->getMeta('quantity') ?: 0);
            }
            if (empty($order->launch_qty)) {
                $order->launch_qty = (int)($order->getMeta('launch_qty') ?: $order->getMeta('launch') ?: $order->getMeta('launched_qty') ?: 0);
            }
            if (empty($order->panel_qty)) {
                $order->panel_qty = (int)($order->getMeta('panel_qty') ?: $order->getMeta('panel') ?: 0);
            }
            if (empty($order->ups_qty)) {
                $order->ups_qty = (int)($order->getMeta('ups_qty') ?: $order->getMeta('ups') ?: 0);
            }
            if (empty($order->final_qty)) {
                $order->final_qty = (int)($order->getMeta('final_qty') ?: $order->getMeta('completed_qty') ?: $order->getMeta('final') ?: 0);
            }
            if (empty($order->completed_qty)) {
                $order->completed_qty = (int)($order->getMeta('completed_qty') ?: $order->getMeta('final_qty') ?: $order->final_qty ?: 0);
            }
            if (empty($order->failed_qty)) {
                $order->failed_qty = (int)($order->getMeta('failed_qty') ?: $order->getMeta('failed') ?: 0);
            }

            if (empty($order->status) && isset($order->statusDetails) && !empty($order->statusDetails->name)) {
                $order->status = $order->statusDetails->name;
            } elseif (empty($order->status)) {
                $order->status = 'Pending';
            }

            return response()->json([
                'status' => true,
                'data' => $order
            ]);
        }

        public function update(Request $request, $id)
        {
            \Illuminate\Support\Facades\DB::beginTransaction();
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

                // Resolve and validate target status if status or status_id is in request
                $resolvedStatus = null;
                if ($request->has('status') || $request->has('status_id')) {
                    $reqStatus = $request->has('status') ? $request->input('status') : null;
                    $reqStatusId = $request->has('status_id') ? $request->input('status_id') : null;

                    [$resolvedStatus, $statusError] = \App\Services\OrderStatusResolver::resolveAndVerify($reqStatus, $reqStatusId);

                    if ($statusError) {
                        \Illuminate\Support\Facades\DB::rollBack();
                        return response()->json([
                            'success' => false,
                            'status'  => false,
                            'message' => $statusError,
                            'errors'  => [
                                'status' => [$statusError]
                            ]
                        ], 422);
                    }
                }

                // Business Rule: An order cannot be changed to Completed (or kept Completed) without a valid non-empty Bill Number.
                $completedStatuses = ['completed', 'delivered', 'order completed', 'production completed'];
                $targetStatusStr = $resolvedStatus ? $resolvedStatus->name : (string)($order->status ?? '');
                $isTargetCompleted = in_array(strtolower($targetStatusStr), $completedStatuses) ||
                                     ($resolvedStatus && !empty($resolvedStatus->slug) && in_array(strtolower($resolvedStatus->slug), $completedStatuses));

                if ($isTargetCompleted) {
                    $effectiveBillNumber = $request->has('bill_number')
                        ? trim((string)$request->input('bill_number'))
                        : trim((string)($order->bill_number ?? ''));

                    if ($effectiveBillNumber === '') {
                        \Illuminate\Support\Facades\DB::rollBack();
                        return response()->json([
                            'success' => false,
                            'status'  => false,
                            'message' => 'Bill number is required before changing the order status to Completed.',
                            'errors'  => [
                                'bill_number' => [
                                    'Bill number is required when completing an order.'
                                ]
                            ]
                        ], 422);
                    }
                }

                if ($request->has('c_g') && $request->input('c_g') !== null && trim((string)$request->input('c_g')) !== '') {
                    $normalizedCg = strtoupper(trim((string)$request->input('c_g')));
                    if (!in_array($normalizedCg, ['CASH', 'GST', 'BOTH'], true)) {
                        \Illuminate\Support\Facades\DB::rollBack();
                        return response()->json([
                            'success' => false,
                            'status'  => false,
                            'message' => 'The selected C/G is invalid. Allowed values: CASH, GST, BOTH.',
                            'errors'  => [
                                'c_g' => ['The selected C/G is invalid. Allowed values: CASH, GST, BOTH.']
                            ]
                        ], 422);
                    }
                }

                if ($request->has('order_number')) {
                    $cleanOrderNumber = trim((string)$request->input('order_number'));
                    if ($cleanOrderNumber === '') {
                        \Illuminate\Support\Facades\DB::rollBack();
                        return response()->json([
                            'success' => false,
                            'status'  => false,
                            'message' => 'Order number cannot be empty.',
                            'errors'  => [
                                'order_number' => [
                                    'Order number cannot be empty.'
                                ]
                            ]
                        ], 422);
                    }

                    if ((string)$order->order_number !== $cleanOrderNumber) {
                        $exists = \Illuminate\Support\Facades\DB::table('pcb_orders')
                            ->whereRaw('LOWER(TRIM(order_number)) = LOWER(?)', [$cleanOrderNumber])
                            ->where('id', '!=', $order->id)
                            ->whereNull('deleted_at')
                            ->exists();

                        if ($exists) {
                            \Illuminate\Support\Facades\DB::rollBack();
                            return response()->json([
                                'success' => false,
                                'status'  => false,
                                'message' => "Order number \"{$cleanOrderNumber}\" already exists. Please enter a different order number.",
                                'errors'  => [
                                    'order_number' => [
                                        "Order number \"{$cleanOrderNumber}\" already exists. Please enter a different order number."
                                    ]
                                ]
                            ], 422);
                        }

                        $oldOrderNo = $order->order_number ?? 'N/A';
                        $order->order_number = $cleanOrderNumber;
                        $changesLog[] = "Order Number: '{$oldOrderNo}' → '{$cleanOrderNumber}'";
                    }
                }

                if ($request->has('pn_number')) {
                    $newPn = trim((string)$request->input('pn_number'));
                    $currentPn = (string)($order->pn_number ?? '');
                    if ($currentPn !== $newPn) {
                        $oldPn = $order->pn_number ?? 'N/A';
                        $order->pn_number = $newPn !== '' ? $newPn : null;
                        $changesLog[] = "P/N Number: '{$oldPn}' → '" . ($newPn !== '' ? $newPn : 'Empty') . "'";

                        $metaTable = \Illuminate\Support\Facades\Schema::hasTable('pcb_order_meta') ? 'pcb_order_meta' : (\Illuminate\Support\Facades\Schema::hasTable('pcb_order_metas') ? 'pcb_order_metas' : null);
                        if ($metaTable) {
                            \Illuminate\Support\Facades\DB::table($metaTable)
                                ->where('pcb_order_id', $order->id)
                                ->where(function ($mq) use ($oldPn) {
                                    $mq->whereIn('meta_key', ['pn_number', 'p_n', 'part_number'])
                                       ->orWhere(function ($bmq) use ($oldPn) {
                                           $bmq->where('meta_key', 'board_name')
                                               ->where('meta_value', $oldPn);
                                       });
                                })
                                ->update(['meta_value' => $newPn !== '' ? $newPn : '']);

                            \Illuminate\Support\Facades\DB::table($metaTable)->updateOrInsert(
                                ['pcb_order_id' => $order->id, 'meta_key' => 'pn_number'],
                                ['meta_value' => $newPn !== '' ? $newPn : '']
                            );
                        }
                    }
                }

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

                if ($resolvedStatus) {
                    $oldStatusCanonical = \App\Services\OrderStatusResolver::resolve($order->status_id ?: $order->status);
                    $oldValStr = $oldStatusCanonical ? strtolower(trim((string)$oldStatusCanonical->name)) : strtolower(trim((string)($order->status ?? 'pending')));
                    $newValStr = strtolower(trim((string)$resolvedStatus->name));

                    if ($oldValStr !== $newValStr) {
                        $statusChanged = true;
                        $oldDisplay = $order->status ?: ($oldStatusCanonical ? $oldStatusCanonical->name : 'Pending');
                        $order->status = $resolvedStatus->name;
                        $order->status_id = $resolvedStatus->id;
                        $changesLog[] = "Status: '{$oldDisplay}' → '{$resolvedStatus->name}'";

                        if (!in_array($oldValStr, $completedStatuses) && in_array($newValStr, $completedStatuses)) {
                            $statusChangedToCompleted = true;
                        }

                        if ($oldValStr === 'pending' && $newValStr !== 'pending') {
                            $statusChangedFromPendingToProduction = true;
                        }
                    } else {
                        // Ensure both dual fields are synchronized without marking statusChanged
                        $order->status = $resolvedStatus->name;
                        $order->status_id = $resolvedStatus->id;
                    }
                }

                if ($request->has('launch_date') && $order->launch_date !== $request->launch_date) {
                    $order->launch_date = $request->launch_date;
                }

                if ($request->has('delivery_date')) {
                    $rawReqDate = $request->input('delivery_date');
                    $normReqDate = (!empty($rawReqDate)) ? date('Y-m-d', strtotime($rawReqDate)) : null;
                    $normOldDate = !empty($order->delivery_date) ? date('Y-m-d', strtotime($order->delivery_date)) : null;
                    if ($normReqDate !== $normOldDate) {
                        $oldValStr = !empty($normOldDate) ? date('d M Y', strtotime($normOldDate)) : 'N/A';
                        $newValStr = !empty($normReqDate) ? date('d M Y', strtotime($normReqDate)) : 'Cleared';
                        $order->delivery_date = $normReqDate;
                        $changesLog[] = "Delivery Date: '{$oldValStr}' → '{$newValStr}'";
                    }
                }

                if ($request->has('bill_number')) {
                    $cleanBillNumber = trim((string)$request->bill_number);
                    if ((string)$order->bill_number !== $cleanBillNumber) {
                        $oldVal = $order->bill_number ?? 'N/A';
                        $order->bill_number = $cleanBillNumber !== '' ? $cleanBillNumber : null;
                        $changesLog[] = "Bill No: '{$oldVal}' → '{$cleanBillNumber}'";
                    }
                }

                if ($request->has('order_value')) {
                    $newVal = floatval($request->order_value);
                    $oldVal = floatval($order->order_value);
                    if (abs($newVal - $oldVal) > 0.001) {
                        $order->order_value = $newVal;
                        $changesLog[] = "Order Value: ₹{$oldVal} → ₹{$newVal}";
                        \App\Models\PcbOrderMeta::updateOrCreate(
                            ['pcb_order_id' => $order->id, 'meta_key' => 'order_value'],
                            ['meta_value' => (string)$newVal]
                        );
                    }
                }

                if ($request->has('unit_price')) {
                    $newUnit = floatval($request->unit_price);
                    $oldUnit = floatval($order->unit_price);
                    if (abs($newUnit - $oldUnit) > 0.001) {
                        $order->unit_price = $newUnit;
                        $changesLog[] = "Unit Price: ₹{$oldUnit} → ₹{$newUnit}";
                        \App\Models\PcbOrderMeta::updateOrCreate(
                            ['pcb_order_id' => $order->id, 'meta_key' => 'unit_price'],
                            ['meta_value' => (string)$newUnit]
                        );
                    }
                }

                if ($request->has('created_at') || $request->has('submitted_at') || $request->has('submitted_on')) {
                    $rawSubmitted = $request->input('created_at', $request->input('submitted_at', $request->input('submitted_on')));
                    if (!empty($rawSubmitted)) {
                        $parsedDate = date('Y-m-d H:i:s', strtotime($rawSubmitted));
                        $order->created_at = $parsedDate;
                        $changesLog[] = "Submitted Date: " . date('d M Y', strtotime($parsedDate));
                    }
                }

                if ($request->has('layers')) {
                    $newLayers = intval($request->layers);
                    if (intval($order->layers) !== $newLayers) {
                        $order->layers = $newLayers;
                        $changesLog[] = "Layers: {$newLayers}";
                        \App\Models\PcbOrderMeta::updateOrCreate(
                            ['pcb_order_id' => $order->id, 'meta_key' => 'layers'],
                            ['meta_value' => (string)$newLayers]
                        );
                        \App\Models\PcbOrderMeta::updateOrCreate(
                            ['pcb_order_id' => $order->id, 'meta_key' => 'layer'],
                            ['meta_value' => (string)$newLayers]
                        );
                    }
                }

                if ($request->has('board_name')) {
                    $newBoard = trim((string)$request->board_name);
                    if ((string)$order->board_name !== $newBoard) {
                        $order->board_name = $newBoard;
                        $changesLog[] = "Board Name: {$newBoard}";
                        \App\Models\PcbOrderMeta::updateOrCreate(
                            ['pcb_order_id' => $order->id, 'meta_key' => 'board_name'],
                            ['meta_value' => $newBoard]
                        );
                    }
                }

                if ($request->has('mask') || $request->has('pcb_color') || $request->has('solder_mask')) {
                    $maskVal = trim((string)($request->input('mask') ?: $request->input('pcb_color') ?: $request->input('solder_mask')));
                    if ((string)$order->mask !== $maskVal) {
                        $order->mask = $maskVal;
                        $changesLog[] = "Mask Color: {$maskVal}";
                        \App\Models\PcbOrderMeta::updateOrCreate(
                            ['pcb_order_id' => $order->id, 'meta_key' => 'pcb_color'],
                            ['meta_value' => $maskVal]
                        );
                        \App\Models\PcbOrderMeta::updateOrCreate(
                            ['pcb_order_id' => $order->id, 'meta_key' => 'solder_mask'],
                            ['meta_value' => $maskVal]
                        );
                    }
                }

                // Handle arbitrary Technical Parameters and PCB Specifications
                $allowedMetaKeys = [
                    'base_material', 'material', 'substrate_type', 'material_type',
                    'layers', 'layer', 'dimensions', 'dimensions_width', 'dimensions_length', 'dimension_unit',
                    'quantity', 'qty', 'different_design', 'delivery_format', 'panel_format',
                    'thickness', 'board_thickness', 'pcb_color', 'solder_mask', 'coverlay_color',
                    'silkscreen', 'silkscreen_color', 'legend_color', 'surface_finish', 'finish',
                    'gold_thickness', 'copper_weight', 'copper_thickness', 'via_covering', 'via_plating',
                    'min_hole', 'min_hole_size', 'confirm_file', 'mark_on_pcb', 'elec_test',
                    'coverlay_thickness', 'stiffener', 'emi_shielding',
                    'gold_fingers', 'castellated', 'edge_plating', 'blind_slots',
                    'ul_marking', 'humidity', 'kelvin_test', 'paper_between', 'pcb_remark', 'remarks'
                ];

                if ($request->has('metas') && is_array($request->input('metas'))) {
                    foreach ($request->input('metas') as $mKey => $mVal) {
                        $cleanKey = strtolower(trim((string)$mKey));
                        if (in_array($cleanKey, $allowedMetaKeys)) {
                            $cleanVal = trim((string)$mVal);
                            \App\Models\PcbOrderMeta::updateOrCreate(
                                ['pcb_order_id' => $order->id, 'meta_key' => $cleanKey],
                                ['meta_value' => $cleanVal]
                            );
                            $changesLog[] = "Spec {$cleanKey}: {$cleanVal}";
                        }
                    }
                }

                foreach ($allowedMetaKeys as $specKey) {
                    if ($request->has($specKey) && !in_array($specKey, ['layers', 'order_qty', 'qty', 'mask', 'pcb_color', 'solder_mask'])) {
                        $specVal = trim((string)$request->input($specKey));
                        \App\Models\PcbOrderMeta::updateOrCreate(
                            ['pcb_order_id' => $order->id, 'meta_key' => $specKey],
                            ['meta_value' => $specVal]
                        );
                        $changesLog[] = "Spec {$specKey}: {$specVal}";
                    }
                }

                // Handle Manual Payment update / entry
                if ($request->has('payment_status') || $request->has('mark_as_paid') || $request->has('payment_method')) {
                    $payStatusInput = strtolower((string)$request->input('payment_status', $request->input('mark_as_paid') ? 'paid' : ''));
                    if (in_array($payStatusInput, ['paid', 'completed', 'success', 'successful', 'captured'])) {
                        if (\Illuminate\Support\Facades\Schema::hasTable('payment_transactions')) {
                            $existingTx = null;
                            if ($order->transaction_id) {
                                $existingTx = \Illuminate\Support\Facades\DB::table('payment_transactions')->where('id', $order->transaction_id)->first();
                            }
                            $payMethod = $request->input('payment_method', 'Manual');
                            $payAmount = floatval($request->input('order_value', $request->input('payment_amount', $order->order_value ?? 0)));
                            if ($existingTx) {
                                \Illuminate\Support\Facades\DB::table('payment_transactions')
                                    ->where('id', $existingTx->id)
                                    ->update([
                                        'status' => 'success',
                                        'amount' => $payAmount > 0 ? $payAmount : floatval($existingTx->amount),
                                        'payment_method' => $payMethod ?: ($existingTx->payment_method ?? 'Manual'),
                                        'updated_at' => date('Y-m-d H:i:s')
                                    ]);
                            } else {
                                $txnNum = 'TXN-MANUAL-' . strtoupper(\Illuminate\Support\Str::random(8));
                                $newTxnId = \Illuminate\Support\Facades\DB::table('payment_transactions')->insertGetId([
                                    'user_id' => $order->user_id,
                                    'transaction_number' => $txnNum,
                                    'amount' => $payAmount,
                                    'currency' => 'INR',
                                    'status' => 'success',
                                    'payment_method' => $payMethod,
                                    'payload' => json_encode(['note' => 'Manual payment recorded by admin', 'order_number' => $order->order_number]),
                                    'created_at' => date('Y-m-d H:i:s'),
                                    'updated_at' => date('Y-m-d H:i:s')
                                ]);
                                $order->transaction_id = $newTxnId;
                                if (\Illuminate\Support\Facades\Schema::hasColumn('pcb_orders', 'transaction_id')) {
                                    \Illuminate\Support\Facades\DB::table('pcb_orders')->where('id', $order->id)->update(['transaction_id' => $newTxnId]);
                                }
                            }
                            $changesLog[] = "Payment Status: Paid ({$payMethod})";
                        }
                    }
                }

                if ($request->has('q_no') && (string)$order->q_no !== (string)$request->q_no) {
                    $oldVal = $order->q_no ?? 'N/A';
                    $order->q_no = $request->q_no;
                    $changesLog[] = "Q.No: '{$oldVal}' → '{$request->q_no}'";
                }

                if ($request->has('combo_order_ids')) {
                    $comboInput = $request->input('combo_order_ids');
                    if (is_string($comboInput)) {
                        $comboInput = \App\Services\ComboOrderService::parseComboString($comboInput);
                    } elseif (!is_array($comboInput)) {
                        $comboInput = [];
                    }
                    $err = null;
                    \App\Services\ComboOrderService::syncComboOrders($order, $comboInput, $err);
                    if ($err) {
                        \Illuminate\Support\Facades\DB::rollBack();
                        return response()->json(['success' => false, 'message' => $err], 422);
                    }
                    $changesLog[] = "Combo Orders Updated";
                } elseif ($request->has('combo') && (string)$order->combo !== (string)$request->combo) {
                    $oldVal = $order->combo ?? 'N/A';
                    $comboInput = \App\Services\ComboOrderService::parseComboString($request->combo);
                    $err = null;
                    \App\Services\ComboOrderService::syncComboOrders($order, $comboInput, $err);
                    if ($err) {
                        \Illuminate\Support\Facades\DB::rollBack();
                        return response()->json(['success' => false, 'message' => $err], 422);
                    }
                    $changesLog[] = "Combo: '{$oldVal}' → '{$request->combo}'";
                }

                if ($request->has('old_order_ids')) {
                    $oldInput = $request->input('old_order_ids');
                    if (is_string($oldInput)) {
                        $oldInput = \App\Services\OldOrderService::parseOldOrderString($oldInput);
                    } elseif (!is_array($oldInput)) {
                        $oldInput = [];
                    }
                    $err = null;
                    \App\Services\OldOrderService::syncOldOrders($order, $oldInput, $err);
                    if ($err) {
                        \Illuminate\Support\Facades\DB::rollBack();
                        return response()->json(['success' => false, 'message' => $err], 422);
                    }
                    $changesLog[] = "Old Order Numbers Updated";
                } elseif ($request->has('old_order_number') && (string)$order->old_order_number !== (string)$request->old_order_number) {
                    $oldVal = $order->old_order_number ?? 'N/A';
                    $oldInput = \App\Services\OldOrderService::parseOldOrderString($request->old_order_number);
                    $err = null;
                    \App\Services\OldOrderService::syncOldOrders($order, $oldInput, $err);
                    if ($err) {
                        \Illuminate\Support\Facades\DB::rollBack();
                        return response()->json(['success' => false, 'message' => $err], 422);
                    }
                    $changesLog[] = "Old Order Number: '{$oldVal}' → '{$request->old_order_number}'";
                }

                if ($request->has('c_g')) {
                    $rawCg = $request->input('c_g');
                    $newCg = ($rawCg !== null && trim((string)$rawCg) !== '') ? strtoupper(trim((string)$rawCg)) : null;
                    if ($order->c_g !== $newCg) {
                        $oldCg = $order->c_g ?? 'None';
                        $displayNewCg = $newCg ?? 'None';
                        $changesLog[] = "C/G: '{$oldCg}' → '{$displayNewCg}'";
                        $order->c_g = $newCg;
                    }
                }

                if ($request->has('film_applied')) {
                    $filmBool = filter_var($request->input('film_applied'), FILTER_VALIDATE_BOOLEAN) || $request->input('film_applied') == 1 || $request->input('film_applied') === '1' || $request->input('film_applied') === 'true';
                    $order->film_applied = $filmBool ? 1 : 0;
                    $changesLog[] = "Film Applied: " . ($filmBool ? "Yes" : "No");

                    if (\Illuminate\Support\Facades\Schema::hasColumn('pcb_orders', 'film_applied')) {
                        \Illuminate\Support\Facades\DB::table('pcb_orders')->where('id', $order->id)->update(['film_applied' => $filmBool ? 1 : 0]);
                    }

                    \App\Models\PcbOrderMeta::updateOrCreate(
                        ['pcb_order_id' => $order->id, 'meta_key' => 'film_applied'],
                        ['meta_value' => $filmBool ? '1' : '0']
                    );
                }

                if ($request->has('order_qty') && intval($order->order_qty) !== intval($request->order_qty)) {
                    $oldVal = intval($order->order_qty);
                    $order->order_qty = intval($request->order_qty);
                    $changesLog[] = "Order Qty: {$oldVal} → {$order->order_qty} Pcs";
                    \App\Models\PcbOrderMeta::updateOrCreate(
                        ['pcb_order_id' => $order->id, 'meta_key' => 'order_qty'],
                        ['meta_value' => (string)$order->order_qty]
                    );
                    \App\Models\PcbOrderMeta::updateOrCreate(
                        ['pcb_order_id' => $order->id, 'meta_key' => 'qty'],
                        ['meta_value' => (string)$order->order_qty]
                    );
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

                if ($request->has('failed_qty')) {
                    $newFailedQty = intval($request->failed_qty);
                    if (intval($order->failed_qty) !== $newFailedQty) {
                        $oldVal = intval($order->failed_qty ?? 0);
                        $order->failed_qty = $newFailedQty;
                        $changesLog[] = "Failed Qty: {$oldVal} → {$newFailedQty} Pcs";
                        PcbOrderMeta::updateOrCreate(
                            ['pcb_order_id' => $order->id, 'meta_key' => 'failed_qty'],
                            ['meta_value' => (string)$newFailedQty]
                        );
                    }
                }

                try {
                    $order->save();
                } catch (\Illuminate\Database\QueryException $e) {
                    \Illuminate\Support\Facades\DB::rollBack();
                    if ($e->getCode() == 23000 || $e->getCode() == 1062 || str_contains(strtolower($e->getMessage()), 'duplicate')) {
                        $dupVal = isset($cleanOrderNumber) ? $cleanOrderNumber : ($order->order_number ?? '');
                        return response()->json([
                            'success' => false,
                            'status'  => false,
                            'message' => "Order number \"{$dupVal}\" already exists. Please enter a different order number.",
                            'errors'  => [
                                'order_number' => [
                                    "Order number \"{$dupVal}\" already exists. Please enter a different order number."
                                ]
                            ]
                        ], 422);
                    }
                    throw $e;
                }

                // Synchronize status and bill_number to child combo member orders if parent order
                $adminId = $request->attributes->get('admin_id') ?? $request->admin_id ?? 1;
                $adminUser = $adminId ? \Illuminate\Support\Facades\DB::table('admins')->where('id', $adminId)->first() : null;
                $adminName = $adminUser ? $adminUser->name : 'Admin';
                \App\Services\ComboOrderService::syncComboStatus($order, (string)$order->status, (int)$adminId, (string)$adminName);

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
                    $freshFilmApplied = \Illuminate\Support\Facades\Schema::hasColumn('pcb_orders', 'film_applied')
                        ? \Illuminate\Support\Facades\DB::table('pcb_orders')->where('id', $order->id)->value('film_applied')
                        : ($order->getMeta('film_applied') ?: null);
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

                // 1. Create status change history log in pcb_order_status_histories ONLY if status actually changed
                if ($statusChanged && \Illuminate\Support\Facades\Schema::hasTable('pcb_order_status_histories')) {
                    PcbOrderStatusHistory::create([
                        'pcb_order_id' => $order->id,
                        'admin_id' => $adminId ?: 1,
                        'status_name' => $order->status,
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

                    $isMobile = ($request->input('source') === 'mobile') || ($request->header('X-Source') === 'mobile');
                    $sourceLabel = $isMobile ? " [Mobile App]" : "";
                    $actionName = ($request->has('status') ? "Status & Details Updated" : "Order Parameters Updated") . $sourceLabel;
                    $changesStr = implode(", ", $changesLog);
                    $descText = "Updated{$sourceLabel} by {$adminName}: {$changesStr}." . ($remark ? " Remark: {$remark}" : "");

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

                \Illuminate\Support\Facades\DB::commit();

                $withRels = ['metas', 'statusDetails', 'statusHistories'];
                if (\Illuminate\Support\Facades\Schema::hasTable('gerber_files')) {
                    $withRels[] = 'gerberFile';
                }
                return response()->json([
                    'status' => true,
                    'success' => true,
                    'message' => 'Order updated successfully',
                    'data' => $order->load($withRels)
                ]);
            } catch (\Illuminate\Validation\ValidationException $ve) {
                \Illuminate\Support\Facades\DB::rollBack();
                return response()->json([
                    'status' => false,
                    'success' => false,
                    'message' => $ve->getMessage(),
                    'errors' => $ve->errors()
                ], 422);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\DB::rollBack();
                return response()->json([
                    'status' => false,
                    'message' => $e->getMessage(),
                    'error' => $e->getMessage()
                ], 500);
            }
        }

        // Order Internal Notes
        // Order Internal Notes
        public function getNotes($id)
        {
            try {
                if (!\Illuminate\Support\Facades\Schema::hasTable('pcb_order_notes')) {
                    return response()->json(['status' => true, 'data' => []]);
                }

                $order = \Illuminate\Support\Facades\DB::table('pcb_orders')
                    ->where('id', $id)
                    ->orWhere('order_number', $id)
                    ->first();
                $orderId = $order ? $order->id : $id;

                // Ensure created_by column exists
                if (!\Illuminate\Support\Facades\Schema::hasColumn('pcb_order_notes', 'created_by')) {
                    try {
                        \Illuminate\Support\Facades\Schema::table('pcb_order_notes', function (\Illuminate\Database\Schema\Blueprint $table) {
                            $table->unsignedBigInteger('created_by')->nullable()->after('admin_id');
                        });
                        \Illuminate\Support\Facades\DB::statement("UPDATE pcb_order_notes SET created_by = admin_id WHERE created_by IS NULL AND admin_id IS NOT NULL");
                    } catch (\Throwable $e) {}
                }

                $notesQuery = \Illuminate\Support\Facades\DB::table('pcb_order_notes')
                    ->where('pcb_order_notes.pcb_order_id', $orderId);

                if (\Illuminate\Support\Facades\Schema::hasTable('admins')) {
                    $notesQuery->leftJoin('admins', function($join) {
                        $join->on('admins.id', '=', \Illuminate\Support\Facades\DB::raw('COALESCE(pcb_order_notes.created_by, pcb_order_notes.admin_id)'));
                    });
                }

                if (\Illuminate\Support\Facades\Schema::hasColumn('pcb_order_notes', 'deleted_at')) {
                    $notesQuery->whereNull('pcb_order_notes.deleted_at');
                }

                $adminNameSql = 'COALESCE(admins.name, admins.username, "Admin")';

                $notes = $notesQuery->select(
                        'pcb_order_notes.*',
                        \Illuminate\Support\Facades\DB::raw("{$adminNameSql} as admin_name"),
                        \Illuminate\Support\Facades\DB::raw('COALESCE(admins.username, NULL) as admin_username')
                    )
                    ->orderBy('pcb_order_notes.created_at', 'desc')
                    ->get();

                // Fallback to active admin from order logs if admin_name is null
                $logAdminName = null;
                if ($order && \Illuminate\Support\Facades\Schema::hasTable('pcb_order_logs')) {
                    $latestLog = \Illuminate\Support\Facades\DB::table('pcb_order_logs')
                        ->where(function($q) use ($order) {
                            $q->where('pcb_order_id', $order->id)->orWhere('order_number', $order->order_number);
                        });
                    if (\Illuminate\Support\Facades\Schema::hasTable('admins')) {
                        $latestLog->leftJoin('admins', 'pcb_order_logs.admin_id', '=', 'admins.id');
                    }
                    $logRow = $latestLog->whereNotNull('admins.name')->select('admins.name')->latest('pcb_order_logs.id')->first();
                    if ($logRow && !empty($logRow->name)) {
                        $logAdminName = $logRow->name;
                    }
                }
                if (!$logAdminName && \Illuminate\Support\Facades\Schema::hasTable('admins')) {
                    $firstAdm = \Illuminate\Support\Facades\DB::table('admins')->where('id', '>', 0)->orderBy('id')->first();
                    if ($firstAdm) $logAdminName = $firstAdm->name ?? ($firstAdm->username ?? 'Admin');
                }

                $notes = $notes->map(function($n) use ($logAdminName) {
                    if (empty($n->admin_name) && empty($n->admin_username)) {
                        $n->admin_name = $logAdminName ?: 'Admin';
                    }
                    return $n;
                });

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
                $adminId = $request->input('created_by') 
                    ?: ($request->input('admin_id') 
                    ?: ($request->attributes->get('admin_id') 
                    ?: 1));

                if (empty($noteText)) {
                    return response()->json(['status' => false, 'message' => 'Note content cannot be empty.'], 400);
                }

                $order = \Illuminate\Support\Facades\DB::table('pcb_orders')
                    ->where('id', $id)
                    ->orWhere('order_number', $id)
                    ->first();
                $orderId = $order ? $order->id : $id;

                // Ensure created_by column exists
                if (\Illuminate\Support\Facades\Schema::hasTable('pcb_order_notes')) {
                    if (!\Illuminate\Support\Facades\Schema::hasColumn('pcb_order_notes', 'created_by')) {
                        try {
                            \Illuminate\Support\Facades\Schema::table('pcb_order_notes', function (\Illuminate\Database\Schema\Blueprint $table) {
                                $table->unsignedBigInteger('created_by')->nullable()->after('admin_id');
                            });
                        } catch (\Throwable $e) {}
                    }
                }

                $insertData = [
                    'pcb_order_id' => $orderId,
                    'admin_id' => $adminId,
                    'note' => $noteText,
                    'is_internal' => true,
                    'created_at' => now(),
                    'updated_at' => now()
                ];

                if (\Illuminate\Support\Facades\Schema::hasColumn('pcb_order_notes', 'created_by')) {
                    $insertData['created_by'] = $adminId;
                }

                $noteId = \Illuminate\Support\Facades\DB::table('pcb_order_notes')->insertGetId($insertData);

                // Fetch new note and resolve exact admin name by querying admins table
                $newNoteQuery = \Illuminate\Support\Facades\DB::table('pcb_order_notes')
                    ->where('pcb_order_notes.id', $noteId);

                if (\Illuminate\Support\Facades\Schema::hasTable('admins')) {
                    $newNoteQuery->leftJoin('admins', function($join) {
                        $join->on('admins.id', '=', \Illuminate\Support\Facades\DB::raw('COALESCE(pcb_order_notes.created_by, pcb_order_notes.admin_id)'));
                    });
                }

                $adminNameSql = 'COALESCE(admins.name, admins.username, "Admin")';

                $newNote = $newNoteQuery->select(
                        'pcb_order_notes.*',
                        \Illuminate\Support\Facades\DB::raw("{$adminNameSql} as admin_name"),
                        \Illuminate\Support\Facades\DB::raw('COALESCE(admins.username, NULL) as admin_username')
                    )
                    ->first();

                return response()->json([
                    'status' => true,
                    'message' => 'Note added successfully.',
                    'data' => $newNote ?: ['id' => $noteId, 'created_by' => $adminId, 'admin_id' => $adminId, 'note' => $noteText]
                ], 201);
            } catch (\Throwable $th) {
                return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
            }
        }

        public function deleteNote($noteId)
        {
            try {
                if (\Illuminate\Support\Facades\Schema::hasColumn('pcb_order_notes', 'deleted_at')) {
                    \Illuminate\Support\Facades\DB::table('pcb_order_notes')
                        ->where('id', $noteId)
                        ->update(['deleted_at' => now()]);
                } else {
                    \Illuminate\Support\Facades\DB::table('pcb_order_notes')->where('id', $noteId)->delete();
                }
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
                    'board_name' => 'nullable|string|max:200',
                    'pn_number' => 'nullable|string|max:255',
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

                if ($request->filled('delivery_date')) {
                    $validation = \App\Services\DeliveryCalendarService::validateDeliveryDate($request->delivery_date);
                    if (!$validation['valid']) {
                        return response()->json([
                            'status' => false,
                            'success' => false,
                            'message' => $validation['reason']
                        ], 422);
                    }
                }

                return \Illuminate\Support\Facades\DB::transaction(function () use ($request) {
                    $userId = $request->input('user_id');
                    if (empty($userId) || $userId == 0) {
                        $userId = null;
                    }

                    $sourceResolution = \App\Services\OrderPricingService::resolveOrderSource($request->all());
                    $reqSource = $sourceResolution['quotation_source'];
                    $reqOrderType = $sourceResolution['order_type'];
                    $series = $sourceResolution['series'];
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
                                'user_id' => $userId ?? 0,
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

                    // Authoritative pricing calculation via OrderPricingService
                    $pricing = \App\Services\OrderPricingService::calculateOrderPricing($request->all());

                    // Handle Payment & Transaction
                    $transactionId = null;
                    $payMethod = $request->input('payment_method', 'Manual Payment');
                    $isPaid = strtolower($request->input('payment_status', 'pending')) === 'completed' ||
                              strtolower($request->input('payment_status', 'pending')) === 'paid' ||
                              $request->boolean('payment_completed') ||
                              $payMethod === 'Manual Payment';

                    if ($isPaid && \Illuminate\Support\Facades\Schema::hasTable('payment_transactions')) {
                        $txnNum = $request->input('payment_reference') ?: ('TXN-MANUAL-' . strtoupper(Str::random(8)));
                        $transactionId = \Illuminate\Support\Facades\DB::table('payment_transactions')->insertGetId([
                            'user_id' => $userId,
                            'transaction_number' => $txnNum,
                            'amount' => $pricing['total_amount'],
                            'currency' => 'INR',
                            'status' => 'success',
                            'payment_method' => $payMethod,
                            'payload' => json_encode([
                                'reference' => $request->input('payment_reference'),
                                'payment_date' => $request->input('payment_date', date('Y-m-d')),
                                'notes' => $request->input('payment_notes', 'Manual payment recorded by admin'),
                                'order_number' => $orderNumber
                            ]),
                            'created_at' => date('Y-m-d H:i:s'),
                            'updated_at' => date('Y-m-d H:i:s')
                        ]);
                    }

                    // Resolve canonical Pending status
                    $canonicalPending = \App\Services\OrderStatusResolver::getDefaultStatus();
                    $statusId = $canonicalPending ? $canonicalPending->id : null;
                    $statusName = $canonicalPending ? $canonicalPending->name : 'Pending';

                    $pnNumber = $request->filled('pn_number') ? trim($request->input('pn_number')) : ($gerberFileName ?: null);

                    // Create Order Record
                    $orderData = [
                        'user_id' => $userId,
                        'order_number' => $orderNumber,
                        'order_type' => $reqOrderType,
                        'quotation_source' => $reqSource,
                        'jlcpcb_file_key' => ($reqOrderType === 'jlcpcb') ? ($request->input('jlcpcb_file_key') ?: null) : null,
                        'jlcpcb_quotation_snapshot' => ($reqOrderType === 'jlcpcb') ? ($request->input('jlcpcb_quotation_snapshot') ?: null) : null,
                        'pn_number' => $pnNumber,
                        'status' => $statusName,
                        'status_id' => $statusId,
                        'gerber_file_id' => $gerberFileId,
                        'transaction_id' => $transactionId,
                        'unit_price' => $pricing['unit_price'],
                        'order_value' => $pricing['total_amount'],
                        'delivery_date' => $request->input('delivery_date') ?: null,
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s')
                    ];

                    if (\Illuminate\Support\Facades\Schema::hasColumn('pcb_orders', 'board_name')) {
                        $orderData['board_name'] = $request->input('board_name') ?: ($pnNumber ?: 'Standard PCB');
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
                    unset($allParams['pn_number']);

                    if ($gerberFileUrl) {
                        $allParams['gerber_file_url'] = $gerberFileUrl;
                        $allParams['gerber_file_name'] = $gerberFileName;
                        $allParams['gerber_file_size'] = $gerberFileSize;
                    }

                    // Store explicit calculated pricing metadata
                    $allParams['pricing_method'] = $pricing['pricing_method'];
                    $allParams['manual_price'] = $pricing['manual_price'];
                    $allParams['pcb_rate'] = $pricing['pcb_rate'];
                    $allParams['price_per_sqm'] = $pricing['price_per_sqm'];
                    $allParams['subtotal'] = $pricing['subtotal'];
                    $allParams['gst_rate'] = $pricing['gst_rate'];
                    $allParams['gst_amount'] = $pricing['gst_amount'];
                    $allParams['total_amount'] = $pricing['total_amount'];
                    $allParams['payment_method'] = $payMethod;

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
                            'pn_number' => $pnNumber,
                            'order_value' => $pricing['total_amount'],
                            'transaction_id' => $transactionId
                        ]
                    ], 201);
                });

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

                return \Illuminate\Support\Facades\DB::transaction(function () use ($request, $originalOrder) {
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

                    // Find and assign Pending status_id
                    $statusId = null;
                    if (\Illuminate\Support\Facades\Schema::hasTable('pcb_order_statuses')) {
                        $st = \Illuminate\Support\Facades\DB::table('pcb_order_statuses')->where('name', 'Pending')->first();
                        if ($st) {
                            $statusId = $st->id;
                        }
                    } elseif (\Illuminate\Support\Facades\Schema::hasTable('pcb_statuses')) {
                        $st = \Illuminate\Support\Facades\DB::table('pcb_statuses')->where('name', 'Pending')->first();
                        if ($st) {
                            $statusId = $st->id;
                        }
                    } elseif (\Illuminate\Support\Facades\Schema::hasTable('statuses')) {
                        $st = \Illuminate\Support\Facades\DB::table('statuses')->where('name', 'Pending')->first();
                        if ($st) {
                            $statusId = $st->id;
                        }
                    }
                    $newOrder->status_id = $statusId;

                    if (\Illuminate\Support\Facades\Schema::hasColumn('pcb_orders', 'is_reorder')) {
                        $newOrder->is_reorder = true;
                    }
                    if (\Illuminate\Support\Facades\Schema::hasColumn('pcb_orders', 'source_order_number')) {
                        $newOrder->source_order_number = $originalOrder->order_number;
                    }
                    if (\Illuminate\Support\Facades\Schema::hasColumn('pcb_orders', 'source_order_id')) {
                        $newOrder->source_order_id = $originalOrder->id;
                    }

                    $newOrder->completed_qty = 0;
                    $newOrder->failed_qty = 0;
                    $newOrder->launch_qty = 0;
                    $newOrder->panel_qty = 0;
                    $newOrder->ups_qty = 0;
                    $newOrder->final_qty = 0;
                    $newOrder->bill_number = null;
                    $newOrder->launch_date = null;

                    // Resolve C/G status: copy from original order or explicit request
                    if ($request->has('c_g') && !empty($request->input('c_g'))) {
                        $newOrder->c_g = strtoupper(trim($request->input('c_g')));
                    } elseif (!empty($originalOrder->c_g)) {
                        $newOrder->c_g = strtoupper(trim($originalOrder->c_g));
                    } else {
                        $newOrder->c_g = null;
                    }

                    // Handle custom order quantity if specified
                    $reqQty = $request->input('order_qty', $request->input('quantity', $originalOrder->order_qty ?: 1));
                    $newOrder->order_qty = max(1, (int)$reqQty);

                    // Handle custom delivery date if specified
                    $reqDeliveryDate = $request->input('delivery_date', $request->input('deliveryDate'));
                    if (!empty($reqDeliveryDate)) {
                        $newOrder->delivery_date = $reqDeliveryDate;
                    }

                    // Extract dimensions from original order metas if present
                    $dimLen = floatval($request->input('dimensions_length', $originalOrder->getMeta('dimensions_length', 100)));
                    $dimWid = floatval($request->input('dimensions_width', $originalOrder->getMeta('dimensions_width', 100)));
                    if ($dimLen <= 0 || $dimWid <= 0) {
                        $dimStr = (string)$originalOrder->getMeta('dimensions', '');
                        if (preg_match('/(\d+(?:\.\d+)?)\s*x\s*(\d+(?:\.\d+)?)/i', $dimStr, $m)) {
                            $dimLen = floatval($m[1]);
                            $dimWid = floatval($m[2]);
                        }
                    }

                    // Authoritative pricing calculation via OrderPricingService
                    $pricingParams = array_merge($request->all(), [
                        'launch_qty' => $newOrder->order_qty,
                        'order_qty' => $newOrder->order_qty,
                        'dimensions_length' => $dimLen,
                        'dimensions_width' => $dimWid,
                        'auto_calculated_value' => ($originalOrder->unit_price > 0 ? $originalOrder->unit_price * $newOrder->order_qty : $originalOrder->order_value)
                    ]);
                    $pricing = \App\Services\OrderPricingService::calculateOrderPricing($pricingParams);

                    $newOrder->unit_price = $pricing['unit_price'];
                    $newOrder->order_value = $pricing['total_amount'];

                    // Handle Manual Payment Transaction creation for reorder
                    $transactionId = null;
                    $payMethod = $request->input('payment_method', 'Online');
                    if ($payMethod === 'Manual Payment' || $request->boolean('payment_completed')) {
                        if (\Illuminate\Support\Facades\Schema::hasTable('payment_transactions')) {
                            $txnNum = $request->input('payment_reference') ?: ('TXN-MANUAL-' . strtoupper(Str::random(8)));
                            $transactionId = \Illuminate\Support\Facades\DB::table('payment_transactions')->insertGetId([
                                'user_id' => $originalOrder->user_id,
                                'transaction_number' => $txnNum,
                                'amount' => $pricing['total_amount'],
                                'currency' => 'INR',
                                'status' => 'success',
                                'payment_method' => 'Manual Payment',
                                'payload' => json_encode([
                                    'reference' => $request->input('payment_reference'),
                                    'payment_date' => $request->input('payment_date', date('Y-m-d')),
                                    'notes' => $request->input('payment_notes', 'Manual payment recorded for reorder'),
                                    'reordered_from' => $originalOrder->order_number,
                                    'order_number' => $newOrderNumber
                                ]),
                                'created_at' => date('Y-m-d H:i:s'),
                                'updated_at' => date('Y-m-d H:i:s')
                            ]);
                        }
                    }
                    $newOrder->transaction_id = $transactionId;

                    $newOrder->created_at = now();
                    $newOrder->updated_at = now();
                    $newOrder->save();

                    // Replicate metadata with updated pricing, quantity, delivery_date, order_value, and status = Pending
                    $hasQtyMeta = false;
                    $hasDeliveryDateMeta = false;
                    $hasStatusMeta = false;

                    $updatedMetas = [
                        'pricing_method' => $pricing['pricing_method'],
                        'pcb_rate' => $pricing['pcb_rate'],
                        'price_per_sqm' => $pricing['price_per_sqm'],
                        'subtotal' => $pricing['subtotal'],
                        'gst_rate' => $pricing['gst_rate'],
                        'gst_amount' => $pricing['gst_amount'],
                        'total_amount' => $pricing['total_amount'],
                        'payment_method' => $payMethod,
                    ];
                    if ($request->filled('payment_reference')) {
                        $updatedMetas['payment_reference'] = $request->input('payment_reference');
                    }
                    if ($request->filled('payment_notes')) {
                        $updatedMetas['payment_notes'] = $request->input('payment_notes');
                    }

                    foreach ($originalOrder->metas as $meta) {
                        if (in_array($meta->meta_key, ['film_datetime', 'film_date'])) {
                            continue;
                        }

                        $metaValue = $meta->meta_value;
                        if (in_array($meta->meta_key, ['status', 'order_status', 'pcb_status'])) {
                            $hasStatusMeta = true;
                            $metaValue = 'Pending';
                        } elseif ($meta->meta_key === 'quantity') {
                            $hasQtyMeta = true;
                            $metaValue = (string) $newOrder->order_qty;
                        } elseif ($meta->meta_key === 'delivery_date') {
                            $hasDeliveryDateMeta = true;
                            if ($newOrder->delivery_date) {
                                $metaValue = (string) $newOrder->delivery_date;
                            }
                        } elseif (in_array($meta->meta_key, ['order_value', 'total_price', 'total'])) {
                            $metaValue = (string) $newOrder->order_value;
                        } elseif (array_key_exists($meta->meta_key, $updatedMetas)) {
                            $metaValue = (string) $updatedMetas[$meta->meta_key];
                            unset($updatedMetas[$meta->meta_key]);
                        }

                        PcbOrderMeta::create([
                            'pcb_order_id' => $newOrder->id,
                            'meta_key' => $meta->meta_key,
                            'meta_value' => $metaValue,
                        ]);
                    }

                    // Write any remaining new pricing metas
                    foreach ($updatedMetas as $uKey => $uVal) {
                        PcbOrderMeta::create([
                            'pcb_order_id' => $newOrder->id,
                            'meta_key' => $uKey,
                            'meta_value' => (string) $uVal,
                        ]);
                    }

                    if (!$hasStatusMeta) {
                        PcbOrderMeta::create([
                            'pcb_order_id' => $newOrder->id,
                            'meta_key' => 'status',
                            'meta_value' => 'Pending',
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
                });
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
                $productType = $metas['product_type'] ?? 'pcb';
                $sourceResolution = \App\Services\OrderPricingService::resolveOrderSource(array_merge($metas, [
                    'layers' => $layers,
                    'base_material' => $baseMaterial,
                    'product_type' => $productType,
                    'surface_finish' => $surfaceFinish,
                    'thickness' => $thickness,
                    'pcb_color' => $pcbColor,
                    'copper_weight' => $copperWeight,
                ]));
                $orderType = $sourceResolution['order_type'];
                $quotationSource = $sourceResolution['quotation_source'];

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
                    'jlcpcb_file_key' => ($orderType === 'jlcpcb') ? ($order->jlcpcb_file_key ?? ($metas['jlcpcb_file_key'] ?? null)) : null,
                    'jlcpcb_quotation_snapshot' => ($orderType === 'jlcpcb') ? ($jlcSnapshot ?? $order->jlcpcb_quotation_snapshot ?? null) : null,
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

        public function destroy(Request $request, $id)
        {
            $adminId = $request->attributes->get('admin_id');
            $admin = $adminId ? (\Illuminate\Support\Facades\DB::table('admins')->where('id', $adminId)->first() ?: \Illuminate\Support\Facades\DB::table('users')->where('id', $adminId)->first()) : null;

            if (!$admin) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated or invalid admin session.'
                ], 401);
            }

            $permissions = \App\Http\Controllers\Mobile\MobileAuthController::fetchPermissionsForAdmin($admin);

            if (!in_array('*', $permissions) && !in_array('orders.delete', $permissions) && !in_array('orders.manage', $permissions)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized access: You do not have permission to delete orders.'
                ], 403);
            }

            try {
                $order = PcbOrder::withTrashed()->where(function ($q) use ($id) {
                    if (is_numeric($id)) {
                        $q->where('id', $id)->orWhere('order_number', $id);
                    } else {
                        $q->where('order_number', $id);
                    }
                })->first();

                if (!$order) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Order not found.'
                    ], 404);
                }

                if ($order->trashed()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Order is already deleted.'
                    ], 404);
                }

                $orderNumber = $order->order_number;
                $orderId = $order->id;

                \Illuminate\Support\Facades\DB::beginTransaction();

                // Execute Soft Delete (also fires PcbOrder::deleting boot hook)
                $order->delete();

                $deletedAt = $order->deleted_at ?: now()->toDateTimeString();

                // Explicitly ensure cascade soft delete on all related tables
                if (\Illuminate\Support\Facades\Schema::hasTable('pcb_order_meta') && \Illuminate\Support\Facades\Schema::hasColumn('pcb_order_meta', 'deleted_at')) {
                    \Illuminate\Support\Facades\DB::table('pcb_order_meta')
                        ->where('pcb_order_id', $orderId)
                        ->whereNull('deleted_at')
                        ->update(['deleted_at' => $deletedAt]);
                }

                if (\Illuminate\Support\Facades\Schema::hasTable('pcb_order_status_histories') && \Illuminate\Support\Facades\Schema::hasColumn('pcb_order_status_histories', 'deleted_at')) {
                    \Illuminate\Support\Facades\DB::table('pcb_order_status_histories')
                        ->where('pcb_order_id', $orderId)
                        ->whereNull('deleted_at')
                        ->update(['deleted_at' => $deletedAt]);
                }

                if (\Illuminate\Support\Facades\Schema::hasTable('pcb_order_notes') && \Illuminate\Support\Facades\Schema::hasColumn('pcb_order_notes', 'deleted_at')) {
                    \Illuminate\Support\Facades\DB::table('pcb_order_notes')
                        ->where('pcb_order_id', $orderId)
                        ->whereNull('deleted_at')
                        ->update(['deleted_at' => $deletedAt]);
                }

                if (\Illuminate\Support\Facades\Schema::hasTable('job_card_documents') && \Illuminate\Support\Facades\Schema::hasColumn('job_card_documents', 'deleted_at')) {
                    \Illuminate\Support\Facades\DB::table('job_card_documents')
                        ->where('pcb_order_id', $orderId)
                        ->whereNull('deleted_at')
                        ->update(['deleted_at' => $deletedAt]);
                }

                if (\Illuminate\Support\Facades\Schema::hasTable('pcb_order_combos') && \Illuminate\Support\Facades\Schema::hasColumn('pcb_order_combos', 'deleted_at')) {
                    \Illuminate\Support\Facades\DB::table('pcb_order_combos')
                        ->where(function ($q) use ($orderId) {
                            $q->where('parent_order_id', $orderId)
                              ->orWhere('combo_order_id', $orderId);
                        })
                        ->whereNull('deleted_at')
                        ->update(['deleted_at' => $deletedAt]);
                }

                if (\Illuminate\Support\Facades\Schema::hasTable('pcb_order_old_orders') && \Illuminate\Support\Facades\Schema::hasColumn('pcb_order_old_orders', 'deleted_at')) {
                    \Illuminate\Support\Facades\DB::table('pcb_order_old_orders')
                        ->where(function ($q) use ($orderId) {
                            $q->where('order_id', $orderId)
                              ->orWhere('old_order_id', $orderId);
                        })
                        ->whereNull('deleted_at')
                        ->update(['deleted_at' => $deletedAt]);
                }

                // Soft-delete associated payment transaction if no other active order is linked to it
                if (!empty($order->transaction_id) && \Illuminate\Support\Facades\Schema::hasTable('payment_transactions') && \Illuminate\Support\Facades\Schema::hasColumn('payment_transactions', 'deleted_at')) {
                    $hasOtherActiveOrder = \Illuminate\Support\Facades\DB::table('pcb_orders')
                        ->where('transaction_id', $order->transaction_id)
                        ->where('id', '!=', $orderId)
                        ->whereNull('deleted_at')
                        ->exists();

                    if (!$hasOtherActiveOrder) {
                        \Illuminate\Support\Facades\DB::table('payment_transactions')
                            ->where('id', $order->transaction_id)
                            ->whereNull('deleted_at')
                            ->update(['deleted_at' => $deletedAt]);
                    }
                }

                // Audit Log in pcb_order_logs table
                if (\Illuminate\Support\Facades\Schema::hasTable('pcb_order_logs')) {
                    $adminName = $request->attributes->get('admin_name') ?: ($admin->name ?? "Admin #{$adminId}");
                    \Illuminate\Support\Facades\DB::table('pcb_order_logs')->insert([
                        'pcb_order_id' => $orderId,
                        'order_number' => $orderNumber,
                        'admin_id'     => $adminId,
                        'action'       => 'Order Soft Deleted',
                        'description'  => "Order #{$orderNumber} and its associated metadata, payments, status histories, notes, and documents were soft deleted by {$adminName}",
                        'created_at'   => date('Y-m-d H:i:s'),
                        'updated_at'   => date('Y-m-d H:i:s'),
                    ]);
                }

                \Illuminate\Support\Facades\DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => "Order #{$orderNumber} deleted successfully."
                ]);

            } catch (\Throwable $th) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error deleting order: ' . $th->getMessage()
                ], 500);
            }
        }
    }