<?php

namespace App\Services;

use DB;
use Firebase\JWT\JWT;
use Exception;

class AuthService
{
    // Handle user login
    public function login($email, $password, $platform = 'web')
    {
        // If email is empty
        if(empty($email)) {
            return [
                'status' => false,
                'message' => 'Email address is required.'
            ];
        } else if(empty($password)) {
            return [
                'status' => false,
                'message' => 'Password is required.'
            ];
        }

        // Check if user exists
        $user = DB::table('users')->where('email', $email)->first();

        // If user not found
        if(empty($user)) {
            return [
                'status' => false,
                'message' => 'User not found. Please register first.'
            ];
        }

        // Check status
        if(isset($user->status) && $user->status !== 'active') {
            return [
                'status' => false,
                'message' => 'Your account is inactive.'
            ];
        }

        // Verify password hash
        $passwordField = isset($user->password_hash) ? $user->password_hash : ($user->password ?? null);
        if (!$passwordField) {
            return [
                'status' => false,
                'message' => 'Credentials not set.'
            ];
        }

        $password_correct = false;
        if (password_verify($password, $passwordField) || md5($password) === $passwordField) {
            $password_correct = true;
        }

        if($password_correct) {
            // Update last login
            DB::table('users')->where('id', $user->id)->update([
                'last_login_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            // Log activity
            \App\CommonHelper::logActivity($user->id, 'login', 'User logged in successfully.', request());

            // Generate JWT Token
            $payload = [
                'user_id' => $user->id,
                'name' => $user->name ?? (($user->first_name ?? '') . ' ' . ($user->last_name ?? '')),
                'email' => $user->email,
            ];

            // Generate JWT Token
            $jwt_token = JWT::encode($payload, env('JWT_SECRET', '7+18EvAjOct+KzCCwJLpuwEjtXlzevAk4n09YeUkgfA='), 'HS256');

            return [
                'status' => true,
                'data' => [
                    'access_token' => $jwt_token,
                    'user_id' => $user->id,
                    'name' => $user->name ?? (($user->first_name ?? '') . ' ' . ($user->last_name ?? '')),
                    'email' => $user->email,
                ]
            ];
        } else {
            // Return response
            return [
                'status' => false,
                'message' => 'Password is incorrect.'
            ];
        }
    }
}
