<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class RegisterService
{
    // Handle user registration and workspace setup
    public function register($userdata = [])
    {
        $name = $userdata['name'] ?? null;
        $first_name = $userdata['first_name'] ?? null;
        $last_name = $userdata['last_name'] ?? null;
        $email = $userdata['email'] ?? null;
        $password = $userdata['password'] ?? null;
        $company_name = $userdata['company_name'] ?? null;
        $phone = $userdata['phone'] ?? null;
        $referral_source = $userdata['referral_source'] ?? null;

        // If names/credentials are empty
        if(empty($name)) {
            return [
                'status' => false,
                'message' => 'Name is required.'
            ];
        } else if(empty($email)) {
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

        // If user found
        if(!empty($user)) {
            return [
                'status' => false,
                'message' => 'Account already exists. Please try with another email address.'
            ];
        }

        // Check if invite token is provided
        $inviteToken = $userdata['invite_token'] ?? null;
        $invite = null;
        if ($inviteToken) {
            $invite = DB::table('workspace_invitations')
                ->where('token', $inviteToken)
                ->where('status', 'pending')
                ->first();
            if (!$invite) {
                return [
                    'status' => false,
                    'message' => 'Invalid or expired invitation token.'
                ];
            }
        }

        // Begin Transaction
        DB::beginTransaction();

        try {
            // Create User
            $token = md5(uniqid());
            $password_hash = password_hash($password, PASSWORD_BCRYPT);

            // Generate unique referral code
            do {
                $referralCode = strtoupper(Str::random(8));
            } while (DB::table('users')->where('referral_code', $referralCode)->exists());

            // Create new user with 50 starting credits
            $user_data = [
                'name' => $name,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'email' => $email,
                'password_hash' => $password_hash,
                'token' => $token,
                'referral_code' => $referralCode,
                'available_credits' => 50,
                'status' => 'active',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ];

            if (!empty($phone)) {
                $user_data['phone_number'] = $phone;
            }
            if (!empty($referral_source)) {
                $user_data['referral_source'] = $referral_source;
            }

            $user_id = DB::table('users')->insertGetId($user_data);

            // Create a wallet transaction for the signup bonus if table exists
            if (Schema::hasTable('wallet_transactions')) {
                DB::table('wallet_transactions')->insert([
                    'user_id' => $user_id,
                    'transaction_type' => 'signup_bonus',
                    'credit_type' => 'credit',
                    'credits' => 50,
                    'opening_balance' => 0,
                    'closing_balance' => 50,
                    'remarks' => '50 FREE Credits added on successful registration.',
                    'created_at' => date('Y-m-d H:i:s')
                ]);
            }

            // Log activity with signup details if table exists
            if (Schema::hasTable('activity_logs')) {
                DB::table('activity_logs')->insert([
                    'user_id' => $user_id,
                    'action' => 'register',
                    'module' => 'Authentication',
                    'log_data' => json_encode(['description' => 'New user registered.']),
                    'ip_address' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                    'created_at' => date('Y-m-d H:i:s')
                ]);
            }

            // Queue verification email if table exists
            if (Schema::hasTable('email_queue')) {
                DB::table('email_queue')->insert([
                    'to' => $email,
                    'subject' => 'Verify Your Email Address - Megabyte',
                    'template_path' => 'emails/auth/verify_email',
                    'data' => json_encode([
                        'customer_name' => $name,
                        'token' => $token,
                    ]),
                ]);
            }

            DB::commit();

            // Generate JWT Token for immediate login
            $payload = [
                'user_id' => $user_id,
                'name' => $name,
                'email' => $email,
            ];
            $jwt_token = \Firebase\JWT\JWT::encode($payload, env('JWT_SECRET', '7+18EvAjOct+KzCCwJLpuwEjtXlzevAk4n09YeUkgfA='), 'HS256');

            return [
                'status' => true,
                'message' => 'Registration successful.',
                'data' => [
                    'access_token' => $jwt_token,
                    'user_id' => $user_id,
                    'name' => $name,
                    'email' => $email
                ]
            ];

        } catch (\Throwable $th) {
            DB::rollBack();
            return [
                'status' => false,
                'message' => 'Registration failed: ' . $th->getMessage()
            ];
        }
    }
}
