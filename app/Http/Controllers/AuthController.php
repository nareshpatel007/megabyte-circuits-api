<?php

namespace App\Http\Controllers;

use ZipArchive;
use Illuminate\Http\Request;
use App\CommonHelper;
use App\MailHelper;
use ImageKit\ImageKit;
use Illuminate\Support\Facades\DB;
use Firebase\JWT\JWT;

class AuthController extends Controller
{
    // Login
    public function login(Request $request, \App\Services\AuthService $authService)
    {
        try {
            $input = $request->all();
            if (empty($input) && $request->getContent()) {
                $input = json_decode($request->getContent(), true) ?? [];
            }

            // Get requested data
            $email = $input['email'] ?? $request->input('email');
            $password = $input['password'] ?? $request->input('password');
            $platform = $input['platform'] ?? $request->input('platform', 'web');

            // Call login service
            $result = $authService->login($email, $password, $platform);

            // Return response
            return response()->json($result);
        } catch (\Throwable $th) {
            // Return error response
            return response()->json([
                'status' => false,
                'message' => 'Error occurred during login: ' . $th->getMessage()
            ]);
        }
    }

    // Logout
    public function logout(Request $request)
    {
        try {
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return response()->json([
                'status' => true,
                'message' => 'Logged out successfully'
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => true,
                'message' => 'Logged out successfully'
            ]);
        }
    }

