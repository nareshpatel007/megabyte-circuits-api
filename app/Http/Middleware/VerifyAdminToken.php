<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\DB;

class VerifyAdminToken
{
    public function handle(Request $request, Closure $next)
    {
        // Check for custom X-Api-Token header first
        $token = $request->header('X-Api-Token');

        if (!$token) {
            $authHeader = $request->header('Authorization');
            if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
                $token = str_replace('Bearer ', '', $authHeader);
            }
        }

        if (!$token) {
            $token = $request->query('token') ?: $request->query('admin_token') ?: $request->query('api_token');
        }

        // If no token at all, reject
        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 401);
        }

        // Check if token matches expected static API_TOKEN
        $apiToken = \App\Services\CredentialService::get('auth', 'API_TOKEN', 'API_TOKEN');
        $isStaticAuth = ($apiToken && $token === $apiToken);

        // Extract and decode JWT from Authorization header or token if present
        $authHeader = $request->header('Authorization');
        $jwtCandidate = null;
        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            $jwtCandidate = trim(substr($authHeader, 7));
        }
        if (!$jwtCandidate && $token && $token !== $apiToken) {
            $jwtCandidate = $token;
        }

        if ($jwtCandidate && $jwtCandidate !== $apiToken) {
            try {
                $secret = \App\Services\CredentialService::get('auth', 'JWT_SECRET', 'JWT_SECRET', '7+18EvAjOct+KzCCwJLpuwEjtXlzevAk4n09YeUkgfA=');
                $decoded = JWT::decode($jwtCandidate, new Key($secret, 'HS256'));
                if ($decoded) {
                    $adminId = $decoded->sub ?? ($decoded->admin_id ?? null);
                    if ($adminId) {
                        $request->attributes->set('admin_id', $adminId);
                    }
                    if (!empty($decoded->name)) {
                        $request->attributes->set('admin_name', $decoded->name);
                    }
                    if (!empty($decoded->username)) {
                        $request->attributes->set('admin_username', $decoded->username);
                    }
                    return $next($request);
                }
            } catch (\Throwable $e) {
                // If static auth is valid, let it pass despite JWT error
            }
        }

        if ($isStaticAuth) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => 'Invalid token'
        ], 401);
    }
}
