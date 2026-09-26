<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MobileOrderController extends Controller
{
    private function checkPermission(Request $request)
    {
        $adminId = $request->attributes->get('admin_id');
        $admin = null;
        if ($adminId) {
            $admin = DB::table('admins')->where('id', $adminId)->first() ?: DB::table('users')->where('id', $adminId)->first();
        }
        $permissions = $admin ? MobileAuthController::fetchPermissionsForAdmin($admin) : ['orders.view', 'inventory.view'];

        if (!in_array('orders.view', $permissions)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to access Orders.'
            ], 403);
        }

        return null;
    }

    public function getStatuses(Request $request)
    {
        if ($forbidden = $this->checkPermission($request)) {
            return $forbidden;
        }

        try {
            $statuses = [];

            if (Schema::hasTable('pcb_order_statuses')) {
                $statuses = DB::table('pcb_order_statuses')->orderBy('sort_order', 'asc')->pluck('name')->toArray();
            } elseif (Schema::hasTable('pcb_statuses')) {
                $statuses = DB::table('pcb_statuses')->orderBy('sort_order', 'asc')->pluck('name')->toArray();
            } elseif (Schema::hasTable('statuses')) {
                $statuses = DB::table('statuses')->orderBy('sort_order', 'asc')->pluck('name')->toArray();
            }

            if (empty($statuses)) {
                $statuses = [
                    'In Production',
                    'Traveler',
                    'Move',
                    'Etching',
                    'HAL/Tin',
                    'DH Exposer',
                    'Outside Drill',
                    'VGroove',
                    'Etch QC',
                    'Rout Done',
                    'Silk',
                    'Final QC',
                    'Masking Exposer',
                    'FPT',
                    'Rout',
                    'Drilling',
                    'Developing',
                    'Ready to Ship',
                    'Completed',
                    'Cancelled',
                ];
            }

            return response()->json([
                'success' => true,
                'data' => array_values(array_unique($statuses))
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    public function index(Request $request)
    {
        if ($forbidden = $this->checkPermission($request)) {
            return $forbidden;
        }

        try {
            $search = trim($request->input('search', ''));
            $status = trim($request->input('status', ''));
            $mask = trim($request->input('mask', ''));
            $page = max(1, (int)$request->input('page', 1));
            $perPage = min(100, max(5, (int)$request->input('per_page', 20)));

            $query = DB::table('pcb_orders')->whereNull('deleted_at');

            // Hide combo member child orders from mobile production list (show parent & normal orders only)
            if (Schema::hasTable('pcb_order_combos')) {
                $query->whereNotIn('pcb_orders.id', function ($subQuery) {
                    $subQuery->select('combo_order_id')->from('pcb_order_combos');
                });
            }

            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $hasClause = false;

                    if (Schema::hasColumn('pcb_orders', 'order_number')) {
                        $q->where('order_number', 'LIKE', "%{$search}%");
                        $hasClause = true;
                    }
                    if (Schema::hasColumn('pcb_orders', 'tool')) {
                        $hasClause ? $q->orWhere('tool', 'LIKE', "%{$search}%") : $q->where('tool', 'LIKE', "%{$search}%");
                        $hasClause = true;
                    }
                    if (Schema::hasColumn('pcb_orders', 'part_number')) {
                        $hasClause ? $q->orWhere('part_number', 'LIKE', "%{$search}%") : $q->where('part_number', 'LIKE', "%{$search}%");
                        $hasClause = true;
                    }
                    if (Schema::hasColumn('pcb_orders', 'board_name')) {
                        $hasClause ? $q->orWhere('board_name', 'LIKE', "%{$search}%") : $q->where('board_name', 'LIKE', "%{$search}%");
                        $hasClause = true;
                    }
                    if (Schema::hasColumn('pcb_orders', 'user_email')) {
                        $hasClause ? $q->orWhere('user_email', 'LIKE', "%{$search}%") : $q->where('user_email', 'LIKE', "%{$search}%");
                        $hasClause = true;
                    }
                    if (Schema::hasColumn('pcb_orders', 'customer_name')) {
                        $hasClause ? $q->orWhere('customer_name', 'LIKE', "%{$search}%") : $q->where('customer_name', 'LIKE', "%{$search}%");
                        $hasClause = true;
                    }
                    if (Schema::hasColumn('pcb_orders', 'status')) {
                        $hasClause ? $q->orWhere('status', 'LIKE', "%{$search}%") : $q->where('status', 'LIKE', "%{$search}%");
                        $hasClause = true;
                    }
                });
            }

            // Status filtering
            $statusLower = strtolower(trim($status));
            if ($statusLower === 'all' || $statusLower === 'all_statuses' || ($search !== '' && ($statusLower === '' || $statusLower === 'in_production' || $statusLower === 'in production'))) {
                // When explicitly requested 'all' or when searching with a query, search across all statuses including completed/cancelled
            } elseif ($statusLower === '' || $statusLower === 'in_production' || $statusLower === 'in production') {
                $query->whereNotIn(DB::raw('LOWER(status)'), ['completed', 'cancelled', 'delivered', 'archived']);
            } else {
                $query->where('status', 'LIKE', "%{$status}%");
            }

            $total = $query->count();
            $orders = $query->orderBy('id', 'desc')
                ->skip(($page - 1) * $perPage)
                ->take($perPage)
                ->get();

            $today = date('Y-m-d');

            $items = $orders->map(function ($order) use ($today) {
                $dueDateStr = 'Upcoming';
                $delDate = $order->delivery_date ?? null;
                if ($delDate) {
                    if ($delDate === $today) {
                        $dueDateStr = 'Today';
                    } else {
                        $dueDateStr = date('d M Y', strtotime($delDate));
                    }
                }

                $metaMap = DB::table('pcb_order_meta')
                    ->where('pcb_order_id', $order->id)
                    ->pluck('meta_value', 'meta_key')
                    ->toArray();

                $orderQty = (int) ($order->order_qty ?? $order->quantity ?? $metaMap['quantity'] ?? $metaMap['order_qty'] ?? 50);
                $launchQty = (int) ($order->launch_qty ?? $metaMap['launch_qty'] ?? $orderQty);
                $panelQty = (int) ($order->panel_qty ?? $order->panel ?? $metaMap['panel_qty'] ?? $metaMap['panel'] ?? 0);
                $finalQty = (int) ($order->final_qty ?? $order->completed_qty ?? $metaMap['final_qty'] ?? $metaMap['completed_qty'] ?? $orderQty);
                $failedQty = (int) ($order->failed_qty ?? $metaMap['failed_qty'] ?? 0);
                $pendingQty = (int) ($order->pending_qty ?? $metaMap['pending_qty'] ?? max(0, $launchQty - $finalQty - $failedQty));

                return [
                    'id' => (string) $order->id,
                    'tool' => $order->order_number ?? ('M' . $order->id),
                    'status' => ucfirst($order->status ?? 'Traveler'),
                    'film' => isset($metaMap['film']) ? (bool)$metaMap['film'] : false,
                    'film_applied' => isset($order->film_applied) ? (bool)$order->film_applied : (isset($metaMap['film_applied']) ? (bool)$metaMap['film_applied'] : false),
                    'orderNumber' => $metaMap['order_number'] ?? (string)$order->id,
                    'client' => ($order->customer_name ?? null) ?: ($metaMap['client'] ?? 'Apex Controls'),
                    'department' => $metaMap['department'] ?? 'Production',
                    'priority' => $metaMap['priority'] ?? 'Normal',
                    'orderDate' => ($order->created_at ?? null) ? date('d M Y', strtotime($order->created_at)) : date('d M Y'),
                    'dueDate' => $dueDateStr,
                    'assignedTo' => $metaMap['assigned_to'] ?? 'Jignesh',
                    'maskColor' => $this->resolveMaskColor($order, $metaMap),
                    'layers' => $this->resolveLayers($order, $metaMap),
                    'quantity' => $orderQty,
                    'launchQty' => $launchQty,
                    'panel' => $panelQty,
                    'panelQty' => $panelQty,
                    'finalQty' => $finalQty,
                    'failedQty' => $failedQty,
                    'pendingQty' => $pendingQty,
                    'combo' => $order->combo ?? null,
                    'lastUpdate' => ($order->updated_at ?? null) ? date('h:i A', strtotime($order->updated_at)) : 'Just now'
                ];
            });

            if ($mask !== '') {
                $items = $items->filter(function ($item) use ($mask) {
                    return strtolower($item['maskColor']) === strtolower($mask);
                })->values();
            }

            return response()->json([
                'success' => true,
                'data' => $items,
                'meta' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => max(1, (int)ceil($total / $perPage))
                ]
            ]);

        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    public function show(Request $request, $id)
    {
        if ($forbidden = $this->checkPermission($request)) {
            return $forbidden;
        }

        try {
            $order = DB::table('pcb_orders')
                ->whereNull('deleted_at')
                ->where(function($q) use ($id) {
                    $q->where('id', $id)
                      ->orWhere('order_number', $id);
                })
                ->first();

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found'
                ], 404);
            }

            $metaMap = DB::table('pcb_order_meta')
                ->where('pcb_order_id', $order->id)
                ->pluck('meta_value', 'meta_key')
                ->toArray();

            $today = date('Y-m-d');
            $delDate = $order->delivery_date ?? null;
            $dueDateStr = $delDate === $today ? 'Today' : ($delDate ? date('d M Y', strtotime($delDate)) : 'Upcoming');

            // Fetch order history logs
            $history = [];
            if (Schema::hasTable('pcb_order_logs')) {
                $logs = DB::table('pcb_order_logs')
                    ->where('pcb_order_id', $order->id)
                    ->orWhere('order_number', $order->order_number ?? '')
                    ->orderBy('id', 'desc')
                    ->get();

                $history = $logs->map(function ($log) {
                    return [
                        'id' => (string) $log->id,
                        'status' => $log->status ?? 'Updated',
                        'action' => $log->action ?? 'Status Change',
                        'description' => $log->description ?? '',
                        'created_at' => $log->created_at ? date('d M Y · h:i A', strtotime($log->created_at)) : '',
                    ];
                })->toArray();
            } elseif (Schema::hasTable('pcb_order_status_histories')) {
                $histories = DB::table('pcb_order_status_histories')
                    ->where('pcb_order_id', $order->id)
                    ->orderBy('id', 'desc')
                    ->get();

                $history = $histories->map(function ($h) {
                    return [
                        'id' => (string) $h->id,
                        'status' => $h->status_name ?? 'Updated',
                        'action' => 'Status Updated',
                        'description' => $h->remark ?? ('Status updated to ' . ($h->status_name ?? '')),
                        'created_at' => $h->created_at ? date('d M Y · h:i A', strtotime($h->created_at)) : '',
                    ];
                })->toArray();
            }

            $orderQty = (int) ($order->order_qty ?? $order->quantity ?? $metaMap['quantity'] ?? $metaMap['order_qty'] ?? 50);
            $launchQty = (int) ($order->launch_qty ?? $metaMap['launch_qty'] ?? $orderQty);
            $finalQty = (int) ($order->final_qty ?? $order->completed_qty ?? $metaMap['final_qty'] ?? $metaMap['completed_qty'] ?? $orderQty);
            $failedQty = (int) ($order->failed_qty ?? $metaMap['failed_qty'] ?? 0);
            $pendingQty = (int) ($order->pending_qty ?? $metaMap['pending_qty'] ?? max(0, $launchQty - $finalQty - $failedQty));

            $data = [
                'id' => (string) $order->id,
                'tool' => $order->order_number ?? ('M' . $order->id),
                'status' => ucfirst($order->status ?? 'Traveler'),
                'film' => isset($metaMap['film']) ? (bool)$metaMap['film'] : false,
                'film_applied' => isset($order->film_applied) ? (bool)$order->film_applied : (isset($metaMap['film_applied']) ? (bool)$metaMap['film_applied'] : false),
                'orderNumber' => $metaMap['order_number'] ?? (string)$order->id,
                'client' => ($order->customer_name ?? null) ?: ($metaMap['client'] ?? 'Apex Controls'),
                'department' => $metaMap['department'] ?? 'Production',
                'priority' => $metaMap['priority'] ?? 'Normal',
                'orderDate' => ($order->created_at ?? null) ? date('d M Y', strtotime($order->created_at)) : date('d M Y'),
                'dueDate' => $dueDateStr,
                'assignedTo' => $metaMap['assigned_to'] ?? 'Jignesh',
                'maskColor' => $this->resolveMaskColor($order, $metaMap),
                'layers' => $this->resolveLayers($order, $metaMap),
                'quantity' => $orderQty,
                'launchQty' => $launchQty,
                'finalQty' => $finalQty,
                'failedQty' => $failedQty,
                'pendingQty' => $pendingQty,
                'combo' => $order->combo ?? null,
                'combo_orders' => Schema::hasTable('pcb_order_combos') ? DB::table('pcb_order_combos')->join('pcb_orders', 'pcb_order_combos.combo_order_id', '=', 'pcb_orders.id')->where('pcb_order_combos.parent_order_id', $order->id)->select('pcb_orders.id', 'pcb_orders.order_number', 'pcb_orders.status')->get() : [],
                'lastUpdate' => ($order->updated_at ?? null) ? date('h:i A', strtotime($order->updated_at)) : 'Just now',
                'user_email' => $order->user_email ?? '',
                'user_mobile' => $order->user_mobile ?? '',
                'unit_price' => (float)($order->unit_price ?? 0),
                'order_value' => (float)($order->order_value ?? 0),
                'history' => $history,
            ];

            return response()->json([
                'success' => true,
                'data' => $data
            ]);

        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    public function getHistory(Request $request, $id)
    {
        if ($forbidden = $this->checkPermission($request)) {
            return $forbidden;
        }

        try {
            $order = DB::table('pcb_orders')
                ->where('id', $id)
                ->orWhere('order_number', $id)
                ->first();

            if (!$order) {
                return response()->json(['success' => false, 'message' => 'Order not found'], 404);
            }

            $history = [];
            if (Schema::hasTable('pcb_order_logs')) {
                $logs = DB::table('pcb_order_logs')
                    ->where('pcb_order_id', $order->id)
                    ->orWhere('order_number', $order->order_number ?? '')
                    ->orderBy('id', 'desc')
                    ->get();

                $history = $logs->map(function ($log) {
                    return [
                        'id' => (string) $log->id,
                        'status' => $log->status ?? 'Updated',
                        'action' => $log->action ?? 'Status Change',
                        'description' => $log->description ?? '',
                        'created_at' => $log->created_at ? date('d M Y · h:i A', strtotime($log->created_at)) : '',
                    ];
                });
            }

            return response()->json([
                'success' => true,
                'data' => $history
            ]);
        } catch (\Throwable $th) {
            return response()->json(['success' => false, 'message' => $th->getMessage()], 500);
        }
    }

    public function updateStatus(Request $request, $id)
    {
        if ($forbidden = $this->checkPermission($request)) {
            return $forbidden;
        }

        DB::beginTransaction();
        try {
            $newStatus = trim((string)$request->input('status'));
            if (empty($newStatus)) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'Status is required'], 400);
            }

            $order = DB::table('pcb_orders')->where('id', $id)->first();
            if (!$order) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'Order not found'], 404);
            }

            $oldStatus = $order->status ?? 'Pending';
            $oldValStr = strtolower(trim((string)$oldStatus));
            $newValStr = strtolower(trim((string)$newStatus));
            $completedStatuses = ['completed', 'delivered', 'order completed', 'production completed'];

            $isCompleted = in_array($newValStr, $completedStatuses);
            $inputBillNumber = $request->has('bill_number') ? trim((string)$request->input('bill_number')) : null;
            $effectiveBillNumber = $inputBillNumber !== null ? $inputBillNumber : trim((string)($order->bill_number ?? ''));

            if ($isCompleted && $effectiveBillNumber === '') {
                DB::rollBack();
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

            $updateData = [
                'status' => $newStatus,
                'updated_at' => date('Y-m-d H:i:s')
            ];

            if ($inputBillNumber !== null) {
                $updateData['bill_number'] = $inputBillNumber !== '' ? $inputBillNumber : null;
            }

            DB::table('pcb_orders')->where('id', $id)->update($updateData);

            // Dispatch order_status_updated email notification when status changes (previous != new)
            if ($oldValStr !== $newValStr) {
                \App\Services\EmailTemplateService::sendOrderEmail('order_status_updated', $id, null, [
                    'previous_order_status' => $oldStatus,
                ]);
            }

            // Dispatch order_production_film_not_applied email if transition was Pending -> Non-Pending and film_applied != 1
            if ($oldValStr === 'pending' && $newValStr !== 'pending') {
                $freshFilmApplied = DB::table('pcb_orders')->where('id', $id)->value('film_applied');
                if ((int)$freshFilmApplied !== 1) {
                    \App\Services\EmailTemplateService::sendOrderEmail('order_production_film_not_applied', $id, null, [
                        'previous_order_status' => $oldStatus,
                        'film_applied'          => 'No',
                    ]);
                }
            }

            $adminId = $request->attributes->get('admin_id');
            $adminUser = $adminId ? DB::table('admins')->where('id', $adminId)->first() : null;
            $adminName = $adminUser ? $adminUser->name : 'Operator';

            // Insert into pcb_order_logs
            if (Schema::hasTable('pcb_order_logs')) {
                DB::table('pcb_order_logs')->insert([
                    'pcb_order_id' => $order->id,
                    'order_number' => $order->order_number ?? (string)$order->id,
                    'admin_id' => $adminId,
                    'status' => $newStatus,
                    'action' => "Status Updated: {$newStatus}",
                    'description' => "Order status updated from '{$oldStatus}' to '{$newStatus}' by {$adminName}.",
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            }

            if (Schema::hasTable('pcb_order_status_histories')) {
                DB::table('pcb_order_status_histories')->insert([
                    'pcb_order_id' => $order->id,
                    'admin_id' => $adminId ?: 1,
                    'status_name' => $newStatus,
                    'remark' => "Status updated to '{$newStatus}' by {$adminName}",
                    'created_at' => date('Y-m-d H:i:s')
                ]);
            }

            // Synchronize status to child combo member orders if this order is a parent
            $parentPcbOrder = \App\Models\PcbOrder::find($order->id);
            if ($parentPcbOrder) {
                \App\Services\ComboOrderService::syncComboStatus($parentPcbOrder, $newStatus, (int)($adminId ?: 1), $adminName);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Order status updated successfully',
                'data' => [
                    'status' => $newStatus,
                    'bill_number' => $effectiveBillNumber !== '' ? $effectiveBillNumber : null
                ]
            ]);

        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $th->getMessage()], 500);
        }
    }

    public function getNotes(Request $request, $id)
    {
        if ($forbidden = $this->checkPermission($request)) {
            return $forbidden;
        }

        try {
            $notes = [];
            if (Schema::hasTable('pcb_order_notes')) {
                $notes = DB::table('pcb_order_notes')
                    ->leftJoin('admins', 'pcb_order_notes.admin_id', '=', 'admins.id')
                    ->where('pcb_order_notes.pcb_order_id', $id)
                    ->orderBy('pcb_order_notes.id', 'desc')
                    ->select(
                        'pcb_order_notes.id',
                        'pcb_order_notes.note',
                        'pcb_order_notes.created_at',
                        'admins.name as name',
                        'admins.name as admin_name'
                    )
                    ->get()
                    ->map(function ($n) {
                        return [
                            'id' => (string) $n->id,
                            'note' => $n->note,
                            'name' => $n->name ?: 'System / Staff',
                            'admin_name' => $n->admin_name ?: 'System / Staff',
                            'created_at' => ($n->created_at ?? null) ? date('d M Y, h:i A', strtotime($n->created_at)) : ''
                        ];
                    });
            }

            return response()->json([
                'success' => true,
                'data' => $notes
            ]);

        } catch (\Throwable $th) {
            return response()->json(['success' => false, 'message' => $th->getMessage()], 500);
        }
    }

    public function addNote(Request $request, $id)
    {
        if ($forbidden = $this->checkPermission($request)) {
            return $forbidden;
        }

        try {
            $note = trim($request->input('note', ''));
            if (empty($note)) {
                return response()->json(['success' => false, 'message' => 'Note content is required'], 400);
            }

            $adminId = $request->attributes->get('admin_id') ?: 1;

            if (Schema::hasTable('pcb_order_notes')) {
                DB::table('pcb_order_notes')->insert([
                    'pcb_order_id' => $id,
                    'admin_id' => $adminId,
                    'note' => $note,
                    'is_internal' => 1,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Note added successfully'
            ]);

        } catch (\Throwable $th) {
            return response()->json(['success' => false, 'message' => $th->getMessage()], 500);
        }
    }

    public function updateQuantities(Request $request, $id)
    {
        if ($forbidden = $this->checkPermission($request)) {
            return $forbidden;
        }

        try {
            $order = DB::table('pcb_orders')->where('id', $id)->first();
            if (!$order) {
                return response()->json(['success' => false, 'message' => 'Order not found'], 404);
            }

            $adminId = $request->attributes->get('admin_id');
            $adminUser = $adminId ? DB::table('admins')->where('id', $adminId)->first() : null;
            $adminName = $adminUser ? $adminUser->name : 'Operator';

            $now = date('Y-m-d H:i:s');
            $updatedFields = [];
            $logMessages = [];

            // 1. Launch Qty
            if ($request->has('launch_qty')) {
                $newVal = (int) $request->input('launch_qty');
                $oldVal = (int) ($order->launch_qty ?? 0);
                if ($newVal !== $oldVal) {
                    if (Schema::hasColumn('pcb_orders', 'launch_qty')) {
                        $updatedFields['launch_qty'] = $newVal;
                    }
                    DB::table('pcb_order_meta')->updateOrInsert(
                        ['pcb_order_id' => $id, 'meta_key' => 'launch_qty'],
                        ['meta_value' => (string)$newVal, 'updated_at' => $now]
                    );
                    $logMessages[] = "Launched Qty: {$oldVal} → {$newVal}";
                }
            }

            // 2. Final / Completed Qty
            if ($request->has('final_qty')) {
                $newVal = (int) $request->input('final_qty');
                $oldVal = (int) ($order->final_qty ?? $order->completed_qty ?? 0);
                if ($newVal !== $oldVal) {
                    if (Schema::hasColumn('pcb_orders', 'final_qty')) {
                        $updatedFields['final_qty'] = $newVal;
                    }
                    if (Schema::hasColumn('pcb_orders', 'completed_qty')) {
                        $updatedFields['completed_qty'] = $newVal;
                    }
                    DB::table('pcb_order_meta')->updateOrInsert(
                        ['pcb_order_id' => $id, 'meta_key' => 'final_qty'],
                        ['meta_value' => (string)$newVal, 'updated_at' => $now]
                    );
                    $logMessages[] = "Final Qty: {$oldVal} → {$newVal}";
                }
            }

            // 3. Failed Qty
            if ($request->has('failed_qty')) {
                $newVal = (int) $request->input('failed_qty');
                $oldVal = (int) ($order->failed_qty ?? 0);
                if ($newVal !== $oldVal) {
                    if (Schema::hasColumn('pcb_orders', 'failed_qty')) {
                        $updatedFields['failed_qty'] = $newVal;
                    }
                    DB::table('pcb_order_meta')->updateOrInsert(
                        ['pcb_order_id' => $id, 'meta_key' => 'failed_qty'],
                        ['meta_value' => (string)$newVal, 'updated_at' => $now]
                    );
                    $logMessages[] = "Failed Qty: {$oldVal} → {$newVal}";
                }
            }

            if (!empty($updatedFields)) {
                $updatedFields['updated_at'] = $now;
                DB::table('pcb_orders')->where('id', $id)->update($updatedFields);
            }

            // Write to pcb_order_logs audit log
            if (!empty($logMessages) && Schema::hasTable('pcb_order_logs')) {
                $desc = "Quantities updated by {$adminName}: " . implode(', ', $logMessages);
                DB::table('pcb_order_logs')->insert([
                    'pcb_order_id' => $order->id,
                    'order_number' => $order->order_number ?? (string)$order->id,
                    'admin_id' => $adminId,
                    'status' => $order->status ?? 'Production',
                    'action' => 'Quantities Updated',
                    'description' => $desc,
                    'created_at' => $now,
                    'updated_at' => $now
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Order quantities updated successfully'
            ]);

        } catch (\Throwable $th) {
            return response()->json(['success' => false, 'message' => $th->getMessage()], 500);
        }
    }

    public function updateFilmApplied(Request $request, $id)
    {
        if ($forbidden = $this->checkPermission($request)) {
            return $forbidden;
        }

        try {
            $order = DB::table('pcb_orders')->where('id', $id)->orWhere('order_number', $id)->first();
            if (!$order) {
                return response()->json(['success' => false, 'message' => 'Order not found'], 404);
            }

            $filmApplied = filter_var($request->input('film_applied'), FILTER_VALIDATE_BOOLEAN);
            $now = date('Y-m-d H:i:s');

            $updatedFields = ['updated_at' => $now];
            if (Schema::hasColumn('pcb_orders', 'film_applied')) {
                $updatedFields['film_applied'] = $filmApplied ? 1 : 0;
            }

            DB::table('pcb_orders')->where('id', $order->id)->update($updatedFields);

            DB::table('pcb_order_meta')->updateOrInsert(
                ['pcb_order_id' => $order->id, 'meta_key' => 'film_applied'],
                ['meta_value' => $filmApplied ? '1' : '0', 'updated_at' => $now]
            );

            $adminId = $request->attributes->get('admin_id');
            $adminUser = $adminId ? DB::table('admins')->where('id', $adminId)->first() : null;
            $adminName = $adminUser ? $adminUser->name : 'Operator';
            $statusStr = $filmApplied ? 'True (Applied)' : 'False (Not Applied)';

            if (Schema::hasTable('pcb_order_logs')) {
                DB::table('pcb_order_logs')->insert([
                    'pcb_order_id' => $order->id,
                    'order_number' => $order->order_number ?? (string)$order->id,
                    'admin_id' => $adminId,
                    'status' => $order->status ?? 'Production',
                    'action' => 'Film Applied Updated',
                    'description' => "Film Applied marked as {$statusStr} by {$adminName}.",
                    'created_at' => $now,
                    'updated_at' => $now
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Film applied status updated successfully',
                'film_applied' => $filmApplied
            ]);

        } catch (\Throwable $th) {
            return response()->json(['success' => false, 'message' => $th->getMessage()], 500);
        }
    }

    private function resolveLayers($order, array $metaMap): int
    {
        $raw = $order->layers ?? $metaMap['layers'] ?? $metaMap['layer'] ?? null;
        if ($raw !== null && $raw !== '') {
            if (is_numeric($raw)) {
                return max(1, (int)$raw);
            }
            if (preg_match('/(\d+)/', (string)$raw, $matches)) {
                return max(1, (int)$matches[1]);
            }
        }
        return 2;
    }

    private function resolveMaskColor($order, array $metaMap): string
    {
        $raw = $order->mask ?? $metaMap['mask_color'] ?? $metaMap['pcb_color'] ?? $metaMap['solder_mask'] ?? $metaMap['mask'] ?? $metaMap['coverlay_color'] ?? null;
        return ($raw !== null && trim((string)$raw) !== '') ? (string)$raw : 'Green';
    }
}
