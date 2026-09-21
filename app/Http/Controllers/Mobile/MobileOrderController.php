<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
                      ->orWhere('customer_name', 'LIKE', "%{$search}%")
                      ->orWhere('board_name', 'LIKE', "%{$search}%")
                      ->orWhere('user_email', 'LIKE', "%{$search}%")
                      ->orWhere('status', 'LIKE', "%{$search}%");
                });
            }

            if ($status !== '') {
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
                if ($order->delivery_date) {
                    if ($order->delivery_date === $today) {
                        $dueDateStr = 'Today';
                    } else {
                        $dueDateStr = date('d M Y', strtotime($order->delivery_date));
                    }
                }

                $metaMap = DB::table('pcb_order_meta')
                    ->where('pcb_order_id', $order->id)
                    ->pluck('meta_value', 'meta_key')
                    ->toArray();

                return [
                    'id' => (string) $order->id,
                    'tool' => $order->order_number ?? ('M' . $order->id),
                    'status' => ucfirst($order->status ?? 'Traveler'),
                    'film' => isset($metaMap['film']) ? (bool)$metaMap['film'] : false,
                    'orderNumber' => $metaMap['order_number'] ?? (string)$order->id,
                    'client' => ($order->customer_name ?? null) ?: ($metaMap['client'] ?? 'Apex Controls'),
                    'department' => $metaMap['department'] ?? 'Production',
                    'priority' => $metaMap['priority'] ?? 'Normal',
                    'orderDate' => $order->created_at ? date('d M Y', strtotime($order->created_at)) : date('d M Y'),
                    'dueDate' => $dueDateStr,
                    'assignedTo' => $metaMap['assigned_to'] ?? 'Jignesh',
                    'maskColor' => $metaMap['mask_color'] ?? 'Green',
                    'layers' => isset($metaMap['layers']) ? (int)$metaMap['layers'] : 4,
                    'quantity' => isset($metaMap['quantity']) ? (int)$metaMap['quantity'] : 100,
                    'lastUpdate' => $order->updated_at ? date('h:i A', strtotime($order->updated_at)) : 'Just now'
                ];
            });

            // Filter mask in memory if specified
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
                ->where('id', $id)
                ->orWhere('order_number', $id)
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
            $dueDateStr = $order->delivery_date === $today ? 'Today' : ($order->delivery_date ? date('d M Y', strtotime($order->delivery_date)) : 'Upcoming');

            $data = [
                'id' => (string) $order->id,
                'tool' => $order->order_number ?? ('M' . $order->id),
                'status' => ucfirst($order->status ?? 'Traveler'),
                'film' => isset($metaMap['film']) ? (bool)$metaMap['film'] : false,
                'orderNumber' => $metaMap['order_number'] ?? (string)$order->id,
                'client' => ($order->customer_name ?? null) ?: ($metaMap['client'] ?? 'Apex Controls'),
                'department' => $metaMap['department'] ?? 'Production',
                'priority' => $metaMap['priority'] ?? 'Normal',
                'orderDate' => $order->created_at ? date('d M Y', strtotime($order->created_at)) : date('d M Y'),
                'dueDate' => $dueDateStr,
                'assignedTo' => $metaMap['assigned_to'] ?? 'Jignesh',
                'maskColor' => $metaMap['mask_color'] ?? 'Green',
                'layers' => isset($metaMap['layers']) ? (int)$metaMap['layers'] : 4,
                'quantity' => isset($metaMap['quantity']) ? (int)$metaMap['quantity'] : 100,
                'lastUpdate' => $order->updated_at ? date('h:i A', strtotime($order->updated_at)) : 'Just now',
                'user_email' => $order->user_email ?? '',
                'user_mobile' => $order->user_mobile ?? '',
                'unit_price' => (float)($order->unit_price ?? 0),
                'order_value' => (float)($order->order_value ?? 0),
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

            DB::table('pcb_orders')->where('id', $id)->update([
                'status' => strtolower($newStatus),
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Order status updated successfully'
            ]);

        } catch (\Throwable $th) {
            return response()->json(['success' => false, 'message' => $th->getMessage()], 500);
        }
    }
}
