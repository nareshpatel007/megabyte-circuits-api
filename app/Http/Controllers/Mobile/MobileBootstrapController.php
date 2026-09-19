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

            $hasOrdersPermission = in_array('orders.view', $permissions);
            $hasInventoryPermission = in_array('inventory.view', $permissions);

            // Unread notifications count
            $unreadNotificationsCount = 0;
            if (DB::getSchemaBuilder()->hasTable('notifications')) {
                $unreadNotificationsCount = DB::table('notifications')
                    ->where('user_id', $adminId)
                    ->where('read', 0)
                    ->count();
            } else {
                $unreadNotificationsCount = 3;
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
                    'permissions' => $permissions,
                    'modules' => [
                        'orders' => $hasOrdersPermission,
                        'inventory' => $hasInventoryPermission,
                        'profile' => true,
                    ],
                    'company' => [
                        'name' => "Megabyte's Circuit Systems",
                        'short_name' => 'Megabyte Circuits',
                        'logo' => null,
                    ],
                    'shift' => [
                        'name' => 'Shift A',
                        'start' => '08:00',
                        'end' => '18:00',
                        'status' => 'active',
                        'display' => 'Shift A · Live floor'
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
