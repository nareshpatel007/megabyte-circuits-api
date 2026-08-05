<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    // Overview Metrics & Recent Activity
    public function overview(Request $request)
    {
        try {
            $userId = $request->input('user_id');
            if (!$userId) {
                return response()->json(['status' => false, 'message' => 'User ID is required'], 400);
            }

            $totalOrders = DB::table('pcb_orders')->where('user_id', $userId)->whereNull('deleted_at')->count();
            $pendingOrders = DB::table('pcb_orders')
                ->leftJoin('pcb_order_statuses', 'pcb_orders.status_id', '=', 'pcb_order_statuses.id')
                ->where('pcb_orders.user_id', $userId)
                ->where('pcb_order_statuses.name', 'Pending')
                ->whereNull('pcb_orders.deleted_at')
                ->count();

            $totalGerberFiles = DB::table('gerber_files')->where('user_id', $userId)->count();
            $totalSpent = DB::table('payment_transactions')
                ->where('user_id', $userId)
                ->where('status', 'success')
                ->sum('amount');

            // Recent 5 Orders
            $recentOrders = DB::table('pcb_orders')
                ->leftJoin('pcb_order_statuses', 'pcb_orders.status_id', '=', 'pcb_order_statuses.id')
                ->leftJoin('gerber_files', 'pcb_orders.gerber_file_id', '=', 'gerber_files.id')
                ->leftJoin('payment_transactions', 'pcb_orders.transaction_id', '=', 'payment_transactions.id')
                ->leftJoin('user_addresses as ship', 'pcb_orders.shipping_address_id', '=', 'ship.id')
                ->leftJoin('user_addresses as bill', 'pcb_orders.billing_address_id', '=', 'bill.id')
                ->where('pcb_orders.user_id', $userId)
                ->whereNull('pcb_orders.deleted_at')
                ->select(
                    'pcb_orders.*',
                    'pcb_order_statuses.name as status_name',
                    'pcb_order_statuses.label as status_label',
                    'gerber_files.original_name as gerber_name',
                    'gerber_files.file_url as gerber_url',
                    'payment_transactions.transaction_number',
                    'payment_transactions.razorpay_payment_id',
                    'ship.first_name as shipping_first_name',
                    'ship.last_name as shipping_last_name',
                    'ship.street_address as shipping_street',
                    'ship.city as shipping_city',
                    'ship.state as shipping_state',
                    'ship.postal_code as shipping_postal',
                    'ship.mobile as shipping_mobile',
                    'bill.first_name as billing_first_name',
                    'bill.last_name as billing_last_name',
                    'bill.street_address as billing_street',
                    'bill.city as billing_city',
                    'bill.state as billing_state',
                    'bill.postal_code as billing_postal'
                )
                ->orderBy('pcb_orders.id', 'desc')
                ->limit(5)
                ->get();

            foreach ($recentOrders as $order) {
                $metas = DB::table('pcb_order_meta')
                    ->where('pcb_order_id', $order->id)
                    ->pluck('meta_value', 'meta_key');
                $order->meta = $metas;
            }

            return response()->json([
                'status' => true,
                'metrics' => [
                    'total_orders' => $totalOrders,
                    'pending_orders' => $pendingOrders,
                    'gerber_files_count' => $totalGerberFiles,
                    'total_spent' => (float)$totalSpent
                ],
                'recent_orders' => $recentOrders
            ]);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    // Single Order Details API
    public function orderDetails(Request $request)
    {
        try {
            $orderId = $request->input('id');
            $userId = $request->input('user_id');

            if (!$orderId) {
                return response()->json(['status' => false, 'message' => 'Order ID is required'], 400);
            }

            $query = DB::table('pcb_orders')
                ->leftJoin('pcb_order_statuses', 'pcb_orders.status_id', '=', 'pcb_order_statuses.id')
                ->leftJoin('gerber_files', 'pcb_orders.gerber_file_id', '=', 'gerber_files.id')
                ->leftJoin('payment_transactions', 'pcb_orders.transaction_id', '=', 'payment_transactions.id')
                ->leftJoin('user_addresses as ship', 'pcb_orders.shipping_address_id', '=', 'ship.id')
                ->leftJoin('user_addresses as bill', 'pcb_orders.billing_address_id', '=', 'bill.id')
                ->where('pcb_orders.id', $orderId)
                ->whereNull('pcb_orders.deleted_at');

            if ($userId) {
                $query->where('pcb_orders.user_id', $userId);
            }

            $order = $query->select(
                'pcb_orders.*',
                'pcb_order_statuses.name as status_name',
                'pcb_order_statuses.label as status_label',
                'gerber_files.original_name as gerber_name',
                'gerber_files.file_url as gerber_url',
                'gerber_files.preview_data as gerber_preview_data',
                'payment_transactions.transaction_number',
                'payment_transactions.razorpay_payment_id',
                'payment_transactions.status as payment_status',
                'payment_transactions.amount as payment_amount',
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
            )->first();

            if (!$order) {
                return response()->json(['status' => false, 'message' => 'Order not found'], 404);
            }

            // Format gerber_url cleanly
            if ($order->gerber_url && !str_starts_with($order->gerber_url, 'http')) {
                $order->gerber_url = '/' . ltrim($order->gerber_url, '/');
            }

            $metas = DB::table('pcb_order_meta')
                ->where('pcb_order_id', $order->id)
                ->pluck('meta_value', 'meta_key');
            $order->meta = $metas;

            if (empty($order->gerber_preview_data) && isset($metas['preview_data'])) {
                $order->gerber_preview_data = $metas['preview_data'];
            }

            // Fetch Order Activity Logs from pcb_order_logs table
            $logs = DB::table('pcb_order_logs')
                ->where(function($q) use ($order) {
                    $q->where('pcb_order_id', $order->id)
                      ->orWhere('order_number', $order->order_number);
                })
                ->orderBy('id', 'asc')
                ->get();

            $order->logs = $logs;

            return response()->json(['status' => true, 'order' => $order]);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    // Sidebar Badge Counts API
    public function sidebarCounts(Request $request)
    {
        try {
            $userId = $request->input('user_id');
            if (!$userId) {
                return response()->json(['status' => false, 'message' => 'User ID is required'], 400);
            }

            $totalOrders = DB::table('pcb_orders')->where('user_id', $userId)->whereNull('deleted_at')->count();
            $totalGerberFiles = DB::table('gerber_files')->where('user_id', $userId)->whereNull('deleted_at')->count();
            $totalAddresses = DB::table('user_addresses')->where('user_id', $userId)->whereNull('deleted_at')->count();

            return response()->json([
                'status' => true,
                'counts' => [
                    'orders' => $totalOrders,
                    'gerber_files' => $totalGerberFiles,
                    'addresses' => $totalAddresses
                ]
            ]);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    // User Account Profile Details API
    public function accountDetails(Request $request)
    {
        try {
            $userId = $request->input('user_id');
            if (!$userId) {
                return response()->json(['status' => false, 'message' => 'User ID is required'], 400);
            }

            $user = DB::table('users')->where('id', $userId)->first();
            if (!$user) {
                return response()->json(['status' => false, 'message' => 'User not found'], 404);
            }

            return response()->json([
                'status' => true,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'created_at' => $user->created_at ?? null
                ]
            ]);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    // Orders List with Meta Specifications
    public function orders(Request $request)
    {
        try {
            $userId = $request->input('user_id');
            if (!$userId) {
                return response()->json(['status' => false, 'message' => 'User ID is required'], 400);
            }

            $orders = DB::table('pcb_orders')
                ->leftJoin('pcb_order_statuses', 'pcb_orders.status_id', '=', 'pcb_order_statuses.id')
                ->leftJoin('gerber_files', 'pcb_orders.gerber_file_id', '=', 'gerber_files.id')
                ->leftJoin('payment_transactions', 'pcb_orders.transaction_id', '=', 'payment_transactions.id')
                ->leftJoin('user_addresses as ship', 'pcb_orders.shipping_address_id', '=', 'ship.id')
                ->leftJoin('user_addresses as bill', 'pcb_orders.billing_address_id', '=', 'bill.id')
                ->where('pcb_orders.user_id', $userId)
                ->whereNull('pcb_orders.deleted_at')
                ->select(
                    'pcb_orders.*',
                    'pcb_order_statuses.name as status_name',
                    'pcb_order_statuses.label as status_label',
                    'gerber_files.original_name as gerber_name',
                    'gerber_files.file_url as gerber_url',
                    'payment_transactions.transaction_number',
                    'payment_transactions.razorpay_payment_id',
                    'ship.first_name as shipping_first_name',
                    'ship.last_name as shipping_last_name',
                    'ship.street_address as shipping_street',
                    'ship.city as shipping_city',
                    'ship.state as shipping_state',
                    'ship.postal_code as shipping_postal',
                    'ship.mobile as shipping_mobile',
                    'bill.first_name as billing_first_name',
                    'bill.last_name as billing_last_name',
                    'bill.street_address as billing_street',
                    'bill.city as billing_city',
                    'bill.state as billing_state',
                    'bill.postal_code as billing_postal'
                )
                ->orderBy('pcb_orders.id', 'desc')
                ->get();

            // Attach meta specs for each order
            foreach ($orders as $order) {
                $metas = DB::table('pcb_order_meta')
                    ->where('pcb_order_id', $order->id)
                    ->pluck('meta_value', 'meta_key');
                $order->meta = $metas;
            }

            return response()->json(['status' => true, 'orders' => $orders]);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    // Gerber Files List (Active only)
    public function gerberFiles(Request $request)
    {
        try {
            $userId = $request->input('user_id');
            if (!$userId) {
                return response()->json(['status' => false, 'message' => 'User ID is required'], 400);
            }

            $files = DB::table('gerber_files')
                ->where('user_id', $userId)
                ->whereNull('deleted_at') // Filter out soft-deleted gerber files
                ->orderBy('id', 'desc')
                ->get();

            foreach ($files as $file) {
                if ($file->file_url && !str_starts_with($file->file_url, 'http')) {
                    $file->file_url = '/' . ltrim($file->file_url, '/');
                }
            }

            return response()->json(['status' => true, 'gerber_files' => $files]);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    // Soft-delete a Gerber File
    public function deleteGerberFile(Request $request)
    {
        try {
            $input = $request->all();
            if (empty($input) && $request->getContent()) {
                $input = json_decode($request->getContent(), true) ?? [];
            }

            $id = $input['id'] ?? null;
            $userId = $input['user_id'] ?? null;

            if (!$id || !$userId) {
                return response()->json(['status' => false, 'message' => 'File ID and User ID are required'], 400);
            }

            DB::table('gerber_files')
                ->where('id', $id)
                ->where('user_id', $userId)
                ->update(['deleted_at' => date('Y-m-d H:i:s')]);

            return response()->json(['status' => true, 'message' => 'Gerber file deleted successfully']);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    // Payment History List
    public function payments(Request $request)
    {
        try {
            $userId = $request->input('user_id');
            if (!$userId) {
                return response()->json(['status' => false, 'message' => 'User ID is required'], 400);
            }

            $payments = DB::table('payment_transactions')
                ->where('user_id', $userId)
                ->whereIn('status', ['success', 'failed', 'failure']) // Only show success or failure transactions
                ->orderBy('id', 'desc')
                ->get();

            return response()->json(['status' => true, 'payments' => $payments]);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }
}
