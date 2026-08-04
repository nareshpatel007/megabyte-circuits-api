<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Firebase\JWT\JWT;
use App\CommonHelper;

class AdminController extends Controller
{
    // Admin login
    public function login(Request $request)
    {
        try {
            $usernameOrEmail = $request->input('username') ?: $request->input('email');
            $password = $request->input('password');

            if (empty($usernameOrEmail) || empty($password)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Username or Email and password are required.'
                ], 400);
            }

            $admin = DB::table('admins')
                ->where('email', $usernameOrEmail)
                ->orWhere('username', $usernameOrEmail)
                ->first();

            if (!$admin) {
                return response()->json([
                    'status' => false,
                    'message' => 'Account not found.'
                ], 404);
            }

            // Verify password
            if (!password_verify($password, $admin->password_hash)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid credentials.'
                ], 401);
            }

            // Check if status is active
            if (isset($admin->status) && $admin->status !== 'active') {
                return response()->json([
                    'status' => false,
                    'message' => 'This admin account is inactive.'
                ], 403);
            }

            // Fetch Role & Permissions
            $role = null;
            $permissions = [];
            if (!empty($admin->role_id) && Schema::hasTable('roles')) {
                $roleObj = DB::table('roles')->where('id', $admin->role_id)->first();
                if ($roleObj) {
                    $role = $roleObj->name;
                    $permissions = DB::table('role_permissions')
                        ->join('permissions', 'role_permissions.permission_id', '=', 'permissions.id')
                        ->where('role_permissions.role_id', $admin->role_id)
                        ->pluck('permissions.slug')
                        ->toArray();
                }
            }

            // Update last login timestamp
            $now = date('Y-m-d H:i:s');
            DB::table('admins')->where('id', $admin->id)->update([
                'last_login_at' => $now,
                'updated_at' => $now
            ]);

            // Generate JWT Token
            $payload = [
                'admin_id' => $admin->id,
                'name' => $admin->name,
                'username' => $admin->username ?? strtok($admin->email, '@'),
                'email' => $admin->email,
                'role' => $role ?? 'Admin',
                'permissions' => $permissions,
                'is_admin' => true,
                'exp' => time() + (24 * 60 * 60) // 24 hours
            ];

            $secret = env('JWT_SECRET', '7+18EvAjOct+KzCCwJLpuwEjtXlzevAk4n09YeUkgfA=');
            $jwt_token = JWT::encode($payload, $secret, 'HS256');

            return response()->json([
                'status' => true,
                'message' => 'Logged in successfully as Admin',
                'data' => [
                    'id' => $admin->id,
                    'admin_id' => $admin->id,
                    'access_token' => $jwt_token,
                    'name' => $admin->name,
                    'username' => $admin->username ?? strtok($admin->email, '@'),
                    'email' => $admin->email,
                    'role' => $role ?? 'Admin',
                    'permissions' => $permissions,
                    'is_admin' => true
                ]
            ]);

        } catch (\Throwable $th) {
            return response()->json([
                'status' => false,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    // Dashboard Statistics
    public function stats(Request $request)
    {
        try {
            $hasOrders = Schema::hasTable('pcb_orders');
            $hasUsers = Schema::hasTable('pcb_users') || Schema::hasTable('users');
            $userTable = Schema::hasTable('pcb_users') ? 'pcb_users' : 'users';

            $totalRevenue = $hasOrders ? (float)DB::table('pcb_orders')->sum('order_value') : 0;
            $totalOrders  = $hasOrders ? DB::table('pcb_orders')->count() : 0;

            $statusTable = Schema::hasTable('pcb_order_statuses') ? 'pcb_order_statuses' : (Schema::hasTable('pcb_statuses') ? 'pcb_statuses' : (Schema::hasTable('statuses') ? 'statuses' : null));

            $pendingOrders = 0;
            $mfgRuns = 0;
            $statusCounts = [];

            if ($hasOrders) {
                if ($statusTable) {
                    $pendingOrders = DB::table('pcb_orders')
                        ->leftJoin($statusTable, 'pcb_orders.status_id', '=', "{$statusTable}.id")
                        ->where(function ($q) use ($statusTable) {
                            $q->where("{$statusTable}.name", 'like', '%pending%')
                              ->orWhere("{$statusTable}.name", 'like', '%move%')
                              ->orWhereNull('pcb_orders.status_id');
                        })
                        ->count();

                    $mfgRuns = DB::table('pcb_orders')
                        ->leftJoin($statusTable, 'pcb_orders.status_id', '=', "{$statusTable}.id")
                        ->whereNotIn("{$statusTable}.name", ['Cancelled', 'Completed', 'Shipped'])
                        ->count();

                    $rawCounts = DB::table('pcb_orders')
                        ->leftJoin($statusTable, 'pcb_orders.status_id', '=', "{$statusTable}.id")
                        ->select(DB::raw("COALESCE({$statusTable}.name, 'Pending') as status_name"), DB::raw('count(*) as total'))
                        ->groupBy('status_name')
                        ->get();

                    foreach ($rawCounts as $row) {
                        $statusCounts[$row->status_name] = $row->total;
                    }
                } else {
                    $pendingOrders = $totalOrders;
                    $statusCounts = ['Pending' => $totalOrders];
                }
            }

            $totalUsers = $hasUsers ? DB::table($userTable)->count() : 0;

            return response()->json([
                'status' => true,
                'stats'  => [
                    'total_revenue'   => $totalRevenue,
                    'total_orders'    => $totalOrders,
                    'pending_orders'  => $pendingOrders,
                    'active_mfg_runs' => $mfgRuns,
                    'total_users'     => $totalUsers,
                    'status_counts'   => $statusCounts,
                ]
            ]);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    // Users List with search & pagination
    public function users(Request $request)
    {
        try {
            $search = $request->input('search');
            
            $query = DB::table('users');

            if (!empty($search)) {
                $query->where(function($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('first_name', 'like', "%{$search}%")
                      ->orWhere('last_name', 'like', "%{$search}%")
                      ->orWhere('company_name', 'like', "%{$search}%");
                });
            }

            $users = $query->orderBy('created_at', 'desc')->get();

            // Fetch order stats aggregated by user_id
            $orderStats = DB::table('pcb_orders')
                ->select(
                    'user_id',
                    DB::raw('COUNT(id) as orders_count'),
                    DB::raw('COALESCE(SUM(order_value), 0) as total_spent')
                )
                ->whereNotNull('user_id')
                ->groupBy('user_id')
                ->get()
                ->keyBy('user_id');

            $users->transform(function ($user) use ($orderStats) {
                $stat = $orderStats->get($user->id);
                $user->orders_count = $stat ? (int)$stat->orders_count : 0;
                $user->total_spent = $stat ? (float)$stat->total_spent : 0;
                return $user;
            });

            return response()->json([
                'status' => true,
                'data' => $users,
                'users' => $users
            ]);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    // Create new client/user account
    public function createUser(Request $request)
    {
        try {
            $firstName = $request->input('first_name');
            $lastName = $request->input('last_name');
            $name = $request->input('name');
            if (empty($name)) {
                $name = trim("{$firstName} {$lastName}");
            }
            $email = strtolower(trim($request->input('email', '')));
            $password = $request->input('password');
            $phone = $request->input('phone') ?: $request->input('phone_number');
            $companyName = $request->input('company_name');
            $gstin = $request->input('gstin') ?: $request->input('gst_number');

            if (empty($email)) {
                return response()->json(['status' => false, 'message' => 'Email address is required.'], 400);
            }

            // Check if user email already exists
            $existingUser = DB::table('users')->where('email', $email)->first();
            if ($existingUser) {
                return response()->json(['status' => false, 'message' => 'A client account with this email already exists.'], 422);
            }

            if (empty($password)) {
                $password = \Illuminate\Support\Str::random(10);
            }

            $now = date('Y-m-d H:i:s');
            $userPayload = [
                'name' => $name,
                'first_name' => $firstName ?? strtok($name, ' '),
                'last_name' => $lastName ?? '',
                'email' => $email,
                'password' => password_hash($password, PASSWORD_BCRYPT),
                'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                'phone_number' => $phone,
                'company_name' => $companyName,
                'gstin' => $gstin,
                'status' => 'Active',
                'created_at' => $now,
                'updated_at' => $now,
            ];

            // Filter columns based on actual users table schema
            $columns = Schema::getColumnListing('users');
            $insertData = [];
            foreach ($userPayload as $key => $val) {
                if (in_array($key, $columns)) {
                    $insertData[$key] = $val;
                }
            }

            $userId = DB::table('users')->insertGetId($insertData);

            // Sync with pcb_users if table exists
            if (Schema::hasTable('pcb_users')) {
                $pcbCols = Schema::getColumnListing('pcb_users');
                $pcbPayload = [
                    'id' => $userId,
                    'name' => $name,
                    'email' => $email,
                    'mobile' => $phone,
                    'gst_number' => $gstin,
                    'status' => 'Active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $pcbInsert = [];
                foreach ($pcbPayload as $k => $v) {
                    if (in_array($k, $pcbCols)) {
                        $pcbInsert[$k] = $v;
                    }
                }
                try {
                    DB::table('pcb_users')->insert($pcbInsert);
                } catch (\Throwable $e) {
                    // Ignore duplicate key if auto-incremented
                }
            }

            return response()->json([
                'status' => true,
                'message' => 'Client account created successfully.',
                'user_id' => $userId,
                'generated_password' => $password,
                'data' => array_merge(['id' => $userId], $insertData)
            ]);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    // Get single client details with orders, transactions, and overview
    public function showUser(Request $request, $id)
    {
        try {
            $user = DB::table('users')->where('id', $id)->first();
            if (!$user && Schema::hasTable('pcb_users')) {
                $user = DB::table('pcb_users')->where('id', $id)->first();
            }

            if (!$user) {
                return response()->json(['status' => false, 'message' => 'Client not found'], 404);
            }

            // Remove sensitive fields
            unset($user->password, $user->password_hash, $user->remember_token);

            // Fetch user orders with status and gerber file details
            $statusTable = Schema::hasTable('pcb_order_statuses') ? 'pcb_order_statuses' : (Schema::hasTable('pcb_statuses') ? 'pcb_statuses' : (Schema::hasTable('statuses') ? 'statuses' : null));

            $ordersQuery = DB::table('pcb_orders')->where('pcb_orders.user_id', $id);
            $selectFields = ['pcb_orders.*'];

            if ($statusTable) {
                $ordersQuery->leftJoin($statusTable, 'pcb_orders.status_id', '=', "{$statusTable}.id");
                $selectFields[] = "{$statusTable}.name as status_name";
            }

            if (Schema::hasTable('gerber_files')) {
                $ordersQuery->leftJoin('gerber_files', 'pcb_orders.gerber_file_id', '=', 'gerber_files.id');
                $selectFields[] = 'gerber_files.original_name as gerber_file_name';
                $selectFields[] = 'gerber_files.file_name as gerber_file_sys_name';
            }

            $orders = $ordersQuery->select($selectFields)->orderBy('pcb_orders.created_at', 'desc')->get();

            // Calculate Order Summary Stats
            $ordersCount = $orders->count();
            $totalSpent = $orders->sum('order_value');
            $completedOrders = $orders->filter(function($o) {
                $st = strtolower($o->status_name ?? $o->status ?? '');
                return in_array($st, ['completed', 'shipped', 'delivered']);
            })->count();
            $pendingOrders = $orders->filter(function($o) {
                $st = strtolower($o->status_name ?? $o->status ?? '');
                return in_array($st, ['pending', 'in production', 'processing', 'under review']);
            })->count();

            // Fetch Payment Transactions (filter ONLY status = success, paid, fail, failed)
            $transactions = [];
            if (Schema::hasTable('payment_transactions')) {
                $transactions = DB::table('payment_transactions')
                    ->leftJoin('pcb_orders', 'payment_transactions.id', '=', 'pcb_orders.transaction_id')
                    ->where('payment_transactions.user_id', $id)
                    ->whereIn(DB::raw('LOWER(payment_transactions.status)'), ['success', 'paid', 'completed', 'fail', 'failed', 'failure'])
                    ->select(
                        'payment_transactions.*',
                        'pcb_orders.id as pcb_order_id',
                        'pcb_orders.order_number'
                    )
                    ->orderBy('payment_transactions.created_at', 'desc')
                    ->get();
            } else if (Schema::hasTable('pcb_payments')) {
                $transactions = DB::table('pcb_payments')
                    ->where('user_id', $id)
                    ->whereIn(DB::raw('LOWER(status)'), ['success', 'paid', 'completed', 'fail', 'failed', 'failure'])
                    ->orderBy('created_at', 'desc')
                    ->get();
            } else if (Schema::hasTable('payments')) {
                $transactions = DB::table('payments')
                    ->where('user_id', $id)
                    ->whereIn(DB::raw('LOWER(status)'), ['success', 'paid', 'completed', 'fail', 'failed', 'failure'])
                    ->orderBy('created_at', 'desc')
                    ->get();
            } else {
                // Derived payment transactions from user orders
                $transactions = DB::table('pcb_orders')
                    ->where('user_id', $id)
                    ->select(
                        'id',
                        DB::raw("CONCAT('TXN-', LPAD(id, 6, '0')) as transaction_number"),
                        DB::raw("CONCAT('PAY-', id) as payment_id"),
                        'id as pcb_order_id',
                        'order_number',
                        'order_value as amount',
                        DB::raw("COALESCE(status, 'Paid') as status"),
                        'created_at'
                    )
                    ->orderBy('created_at', 'desc')
                    ->get();
            }

            // Fetch User Addresses if any
            $addresses = [];
            if (Schema::hasTable('user_addresses')) {
                $addresses = DB::table('user_addresses')->where('user_id', $id)->get();
            } else if (Schema::hasTable('addresses')) {
                $addresses = DB::table('addresses')->where('user_id', $id)->get();
            }

            return response()->json([
                'status' => true,
                'data' => [
                    'user' => $user,
                    'orders' => $orders,
                    'transactions' => $transactions,
                    'addresses' => $addresses,
                    'stats' => [
                        'orders_count' => $ordersCount,
                        'total_spent' => (float)$totalSpent,
                        'completed_orders' => $completedOrders,
                        'pending_orders' => $pendingOrders,
                        'total_transactions' => count($transactions)
                    ]
                ]
            ]);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    // Adjust user credits manually
    public function updateUserCredits(Request $request, $id)
    {
        try {
            $credits = intval($request->input('credits', 0));
            $remarks = $request->input('remarks', 'Adjusted manually by Admin');

            if ($credits === 0) {
                return response()->json(['status' => false, 'message' => 'Credits amount must not be 0.'], 400);
            }

            $user = DB::table('users')->where('id', $id)->first();
            if (!$user) {
                return response()->json(['status' => false, 'message' => 'User not found.'], 404);
            }

            DB::beginTransaction();

            $opening = $user->available_credits;
            $closing = $opening + $credits;

            if ($closing < 0) {
                $closing = 0;
            }

            // Update user balance
            DB::table('users')->where('id', $id)->update([
                'available_credits' => $closing,
                'updated_at' => now()
            ]);

            // Add wallet transaction
            DB::table('wallet_transactions')->insert([
                'user_id' => $id,
                'transaction_type' => 'admin_adjustment',
                'credit_type' => $credits > 0 ? 'credit' : 'debit',
                'credits' => abs($credits),
                'opening_balance' => $opening,
                'closing_balance' => $closing,
                'remarks' => $remarks,
                'created_at' => now()
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Credits updated successfully.',
                'new_balance' => $closing
            ]);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    // Update existing client details
    public function updateUser(Request $request, $id)
    {
        try {
            $user = DB::table('users')->where('id', $id)->first();
            if (!$user) {
                return response()->json(['status' => false, 'message' => 'Client not found.'], 404);
            }

            $firstName = $request->input('first_name', $user->first_name ?? '');
            $lastName = $request->input('last_name', $user->last_name ?? '');
            $name = $request->input('name');
            if (empty($name)) {
                $name = trim("{$firstName} {$lastName}");
            }
            $email = strtolower(trim($request->input('email', $user->email)));
            $phone = $request->input('phone') ?: $request->input('phone_number', $user->phone_number ?? '');
            $companyName = $request->input('company_name', $user->company_name ?? '');
            $gstin = $request->input('gstin') ?: $request->input('gst_number', $user->gstin ?? '');
            $status = $request->input('status', $user->status ?? 'Active');

            $updateData = [
                'name' => $name,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'phone_number' => $phone,
                'company_name' => $companyName,
                'gstin' => $gstin,
                'status' => $status,
                'updated_at' => date('Y-m-d H:i:s'),
            ];

            // If password is updated
            $newPassword = $request->input('password');
            if (!empty($newPassword)) {
                $updateData['password'] = password_hash($newPassword, PASSWORD_BCRYPT);
                $updateData['password_hash'] = password_hash($newPassword, PASSWORD_BCRYPT);
            }

            $columns = Schema::getColumnListing('users');
            $filteredData = [];
            foreach ($updateData as $key => $val) {
                if (in_array($key, $columns)) {
                    $filteredData[$key] = $val;
                }
            }

            DB::table('users')->where('id', $id)->update($filteredData);

            if (Schema::hasTable('pcb_users')) {
                $pcbCols = Schema::getColumnListing('pcb_users');
                $pcbPayload = [
                    'name' => $name,
                    'email' => $email,
                    'mobile' => $phone,
                    'gst_number' => $gstin,
                    'status' => $status,
                    'updated_at' => date('Y-m-d H:i:s'),
                ];
                $pcbFiltered = [];
                foreach ($pcbPayload as $k => $v) {
                    if (in_array($k, $pcbCols)) {
                        $pcbFiltered[$k] = $v;
                    }
                }
                if (!empty($pcbFiltered)) {
                    DB::table('pcb_users')->where('id', $id)->update($pcbFiltered);
                }
            }

            return response()->json([
                'status' => true,
                'message' => 'Client details updated successfully.'
            ]);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    // Change user status
    public function toggleUserStatus(Request $request, $id)
    {
        try {
            $status = $request->input('status');
            if (empty($status)) {
                return response()->json(['status' => false, 'message' => 'Status is required.'], 400);
            }

            $user = DB::table('users')->where('id', $id)->first();
            if (!$user) {
                return response()->json(['status' => false, 'message' => 'Client not found.'], 404);
            }

            DB::table('users')->where('id', $id)->update([
                'status' => $status,
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            if (Schema::hasTable('pcb_users')) {
                if (Schema::hasColumn('pcb_users', 'status')) {
                    DB::table('pcb_users')->where('id', $id)->update([
                        'status' => $status,
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
                }
            }

            return response()->json([
                'status' => true,
                'message' => "Client status updated to {$status}."
            ]);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    // Soft delete user and related records (orders, transactions, addresses)
    public function deleteUser(Request $request, $id)
    {
        try {
            $user = DB::table('users')->where('id', $id)->first();
            if (!$user && Schema::hasTable('pcb_users')) {
                $user = DB::table('pcb_users')->where('id', $id)->first();
            }

            if (!$user) {
                return response()->json(['status' => false, 'message' => 'Client not found.'], 404);
            }

            $now = date('Y-m-d H:i:s');

            if (Schema::hasColumn('users', 'deleted_at')) {
                DB::table('users')->where('id', $id)->update(['deleted_at' => $now, 'status' => 'Deleted']);
            } else {
                DB::table('users')->where('id', $id)->update(['status' => 'Deleted']);
            }

            if (Schema::hasTable('pcb_users')) {
                if (Schema::hasColumn('pcb_users', 'deleted_at')) {
                    DB::table('pcb_users')->where('id', $id)->update(['deleted_at' => $now, 'status' => 'Deleted']);
                } else {
                    DB::table('pcb_users')->where('id', $id)->update(['status' => 'Deleted']);
                }
            }

            // Soft delete pcb_orders
            if (Schema::hasTable('pcb_orders')) {
                if (Schema::hasColumn('pcb_orders', 'deleted_at')) {
                    DB::table('pcb_orders')->where('user_id', $id)->update(['deleted_at' => $now]);
                }
            }

            // Soft delete payment_transactions
            if (Schema::hasTable('payment_transactions')) {
                if (Schema::hasColumn('payment_transactions', 'deleted_at')) {
                    DB::table('payment_transactions')->where('user_id', $id)->update(['deleted_at' => $now]);
                }
            }

            // Soft delete user_addresses
            if (Schema::hasTable('user_addresses')) {
                if (Schema::hasColumn('user_addresses', 'deleted_at')) {
                    DB::table('user_addresses')->where('user_id', $id)->update(['deleted_at' => $now]);
                }
            }

            return response()->json([
                'status' => true,
                'message' => 'Client account and all associated records soft deleted successfully.'
            ]);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    // Support Tickets List
    public function tickets(Request $request)
    {
        try {
            $tickets = DB::table('support_tickets')
                ->leftJoin('users', 'support_tickets.user_id', '=', 'users.id')
                ->select('support_tickets.*', 'users.name as user_name', 'users.email as user_email')
                ->orderBy('support_tickets.created_at', 'desc')
                ->paginate(15);

            return response()->json([
                'status' => true,
                'tickets' => $tickets
            ]);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    // Reply to support ticket
    public function replyTicket(Request $request, $id)
    {
        try {
            $status = $request->input('status', 'resolved');
            $replyMessage = $request->input('reply');

            $ticket = DB::table('support_tickets')->where('id', $id)->first();
            if (!$ticket) {
                return response()->json(['status' => false, 'message' => 'Ticket not found.'], 404);
            }

            DB::table('support_tickets')->where('id', $id)->update([
                'status' => $status,
                'updated_at' => now()
            ]);

            // Queue notification email to customer
            if (!empty($replyMessage)) {
                $user = DB::table('users')->where('id', $ticket->user_id)->first();
                if ($user) {
                    DB::table('email_queue')->insert([
                        'to' => $user->email,
                        'subject' => "Re: Support Ticket #{$ticket->ticket_number} Update",
                        'template_path' => 'emails.support_ticket_reply',
                        'data' => json_encode([
                            'ticket_number' => $ticket->ticket_number,
                            'customer_name' => $user->name,
                            'reply' => $replyMessage,
                            'status' => $status,
                            'original_subject' => $ticket->subject
                        ]),
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }
            }

            return response()->json([
                'status' => true,
                'message' => 'Ticket updated successfully.'
            ]);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    // Credit Packs CRUD
    public function listPacks(Request $request)
    {
        try {
            $packs = DB::table('credit_packs')->orderBy('price', 'asc')->get();
            return response()->json(['status' => true, 'packs' => $packs]);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    public function createPack(Request $request)
    {
        try {
            $name = $request->input('name');
            $price = floatval($request->input('price'));
            $credits = intval($request->input('total_credits'));
            $description = $request->input('description');

            if (empty($name) || $price <= 0 || $credits <= 0) {
                return response()->json(['status' => false, 'message' => 'Name, positive price, and credits are required.'], 400);
            }

            $id = DB::table('credit_packs')->insertGetId([
                'name' => $name,
                'price' => $price,
                'total_credits' => $credits,
                'description' => $description,
                'status' => $request->input('status', 'active'),
                'created_at' => now(),
                'updated_at' => now()
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Credit pack created.',
                'pack_id' => $id
            ]);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    public function updatePack(Request $request, $id)
    {
        try {
            $name = $request->input('name');
            $price = floatval($request->input('price'));
            $credits = intval($request->input('total_credits'));
            $description = $request->input('description');
            $status = $request->input('status', 'active');

            $pack = DB::table('credit_packs')->where('id', $id)->first();
            if (!$pack) {
                return response()->json(['status' => false, 'message' => 'Pack not found.'], 404);
            }

            DB::table('credit_packs')->where('id', $id)->update([
                'name' => $name ?? $pack->name,
                'price' => $price > 0 ? $price : $pack->price,
                'total_credits' => $credits > 0 ? $credits : $pack->total_credits,
                'description' => $description ?? $pack->description,
                'status' => $status,
                'updated_at' => now()
            ]);

            return response()->json(['status' => true, 'message' => 'Credit pack updated successfully.']);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    public function deletePack(Request $request, $id)
    {
        try {
            DB::table('credit_packs')->where('id', $id)->delete();
            return response()->json(['status' => true, 'message' => 'Credit pack deleted.']);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    // Scraping usage logs
    public function usageLogs(Request $request)
    {
        try {
            $logs = DB::table('usage_logs')
                ->leftJoin('users', 'usage_logs.user_id', '=', 'users.id')
                ->select('usage_logs.*', 'users.name as user_name', 'users.email as user_email')
                ->orderBy('usage_logs.created_at', 'desc')
                ->paginate(20);

            return response()->json([
                'status' => true,
                'logs' => $logs
            ]);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }


    // Change Admin Password
    public function changePassword(Request $request)
    {
        try {
            $authHeader = $request->header('Authorization');
            $token = str_replace('Bearer ', '', $authHeader);
            $secret = env('JWT_SECRET', '7+18EvAjOct+KzCCwJLpuwEjtXlzevAk4n09YeUkgfA=');
            $decoded = JWT::decode($token, new \Firebase\JWT\Key($secret, 'HS256'));
            $adminId = $decoded->admin_id ?? null;

            if (!$adminId) {
                return response()->json(['status' => false, 'message' => 'Unauthorized admin.'], 401);
            }

            $currentPassword = $request->input('current_password');
            $newPassword = $request->input('new_password');

            if (empty($currentPassword) || empty($newPassword)) {
                return response()->json(['status' => false, 'message' => 'Current and new password are required.'], 400);
            }

            $admin = DB::table('admins')->where('id', $adminId)->first();
            if (!$admin) {
                return response()->json(['status' => false, 'message' => 'Admin account not found.'], 404);
            }

            if (!password_verify($currentPassword, $admin->password_hash)) {
                return response()->json(['status' => false, 'message' => 'Incorrect current password.'], 400);
            }

            // Update password
            DB::table('admins')->where('id', $adminId)->update([
                'password_hash' => password_verify($newPassword, PASSWORD_BCRYPT) ? password_hash($newPassword, PASSWORD_BCRYPT) : password_hash($newPassword, PASSWORD_BCRYPT),
                'updated_at' => now()
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Password updated successfully.'
            ]);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    // Visitor Logs
    public function visitors(Request $request)
    {
        try {
            $search = $request->input('search', '');

            $query = DB::table('visitor_logs')
                ->leftJoin('users', 'visitor_logs.user_id', '=', 'users.id')
                ->select(
                    'visitor_logs.*', 
                    'users.name as user_name', 
                    'users.email as user_email'
                );

            if ($search) {
                $query->where(function($q) use ($search) {
                    $q->where('visitor_logs.ip_address', 'like', "%{$search}%")
                      ->orWhere('visitor_logs.country', 'like', "%{$search}%")
                      ->orWhere('visitor_logs.city', 'like', "%{$search}%")
                      ->orWhere('visitor_logs.url', 'like', "%{$search}%")
                      ->orWhere('users.name', 'like', "%{$search}%")
                      ->orWhere('users.email', 'like', "%{$search}%");
                });
            }

            $visitors = $query->orderBy('visitor_logs.created_at', 'desc')->paginate(20);

            return response()->json([
                'status' => true,
                'visitors' => $visitors
            ]);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    // ==========================================
    // Staff & Admin User Management Endpoints
    // ==========================================

    public function listStaff(Request $request)
    {
        try {
            $staff = DB::table('admins')
                ->leftJoin('roles', 'admins.role_id', '=', 'roles.id')
                ->select(
                    'admins.id',
                    'admins.name',
                    'admins.username',
                    'admins.email',
                    'admins.status',
                    'admins.role_id',
                    'admins.last_login_at',
                    'admins.created_at',
                    'roles.name as role_name'
                )
                ->orderBy('admins.id', 'asc')
                ->get();

            return response()->json([
                'status' => true,
                'data' => $staff
            ]);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    public function showStaff(Request $request, $id)
    {
        try {
            $admin = DB::table('admins')
                ->leftJoin('roles', 'admins.role_id', '=', 'roles.id')
                ->where('admins.id', $id)
                ->select('admins.*', 'roles.name as role_name')
                ->first();

            if (!$admin) {
                return response()->json(['status' => false, 'message' => 'Staff user not found.'], 404);
            }

            unset($admin->password_hash);

            return response()->json([
                'status' => true,
                'data' => $admin
            ]);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    public function createStaff(Request $request)
    {
        try {
            $name = $request->input('name');
            $username = $request->input('username');
            $email = $request->input('email');
            $password = $request->input('password');
            $roleId = $request->input('role_id');
            $status = $request->input('status', 'active');
            $phoneVal = $request->input('phone') ?: ($request->input('mobile') ?: $request->input('phone_number'));

            if (empty($name) || empty($email) || empty($password)) {
                return response()->json(['status' => false, 'message' => 'Name, email, and password are required.'], 400);
            }

            // Check duplicate email / username
            if (DB::table('admins')->where('email', $email)->exists()) {
                return response()->json(['status' => false, 'message' => 'An account with this email already exists.'], 422);
            }
            if ($username && DB::table('admins')->where('username', $username)->exists()) {
                return response()->json(['status' => false, 'message' => 'An account with this username already exists.'], 422);
            }

            if (!Schema::hasColumn('admins', 'phone') && !Schema::hasColumn('admins', 'phone_number') && !Schema::hasColumn('admins', 'mobile')) {
                try {
                    Schema::table('admins', function (Blueprint $table) {
                        $table->string('phone')->nullable()->after('email');
                    });
                } catch (\Throwable $e) {}
            }

            $insertData = [
                'name' => $name,
                'username' => $username ?: strtok($email, '@'),
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                'role_id' => $roleId ?: null,
                'status' => $status,
                'created_at' => now(),
                'updated_at' => now()
            ];

            if ($phoneVal !== null) {
                if (Schema::hasColumn('admins', 'phone')) {
                    $insertData['phone'] = $phoneVal;
                } elseif (Schema::hasColumn('admins', 'phone_number')) {
                    $insertData['phone_number'] = $phoneVal;
                } elseif (Schema::hasColumn('admins', 'mobile')) {
                    $insertData['mobile'] = $phoneVal;
                }
            }

            $id = DB::table('admins')->insertGetId($insertData);

            return response()->json([
                'status' => true,
                'message' => 'Staff user created successfully.',
                'data' => ['id' => $id]
            ], 201);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    public function updateStaff(Request $request, $id)
    {
        try {
            $admin = DB::table('admins')->where('id', $id)->first();
            if (!$admin) {
                return response()->json(['status' => false, 'message' => 'User not found.'], 404);
            }

            $updateData = ['updated_at' => now()];

            if ($request->has('name')) $updateData['name'] = $request->input('name');
            if ($request->has('username')) $updateData['username'] = $request->input('username');
            if ($request->has('email')) $updateData['email'] = $request->input('email');
            if ($request->has('role_id')) $updateData['role_id'] = $request->input('role_id');
            if ($request->has('status')) $updateData['status'] = $request->input('status');
            if ($request->has('phone') || $request->has('mobile') || $request->has('phone_number')) {
                $phoneVal = $request->input('phone') ?: ($request->input('mobile') ?: $request->input('phone_number'));
                if (!Schema::hasColumn('admins', 'phone') && !Schema::hasColumn('admins', 'phone_number') && !Schema::hasColumn('admins', 'mobile')) {
                    try {
                        Schema::table('admins', function (Blueprint $table) {
                            $table->string('phone')->nullable()->after('email');
                        });
                    } catch (\Throwable $e) {}
                }
                if (Schema::hasColumn('admins', 'phone')) {
                    $updateData['phone'] = $phoneVal;
                } elseif (Schema::hasColumn('admins', 'phone_number')) {
                    $updateData['phone_number'] = $phoneVal;
                } elseif (Schema::hasColumn('admins', 'mobile')) {
                    $updateData['mobile'] = $phoneVal;
                }
            }
            if ($request->filled('password')) {
                $updateData['password_hash'] = password_hash($request->input('password'), PASSWORD_BCRYPT);
            }

            DB::table('admins')->where('id', $id)->update($updateData);
            if ($request->filled('password')) {
                $updateData['password_hash'] = password_hash($request->input('password'), PASSWORD_BCRYPT);
            }

            DB::table('admins')->where('id', $id)->update($updateData);

            return response()->json([
                'status' => true,
                'message' => 'Staff user updated successfully.'
            ]);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    public function deleteStaff(Request $request, $id)
    {
        try {
            if ((int)$id === 1) {
                return response()->json(['status' => false, 'message' => 'Cannot delete default Super Admin account.'], 403);
            }

            DB::table('admins')->where('id', $id)->delete();

            return response()->json([
                'status' => true,
                'message' => 'Staff user deleted.'
            ]);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    // ==========================================
    // Roles & Permissions Endpoints
    // ==========================================

    public function listRoles(Request $request)
    {
        try {
            $roles = DB::table('roles')->get();

            foreach ($roles as $role) {
                $role->permissions = DB::table('role_permissions')
                    ->join('permissions', 'role_permissions.permission_id', '=', 'permissions.id')
                    ->where('role_permissions.role_id', $role->id)
                    ->select('permissions.id', 'permissions.name', 'permissions.slug', 'permissions.module')
                    ->get();

                if ((isset($role->name) && strtolower($role->name) === 'super admin') || (int)$role->id === 1) {
                    $role->users_count = DB::table('admins')
                        ->where(function ($q) use ($role) {
                            $q->where('role_id', $role->id)
                              ->orWhereNull('role_id');
                        })->count();
                } else {
                    $role->users_count = DB::table('admins')->where('role_id', $role->id)->count();
                }
            }

            return response()->json([
                'status' => true,
                'data' => $roles
            ]);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    public function showRole(Request $request, $id)
    {
        try {
            $role = DB::table('roles')->where('id', $id)->first();
            if (!$role) {
                return response()->json(['status' => false, 'message' => 'Role not found.'], 404);
            }

            $role->permissions = DB::table('role_permissions')
                ->join('permissions', 'role_permissions.permission_id', '=', 'permissions.id')
                ->where('role_permissions.role_id', $role->id)
                ->select('permissions.id', 'permissions.name', 'permissions.slug', 'permissions.module')
                ->get();

            return response()->json([
                'status' => true,
                'data' => $role
            ]);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    public function listPermissions(Request $request)
    {
        try {
            $permissions = DB::table('permissions')->get();
            return response()->json([
                'status' => true,
                'data' => $permissions
            ]);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    public function createRole(Request $request)
    {
        try {
            $name = $request->input('name');
            $description = $request->input('description');
            $permissionIds = $request->input('permission_ids', []);

            if (empty($name)) {
                return response()->json(['status' => false, 'message' => 'Role name is required.'], 400);
            }

            $roleId = DB::table('roles')->insertGetId([
                'name' => $name,
                'description' => $description,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            if (is_array($permissionIds)) {
                foreach ($permissionIds as $pId) {
                    DB::table('role_permissions')->insert([
                        'role_id' => $roleId,
                        'permission_id' => $pId,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }
            }

            return response()->json([
                'status' => true,
                'message' => 'Role created successfully.',
                'data' => ['id' => $roleId]
            ], 201);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    public function updateRole(Request $request, $id)
    {
        try {
            $role = DB::table('roles')->where('id', $id)->first();
            if (!$role) {
                return response()->json(['status' => false, 'message' => 'Role not found.'], 404);
            }

            if ($request->has('name')) {
                DB::table('roles')->where('id', $id)->update([
                    'name' => $request->input('name'),
                    'description' => $request->input('description', $role->description),
                    'updated_at' => now()
                ]);
            }

            if ($request->has('permission_ids') && is_array($request->input('permission_ids'))) {
                DB::table('role_permissions')->where('role_id', $id)->delete();
                foreach ($request->input('permission_ids') as $pId) {
                    DB::table('role_permissions')->insert([
                        'role_id' => $id,
                        'permission_id' => $pId,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }
            }

            return response()->json([
                'status' => true,
                'message' => 'Role updated successfully.'
            ]);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    public function deleteRole(Request $request, $id)
    {
        try {
            if ((int)$id === 1) {
                return response()->json(['status' => false, 'message' => 'Cannot delete Super Admin role.'], 403);
            }

            DB::table('role_permissions')->where('role_id', $id)->delete();
            DB::table('roles')->where('id', $id)->delete();

            return response()->json([
                'status' => true,
                'message' => 'Role deleted.'
            ]);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    /**
     * Get completed payments list with pagination and filters for admin panel
     */
    public function payments(Request $request)
    {
        try {
            $userTable = Schema::hasTable('pcb_users') ? 'pcb_users' : 'users';
            $userColumns = Schema::hasTable($userTable) ? Schema::getColumnListing($userTable) : [];
            $mobileCol = in_array('mobile', $userColumns) ? 'mobile' : (in_array('phone_number', $userColumns) ? 'phone_number' : null);
            $mobileSelect = $mobileCol ? "{$userTable}.{$mobileCol} as user_mobile" : DB::raw("NULL as user_mobile");

            $query = DB::table('payment_transactions')
                ->leftJoin($userTable, 'payment_transactions.user_id', '=', "{$userTable}.id")
                ->select(
                    'payment_transactions.*',
                    "{$userTable}.name as user_name",
                    "{$userTable}.email as user_email",
                    $mobileSelect
                );

            // Filter for completed/successful payments
            $statusFilter = $request->input('status', 'success');
            if ($statusFilter !== 'all') {
                $query->where('payment_transactions.status', $statusFilter);
            }

            // Search filter (Transaction #, Razorpay Payment ID, Razorpay Order ID, Customer Name/Email)
            if ($request->filled('search')) {
                $search = trim($request->input('search'));
                $query->where(function ($q) use ($search, $userTable, $mobileCol) {
                    $q->where('payment_transactions.transaction_number', 'LIKE', "%{$search}%")
                        ->orWhere('payment_transactions.razorpay_payment_id', 'LIKE', "%{$search}%")
                        ->orWhere('payment_transactions.razorpay_order_id', 'LIKE', "%{$search}%")
                        ->orWhere("{$userTable}.name", 'LIKE', "%{$search}%")
                        ->orWhere("{$userTable}.email", 'LIKE', "%{$search}%");
                    if ($mobileCol) {
                        $q->orWhere("{$userTable}.{$mobileCol}", 'LIKE', "%{$search}%");
                    }
                });
            }

            // Payment method filter (e.g. Razorpay, UPI, Card, Netbanking)
            if ($request->filled('payment_method')) {
                $query->where('payment_transactions.payment_method', $request->input('payment_method'));
            }

            // Date Range Filtering
            if ($request->filled('start_date')) {
                $query->whereDate('payment_transactions.created_at', '>=', $request->input('start_date'));
            }
            if ($request->filled('end_date')) {
                $query->whereDate('payment_transactions.created_at', '<=', $request->input('end_date'));
            }

            // Sorting
            $sortBy = $request->input('sort_by', 'created_at');
            $sortOrder = strtolower($request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
            
            $validSortColumns = ['id', 'amount', 'created_at', 'status', 'payment_method'];
            if (!in_array($sortBy, $validSortColumns)) {
                $sortBy = 'created_at';
            }
            
            $query->orderBy("payment_transactions.{$sortBy}", $sortOrder);

            // Pagination
            $perPage = (int) $request->input('per_page', 15);
            $page = (int) $request->input('page', 1);

            $total = $query->count();
            $payments = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

            // Total statistics
            $totalAmount = DB::table('payment_transactions')
                ->where('status', 'success')
                ->sum('amount');

            return response()->json([
                'status' => true,
                'data' => $payments,
                'meta' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => ceil($total / $perPage) ?: 1,
                    'total_completed_amount' => (float)$totalAmount
                ]
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch payments: ' . $th->getMessage()
            ], 500);
        }
    }

    /**
     * Get all Gerber files with client and order details
     */
    public function gerberFiles(Request $request)
    {
        try {
            $query = DB::table('gerber_files')
                ->leftJoin('users', 'gerber_files.user_id', '=', 'users.id')
                ->leftJoin('pcb_orders', 'pcb_orders.gerber_file_id', '=', 'gerber_files.id')
                ->whereNull('gerber_files.deleted_at')
                ->select(
                    'gerber_files.id',
                    'gerber_files.user_id',
                    'gerber_files.original_name',
                    'gerber_files.file_name',
                    'gerber_files.file_path',
                    'gerber_files.file_url',
                    'gerber_files.file_size',
                    'gerber_files.board_name',
                    'gerber_files.preview_data',
                    'gerber_files.created_at',
                    'gerber_files.updated_at',
                    'users.name as client_name',
                    'users.first_name as client_first_name',
                    'users.last_name as client_last_name',
                    'users.email as client_email',
                    'users.company_name as client_company',
                    'users.phone_number as client_phone',
                    'pcb_orders.id as order_id',
                    'pcb_orders.order_number as order_number'
                );

            // Search filter
            $search = trim($request->input('search', ''));
            if (!empty($search)) {
                $query->where(function ($q) use ($search) {
                    $q->where('gerber_files.original_name', 'like', "%{$search}%")
                        ->orWhere('gerber_files.board_name', 'like', "%{$search}%")
                        ->orWhere('gerber_files.file_name', 'like', "%{$search}%")
                        ->orWhere('users.name', 'like', "%{$search}%")
                        ->orWhere('users.email', 'like', "%{$search}%")
                        ->orWhere('users.company_name', 'like', "%{$search}%")
                        ->orWhere('pcb_orders.order_number', 'like', "%{$search}%");
                });
            }

            // Type filter: client | guest | all
            $type = $request->input('type');
            if ($type === 'client') {
                $query->whereNotNull('gerber_files.user_id');
            } elseif ($type === 'guest') {
                $query->whereNull('gerber_files.user_id');
            }

            // Attachment filter: attached | unattached
            $attachment = $request->input('attachment');
            if ($attachment === 'attached') {
                $query->whereNotNull('pcb_orders.id');
            } elseif ($attachment === 'unattached') {
                $query->whereNull('pcb_orders.id');
            }

            // Ordering & Pagination
            $query->orderBy('gerber_files.created_at', 'desc');

            $files = $query->get();

            // Stats calculation
            $totalFiles = DB::table('gerber_files')->whereNull('deleted_at')->count();
            $clientFilesCount = DB::table('gerber_files')->whereNull('deleted_at')->whereNotNull('user_id')->count();
            $guestFilesCount = DB::table('gerber_files')->whereNull('deleted_at')->whereNull('user_id')->count();
            $orderedFilesCount = DB::table('pcb_orders')->whereNotNull('gerber_file_id')->distinct('gerber_file_id')->count('gerber_file_id');

            return response()->json([
                'status' => true,
                'data' => $files,
                'stats' => [
                    'total_files' => $totalFiles,
                    'client_files' => $clientFilesCount,
                    'guest_files' => $guestFilesCount,
                    'ordered_files' => $orderedFilesCount
                ]
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch Gerber files: ' . $th->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a Gerber file
     */
    public function deleteGerberFile($id)
    {
        try {
            $file = DB::table('gerber_files')->where('id', $id)->first();
            if (!$file) {
                return response()->json([
                    'status' => false,
                    'message' => 'Gerber file not found'
                ], 404);
            }

            // Remove physical file if exists
            if (!empty($file->file_path) && \Illuminate\Support\Facades\Storage::disk('public')->exists($file->file_path)) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($file->file_path);
            }

            // Delete database row
            DB::table('gerber_files')->where('id', $id)->delete();

            return response()->json([
                'status' => true,
                'message' => 'Gerber file deleted successfully'
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete Gerber file: ' . $th->getMessage()
            ], 500);
        }
    }
}

