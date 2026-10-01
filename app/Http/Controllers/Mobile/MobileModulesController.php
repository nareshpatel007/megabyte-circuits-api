<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MobileModulesController extends Controller
{
    /**
     * Check if the authenticated employee has a given permission.
     */
    public static function hasPermission(Request $request, string $permission): bool
    {
        $adminId = $request->attributes->get('admin_id');
        if (!$adminId) {
            return false;
        }

        $admin = DB::table('admins')->where('id', $adminId)->first();
        if (!$admin) {
            $admin = DB::table('users')->where('id', $adminId)->first();
        }
        if (!$admin) {
            return false;
        }

        $permissions = MobileAuthController::fetchPermissionsForAdmin($admin);

        if (in_array('*', $permissions) || in_array($permission, $permissions)) {
            return true;
        }

        // Module fallbacks & alias mappings
        if ($permission === 'gerber.view' && in_array('orders.view_gerber', $permissions)) {
            return true;
        }
        if ($permission === 'clients.view' && in_array('users.manage', $permissions)) {
            return true;
        }
        if ($permission === 'staff.view' && in_array('users.manage', $permissions)) {
            return true;
        }
        if ($permission === 'role.view' && in_array('roles.manage', $permissions)) {
            return true;
        }
        if ($permission === 'dashboard.view') {
            return true;
        }

        return false;
    }

    /**
     * Mobile Payments List Endpoint
     */
    public function payments(Request $request)
    {
        if (!self::hasPermission($request, 'payments.view')) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to access Payments.'
            ], 403);
        }

        try {
            if (!Schema::hasTable('payment_transactions')) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0]
                ]);
            }

            $userTable = Schema::hasTable('pcb_users') ? 'pcb_users' : 'users';
            $ordersTable = Schema::hasTable('pcb_orders') ? 'pcb_orders' : (Schema::hasTable('orders') ? 'orders' : null);

            $query = DB::table('payment_transactions');
            if (Schema::hasColumn('payment_transactions', 'deleted_at')) {
                $query->whereNull('payment_transactions.deleted_at');
            }

            if ($ordersTable) {
                $query->leftJoin($ordersTable, 'payment_transactions.id', '=', "{$ordersTable}.transaction_id")
                    ->leftJoin($userTable, function ($join) use ($userTable, $ordersTable) {
                        $join->on(DB::raw("COALESCE(payment_transactions.user_id, {$ordersTable}.user_id)"), '=', "{$userTable}.id");
                    })
                    ->select([
                        'payment_transactions.*',
                        "{$userTable}.name as client_name",
                        "{$userTable}.email as client_email",
                        "{$ordersTable}.id as order_id",
                        "{$ordersTable}.order_number as order_number",
                        "{$ordersTable}.order_value as order_value",
                    ]);
            } else {
                $query->leftJoin($userTable, 'payment_transactions.user_id', '=', "{$userTable}.id")
                    ->select([
                        'payment_transactions.*',
                        "{$userTable}.name as client_name",
                        "{$userTable}.email as client_email",
                    ]);
            }

            if ($search = trim($request->input('search', ''))) {
                $query->where(function ($q) use ($search, $userTable, $ordersTable) {
                    $q->where('payment_transactions.transaction_number', 'like', "%{$search}%")
                        ->orWhere('payment_transactions.razorpay_payment_id', 'like', "%{$search}%")
                        ->orWhere('payment_transactions.razorpay_order_id', 'like', "%{$search}%")
                        ->orWhere("{$userTable}.name", 'like', "%{$search}%")
                        ->orWhere("{$userTable}.email", 'like', "%{$search}%");
                    if ($ordersTable) {
                        $q->orWhere("{$ordersTable}.order_number", 'like', "%{$search}%");
                    }
                });
            }

            if ($status = trim($request->input('status', ''))) {
                $statusLower = strtolower($status);
                if (in_array($statusLower, ['completed', 'paid', 'success'])) {
                    $query->whereIn(DB::raw('LOWER(payment_transactions.status)'), ['success', 'paid', 'completed']);
                } elseif (in_array($statusLower, ['failed', 'failure', 'fail'])) {
                    $query->whereIn(DB::raw('LOWER(payment_transactions.status)'), ['failed', 'failure', 'fail']);
                } elseif ($statusLower === 'pending') {
                    $query->whereIn(DB::raw('LOWER(payment_transactions.status)'), ['pending', 'initiated', 'processing']);
                } else {
                    $query->where('payment_transactions.status', $status);
                }
            }

            $perPage = (int) $request->input('per_page', 20);
            $payments = $query->orderBy('payment_transactions.id', 'desc')->paginate($perPage);

            $transformedData = collect($payments->items())->map(function ($p) {
                $payloadData = [];
                if (!empty($p->payload)) {
                    $payloadData = is_string($p->payload) ? json_decode($p->payload, true) : (array)$p->payload;
                }

                $rawStatus = strtolower($p->status ?? 'pending');
                $normalizedStatus = 'Pending';
                if (in_array($rawStatus, ['success', 'paid', 'completed'])) {
                    $normalizedStatus = 'Completed';
                } elseif (in_array($rawStatus, ['failed', 'failure', 'fail'])) {
                    $normalizedStatus = 'Failed';
                }

                $orderNumber = $p->order_number ?? ($payloadData['order_number'] ?? null);
                $clientName = $p->client_name ?? ($payloadData['customer_name'] ?? ($payloadData['name'] ?? null));
                $clientEmail = $p->client_email ?? ($payloadData['customer_email'] ?? ($payloadData['email'] ?? null));

                return [
                    'id' => $p->id,
                    'transaction_number' => $p->transaction_number,
                    'razorpay_payment_id' => $p->razorpay_payment_id ?? null,
                    'razorpay_order_id' => $p->razorpay_order_id ?? null,
                    'amount' => (float)$p->amount,
                    'total_amount' => (float)($p->amount ?? ($p->order_value ?? 0)),
                    'order_value' => isset($p->order_value) ? (float)$p->order_value : (float)$p->amount,
                    'advance_payment' => (float)$p->amount,
                    'currency' => $p->currency ?? 'INR',
                    'status' => $p->status,
                    'payment_status' => $normalizedStatus,
                    'payment_method' => $p->payment_method ?? 'Online',
                    'order_id' => $p->order_id ?? null,
                    'order_number' => $orderNumber,
                    'client_name' => $clientName ?: 'Direct Client',
                    'client_email' => $clientEmail,
                    'user_name' => $clientName ?: 'Direct Client',
                    'user_email' => $clientEmail,
                    'created_at' => $p->created_at,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $transformedData,
                'meta' => [
                    'current_page' => $payments->currentPage(),
                    'last_page' => $payments->lastPage(),
                    'total' => $payments->total()
                ]
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch payments: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mobile Gerber Files List Endpoint
     */
    public function gerberFiles(Request $request)
    {
        if (!self::hasPermission($request, 'gerber.view')) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to access Gerber Files.'
            ], 403);
        }

        try {
            if (!Schema::hasTable('gerber_files')) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0]
                ]);
            }

            $query = DB::table('gerber_files')
                ->leftJoin('users', 'gerber_files.user_id', '=', 'users.id')
                ->whereNull('gerber_files.deleted_at')
                ->select([
                    'gerber_files.id',
                    'gerber_files.original_name',
                    'gerber_files.file_name',
                    'gerber_files.file_path',
                    'gerber_files.file_url',
                    'gerber_files.file_size',
                    'gerber_files.board_name',
                    'gerber_files.created_at',
                    'users.name as client_name',
                    'users.email as client_email',
                ]);

            if ($search = $request->input('search')) {
                $query->where(function ($q) use ($search) {
                    $q->where('gerber_files.original_name', 'like', "%{$search}%")
                        ->orWhere('gerber_files.board_name', 'like', "%{$search}%")
                        ->orWhere('users.name', 'like', "%{$search}%");
                });
            }

            $perPage = (int) $request->input('per_page', 20);
            $files = $query->orderBy('gerber_files.id', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $files->items(),
                'meta' => [
                    'current_page' => $files->currentPage(),
                    'last_page' => $files->lastPage(),
                    'total' => $files->total()
                ]
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch gerber files: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mobile Clients List Endpoint
     */
    public function clients(Request $request)
    {
        if (
            !self::hasPermission($request, 'clients.view') &&
            !self::hasPermission($request, 'orders.edit') &&
            !self::hasPermission($request, 'orders.manage')
        ) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to access Clients.'
            ], 403);
        }

        try {
            $query = DB::table('users');
            if (Schema::hasColumn('users', 'deleted_at')) {
                $query->whereNull('deleted_at');
            }

            if ($search = $request->input('search')) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('company', 'like', "%{$search}%")
                        ->orWhere('mobile', 'like', "%{$search}%");
                });
            }

            $perPage = (int) $request->input('per_page', 20);
            $clients = $query->orderBy('id', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $clients->items(),
                'meta' => [
                    'current_page' => $clients->currentPage(),
                    'last_page' => $clients->lastPage(),
                    'total' => $clients->total()
                ]
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch clients: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mobile Staff List Endpoint
     */
    public function staff(Request $request)
    {
        if (!self::hasPermission($request, 'staff.view')) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to access Staff.'
            ], 403);
        }

        try {
            $query = DB::table('admins')
                ->leftJoin('roles', 'admins.role_id', '=', 'roles.id')
                ->select([
                    'admins.id',
                    'admins.name',
                    'admins.username',
                    'admins.email',
                    'admins.status',
                    'admins.role_id',
                    'admins.created_at',
                    'roles.name as role_name'
                ]);

            if ($search = $request->input('search')) {
                $query->where(function ($q) use ($search) {
                    $q->where('admins.name', 'like', "%{$search}%")
                        ->orWhere('admins.email', 'like', "%{$search}%")
                        ->orWhere('admins.username', 'like', "%{$search}%");
                });
            }

            $perPage = (int) $request->input('per_page', 20);
            $staff = $query->orderBy('admins.id', 'asc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $staff->items(),
                'meta' => [
                    'current_page' => $staff->currentPage(),
                    'last_page' => $staff->lastPage(),
                    'total' => $staff->total()
                ]
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch staff: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mobile Roles List Endpoint
     */
    public function roles(Request $request)
    {
        if (
            !self::hasPermission($request, 'role.view') &&
            !self::hasPermission($request, 'roles.view') &&
            !self::hasPermission($request, 'roles.manage') &&
            !self::hasPermission($request, 'staff.view') &&
            !self::hasPermission($request, 'staff.edit') &&
            !self::hasPermission($request, 'staff.create') &&
            !self::hasPermission($request, 'users.manage')
        ) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to access Roles.'
            ], 403);
        }

        try {
            $roles = DB::table('roles')->get()->map(function ($r) {
                $r->users_count = DB::table('admins')->where('role_id', $r->id)->count();
                return $r;
            });

            return response()->json([
                'success' => true,
                'data' => $roles
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch roles: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mobile Email Logs Endpoint
     */
    public function emailLogs(Request $request)
    {
        if (!self::hasPermission($request, 'email_logs.view')) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to access Email Logs.'
            ], 403);
        }

        try {
            if (!Schema::hasTable('email_logs')) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'meta' => ['total' => 0]
                ]);
            }

            $query = DB::table('email_logs');
            if ($search = $request->input('search')) {
                $query->where(function ($q) use ($search) {
                    $q->where('to', 'like', "%{$search}%")
                        ->orWhere('subject', 'like', "%{$search}%");
                });
            }

            $perPage = (int) $request->input('per_page', 20);
            $logs = $query->orderBy('id', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $logs->items(),
                'meta' => [
                    'current_page' => $logs->currentPage(),
                    'last_page' => $logs->lastPage(),
                    'total' => $logs->total()
                ]
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch email logs: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mobile System Health Overview Endpoint
     */
    public function systemHealth(Request $request)
    {
        if (!self::hasPermission($request, 'system_health.view')) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to access System Health.'
            ], 403);
        }

        try {
            $dbStatus = 'healthy';
            try {
                DB::connection()->getPdo();
            } catch (\Throwable $e) {
                $dbStatus = 'unreachable';
            }

            $failedJobsCount = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
            $ordersTotal = Schema::hasTable('pcb_orders') ? DB::table('pcb_orders')->count() : 0;

            return response()->json([
                'success' => true,
                'data' => [
                    'environment' => app()->environment(),
                    'database' => $dbStatus,
                    'php_version' => PHP_VERSION,
                    'failed_jobs' => $failedJobsCount,
                    'total_orders' => $ordersTotal,
                    'server_time' => now()->toDateTimeString(),
                    'status' => 'Operational'
                ]
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch system health: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mobile Blogs Endpoint
     */
    public function blogs(Request $request)
    {
        if (!self::hasPermission($request, 'blog.view')) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to access Blog Management.'
            ], 403);
        }

        try {
            if (!Schema::hasTable('blogs')) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'meta' => ['total' => 0]
                ]);
            }

            $query = DB::table('blogs');
            if ($search = $request->input('search')) {
                $query->where('title', 'like', "%{$search}%");
            }

            $perPage = (int) $request->input('per_page', 20);
            $blogs = $query->orderBy('id', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $blogs->items(),
                'meta' => [
                    'current_page' => $blogs->currentPage(),
                    'last_page' => $blogs->lastPage(),
                    'total' => $blogs->total()
                ]
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch blogs: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mobile Settings Endpoint
     */
    public function settings(Request $request)
    {
        if (!self::hasPermission($request, 'settings.general')) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to access Settings.'
            ], 403);
        }

        try {
            $statuses = DB::table('pcb_order_statuses')->orderBy('sort_order', 'asc')->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'app_name' => 'Megabyte Circuits Mobile',
                    'version' => '1.0.0',
                    'order_statuses' => $statuses
                ]
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch settings: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Realtime SSE Stream for Employee Permission Changes
     */
    public function streamPermissions(Request $request)
    {
        $adminId = $request->attributes->get('admin_id');

        return response()->stream(function () use ($adminId) {
            $start = time();
            $lastPermHash = '';

            while (time() - $start < 25) {
                if ($adminId) {
                    $admin = DB::table('admins')->where('id', $adminId)->first();
                    if (!$admin) {
                        $admin = DB::table('users')->where('id', $adminId)->first();
                    }
                    if ($admin) {
                        $perms = MobileAuthController::fetchPermissionsForAdmin($admin);
                        $currentHash = md5(json_encode($perms));
                        if ($lastPermHash !== '' && $lastPermHash !== $currentHash) {
                            echo "data: " . json_encode(['event' => 'UserPermissionsUpdated', 'permissions' => $perms]) . "\n\n";
                            if (ob_get_level() > 0) ob_flush();
                            flush();
                        }
                        $lastPermHash = $currentHash;
                    }
                }
                sleep(2);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    // --- CLIENTS CRUD ---
    public function storeClient(Request $request)
    {
        if (!self::hasPermission($request, 'clients.create')) {
            return response()->json(['success' => false, 'message' => 'You do not have permission to create clients.'], 403);
        }
        try {
            $name = trim($request->input('name', ''));
            $email = trim($request->input('email', ''));
            if (empty($name) || empty($email)) {
                return response()->json(['success' => false, 'message' => 'Name and email are required.'], 422);
            }
            $id = DB::table('users')->insertGetId([
                'name' => $name,
                'email' => $email,
                'company' => trim($request->input('company', '')),
                'mobile' => trim($request->input('mobile', '')),
                'password' => bcrypt(trim($request->input('password', '12345678'))),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            return response()->json(['success' => true, 'message' => 'Client created successfully.', 'id' => (string)$id]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function updateClient(Request $request, $id)
    {
        if (!self::hasPermission($request, 'clients.edit')) {
            return response()->json(['success' => false, 'message' => 'You do not have permission to edit clients.'], 403);
        }
        try {
            $update = ['updated_at' => date('Y-m-d H:i:s')];
            if ($request->has('name')) $update['name'] = trim($request->input('name'));
            if ($request->has('email')) $update['email'] = trim($request->input('email'));
            if ($request->has('company')) $update['company'] = trim($request->input('company'));
            if ($request->has('mobile')) $update['mobile'] = trim($request->input('mobile'));
            DB::table('users')->where('id', $id)->update($update);
            return response()->json(['success' => true, 'message' => 'Client updated successfully.']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function deleteClient(Request $request, $id)
    {
        if (!self::hasPermission($request, 'clients.delete')) {
            return response()->json(['success' => false, 'message' => 'You do not have permission to delete clients.'], 403);
        }
        try {
            if (Schema::hasColumn('users', 'deleted_at')) {
                DB::table('users')->where('id', $id)->update(['deleted_at' => date('Y-m-d H:i:s')]);
            } else {
                DB::table('users')->where('id', $id)->delete();
            }
            return response()->json(['success' => true, 'message' => 'Client deleted successfully.']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // --- STAFF CRUD ---
    public function storeStaff(Request $request)
    {
        if (!self::hasPermission($request, 'staff.create')) {
            return response()->json(['success' => false, 'message' => 'You do not have permission to add staff members.'], 403);
        }
        try {
            $name = trim($request->input('name', ''));
            $email = trim($request->input('email', ''));
            if (empty($name) || empty($email)) {
                return response()->json(['success' => false, 'message' => 'Name and email are required.'], 422);
            }
            $username = trim($request->input('username', strtolower(explode(' ', $name)[0] . rand(100, 999))));
            $roleId = (int)$request->input('role_id', 2);
            $id = DB::table('admins')->insertGetId([
                'name' => $name,
                'username' => $username,
                'email' => $email,
                'role_id' => $roleId,
                'status' => 'Active',
                'password' => bcrypt(trim($request->input('password', '12345678'))),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            return response()->json(['success' => true, 'message' => 'Staff member created successfully.', 'id' => (string)$id]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function updateStaff(Request $request, $id)
    {
        if (!self::hasPermission($request, 'staff.edit')) {
            return response()->json(['success' => false, 'message' => 'You do not have permission to edit staff members.'], 403);
        }
        try {
            $update = ['updated_at' => date('Y-m-d H:i:s')];
            if ($request->has('name')) $update['name'] = trim($request->input('name'));
            if ($request->has('email')) $update['email'] = trim($request->input('email'));
            if ($request->has('username')) $update['username'] = trim($request->input('username'));
            if ($request->has('role_id')) $update['role_id'] = (int)$request->input('role_id');
            if ($request->has('status')) $update['status'] = trim($request->input('status'));
            DB::table('admins')->where('id', $id)->update($update);
            return response()->json(['success' => true, 'message' => 'Staff member updated successfully.']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function deleteStaff(Request $request, $id)
    {
        if (!self::hasPermission($request, 'staff.delete')) {
            return response()->json(['success' => false, 'message' => 'You do not have permission to delete staff members.'], 403);
        }
        try {
            DB::table('admins')->where('id', $id)->delete();
            return response()->json(['success' => true, 'message' => 'Staff member deleted successfully.']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // --- ROLES CRUD ---
    public function storeRole(Request $request)
    {
        if (!self::hasPermission($request, 'role.create')) {
            return response()->json(['success' => false, 'message' => 'You do not have permission to create roles.'], 403);
        }
        try {
            $name = trim($request->input('name', ''));
            if (empty($name)) {
                return response()->json(['success' => false, 'message' => 'Role name is required.'], 422);
            }
            $slug = strtolower(str_replace(' ', '-', $name));
            $id = DB::table('roles')->insertGetId([
                'name' => $name,
                'slug' => $slug,
                'description' => trim($request->input('description', '')),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            return response()->json(['success' => true, 'message' => 'Role created successfully.', 'id' => (string)$id]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function updateRole(Request $request, $id)
    {
        if (!self::hasPermission($request, 'role.edit')) {
            return response()->json(['success' => false, 'message' => 'You do not have permission to edit roles.'], 403);
        }
        try {
            $update = ['updated_at' => date('Y-m-d H:i:s')];
            if ($request->has('name')) $update['name'] = trim($request->input('name'));
            if ($request->has('description')) $update['description'] = trim($request->input('description'));
            DB::table('roles')->where('id', $id)->update($update);
            return response()->json(['success' => true, 'message' => 'Role updated successfully.']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function deleteRole(Request $request, $id)
    {
        if (!self::hasPermission($request, 'role.delete')) {
            return response()->json(['success' => false, 'message' => 'You do not have permission to delete roles.'], 403);
        }
        try {
            DB::table('roles')->where('id', $id)->delete();
            return response()->json(['success' => true, 'message' => 'Role deleted successfully.']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // --- BLOGS CRUD ---
    public function showBlog(Request $request, $id)
    {
        if (!self::hasPermission($request, 'blog.view')) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to view blog posts.'
            ], 403);
        }

        try {
            $blog = DB::table('blogs')->where('id', $id)->first();
            if (!$blog) {
                return response()->json([
                    'success' => false,
                    'message' => 'Blog post not found.'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $blog
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch blog post: ' . $e->getMessage()
            ], 500);
        }
    }

    public function storeBlog(Request $request)
    {
        if (!self::hasPermission($request, 'blog.create')) {
            return response()->json(['success' => false, 'message' => 'You do not have permission to create blog posts.'], 403);
        }
        try {
            $title = trim($request->input('title', ''));
            if (empty($title)) {
                return response()->json(['success' => false, 'message' => 'Blog title is required.'], 422);
            }
            $id = DB::table('blogs')->insertGetId([
                'title' => $title,
                'slug' => strtolower(str_replace(' ', '-', $title)),
                'content' => trim($request->input('content', '')),
                'status' => trim($request->input('status', 'Published')),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            return response()->json(['success' => true, 'message' => 'Blog post created successfully.', 'id' => (string)$id]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function updateBlog(Request $request, $id)
    {
        if (!self::hasPermission($request, 'blog.edit')) {
            return response()->json(['success' => false, 'message' => 'You do not have permission to edit blog posts.'], 403);
        }
        try {
            $update = ['updated_at' => date('Y-m-d H:i:s')];
            if ($request->has('title')) {
                $update['title'] = trim($request->input('title'));
                $update['slug'] = strtolower(str_replace(' ', '-', $update['title']));
            }
            if ($request->has('content')) $update['content'] = trim($request->input('content'));
            if ($request->has('status')) $update['status'] = trim($request->input('status'));
            DB::table('blogs')->where('id', $id)->update($update);
            return response()->json(['success' => true, 'message' => 'Blog post updated successfully.']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function deleteBlog(Request $request, $id)
    {
        if (!self::hasPermission($request, 'blog.delete')) {
            return response()->json(['success' => false, 'message' => 'You do not have permission to delete blog posts.'], 403);
        }
        try {
            DB::table('blogs')->where('id', $id)->delete();
            return response()->json(['success' => true, 'message' => 'Blog post deleted successfully.']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // --- GERBER FILES CRUD ---
    public function deleteGerberFile(Request $request, $id)
    {
        if (!self::hasPermission($request, 'gerber.delete')) {
            return response()->json(['success' => false, 'message' => 'You do not have permission to delete Gerber files.'], 403);
        }
        try {
            if (Schema::hasColumn('gerber_files', 'deleted_at')) {
                DB::table('gerber_files')->where('id', $id)->update(['deleted_at' => date('Y-m-d H:i:s')]);
            } else {
                DB::table('gerber_files')->where('id', $id)->delete();
            }
            return response()->json(['success' => true, 'message' => 'Gerber file deleted successfully.']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // --- STATUSES CRUD ---
    public function storeStatus(Request $request)
    {
        if (!self::hasPermission($request, 'statuses.manage') && !self::hasPermission($request, 'settings.update')) {
            return response()->json(['success' => false, 'message' => 'You do not have permission to manage statuses.'], 403);
        }
        try {
            $name = trim($request->input('name', ''));
            if (empty($name)) {
                return response()->json(['success' => false, 'message' => 'Status name is required.'], 422);
            }
            $table = Schema::hasTable('pcb_order_statuses') ? 'pcb_order_statuses' : (Schema::hasTable('pcb_statuses') ? 'pcb_statuses' : 'statuses');
            $maxOrder = DB::table($table)->max('sort_order') ?? 0;
            $id = DB::table($table)->insertGetId([
                'name' => $name,
                'sort_order' => $maxOrder + 1,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            return response()->json(['success' => true, 'message' => 'Status added successfully.', 'id' => (string)$id]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function updateStatusConfig(Request $request, $id)
    {
        if (!self::hasPermission($request, 'statuses.manage') && !self::hasPermission($request, 'settings.update')) {
            return response()->json(['success' => false, 'message' => 'You do not have permission to manage statuses.'], 403);
        }
        try {
            $table = Schema::hasTable('pcb_order_statuses') ? 'pcb_order_statuses' : (Schema::hasTable('pcb_statuses') ? 'pcb_statuses' : 'statuses');
            $update = ['updated_at' => date('Y-m-d H:i:s')];
            if ($request->has('name')) $update['name'] = trim($request->input('name'));
            if ($request->has('sort_order')) $update['sort_order'] = (int)$request->input('sort_order');
            DB::table($table)->where('id', $id)->update($update);
            return response()->json(['success' => true, 'message' => 'Status updated successfully.']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function deleteStatusConfig(Request $request, $id)
    {
        if (!self::hasPermission($request, 'statuses.manage') && !self::hasPermission($request, 'settings.update')) {
            return response()->json(['success' => false, 'message' => 'You do not have permission to manage statuses.'], 403);
        }
        try {
            $table = Schema::hasTable('pcb_order_statuses') ? 'pcb_order_statuses' : (Schema::hasTable('pcb_statuses') ? 'pcb_statuses' : 'statuses');
            DB::table($table)->where('id', $id)->delete();
            return response()->json(['success' => true, 'message' => 'Status deleted successfully.']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
