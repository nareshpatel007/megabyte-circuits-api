<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Firebase\JWT\JWT;

class MobileAuthController extends Controller
{
    public function login(Request $request)
    {
        try {
            $usernameOrEmail = trim($request->input('username') ?: $request->input('email'));
            $password = $request->input('password');

            if (empty($usernameOrEmail) || empty($password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Username or Email and password are required.'
                ], 400);
            }

            // Check admins table first (staff/operations employees)
            $admin = DB::table('admins')
                ->where('email', $usernameOrEmail)
                ->orWhere('username', $usernameOrEmail)
                ->first();

            if ($admin) {
                if (!password_verify($password, $admin->password_hash)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid credentials.'
                    ], 401);
                }

                if (isset($admin->status) && strtolower($admin->status) !== 'active') {
                    return response()->json([
                        'success' => false,
                        'message' => 'Your account is inactive.'
                    ], 403);
                }

                // Get permissions
                $permissions = $this->fetchPermissionsForAdmin($admin);

                $now = date('Y-m-d H:i:s');
                DB::table('admins')->where('id', $admin->id)->update([
                    'last_login_at' => $now,
                    'updated_at' => $now
                ]);

                $secret = env('JWT_SECRET', '7+18EvAjOct+KzCCwJLpuwEjtXlzevAk4n09YeUkgfA=');
                $payload = [
                    'admin_id' => $admin->id,
                    'name'     => $admin->name,
                    'username' => $admin->username ?? strtok($admin->email, '@'),
                    'email'    => $admin->email,
                    'is_admin' => true,
                    'exp'      => time() + (30 * 24 * 60 * 60) // 30 days token
                ];

                $token = JWT::encode($payload, $secret, 'HS256');

                return response()->json([
                    'success' => true,
                    'message' => 'Login successful',
                    'data' => [
                        'token' => $token,
                        'user' => [
                            'id' => $admin->id,
                            'name' => $admin->name,
                            'username' => $admin->username ?? strtok($admin->email, '@'),
                            'email' => $admin->email,
                            'role' => $admin->role ?? 'Employee',
                            'department' => $admin->department ?? 'Production',
                            'employee_code' => 'MCS-' . str_pad($admin->id, 3, '0', STR_PAD_LEFT),
                        ],
                        'permissions' => $permissions
                    ]
                ]);
            }

            // Check users table as fallback
            $user = DB::table('users')
                ->where('email', $usernameOrEmail)
                ->first();

            if ($user) {
                $passwordValid = false;
                if (!empty($user->password_hash)) {
                    $passwordValid = password_verify($password, $user->password_hash);
                } elseif (!empty($user->password)) {
                    $passwordValid = password_verify($password, $user->password) || $password === $user->password;
                }

                if (!$passwordValid) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid credentials.'
                    ], 401);
                }

                $secret = env('JWT_SECRET', '7+18EvAjOct+KzCCwJLpuwEjtXlzevAk4n09YeUkgfA=');
                $payload = [
                    'admin_id' => $user->id,
                    'name'     => $user->name,
                    'email'    => $user->email,
                    'exp'      => time() + (30 * 24 * 60 * 60)
                ];

                $token = JWT::encode($payload, $secret, 'HS256');

                return response()->json([
                    'success' => true,
                    'message' => 'Login successful',
                    'data' => [
                        'token' => $token,
                        'user' => [
                            'id' => $user->id,
                            'name' => $user->name,
                            'username' => strtok($user->email, '@'),
                            'email' => $user->email,
                            'role' => 'Employee',
                            'department' => 'Production',
                            'employee_code' => 'MCS-' . str_pad($user->id, 3, '0', STR_PAD_LEFT),
                        ],
                        'permissions' => ['orders.view', 'inventory.view']
                    ]
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Account not found.'
            ], 404);

        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    public function logout(Request $request)
    {
        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully'
        ]);
    }

    public static function fetchPermissionsForAdmin($admin)
    {
        if (!Schema::hasTable('permissions')) {
            return ['orders.view', 'inventory.view', 'orders.manage', 'inventory.manage'];
        }

        // Ensure default permissions exist in permissions table
        self::ensureDefaultPermissions();

        $isSuperAdmin = false;
        if (!empty($admin->role_id) && Schema::hasTable('roles')) {
            $role = DB::table('roles')->where('id', $admin->role_id)->first();
            if ($role && (strtolower($role->name) === 'super admin' || (int)$role->id === 1)) {
                $isSuperAdmin = true;
            }
        } else {
            if (empty($admin->role_id) || (int)$admin->id === 1) {
                $isSuperAdmin = true;
            }
        }

        if ($isSuperAdmin) {
            return DB::table('permissions')->pluck('slug')->toArray();
        }

        if (!empty($admin->role_id)) {
            $permissions = DB::table('role_permissions')
                ->join('permissions', 'role_permissions.permission_id', '=', 'permissions.id')
                ->where('role_permissions.role_id', $admin->role_id)
                ->pluck('permissions.slug')
                ->toArray();
            return array_values(array_unique($permissions));
        }

        return ['orders.view', 'inventory.view'];
    }

    private static function ensureDefaultPermissions()
    {
        $defaults = [
            ['name' => 'View Orders', 'slug' => 'orders.view', 'module' => 'Orders'],
            ['name' => 'Manage Orders', 'slug' => 'orders.manage', 'module' => 'Orders'],
            ['name' => 'View Inventory', 'slug' => 'inventory.view', 'module' => 'Inventory'],
            ['name' => 'Manage Inventory', 'slug' => 'inventory.manage', 'module' => 'Inventory'],
        ];

        foreach ($defaults as $d) {
            $exists = DB::table('permissions')->where('slug', $d['slug'])->exists();
            if (!$exists) {
                DB::table('permissions')->insert(array_merge($d, [
                    'created_at' => now(),
                    'updated_at' => now()
                ]));
            }
        }
    }
}
