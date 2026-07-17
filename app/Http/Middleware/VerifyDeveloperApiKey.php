<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class VerifyDeveloperApiKey
{
    public function handle(Request $request, Closure $next)
    {
        $authHeader = $request->header('Authorization');
        
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return response()->json([
                'status' => 'error',
                'code' => 401,
                'error' => 'unauthorized',
                'message' => 'Missing or malformed Authorization header.'
            ], 401);
        }

        $apiKey = str_replace('Bearer ', '', $authHeader);

        // Find the user by api_key in users table
        $user = DB::table('users')->where('api_key', $apiKey)->first();

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'code' => 403,
                'error' => 'forbidden',
                'message' => 'API key is invalid, revoked, or belongs to an inactive account.'
            ], 403);
        }

        if (isset($user->status) && $user->status !== 'active') {
            return response()->json([
                'status' => 'error',
                'code' => 403,
                'error' => 'forbidden',
                'message' => 'API key is invalid, revoked, or belongs to an inactive account.'
            ], 403);
        }

        // Attach user to request attributes
        $request->attributes->set('developer_user', $user);

        return $next($request);
    }
}
