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
        if ($permission === 'gerber.view' && (in_array('orders.view_gerber', $permissions) || in_array('orders.view', $permissions))) {
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

            $query = DB::table('payment_transactions')
                ->leftJoin('users', 'payment_transactions.user_id', '=', 'users.id')
                ->select([
                    'payment_transactions.id',
                    'payment_transactions.transaction_number',
                    'payment_transactions.amount',
                    'payment_transactions.currency',
                    'payment_transactions.status',
                    'payment_transactions.payment_method',
                    'payment_transactions.created_at',
                    'users.name as client_name',
                    'users.email as client_email',
                ]);

            if ($search = $request->input('search')) {
                $query->where(function ($q) use ($search) {
                    $q->where('payment_transactions.transaction_number', 'like', "%{$search}%")
                        ->orWhere('users.name', 'like', "%{$search}%")
                        ->orWhere('users.email', 'like', "%{$search}%");
                });
            }

            if ($status = $request->input('status')) {
                $query->where('payment_transactions.status', $status);
            }

            $perPage = (int) $request->input('per_page', 20);
            $payments = $query->orderBy('payment_transactions.id', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $payments->items(),
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
        if (!self::hasPermission($request, 'clients.view')) {
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
        if (!self::hasPermission($request, 'role.view')) {
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
}
