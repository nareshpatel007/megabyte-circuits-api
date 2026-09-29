<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class AdminProfileController extends Controller
{
    private function getAuthenticatedAdminId(Request $request)
    {
        return $request->attributes->get('admin_id');
    }

    private function formatAdminData($admin)
    {
        if (!$admin) {
            return null;
        }

        $avatarUrl = null;
        if (!empty($admin->profile_picture)) {
            $raw = $admin->profile_picture;
            if (str_starts_with($raw, 'http://') || str_starts_with($raw, 'https://')) {
                $avatarUrl = $raw;
            } else {
                $clean = ltrim($raw, '/');
                if (!str_starts_with($clean, 'storage/')) {
                    $clean = 'storage/' . $clean;
                }
                $avatarUrl = asset($clean);
            }
            // Append cache busting parameter if timestamp exists
            $updatedTs = !empty($admin->updated_at) ? strtotime($admin->updated_at) : time();
            $avatarUrl .= (str_contains($avatarUrl, '?') ? '&' : '?') . 'v=' . $updatedTs;
        }

        $roleName = $admin->role_name ?? null;
        if (empty($roleName) && !empty($admin->role_id) && Schema::hasTable('roles')) {
            $roleObj = DB::table('roles')->where('id', $admin->role_id)->first();
            if ($roleObj) {
                $roleName = $roleObj->name;
            }
        }
        if (empty($roleName)) {
            $roleName = ((int)$admin->id === 1 || (int)($admin->role_id ?? 0) === 1) ? 'Super Admin' : 'Administrator';
        }

        return [
            'id' => (int) $admin->id,
            'admin_id' => (int) $admin->id,
            'name' => $admin->name ?? '',
            'username' => $admin->username ?? (isset($admin->email) ? strtok($admin->email, '@') : ''),
            'email' => $admin->email ?? '',
            'mobile' => $admin->mobile ?? $admin->phone ?? '',
            'phone' => $admin->mobile ?? $admin->phone ?? '',
            'profile_picture' => $avatarUrl,
            'avatar_url' => $avatarUrl,
            'role' => $roleName,
            'role_id' => $admin->role_id ?? null,
            'status' => ucfirst($admin->status ?? 'active'),
            'last_login_at' => !empty($admin->last_login_at) ? date('d M Y, h:i A', strtotime($admin->last_login_at)) : null,
            'created_at' => !empty($admin->created_at) ? date('d M Y', strtotime($admin->created_at)) : date('d M Y'),
        ];
    }

    public function show(Request $request)
    {
        try {
            $adminId = $this->getAuthenticatedAdminId($request);
            if (!$adminId) {
                return response()->json(['status' => false, 'message' => 'Unauthorized access.'], 401);
            }

            $query = DB::table('admins')->where('admins.id', $adminId);
            if (Schema::hasTable('roles')) {
                $query->leftJoin('roles', 'admins.role_id', '=', 'roles.id')
                      ->select('admins.*', 'roles.name as role_name');
            } else {
                $query->select('admins.*');
            }

            $admin = $query->first();

            if (!$admin) {
                return response()->json(['status' => false, 'message' => 'Admin account not found.'], 404);
            }

            return response()->json([
                'status' => true,
                'data' => $this->formatAdminData($admin)
            ]);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    public function update(Request $request)
    {
        try {
            $adminId = $this->getAuthenticatedAdminId($request);
            if (!$adminId) {
                return response()->json(['status' => false, 'message' => 'Unauthorized access.'], 401);
            }

            $admin = DB::table('admins')->where('id', $adminId)->first();
            if (!$admin) {
                return response()->json(['status' => false, 'message' => 'Admin account not found.'], 404);
            }

            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'email' => 'required|email|max:255',
                'username' => 'nullable|string|max:100',
                'mobile' => 'nullable|string|max:45',
                'phone' => 'nullable|string|max:45',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $email = trim($request->input('email'));
            $username = trim($request->input('username', ''));
            $mobile = trim($request->input('mobile', $request->input('phone', '')));

            // Check email uniqueness among admins excluding self
            $emailExists = DB::table('admins')
                ->where('email', $email)
                ->where('id', '!=', $adminId)
                ->exists();

            if ($emailExists) {
                return response()->json([
                    'status' => false,
                    'message' => 'The email address is already in use by another admin.',
                    'errors' => ['email' => ['The email address is already taken.']]
                ], 422);
            }

            // Check username uniqueness if provided
            if ($username !== '') {
                $usernameExists = DB::table('admins')
                    ->where('username', $username)
                    ->where('id', '!=', $adminId)
                    ->exists();

                if ($usernameExists) {
                    return response()->json([
                        'status' => false,
                        'message' => 'The username is already in use.',
                        'errors' => ['username' => ['The username is already taken.']]
                    ], 422);
                }
            }

            $now = date('Y-m-d H:i:s');
            $updateData = [
                'name' => trim($request->input('name')),
                'email' => $email,
                'updated_at' => $now,
            ];

            if ($username !== '' && Schema::hasColumn('admins', 'username')) {
                $updateData['username'] = $username;
            }

            if (Schema::hasColumn('admins', 'mobile')) {
                $updateData['mobile'] = $mobile !== '' ? $mobile : null;
            }

            DB::table('admins')->where('id', $adminId)->update($updateData);

            $updatedAdmin = DB::table('admins')
                ->leftJoin('roles', 'admins.role_id', '=', 'roles.id')
                ->where('admins.id', $adminId)
                ->select('admins.*', 'roles.name as role_name')
                ->first();

            return response()->json([
                'status' => true,
                'message' => 'Profile updated successfully.',
                'data' => $this->formatAdminData($updatedAdmin)
            ]);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    public function uploadPicture(Request $request)
    {
        try {
            $adminId = $this->getAuthenticatedAdminId($request);
            if (!$adminId) {
                return response()->json(['status' => false, 'message' => 'Unauthorized access.'], 401);
            }

            $admin = DB::table('admins')->where('id', $adminId)->first();
            if (!$admin) {
                return response()->json(['status' => false, 'message' => 'Admin account not found.'], 404);
            }

            $file = $request->file('picture')
                ?: $request->file('profile_picture')
                ?: $request->file('avatar')
                ?: $request->file('image')
                ?: $request->file('file');

            if (!$file || !$file->isValid()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Please provide a valid image file.'
                ], 400);
            }

            $validator = Validator::make(['file' => $file], [
                'file' => 'required|image|mimes:jpeg,jpg,png,webp|max:5120'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid image file. Supported formats: JPG, PNG, WEBP (Max: 5MB).',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Remove old picture file if present
            if (!empty($admin->profile_picture)) {
                $oldPath = $admin->profile_picture;
                if (!str_starts_with($oldPath, 'http://') && !str_starts_with($oldPath, 'https://')) {
                    $cleanOld = str_replace('/storage/', '', ltrim($oldPath, '/'));
                    if (Storage::disk('public')->exists($cleanOld)) {
                        Storage::disk('public')->delete($cleanOld);
                    }
                }
            }

            $ext = strtolower($file->getClientOriginalExtension()) ?: 'jpg';
            $filename = 'admin_avatar_' . $adminId . '_' . time() . '_' . \Illuminate\Support\Str::random(6) . '.' . $ext;
            $savedPath = $file->storeAs('avatars', $filename, 'public');

            $now = date('Y-m-d H:i:s');
            DB::table('admins')->where('id', $adminId)->update([
                'profile_picture' => 'avatars/' . $filename,
                'updated_at' => $now
            ]);

            $updatedAdmin = DB::table('admins')
                ->leftJoin('roles', 'admins.role_id', '=', 'roles.id')
                ->where('admins.id', $adminId)
                ->select('admins.*', 'roles.name as role_name')
                ->first();

            $formatted = $this->formatAdminData($updatedAdmin);
            return response()->json([
                'status' => true,
                'message' => 'Profile picture updated successfully.',
                'avatar_url' => $formatted['avatar_url'] ?? null,
                'profile_picture' => $formatted['profile_picture'] ?? null,
                'data' => $formatted
            ]);

        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    public function deletePicture(Request $request)
    {
        try {
            $adminId = $this->getAuthenticatedAdminId($request);
            if (!$adminId) {
                return response()->json(['status' => false, 'message' => 'Unauthorized access.'], 401);
            }

            $admin = DB::table('admins')->where('id', $adminId)->first();
            if (!$admin) {
                return response()->json(['status' => false, 'message' => 'Admin account not found.'], 404);
            }

            if (!empty($admin->profile_picture)) {
                $oldPath = $admin->profile_picture;
                if (!str_starts_with($oldPath, 'http://') && !str_starts_with($oldPath, 'https://')) {
                    $cleanOld = str_replace('/storage/', '', ltrim($oldPath, '/'));
                    if (Storage::disk('public')->exists($cleanOld)) {
                        Storage::disk('public')->delete($cleanOld);
                    }
                }
            }

            $now = date('Y-m-d H:i:s');
            DB::table('admins')->where('id', $adminId)->update([
                'profile_picture' => null,
                'updated_at' => $now
            ]);

            $updatedAdmin = DB::table('admins')
                ->leftJoin('roles', 'admins.role_id', '=', 'roles.id')
                ->where('admins.id', $adminId)
                ->select('admins.*', 'roles.name as role_name')
                ->first();

            $formatted = $this->formatAdminData($updatedAdmin);
            return response()->json([
                'status' => true,
                'message' => 'Profile picture removed successfully.',
                'avatar_url' => null,
                'profile_picture' => null,
                'data' => $formatted
            ]);

        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    public function changePassword(Request $request)
    {
        try {
            $adminId = $this->getAuthenticatedAdminId($request);
            if (!$adminId) {
                return response()->json(['status' => false, 'message' => 'Unauthorized access.'], 401);
            }

            $admin = DB::table('admins')->where('id', $adminId)->first();
            if (!$admin) {
                return response()->json(['status' => false, 'message' => 'Admin account not found.'], 404);
            }

            $validator = Validator::make($request->all(), [
                'current_password' => 'required|string',
                'new_password' => 'required|string|min:6',
                'confirm_password' => 'nullable|string',
                'new_password_confirmation' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $currentPassword = $request->input('current_password');
            $newPassword = $request->input('new_password');
            $confirmPassword = $request->input('confirm_password') ?? $request->input('new_password_confirmation');

            if ($confirmPassword !== null && $newPassword !== $confirmPassword) {
                return response()->json([
                    'status' => false,
                    'message' => 'New password and password confirmation do not match.',
                    'errors' => ['confirm_password' => ['New password and confirmation do not match.']]
                ], 422);
            }

            // Verify current password against database password_hash
            if (!password_verify($currentPassword, $admin->password_hash)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Current password is incorrect.',
                    'errors' => ['current_password' => ['Current password is incorrect.']]
                ], 422);
            }

            $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
            $now = date('Y-m-d H:i:s');

            DB::table('admins')->where('id', $adminId)->update([
                'password_hash' => $newHash,
                'updated_at' => $now
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Password changed successfully.'
            ]);

        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }
}