    // Register
    public function register(Request $request, \App\Services\RegisterService $registerService)
    {
        try {
            $input = $request->all();
            if (empty($input) && $request->getContent()) {
                $input = json_decode($request->getContent(), true) ?? [];
            }

            // Get requested data
            $name = $input['name'] ?? $request->input('name') ?? (($input['first_name'] ?? $request->input('first_name') ?? '') . ' ' . ($input['last_name'] ?? $request->input('last_name') ?? ''));
            $email = $input['email'] ?? $request->input('email');
            $password = $input['password'] ?? $request->input('password');
            $company_name = $input['company_name'] ?? $request->input('company_name');
            $phone = $input['phone'] ?? $request->input('phone');
            $referral_source = $input['referral_source'] ?? $request->input('referral_source');

            $firstName = $input['first_name'] ?? $request->input('first_name');
            $lastName = $input['last_name'] ?? $request->input('last_name');
            if (empty($firstName) && empty($lastName)) {
                $nameParts = explode(' ', trim((string)$name));
                $firstName = $nameParts[0] ?? '';
                $lastName = isset($nameParts[1]) ? implode(' ', array_slice($nameParts, 1)) : '';
            }

            // Call register service
            $result = $registerService->register([
                'username' => trim((string)($input['username'] ?? $request->input('username') ?? '')),
                'name' => trim((string)$name),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'password' => $password,
                'company_name' => $company_name,
                'country' => $input['country'] ?? $request->input('country'),
                'gst_number' => $input['gst_number'] ?? $request->input('gst_number'),
                'phone' => $phone,
                'referral_source' => $referral_source,
                'invite_token' => $input['invite_token'] ?? $request->input('invite_token')
            ]);

            // Return response
            return response()->json($result);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => false,
                'message' => $th->getMessage()
            ]);
        }
    }

    // Verify account
    public function verification(Request $request)
    {
        try {
            // Get requested data
            $token = $request->input('token');

            // If token is empty
            if (empty($token)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Token is required.'
                ], 200);
            }

            // Check if user exists
            $user = DB::table('users')->where('token', $token)->first();

            // If user not found
            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'Token is invalid or expired. Please try again.'
                ]);
            } else if (isset($user->status) && $user->status !== 'active' && $user->status !== 'Active') {
                return response()->json([
                    'status' => false,
                    'message' => 'Your account is inactive. Please contact admin.'
                ]);
            } else if ($user->email_verify == 1) {
                return response()->json([
                    'status' => false,
                    'message' => 'Account already verified. Please login.'
                ]);
            } else {
                // Generate JWT Token payload matching AuthService login payload
                $payload = [
                    'user_id' => $user->id,
                    'name' => $user->name ?? (($user->first_name ?? '') . ' ' . ($user->last_name ?? '')),
                    'email' => $user->email,
                ];

                // Generate JWT Token
                $jwt_token = JWT::encode($payload, env('JWT_SECRET', '7+18EvAjOct+KzCCwJLpuwEjtXlzevAk4n09YeUkgfA='), 'HS256');

                // Update user token and status
                DB::table('users')->where('id', $user->id)->update([
                    'email_verify' => 1,
                    'token' => $jwt_token,
                    'updated_at' => date('Y-m-d H:i:s')
                ]);

                // Return response
                return response()->json([
                    'status' => true,
                    'data' => [
                        'access_token' => $jwt_token,
                        'user_id' => $user->id,
                        'name' => $payload['name'],
                        'email' => $user->email,
                    ]
                ]);
            }
        } catch (\Throwable $th) {
            // If error
            return response()->json([
                'status' => false,
                'message' => $th->getMessage()
            ]);
        }
    }

    // Forgot password
    public function forgot_password(Request $request)
    {
        try {
            // Get requested data
            $email = $request->input('email');

            // If email is empty
            if (empty($email)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Email address is required.'
                ], 200);
            }

            // Check if user exists
            $user = DB::table('users')->where('email', $email)->first();

            // If user not found
            if (empty($user)) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not found. Please register first.'
                ]);
            }

            // Generate token
            $token = CommonHelper::generate_token();

            // Update user
            DB::table('users')->where('email', $email)->update([
                'token' => $token,
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            // Send customer email for forgot password
            DB::table('email_queue')->insert([
                'to' => $email,
                'subject' => 'Reset Your Password for Connectly360 Account',
                'template_path' => 'emails/auth/reset_password',
                'data' => json_encode([
                    'customer_name' => $user->name ?? ($user->first_name ?? ''),
                    'token' => $token,
                ]),
            ]);

            // Return response
            return response()->json([
                'status' => true,
                'message' => 'Please check your email to reset your password.'
            ]);
        } catch (\Throwable $th) {
            // Return response
            return response()->json([
                'status' => false,
                'message' => "User not found. Please register first."
            ]);
        }
    }

    // Reset password
    public function reset_password(Request $request)
    {
        try {
            // Get requested data
            $token = $request->input('token');
            $password = $request->input('password');

            // If token is empty
            if (empty($token)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Token is required.'
                ], 200);
            } else if (empty($password)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Password is required.'
                ]);
            }

            // Check if user exists
            $user = DB::table('users')->where('token', $token)->first();

            // If user not found
            if (empty($user)) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not found. Please register first.'
                ]);
            }

            // Generate new token
            $new_token = md5(uniqid());

            // Begin transaction
            DB::beginTransaction();

            // Update user
            $updateData = [
                'token' => $new_token,
                'updated_at' => date('Y-m-d H:i:s')
            ];

            // Check if schema has password_hash or password
            $updateData['password_hash'] = password_hash($password, PASSWORD_BCRYPT);
            $updateData['password'] = md5($password);

            DB::table('users')->where('token', $token)->update($updateData);

            // Commit transaction
            DB::commit();

            // Return response
            return response()->json([
                'status' => true,
                'message' => 'Password updated successfully.'
            ]);
        } catch (\Throwable $th) {
            // Rollback transaction
            DB::rollBack();

            // Return response
            return response()->json([
                'status' => false,
                'message' => $th->getMessage()
            ]);
        }
    }

    // Fetch user info
    public function profile(Request $request)
    {
        try {
            // Get requested data
            $user_id = $request->input('user_id');

            // Fallback: If empty, extract from Bearer token
            if (empty($user_id)) {
                $authHeader = $request->header('Authorization');
                if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
                    $token = str_replace('Bearer ', '', $authHeader);
                    try {
                        $secret = env('JWT_SECRET', '7+18EvAjOct+KzCCwJLpuwEjtXlzevAk4n09YeUkgfA=');
                        $decoded = JWT::decode($token, new \Firebase\JWT\Key($secret, 'HS256'));
                        $user_id = $decoded->user_id ?? null;
                    } catch (\Throwable $e) {
                        // ignore and fall through
                    }
                }
            }

            // If user id is still empty
            if (empty($user_id)) {
                return response()->json([
                    'status' => false,
                    'message' => 'User id is required.'
                ], 200);
            }

            // Check if user exists
            $user = DB::table('users')->where('id', $user_id)->first();

            // If user not found
            if (empty($user)) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not found.'
                ]);
            }

            // Calculate total spent from payments table
            $totalSpent = DB::table('payments')
                ->where('user_id', $user->id)
                ->where('payment_status', 'success')
                ->sum('amount') ?? 0;

            // Calculate total credits purchased
            $creditsPurchased = DB::table('wallet_transactions')
                ->where('user_id', $user->id)
                ->where('transaction_type', 'purchase')
                ->sum('credits') ?? 0;

            // Calculate total credits used (debited)
            $creditsUsed = DB::table('wallet_transactions')
                ->where('user_id', $user->id)
                ->where('credit_type', 'debit')
                ->sum('credits') ?? 0;
            $creditsUsed = abs($creditsUsed);

            // Calculate free & bonus credits received
            $freeAndBonusCredits = DB::table('wallet_transactions')
                ->where('user_id', $user->id)
                ->where('credit_type', 'credit')
                ->where('transaction_type', '!=', 'purchase')
                ->sum('credits') ?? 0;

            // Attach fields to user object
            $user->total_spent = floatval($totalSpent);
            $user->credits_purchased = intval($creditsPurchased);
            $user->credits_used = intval($creditsUsed);
            $user->free_bonus_credits = intval($freeAndBonusCredits);

            // Return response
            return response()->json([
                'status' => true,
                'data' => $user
            ]);
        } catch (\Throwable $th) {
            // Return response
            return response()->json([
                'status' => false,
                'message' => $th->getMessage()
            ]);
        }
    }

    // Update profile
    public function update_profile(Request $request)
    {
        try {
            // Get requested data
            $user_id = $request->input('user_id');
            $first_name = $request->input('first_name');
            $last_name = $request->input('last_name');
            $phone = $request->input('phone');
            $avatar = $request->input('avatar');

            // If user id is empty
            if (empty($user_id)) {
                return response()->json([
                    'status' => false,
                    'message' => 'User id is required.'
                ], 200);
            }

            // Check if user exists
            $user = DB::table('users')->where('id', $user_id)->first();

            // If user not found
            if (empty($user)) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not found.'
                ]);
            }

            // Define payload data
            $data = [
                'first_name' => $first_name,
                'last_name' => $last_name,
                'phone_number' => $phone,
                'updated_at' => date('Y-m-d H:i:s')
            ];

            // If avatar is not empty
            if (!empty($avatar)) {
                $data['avatar'] = $avatar;
            }

            // Update basic info
            DB::table('users')->where('id', $user_id)->update($data);

            // Return response
            return response()->json([
                'status' => true
            ]);
        } catch (\Throwable $th) {
            // Return response
            return response()->json([
                'status' => false,
                'message' => "Error occurred while updating profile. Please try again.",
            ]);
        }
    }

    // Change password
    public function change_password(Request $request)
    {
        try {
            // Get requested data
            $user_id = $request->input('user_id');
            $current_password = $request->input('current_password');
            $new_password = $request->input('new_password');

            // If user id is empty
            if (empty($user_id) || empty($current_password) || empty($new_password)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Required fields are missing.'
                ], 200);
            }

            // Check if user exists
            $user = DB::table('users')->where('id', $user_id)->first();

            // If user not found
            if (empty($user)) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not found.'
                ]);
            }

            // Check if password is correct
            if (md5($current_password) !== $user->password) {
                return response()->json([
                    'status' => false,
                    'message' => 'Current password is incorrect.'
                ]);
            }

            // Begin transaction
            DB::beginTransaction();

            // Update record
            DB::table('users')->where('id', $user_id)->update([
                'password' => md5($new_password),
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            // Commit transaction
            DB::commit();

            // Return response
            return response()->json([
                'status' => true
            ]);
        } catch (\Throwable $th) {
            // Return response
            return response()->json([
                'status' => false,
                'message' => "Error occurred while updating password. Please try again."
            ]);
        }
    }

    // Google Login SSO
    public function googleLogin(Request $request, \App\Services\RegisterService $registerService, \App\Services\AuthService $authService)
    {
        try {
            // Requested data
            $email = $request->input('email');
            $name = $request->input('name');
            $avatar = $request->input('avatar');

            if (empty($email)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Email is required.'
                ]);
            }

            // Check if user already exists
            $user = DB::table('users')->where('email', $email)->first();

            if (empty($user)) {
                // Register a new user
                $uuid = (string) \Illuminate\Support\Str::uuid();

                // Extract first and last name from Google full name or email
                $fullName = trim((string)$name);
                if (empty($fullName)) {
                    $fullName = explode('@', $email)[0];
                }
                $nameParts = explode(' ', $fullName);
                $firstName = $nameParts[0] ?? '';
                $lastName = count($nameParts) > 1 ? implode(' ', array_slice($nameParts, 1)) : '';

                // Generate unique username stored in name column
                $baseUsername = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $firstName . ($lastName ? substr($lastName, 0, 1) : '')));
                if (empty($baseUsername)) {
                    $baseUsername = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', explode('@', $email)[0]));
                }
                if (empty($baseUsername)) {
                    $baseUsername = 'user';
                }

                $generatedUsername = $baseUsername;
                $counter = 1;
                while (DB::table('users')->where('name', $generatedUsername)->exists()) {
                    $generatedUsername = $baseUsername . $counter;
                    $counter++;
                }

                do {
                    $referralCode = strtoupper(\Illuminate\Support\Str::random(8));
                } while (DB::table('users')->where('referral_code', $referralCode)->exists());

                $userId = DB::table('users')->insertGetId([
                    'uuid' => $uuid,
                    'name' => $generatedUsername,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $email,
                    'avatar' => $avatar,
                    'api_key' => \Illuminate\Support\Str::random(32),
                    'referral_code' => $referralCode,
                    'status' => 'active',
                    'available_credits' => 50,
                    'total_bonus_credits' => 50,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);

                // Create wallet transaction entry
                DB::table('wallet_transactions')->insert([
                    'user_id' => $userId,
                    'transaction_type' => 'signup_bonus',
                    'credit_type' => 'credit',
                    'credits' => 50,
                    'opening_balance' => 0,
                    'closing_balance' => 50,
                    'remarks' => '50 FREE Credits added on successful Google Sign-In registration.',
                    'created_at' => date('Y-m-d H:i:s')
                ]);

                // Log activity
                DB::table('activity_logs')->insert([
                    'user_id' => $userId,
                    'action' => 'register',
                    'module' => 'Authentication',
                    'log_data' => json_encode(['description' => 'New user registered via Google SSO and received 50 FREE credits.']),
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'created_at' => date('Y-m-d H:i:s')
                ]);

                $user = DB::table('users')->where('id', $userId)->first();
            } else {
                // Update user avatar, first/last name if empty, and login stats
                $fullName = trim((string)$name);
                $nameParts = explode(' ', $fullName);
                $firstName = $nameParts[0] ?? '';
                $lastName = count($nameParts) > 1 ? implode(' ', array_slice($nameParts, 1)) : '';

                $update = [
                    'avatar' => $avatar,
                    'last_login_at' => date('Y-m-d H:i:s'),
                    'last_login_ip' => $request->ip(),
                    'updated_at' => date('Y-m-d H:i:s')
                ];
                if (empty($user->first_name) && !empty($firstName)) {
                    $update['first_name'] = $firstName;
                }
                if (empty($user->last_name) && !empty($lastName)) {
                    $update['last_name'] = $lastName;
                }
                if (empty($user->api_key)) {
                    $update['api_key'] = \Illuminate\Support\Str::random(32);
                }
                if (empty($user->referral_code)) {
                    do {
                        $referralCode = strtoupper(\Illuminate\Support\Str::random(8));
                    } while (DB::table('users')->where('referral_code', $referralCode)->exists());
                    $update['referral_code'] = $referralCode;
                }
                DB::table('users')->where('id', $user->id)->update($update);
                $user = DB::table('users')->where('id', $user->id)->first();
            }

            // Generate JWT Token
            $payload = [
                'user_id' => $user->id,
                'uuid' => $user->uuid,
                'name' => $user->name,
                'email' => $user->email,
                'credits' => $user->available_credits,
                'avatar' => $user->avatar
            ];

            // Generate JWT Token
            $jwt_token = JWT::encode($payload, env('JWT_SECRET', '7+18EvAjOct+KzCCwJLpuwEjtXlzevAk4n09YeUkgfA='), 'HS256');

            // Response
            return response()->json([
                'status' => true,
                'data' => [
                    'access_token' => $jwt_token,
                    'user_id' => $user->id,
                    'uuid' => $user->uuid,
                    'name' => $user->name,
                    'email' => $user->email,
                    'credits' => $user->available_credits,
                    'avatar' => $user->avatar
                ]
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => false,
                'message' => 'Error during Google SSO: ' . $th->getMessage()
            ]);
        }
    }

    // Google OAuth Callback Redirect handler
    public function googleCallback(Request $request, \App\Services\RegisterService $registerService, \App\Services\AuthService $authService)
    {
        try {
            $credential = $request->input('credential');
            if (empty($credential)) {
                throw new \Exception('No Google SSO credential provided.');
            }

            $parts = explode('.', $credential);
            if (count($parts) < 2) {
                throw new \Exception('Invalid Google SSO credential structure.');
            }

            $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1])));
            if (!$payload) {
                throw new \Exception('Failed to decode Google SSO payload.');
            }

            $email = $payload->email ?? null;
            $name = $payload->name ?? null;
            $avatar = $payload->picture ?? null;

            if (empty($email)) {
                throw new \Exception('Email is missing from Google SSO payload.');
            }

            // Check if user already exists
            $user = DB::table('users')->where('email', $email)->first();

            if (empty($user)) {
                // Register a new user
                $uuid = (string) \Illuminate\Support\Str::uuid();

                // Extract first and last name from Google full name or email
                $fullName = trim((string)$name);
                if (empty($fullName)) {
                    $fullName = explode('@', $email)[0];
                }
                $nameParts = explode(' ', $fullName);
                $firstName = $nameParts[0] ?? '';
                $lastName = count($nameParts) > 1 ? implode(' ', array_slice($nameParts, 1)) : '';

                // Generate unique username stored in name column
                $baseUsername = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $firstName . ($lastName ? substr($lastName, 0, 1) : '')));
                if (empty($baseUsername)) {
                    $baseUsername = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', explode('@', $email)[0]));
                }
                if (empty($baseUsername)) {
                    $baseUsername = 'user';
                }
                
                $generatedUsername = $baseUsername;
                $counter = 1;
                while (DB::table('users')->where('name', $generatedUsername)->exists()) {
                    $generatedUsername = $baseUsername . $counter;
                    $counter++;
                }

                do {
                    $referralCode = strtoupper(\Illuminate\Support\Str::random(8));
                } while (DB::table('users')->where('referral_code', $referralCode)->exists());

                $insertData = [
                    'uuid' => $uuid,
                    'google_id' => $payload->sub ?? null,
                    'name' => $generatedUsername,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $email,
                    'avatar' => $avatar,
                    'api_key' => \Illuminate\Support\Str::random(32),
                    'referral_code' => $referralCode,
                    'status' => 'active',
                    'available_credits' => 20,
                    'total_bonus_credits' => 20,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ];

                $userId = DB::table('users')->insertGetId($insertData);

                // Create wallet transaction entry
                DB::table('wallet_transactions')->insert([
                    'user_id' => $userId,
                    'transaction_type' => 'signup_bonus',
                    'credit_type' => 'credit',
                    'credits' => 20,
                    'opening_balance' => 0,
                    'closing_balance' => 20,
                    'remarks' => '20 FREE Credits added on successful via Google registration.',
                    'created_at' => date('Y-m-d H:i:s')
                ]);

                // Fetch user
                $user = DB::table('users')->where('id', $userId)->first();
            } else {
                // Update user avatar, first/last name if empty, and login stats
                $fullName = trim((string)$name);
                $nameParts = explode(' ', $fullName);
                $firstName = $nameParts[0] ?? '';
                $lastName = count($nameParts) > 1 ? implode(' ', array_slice($nameParts, 1)) : '';

                $update = [
                    'avatar' => $avatar,
                    'last_login_at' => date('Y-m-d H:i:s'),
                    'last_login_ip' => $request->ip(),
                    'updated_at' => date('Y-m-d H:i:s')
                ];
                if (empty($user->first_name) && !empty($firstName)) {
                    $update['first_name'] = $firstName;
                }
                if (empty($user->last_name) && !empty($lastName)) {
                    $update['last_name'] = $lastName;
                }
                if (empty($user->google_id) && isset($payload->sub)) {
                    $update['google_id'] = $payload->sub;
                }
                if (empty($user->api_key)) {
                    $update['api_key'] = \Illuminate\Support\Str::random(32);
                }
                if (empty($user->referral_code)) {
                    do {
                        $referralCode = strtoupper(\Illuminate\Support\Str::random(8));
                    } while (DB::table('users')->where('referral_code', $referralCode)->exists());
                    $update['referral_code'] = $referralCode;
                }
                DB::table('users')->where('id', $user->id)->update($update);
                $user = DB::table('users')->where('id', $user->id)->first();
            }

            // Generate JWT Token
            $payload_data = [
                'user_id' => $user->id,
                'uuid' => $user->uuid,
                'name' => $user->name,
                'email' => $user->email,
                'credits' => $user->available_credits,
                'avatar' => $user->avatar
            ];

            $jwt_token = JWT::encode($payload_data, env('JWT_SECRET', '7+18EvAjOct+KzCCwJLpuwEjtXlzevAk4n09YeUkgfA='), 'HS256');

            $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
            return redirect($frontendUrl . '/login?token=' . urlencode($jwt_token) . '&name=' . urlencode($payload_data['name']) . '&email=' . urlencode($email) . '&avatar=' . urlencode($avatar));
        } catch (\Throwable $th) {
            $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
            return redirect($frontendUrl . '/login?error=' . urlencode($th->getMessage()));
        }
    }

    // Fetch usage logs for user
    public function usageLogs(Request $request)
    {
        try {
            $authHeader = $request->header('Authorization');
            if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
                return response()->json(['status' => false, 'message' => 'Unauthorized'], 401);
            }

            $token = str_replace('Bearer ', '', $authHeader);
            $secret = env('JWT_SECRET', '7+18EvAjOct+KzCCwJLpuwEjtXlzevAk4n09YeUkgfA=');
            $decoded = JWT::decode($token, new \Firebase\JWT\Key($secret, 'HS256'));
            $userId = $decoded->user_id ?? null;

            if (empty($userId)) {
                return response()->json(['status' => false, 'message' => 'Unauthorized'], 401);
            }

            $logs = DB::table('usage_logs')
                ->where('user_id', $userId)
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'status' => true,
                'logs' => $logs
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => false,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    // Fetch wallet transactions for user
    public function walletTransactions(Request $request)
    {
        try {
            $authHeader = $request->header('Authorization');
            if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
                return response()->json(['status' => false, 'message' => 'Unauthorized'], 401);
            }

            $token = str_replace('Bearer ', '', $authHeader);
            $secret = env('JWT_SECRET', '7+18EvAjOct+KzCCwJLpuwEjtXlzevAk4n09YeUkgfA=');
            $decoded = JWT::decode($token, new \Firebase\JWT\Key($secret, 'HS256'));
            $userId = $decoded->user_id ?? null;

            if (empty($userId)) {
                return response()->json(['status' => false, 'message' => 'Unauthorized'], 401);
            }

            $transactions = DB::table('wallet_transactions')
                ->where('user_id', $userId)
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'status' => true,
                'transactions' => $transactions
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => false,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    // Fetch user payment history
    public function paymentHistory(Request $request)
    {
        try {
            $userId = $this->getUserIdFromToken($request);
            if (empty($userId)) {
                return response()->json(['status' => false, 'message' => 'Unauthorized'], 401);
            }

            $payments = DB::table('payments')
                ->leftJoin('credit_packs', 'payments.credit_pack_id', '=', 'credit_packs.id')
                ->where('payments.user_id', $userId)
                ->select('payments.*', 'credit_packs.name as pack_name', 'credit_packs.total_credits as pack_credits')
                ->orderBy('payments.created_at', 'desc')
                ->get();

            return response()->json([
                'status' => true,
                'payments' => $payments
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => false,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    // Rotate API Key
    public function rotateApiKey(Request $request)
    {
        try {
            $authHeader = $request->header('Authorization');
            if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
                return response()->json(['status' => false, 'message' => 'Unauthorized'], 401);
            }

            $token = str_replace('Bearer ', '', $authHeader);
            $secret = env('JWT_SECRET', '7+18EvAjOct+KzCCwJLpuwEjtXlzevAk4n09YeUkgfA=');
            $decoded = JWT::decode($token, new \Firebase\JWT\Key($secret, 'HS256'));
            $userId = $decoded->user_id ?? null;

            if (empty($userId)) {
                return response()->json(['status' => false, 'message' => 'Unauthorized'], 401);
            }

            $newKey = \Illuminate\Support\Str::random(32);

            DB::table('users')->where('id', $userId)->update([
                'api_key' => $newKey,
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            return response()->json([
                'status' => true,
                'api_key' => $newKey
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => false,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    // Fetch active credit packs
    public function getCreditPacks(Request $request)
    {
        try {
            $packs = DB::table('credit_packs')
                ->where('status', 'active')
                ->orderBy('sort_order', 'asc')
                ->get();

            return response()->json([
                'status' => true,
                'packs' => $packs
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => false,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    // Create Razorpay Order
    public function createRazorpayOrder(Request $request)
    {
        try {
            $userId = $this->getUserIdFromToken($request);
            if (empty($userId)) {
                return response()->json(['status' => false, 'message' => 'Unauthorized'], 401);
            }

            $packId = $request->input('pack_id');
            if (empty($packId)) {
                return response()->json(['status' => false, 'message' => 'Pack ID is required.'], 400);
            }

            $pack = DB::table('credit_packs')->where('id', $packId)->first();
            if (!$pack) {
                return response()->json(['status' => false, 'message' => 'Credit pack not found.'], 404);
            }

            $orderNumber = 'LS360-' . strtoupper(\Illuminate\Support\Str::random(10));
            $uuid = (string) \Illuminate\Support\Str::uuid();

            // Insert pending local order
            $orderId = DB::table('orders')->insertGetId([
                'uuid' => $uuid,
                'user_id' => $userId,
                'order_number' => $orderNumber,
                'credit_pack_id' => $pack->id,
                'credits' => $pack->credits,
                'bonus_credits' => $pack->bonus_credits ?? 0,
                'total_credits' => $pack->total_credits,
                'currency' => $pack->currency ?? 'INR',
                'subtotal' => $pack->price,
                'discount' => 0.00,
                'tax' => 0.00,
                'total_amount' => $pack->price,
                'status' => 'pending',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            // Call Razorpay orders API via cURL
            $keyId = config('services.razorpay.key_id');
            $keySecret = config('services.razorpay.key_secret');
            $amountInPaise = intval($pack->price * 100);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, 'https://api.razorpay.com/v1/orders');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_USERPWD, $keyId . ':' . $keySecret);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                'amount' => $amountInPaise,
                'currency' => $pack->currency ?? 'INR',
                'receipt' => $orderNumber
            ]));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json'
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                return response()->json([
                    'status' => false,
                    'message' => 'Failed to initiate Razorpay order: ' . $response
                ], 500);
            }

            $razorpayOrder = json_decode($response);
            $razorpayOrderId = $razorpayOrder->id;

            // Save Razorpay order ID in local order notes
            DB::table('orders')->where('id', $orderId)->update([
                'notes' => $razorpayOrderId,
                'status' => 'processing'
            ]);

            return response()->json([
                'status' => true,
                'razorpay_order_id' => $razorpayOrderId,
                'amount' => $amountInPaise,
                'currency' => $pack->currency ?? 'INR',
                'key' => $keyId,
                'order_number' => $orderNumber
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => false,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    // Verify Razorpay Payment Signature
    public function verifyRazorpayPayment(Request $request)
    {
        try {
            $userId = $this->getUserIdFromToken($request);
            if (empty($userId)) {
                return response()->json(['status' => false, 'message' => 'Unauthorized'], 401);
            }

            $razorpayOrderId = $request->input('razorpay_order_id');
            $razorpayPaymentId = $request->input('razorpay_payment_id');
            $razorpaySignature = $request->input('razorpay_signature');
            $orderNumber = $request->input('order_number');

            if (empty($razorpayOrderId) || empty($razorpayPaymentId) || empty($razorpaySignature) || empty($orderNumber)) {
                return response()->json(['status' => false, 'message' => 'Missing verification params.'], 400);
            }

            $keySecret = config('services.razorpay.key_secret');
            $expectedSignature = hash_hmac('sha256', $razorpayOrderId . '|' . $razorpayPaymentId, $keySecret);

            if ($expectedSignature !== $razorpaySignature) {
                return response()->json(['status' => false, 'message' => 'Invalid payment signature.'], 400);
            }

            $order = DB::table('orders')->where('order_number', $orderNumber)->first();
            if (!$order) {
                return response()->json(['status' => false, 'message' => 'Order not found.'], 404);
            }

            if ($order->status === 'completed') {
                return response()->json(['status' => true, 'message' => 'Payment already verified.']);
            }

            DB::beginTransaction();

            // Update local order
            DB::table('orders')->where('id', $order->id)->update([
                'status' => 'completed',
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            // Save to payments table
            DB::table('payments')->insert([
                'user_id' => $userId,
                'credit_pack_id' => $order->credit_pack_id,
                'payment_gateway' => 'razorpay',
                'payment_id' => $razorpayPaymentId,
                'order_id' => $razorpayOrderId,
                'amount' => $order->total_amount,
                'currency' => $order->currency,
                'payment_status' => 'success',
                'gateway_response' => json_encode($request->all()),
                'paid_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s')
            ]);

            // Increment user credits
            $user = DB::table('users')->where('id', $userId)->first();
            $openingBalance = $user->available_credits ?? 0;

            // Find credit pack data
            $pack = DB::table('credit_packs')->where('id', $order->credit_pack_id)->first();
            $planKey = $pack->key ?? 'premium';

            // Update users data
            DB::table('users')->where('id', $userId)->update([
                'available_credits' => $openingBalance + $order->total_credits,
                'active_plan' => $planKey
            ]);

            // Insert transaction
            DB::table('wallet_transactions')->insert([
                'user_id' => $userId,
                'transaction_type' => 'purchase',
                'credit_type' => 'credit',
                'credits' => $order->total_credits,
                'opening_balance' => $openingBalance,
                'closing_balance' => $openingBalance + $order->total_credits,
                'remarks' => "Recharged " . $order->total_credits . " credits via Razorpay payment ID: " . $razorpayPaymentId,
                'created_at' => date('Y-m-d H:i:s')
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Payment verified and credits added successfully.',
                'credits_added' => $order->total_credits
            ]);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    private function getUserIdFromToken(Request $request)
    {
        $authHeader = $request->header('Authorization');
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return null;
        }

        $token = str_replace('Bearer ', '', $authHeader);
        try {
            $secret = env('JWT_SECRET', '7+18EvAjOct+KzCCwJLpuwEjtXlzevAk4n09YeUkgfA=');
            $decoded = JWT::decode($token, new \Firebase\JWT\Key($secret, 'HS256'));
            return $decoded->user_id ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
