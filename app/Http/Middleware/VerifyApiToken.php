<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyApiToken
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

        // If no token at all, reject
        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 401);
        }

        // Check if token matches expected static API_TOKEN
        if ($token === env('API_TOKEN')) {
            return $next($request);
        }

        // Otherwise, check if it is a valid user JWT token
        try {
            $secret = env('JWT_SECRET', '7+18EvAjOct+KzCCwJLpuwEjtXlzevAk4n09YeUkgfA=');
            $decoded = \Firebase\JWT\JWT::decode($token, new \Firebase\JWT\Key($secret, 'HS256'));
            if ($decoded) {
                return $next($request);
            }
        } catch (\Throwable $e) {
            // Ignore error and fall through to invalid token response
        }

        return response()->json([
            'success' => false,
            'message' => 'Invalid token'
        ], 401);
    }
}