<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
            // PCB Orders Total Revenue
            $totalRevenue = Schema::hasTable('pcb_orders') ? DB::table('pcb_orders')->sum('order_value') : 0;
            $totalOrders  = Schema::hasTable('pcb_orders') ? DB::table('pcb_orders')->count() : 0;
            $pendingOrders = Schema::hasTable('pcb_orders') ? DB::table('pcb_orders')->where('status', 'like', '%pending%')->orWhere('status', 'like', '%move%')->count() : 0;

            // Manufacturing & Users
            $totalUsers   = Schema::hasTable('pcb_users') ? DB::table('pcb_users')->count() : 0;
            $mfgRuns      = Schema::hasTable('pcb_orders') ? DB::table('pcb_orders')->whereNotIn('status', ['Cancelled', 'Completed', 'Shipped'])->count() : 0;

            // Status Breakdown Distribution
            $statusCounts = Schema::hasTable('pcb_orders') 
                ? DB::table('pcb_orders')->select('status', DB::raw('count(*) as count'))->groupBy('status')->pluck('count', 'status') 
                : [];

            return response()->json([
                'status' => true,
                'stats'  => [
                    'total_revenue'      => (float)$totalRevenue,
                    'total_orders'       => $totalOrders,
                    'pending_orders'     => $pendingOrders,
                    'active_mfg_runs'    => $mfgRuns,
                    'total_users'        => $totalUsers,
                    'status_counts'      => $statusCounts,
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
            $query = DB::table('users')->orderBy('created_at', 'desc');

            if (!empty($search)) {
                $query->where(function($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('first_name', 'like', "%{$search}%")
                      ->orWhere('last_name', 'like', "%{$search}%");
                });
            }

            $users = $query->paginate(15);

            return response()->json([
                'status' => true,
                'users' => $users
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

    // Change user status (active/inactive)
    public function toggleUserStatus(Request $request, $id)
    {
        try {
            $status = $request->input('status');
            if (!in_array($status, ['active', 'inactive'])) {
                return response()->json(['status' => false, 'message' => 'Invalid status value.'], 400);
            }

            $user = DB::table('users')->where('id', $id)->first();
            if (!$user) {
                return response()->json(['status' => false, 'message' => 'User not found.'], 404);
            }

            DB::table('users')->where('id', $id)->update([
                'status' => $status,
                'updated_at' => now()
            ]);

            return response()->json([
                'status' => true,
                'message' => "User status updated to {$status}."
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

    // Payments lists
    public function payments(Request $request)
    {
        try {
            $payments = DB::table('payments')
                ->leftJoin('users', 'payments.user_id', '=', 'users.id')
                ->leftJoin('credit_packs', 'payments.credit_pack_id', '=', 'credit_packs.id')
                ->select('payments.*', 'users.name as user_name', 'users.email as user_email', 'credit_packs.name as pack_name')
                ->orderBy('payments.created_at', 'desc')
                ->paginate(20);

            return response()->json([
                'status' => true,
                'payments' => $payments
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

    public function createStaff(Request $request)
    {
        try {
            $name = $request->input('name');
            $username = $request->input('username');
            $email = $request->input('email');
            $password = $request->input('password');
            $roleId = $request->input('role_id');
            $status = $request->input('status', 'active');

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

            $id = DB::table('admins')->insertGetId([
                'name' => $name,
                'username' => $username ?: strtok($email, '@'),
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                'role_id' => $roleId ?: null,
                'status' => $status,
                'created_at' => now(),
                'updated_at' => now()
            ]);

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
            }

            return response()->json([
                'status' => true,
                'data' => $roles
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

            $slug = \Illuminate\Support\Str::slug($name);

            $roleId = DB::table('roles')->insertGetId([
                'name' => $name,
                'slug' => $slug,
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
                    'slug' => \Illuminate\Support\Str::slug($request->input('name')),
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
}
