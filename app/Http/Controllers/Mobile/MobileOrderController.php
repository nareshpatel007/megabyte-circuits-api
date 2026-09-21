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

            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('order_number', 'LIKE', "%{$search}%")
                      ->orWhere('board_name', 'LIKE', "%{$search}%")
                      ->orWhere('user_email', 'LIKE', "%{$search}%")
                      ->orWhere('status', 'LIKE', "%{$search}%");

                    if (Schema::hasColumn('pcb_orders', 'customer_name')) {
                        $q->orWhere('customer_name', 'LIKE', "%{$search}%");
                    }
                });
            }

            // Default filter: "In Production" (exclude completed / cancelled / archived)
            if ($status === '' || strtolower($status) === 'in_production' || strtolower($status) === 'in production') {
                $query->whereNotIn(DB::raw('LOWER(status)'), ['completed', 'cancelled', 'delivered', 'archived']);
            } elseif (strtolower($status) !== 'all' && strtolower($status) !== 'all_statuses') {
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
                $finalQty = (int) ($order->final_qty ?? $order->completed_qty ?? $metaMap['final_qty'] ?? $metaMap['completed_qty'] ?? $orderQty);
                $failedQty = (int) ($order->failed_qty ?? $metaMap['failed_qty'] ?? 0);
                $pendingQty = (int) ($order->pending_qty ?? $metaMap['pending_qty'] ?? max(0, $launchQty - $finalQty - $failedQty));

                return [
                    'id' => (string) $order->id,
                    'tool' => $order->order_number ?? ('M' . $order->id),
                    'status' => ucfirst($order->status ?? 'Traveler'),
                    'film' => isset($metaMap['film']) ? (bool)$metaMap['film'] : false,
                    'orderNumber' => $metaMap['order_number'] ?? (string)$order->id,
                    'client' => ($order->customer_name ?? null) ?: ($metaMap['client'] ?? 'Apex Controls'),
                    'department' => $metaMap['department'] ?? 'Production',
                    'priority' => $metaMap['priority'] ?? 'Normal',
                    'orderDate' => ($order->created_at ?? null) ? date('d M Y', strtotime($order->created_at)) : date('d M Y'),
                    'dueDate' => $dueDateStr,
                    'assignedTo' => $metaMap['assigned_to'] ?? 'Jignesh',
                    'maskColor' => $metaMap['mask_color'] ?? 'Green',
                    'layers' => isset($metaMap['layers']) ? (int)$metaMap['layers'] : 4,
                    'quantity' => $orderQty,
                    'launchQty' => $launchQty,
                    'finalQty' => $finalQty,
                    'failedQty' => $failedQty,
                    'pendingQty' => $pendingQty,
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
                'orderNumber' => $metaMap['order_number'] ?? (string)$order->id,
                'client' => ($order->customer_name ?? null) ?: ($metaMap['client'] ?? 'Apex Controls'),
                'department' => $metaMap['department'] ?? 'Production',
                'priority' => $metaMap['priority'] ?? 'Normal',
                'orderDate' => ($order->created_at ?? null) ? date('d M Y', strtotime($order->created_at)) : date('d M Y'),
                'dueDate' => $dueDateStr,
                'assignedTo' => $metaMap['assigned_to'] ?? 'Jignesh',
                'maskColor' => $metaMap['mask_color'] ?? 'Green',
                'layers' => isset($metaMap['layers']) ? (int)$metaMap['layers'] : 4,
                'quantity' => $orderQty,
                'launchQty' => $launchQty,
                'finalQty' => $finalQty,
                'failedQty' => $failedQty,
                'pendingQty' => $pendingQty,
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

        try {
            $newStatus = trim($request->input('status'));
            if (empty($newStatus)) {
                return response()->json(['success' => false, 'message' => 'Status is required'], 400);
            }

            $order = DB::table('pcb_orders')->where('id', $id)->first();
            if (!$order) {
                return response()->json(['success' => false, 'message' => 'Order not found'], 404);
            }

            $oldStatus = $order->status ?? 'Pending';

            DB::table('pcb_orders')->where('id', $id)->update([
                'status' => $newStatus,
                'updated_at' => date('Y-m-d H:i:s')
            ]);

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

            return response()->json([
                'success' => true,
                'message' => 'Order status updated successfully'
            ]);

        } catch (\Throwable $th) {
            return response()->json(['success' => false, 'message' => $th->getMessage()], 500);
        }
    }
}
