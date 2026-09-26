<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use App\CommonHelper;

class ImpersonationController extends Controller
{
    /**
     * Start impersonation of a client by an authorized admin.
     * Endpoint: POST /api/admin/clients/{id}/impersonate
     */
    public function start(Request $request, $clientId)
    {
        try {
            // 1. Get authenticated admin ID from request (set by VerifyAdminToken)
            $adminId = $request->attributes->get('admin_id');

            if (!$adminId) {
                // Fallback token decoding if attribute not set
                $adminId = $this->getAdminIdFromRequest($request);
            }

            if (!$adminId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized admin authentication.'
                ], 401);
            }

            // 2. Fetch admin user
            $admin = DB::table('admins')->where('id', $adminId)->first();
            if (!$admin) {
                return response()->json([
                    'status' => false,
                    'message' => 'Admin account not found.'
                ], 404);
            }

            // 3. Permission Check: clients.impersonate
            if (!$this->canAdminImpersonate($admin)) {
                return response()->json([
                    'status' => false,
                    'message' => 'You do not have permission to impersonate clients.'
                ], 403);
            }

            // 4. Ensure request is not already inside an impersonation context (prevent multi-hop)
            if ($request->attributes->get('is_impersonating')) {
                return response()->json([
                    'status' => false,
                    'message' => 'Impersonated client sessions cannot initiate another impersonation.'
                ], 403);
            }

            // 5. Fetch target client user
            $client = DB::table('users')->where('id', $clientId)->first();
            if (!$client) {
                return response()->json([
                    'status' => false,
                    'message' => 'Target client not found.'
                ], 404);
            }

            // 6. Check Client Eligibility & Account Status
            if (!empty($client->deleted_at)) {
                return response()->json([
                    'status' => false,
                    'message' => 'This client account has been deleted and cannot be impersonated.'
                ], 400);
            }

            $clientStatus = strtolower(trim((string)($client->status ?? 'active')));
            if ($clientStatus !== 'active') {
                return response()->json([
                    'status' => false,
                    'message' => "This client cannot be impersonated because the account is {$clientStatus}."
                ], 400);
            }

            // 7. Session Timeout Configuration
            $timeoutSeconds = intval(env('CLIENT_IMPERSONATION_TIMEOUT', 3600));
            if ($timeoutSeconds <= 0) {
                $timeoutSeconds = 3600;
            }

            $now = date('Y-m-d H:i:s');
            $expiresAt = date('Y-m-d H:i:s', time() + $timeoutSeconds);
            $reason = $request->input('reason');

