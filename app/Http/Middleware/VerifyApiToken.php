<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\DB;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;

class VerifyApiToken
{
    public function handle(Request $request, Closure $next)
    {
        $authHeader = $request->header('Authorization');
        $xApiToken = $request->header('X-Api-Token');

        $jwtToken = null;
        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            $jwtToken = str_replace('Bearer ', '', $authHeader);
        }

        // If a JWT user token is present, we MUST validate user token & database status first
        if (!empty($jwtToken)) {
            $userCheck = $this->verifyUserTokenAndStatus($jwtToken, $request);
            if ($userCheck !== null) {
                return $userCheck;
            }
            return $next($request);
        }

        // If no JWT token, check for static API token (server-to-server fallback)
        if ($xApiToken) {
            $apiToken = \App\Services\CredentialService::get('auth', 'API_TOKEN', 'API_TOKEN');
            if ($apiToken && $xApiToken === $apiToken) {
                return $next($request);
            }
        }

        return response()->json([
            'status' => false,
            'success' => false,
            'code' => 'AUTH_REQUIRED',
            'message' => 'Unauthorized access'
        ], 401);
    }

    private function verifyUserTokenAndStatus(string $token, Request $request): ?Response
    {
        try {
            $secret = \App\Services\CredentialService::get('auth', 'JWT_SECRET', 'JWT_SECRET', '7+18EvAjOct+KzCCwJLpuwEjtXlzevAk4n09YeUkgfA=');
            $decoded = JWT::decode($token, new Key($secret, 'HS256'));

            if (!$decoded || empty($decoded->user_id)) {
                return response()->json([
                    'status' => false,
                    'success' => false,
                    'code' => 'TOKEN_INVALID',
                    'message' => 'Invalid authentication token.'
                ], 401);
            }

            // Database status check - ALWAYS backend source of truth
            $user = DB::table('users')->where('id', $decoded->user_id)->first();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'success' => false,
                    'code' => 'ACCOUNT_NOT_FOUND',
                    'message' => 'User account not found.'
                ], 401);
            }

            $status = strtolower(trim((string)($user->status ?? 'active')));

            if ($status === 'suspended') {
                return response()->json([
                    'status' => false,
                    'success' => false,
                    'code' => 'ACCOUNT_SUSPENDED',
                    'message' => 'Your account has been suspended by the administrator.'
                ], 403);
            }

            if ($status === 'blocked') {
                return response()->json([
                    'status' => false,
                    'success' => false,
                    'code' => 'ACCOUNT_BLOCKED',
                    'message' => 'Your account has been blocked. Please contact support.'
                ], 403);
            }

            if ($status === 'inactive') {
                return response()->json([
                    'status' => false,
                    'success' => false,
                    'code' => 'ACCOUNT_INACTIVE',
                    'message' => 'Your account is currently inactive.'
                ], 403);
            }

            if ($status === 'pending') {
                return response()->json([
                    'status' => false,
                    'success' => false,
                    'code' => 'ACCOUNT_PENDING',
                    'message' => 'Your account is awaiting approval.'
                ], 403);
            }

            if ($status === 'deactivated' || $status === 'deleted') {
                return response()->json([
                    'status' => false,
                    'success' => false,
                    'code' => 'ACCOUNT_DEACTIVATED',
                    'message' => 'Your account is no longer active.'
                ], 403);
            }

            // Attach user model/object to request attributes
            $request->attributes->set('authenticated_user', $user);
            return null; // Token and account status valid

        } catch (ExpiredException $e) {
            return response()->json([
                'status' => false,
                'success' => false,
                'code' => 'TOKEN_EXPIRED',
                'message' => 'Your session has expired. Please log in again.'
            ], 401);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => false,
                'success' => false,
                'code' => 'TOKEN_INVALID',
                'message' => 'Invalid authentication token.'
            ], 401);
        }
    }
}