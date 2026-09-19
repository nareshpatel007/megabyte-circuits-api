<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MobileProfileController extends Controller
{
    public function profile(Request $request)
    {
        try {
            $adminId = $request->attributes->get('admin_id');
            $admin = DB::table('admins')->where('id', $adminId)->first() ?: DB::table('users')->where('id', $adminId)->first();

            if (!$admin) {
                return response()->json([
                    'success' => false,
                    'message' => 'User profile not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => (string)$admin->id,
                    'name' => $admin->name ?? 'Jignesh Dave',
                    'email' => $admin->email ?? 'jignesh@megabyte.local',
                    'mobile' => $admin->mobile ?? ($admin->phone_number ?? '+91 98765 43210'),
                    'role' => $admin->role ?? 'Production Operator',
                    'department' => $admin->department ?? 'PCB Production',
                    'employeeCode' => 'MCS-' . str_pad($admin->id, 3, '0', STR_PAD_LEFT),
                    'shift' => 'Shift A · 08:00–18:00',
                    'location' => 'Production Floor, Bay 3'
                ]
            ]);

        } catch (\Throwable $th) {
            return response()->json(['success' => false, 'message' => $th->getMessage()], 500);
        }
    }

    public function updateProfile(Request $request)
    {
        try {
            $adminId = $request->attributes->get('admin_id');
            $name = trim($request->input('name'));
            $email = trim($request->input('email'));
            $mobile = trim($request->input('mobile'));

            if (empty($name) || empty($email)) {
                return response()->json(['success' => false, 'message' => 'Name and email are required'], 400);
            }

            $admin = DB::table('admins')->where('id', $adminId)->first();
            if ($admin) {
                $cols = Schema::getColumnListing('admins');
                $update = ['name' => $name, 'email' => $email, 'updated_at' => date('Y-m-d H:i:s')];
                if (in_array('mobile', $cols)) $update['mobile'] = $mobile;
                DB::table('admins')->where('id', $adminId)->update($update);
            } else {
                $cols = Schema::getColumnListing('users');
                $update = ['name' => $name, 'email' => $email, 'updated_at' => date('Y-m-d H:i:s')];
                if (in_array('phone_number', $cols)) $update['phone_number'] = $mobile;
                DB::table('users')->where('id', $adminId)->update($update);
            }

            return response()->json([
                'success' => true,
                'message' => 'Profile updated successfully'
            ]);

        } catch (\Throwable $th) {
            return response()->json(['success' => false, 'message' => $th->getMessage()], 500);
        }
    }

    public function changePassword(Request $request)
    {
        try {
            $adminId = $request->attributes->get('admin_id');
            $currentPassword = $request->input('current_password');
            $newPassword = $request->input('new_password');

            if (empty($currentPassword) || empty($newPassword)) {
                return response()->json(['success' => false, 'message' => 'Current and new password are required.'], 400);
            }

            if (strlen($newPassword) < 4) {
                return response()->json(['success' => false, 'message' => 'New password must be at least 4 characters.'], 422);
            }

            $admin = DB::table('admins')->where('id', $adminId)->first();
            if ($admin) {
                if (!password_verify($currentPassword, $admin->password_hash)) {
                    return response()->json(['success' => false, 'message' => 'Current password is incorrect.'], 400);
                }
                DB::table('admins')->where('id', $adminId)->update([
                    'password_hash' => password_hash($newPassword, PASSWORD_BCRYPT),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            } else {
                $user = DB::table('users')->where('id', $adminId)->first();
                if (!$user) return response()->json(['success' => false, 'message' => 'User not found.'], 404);
                
                $passValid = password_verify($currentPassword, $user->password_hash ?? '') || password_verify($currentPassword, $user->password ?? '') || $currentPassword === $user->password;
                if (!$passValid) {
                    return response()->json(['success' => false, 'message' => 'Current password is incorrect.'], 400);
                }

                DB::table('users')->where('id', $adminId)->update([
                    'password_hash' => password_hash($newPassword, PASSWORD_BCRYPT),
                    'password' => password_hash($newPassword, PASSWORD_BCRYPT),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Password updated successfully'
            ]);

        } catch (\Throwable $th) {
            return response()->json(['success' => false, 'message' => $th->getMessage()], 500);
        }
    }
}