            // 8. Create Impersonation Session
            $sessionId = DB::table('impersonation_sessions')->insertGetId([
                'admin_id' => $admin->id,
                'client_id' => $client->id,
                'reason' => $reason ? trim((string)$reason) : null,
                'status' => 'active',
                'started_at' => $now,
                'expires_at' => $expiresAt,
                'ip_address' => $request->ip(),
                'user_agent' => substr((string)$request->userAgent(), 0, 500),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // 9. Generate Random Single-Use Handoff Code
            $handoffCode = 'imp_' . CommonHelper::generate_random_string(32);
            $codeExpiresAt = date('Y-m-d H:i:s', time() + 120); // 2 minutes to exchange

            DB::table('impersonation_codes')->insert([
                'code' => $handoffCode,
                'admin_id' => $admin->id,
                'client_id' => $client->id,
                'impersonation_session_id' => $sessionId,
                'expires_at' => $codeExpiresAt,
                'used' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // 10. Audit Log
            CommonHelper::logActivity(
                $client->id,
                'admin_impersonation_started',
                "Admin '{$admin->name}' (ID: {$admin->id}) initiated impersonation for Client '{$client->name}' (ID: {$client->id}, Email: {$client->email}).",
                $request
            );

            // 11. Construct Handoff Redirect URL
            $clientAppUrl = env('QUOTE_URL', env('NEXT_PUBLIC_QUOTE_URL', 'http://localhost:3001'));
            $clientAppUrl = rtrim($clientAppUrl, '/');
            $redirectUrl = "{$clientAppUrl}/auth/impersonate?code={$handoffCode}";

            return response()->json([
                'status' => true,
                'message' => 'Impersonation session initiated successfully.',
                'data' => [
                    'code' => $handoffCode,
                    'redirect_url' => $redirectUrl,
                    'session_id' => $sessionId,
                    'expires_in' => $timeoutSeconds,
                    'client' => [
                        'id' => $client->id,
                        'name' => $client->name ?? (($client->first_name ?? '') . ' ' . ($client->last_name ?? '')),
                        'email' => $client->email,
                    ]
                ]
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to initiate impersonation: ' . $th->getMessage()
            ], 500);
        }
    }

    /**
     * Exchange single-use handoff code for impersonation JWT session.
     * Endpoint: POST /api/auth/impersonate/exchange
     */
    public function exchange(Request $request)
    {
        try {
            $code = $request->input('code');
            if (empty($code)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Impersonation handoff code is required.'
                ], 400);
            }

            // 1. Fetch and validate code
            $codeRecord = DB::table('impersonation_codes')
                ->where('code', $code)
                ->where('used', false)
                ->where('expires_at', '>=', date('Y-m-d H:i:s'))
                ->first();

            if (!$codeRecord) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid or expired impersonation handoff code.'
                ], 400);
            }

            // 2. Mark code as used immediately
            DB::table('impersonation_codes')
                ->where('id', $codeRecord->id)
                ->update(['used' => true, 'updated_at' => date('Y-m-d H:i:s')]);

            // 3. Fetch impersonation session
            $session = DB::table('impersonation_sessions')
                ->where('id', $codeRecord->impersonation_session_id)
                ->first();

            if (!$session || $session->status !== 'active' || strtotime($session->expires_at) < time()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Associated impersonation session has expired or is no longer active.'
                ], 400);
            }

            // 4. Fetch Client User
            $client = DB::table('users')->where('id', $session->client_id)->first();
            if (!$client) {
                return response()->json([
                    'status' => false,
                    'message' => 'Client account not found.'
                ], 404);
            }

            $clientStatus = strtolower(trim((string)($client->status ?? 'active')));
            if ($clientStatus !== 'active') {
                return response()->json([
                    'status' => false,
                    'message' => "Client account is currently {$clientStatus}."
                ], 400);
            }

            // 5. Fetch Admin User
            $admin = DB::table('admins')->where('id', $session->admin_id)->first();
            $adminName = $admin ? $admin->name : 'Administrator';

            // 6. Generate Impersonation JWT Token
            $clientName = $client->name ?? (($client->first_name ?? '') . ' ' . ($client->last_name ?? ''));
            $payload = [
                'user_id' => $client->id,
                'name' => $clientName,
                'email' => $client->email,
                'is_impersonating' => true,
                'admin_id' => $session->admin_id,
                'admin_name' => $adminName,
                'impersonation_session_id' => $session->id,
                'exp' => strtotime($session->expires_at)
            ];

            $jwtSecret = \App\Services\CredentialService::get('auth', 'JWT_SECRET', 'JWT_SECRET', '7+18EvAjOct+KzCCwJLpuwEjtXlzevAk4n09YeUkgfA=');
            $jwt_token = JWT::encode($payload, $jwtSecret, 'HS256');

            // Store token hash in session for reference
            DB::table('impersonation_sessions')
                ->where('id', $session->id)
                ->update([
                    'token_hash' => md5($jwt_token),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);

            return response()->json([
                'status' => true,
                'message' => 'Impersonation token exchanged successfully.',
                'data' => [
                    'access_token' => $jwt_token,
                    'user' => [
                        'id' => $client->id,
                        'name' => $clientName,
                        'email' => $client->email,
                        'company_name' => $client->company_name ?? '',
                        'status' => $clientStatus,
                    ],
                    'impersonation' => [
                        'active' => true,
                        'admin_id' => $session->admin_id,
                        'admin_name' => $adminName,
                        'session_id' => $session->id,
                        'expires_at' => $session->expires_at
                    ]
                ]
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => false,
                'message' => 'Code exchange failed: ' . $th->getMessage()
            ], 500);
        }
    }

    /**
     * Terminate active client impersonation and return to admin context.
     * Endpoint: POST /api/admin/impersonation/stop
     */
    public function stop(Request $request)
    {
        try {
            $sessionId = $request->input('session_id') ?: $request->attributes->get('impersonation_session_id');

            // If session_id not directly available, check token payload
            if (!$sessionId) {
                $tokenData = $this->decodeTokenFromRequest($request);
                if ($tokenData && isset($tokenData->impersonation_session_id)) {
                    $sessionId = $tokenData->impersonation_session_id;
                }
            }

            if ($sessionId) {
                $session = DB::table('impersonation_sessions')->where('id', $sessionId)->first();
                if ($session && $session->status === 'active') {
                    $now = date('Y-m-d H:i:s');
                    $durationMinutes = round((strtotime($now) - strtotime($session->started_at)) / 60);

                    DB::table('impersonation_sessions')
                        ->where('id', $sessionId)
                        ->update([
                            'status' => 'ended',
                            'ended_at' => $now,
                            'updated_at' => $now
                        ]);

                    // Audit Log
                    CommonHelper::logActivity(
                        $session->client_id,
                        'admin_impersonation_ended',
                        "Admin (ID: {$session->admin_id}) ended impersonation session for Client (ID: {$session->client_id}). Duration: {$durationMinutes} minutes.",
                        $request
                    );
                }
            }

            $adminAppUrl = env('ADMIN_APP_URL', env('NEXT_PUBLIC_ADMIN_URL', 'http://localhost:3000'));
            $adminAppUrl = rtrim($adminAppUrl, '/') . '/clients';

            return response()->json([
                'status' => true,
                'message' => 'Impersonation session ended successfully.',
                'admin_redirect_url' => $adminAppUrl
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to end impersonation: ' . $th->getMessage()
            ], 500);
        }
    }

    /**
     * Permission helper: verify whether admin has permission to impersonate clients.
     */
    private function canAdminImpersonate($admin): bool
    {
        // 1. Super Admin role check
        if (!empty($admin->role_id)) {
            $role = DB::table('roles')->where('id', $admin->role_id)->first();
            if ($role && (strtolower(trim($role->name)) === 'super admin' || (int)$role->id === 1)) {
                return true;
            }
        }

        if ((int)($admin->role_id ?? 0) === 1 || (int)($admin->id ?? 0) === 1) {
            return true;
        }

        // 2. Explicit permission check for clients.impersonate
        if (!empty($admin->role_id) && Schema::hasTable('role_permissions')) {
            $hasPerm = DB::table('role_permissions')
                ->join('permissions', 'role_permissions.permission_id', '=', 'permissions.id')
                ->where('role_permissions.role_id', $admin->role_id)
                ->where('permissions.slug', 'clients.impersonate')
                ->exists();

            if ($hasPerm) {
                return true;
            }
        }

        return false;
    }

    private function getAdminIdFromRequest(Request $request)
    {
        $authHeader = $request->header('Authorization') ?: $request->header('X-Api-Token');
        if (!$authHeader) {
            return null;
        }

        $token = str_replace('Bearer ', '', $authHeader);
        try {
            $secret = \App\Services\CredentialService::get('auth', 'JWT_SECRET', 'JWT_SECRET', '7+18EvAjOct+KzCCwJLpuwEjtXlzevAk4n09YeUkgfA=');
            $decoded = JWT::decode($token, new Key($secret, 'HS256'));
            return $decoded->sub ?? ($decoded->admin_id ?? null);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function decodeTokenFromRequest(Request $request)
    {
        $authHeader = $request->header('Authorization');
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return null;
        }
        $token = str_replace('Bearer ', '', $authHeader);
        try {
            $secret = \App\Services\CredentialService::get('auth', 'JWT_SECRET', 'JWT_SECRET', '7+18EvAjOct+KzCCwJLpuwEjtXlzevAk4n09YeUkgfA=');
            return JWT::decode($token, new Key($secret, 'HS256'));
        } catch (\Throwable $e) {
            return null;
        }
    }
}
