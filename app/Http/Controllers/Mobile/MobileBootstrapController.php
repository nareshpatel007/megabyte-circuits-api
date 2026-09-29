<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MobileBootstrapController extends Controller
{
    public function bootstrap(Request $request)
    {
        try {
            $adminId = $request->attributes->get('admin_id');
            $admin = null;
            if ($adminId) {
                $admin = DB::table('admins')->where('id', $adminId)->first();
            }
            if (!$admin && $adminId) {
                $admin = DB::table('users')->where('id', $adminId)->first();
            }

            $permissions = [];
            if ($admin) {
                $permissions = MobileAuthController::fetchPermissionsForAdmin($admin);
            } else {
                $permissions = ['orders.view', 'inventory.view'];
            }

            $isSuperAdmin = in_array('*', $permissions);

            $hasPerm = function($p) use ($permissions, $isSuperAdmin) {
                if ($isSuperAdmin) return true;
                return in_array($p, $permissions);
            };

            $hasOrders = $hasPerm('orders.view');
            $hasInventory = $hasPerm('inventory.view');
            $hasPayments = $hasPerm('payments.view');
            $hasGerber = $hasPerm('gerber.view') || $hasPerm('orders.view_gerber');
            $hasClients = $hasPerm('clients.view') || $hasPerm('users.manage');
            $hasStaff = $hasPerm('staff.view') || $hasPerm('users.manage');
            $hasRoles = $hasPerm('role.view') || $hasPerm('roles.manage');
            $hasEmailLogs = $hasPerm('email_logs.view');
            $hasSystemHealth = $hasPerm('system_health.view');
            $hasBlogs = $hasPerm('blog.view');
            $hasSettings = $hasPerm('settings.general');

            // Unread notifications count
            $unreadNotificationsCount = 0;
            try {
                if (\Illuminate\Support\Facades\Schema::hasTable('notifications')) {
                    $query = DB::table('notifications');

                    if (\Illuminate\Support\Facades\Schema::hasColumn('notifications', 'recipient_id')) {
                        if ($adminId) {
                            $query->where(function ($q) use ($adminId) {
                                $q->where('recipient_id', $adminId)->orWhereNull('recipient_id');
                            });
                        }
                        if (\Illuminate\Support\Facades\Schema::hasColumn('notifications', 'recipient_type')) {
                            $query->whereIn('recipient_type', ['admin', 'all', 'user']);
                        }
                    } elseif (\Illuminate\Support\Facades\Schema::hasColumn('notifications', 'user_id') && $adminId) {
                        $query->where('user_id', $adminId);
                    }

                    if (\Illuminate\Support\Facades\Schema::hasColumn('notifications', 'is_read')) {
                        $query->where('is_read', 0);
                    } elseif (\Illuminate\Support\Facades\Schema::hasColumn('notifications', 'read')) {
                        $query->where('read', 0);
                    }

                    // Exclude disabled events in notification_settings
                    if (\Illuminate\Support\Facades\Schema::hasTable('notification_settings')) {
                        $disabledEvents = DB::table('notification_settings')->where('admin_enabled', false)->pluck('event_key')->toArray();
                        if (!empty($disabledEvents)) {
                            $query->whereNotIn('event_key', $disabledEvents);
                        }
                    }

                    // Filter by employee permissions
                    if (!$isSuperAdmin) {
                        $disallowed = [];
                        if (!in_array('orders.view', $permissions)) $disallowed[] = 'order';
                        if (!in_array('inventory.view', $permissions)) $disallowed[] = 'inventory';
                        if (!in_array('payments.view', $permissions)) $disallowed[] = 'payment';
                        if (!in_array('gerber.view', $permissions) && !in_array('orders.view_gerber', $permissions)) $disallowed[] = 'gerber';
                        if (!in_array('staff.view', $permissions) && !in_array('users.manage', $permissions)) $disallowed[] = 'staff';
                        if (!empty($disallowed)) {
                            $query->whereNotIn('category', $disallowed);
                        }
                    }

                    $unreadNotificationsCount = $query->count();
                }
            } catch (\Throwable $notifEx) {
                $unreadNotificationsCount = 0;
            }

            return response()->json([
                'success' => true,
                'message' => 'Bootstrap data loaded successfully',
                'data' => [
                    'user' => [
                        'id' => $admin->id ?? 1,
                        'name' => $admin->name ?? 'Jignesh Dave',
                        'email' => $admin->email ?? 'jignesh@megabyte.local',
                        'username' => $admin->username ?? 'jignesh',
                        'role' => $admin->role ?? 'Production Operator',
                        'department' => $admin->department ?? 'PCB Production',
                        'employee_code' => 'MCS-' . str_pad($admin->id ?? 42, 3, '0', STR_PAD_LEFT),
                    ],
                    'permissions' => array_values(array_unique($permissions)),
                    'modules' => [
                        'dashboard' => true,
                        'orders' => $hasOrders,
                        'inventory' => $hasInventory,
                        'payments' => $hasPayments,
                        'gerber' => $hasGerber,
                        'clients' => $hasClients,
                        'staff' => $hasStaff,
                        'roles' => $hasRoles,
                        'email_logs' => $hasEmailLogs,
                        'system_health' => $hasSystemHealth,
                        'blogs' => $hasBlogs,
                        'settings' => $hasSettings,
                        'profile' => true,
                    ],
                    'notification_count' => $unreadNotificationsCount
                ]
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => $th->getMessage()
            ], 500);
        }
    }
}
