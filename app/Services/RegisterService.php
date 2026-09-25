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

        // Check if user exists by email or username/name
        $username = $userdata['username'] ?? null;
        $hasUsernameCol = Schema::hasColumn('users', 'username');
        $existingUser = DB::table('users')
            ->where('email', $email)
            ->when($username, function ($query) use ($username, $hasUsernameCol) {
                return $query->orWhere(function ($q) use ($username, $hasUsernameCol) {
                    $q->where('name', $username);
                    if ($hasUsernameCol) {
                        $q->orWhere('username', $username);
                    }
                });
            })
            ->first();

        // If user found
        if (!empty($existingUser)) {
            if (strtolower($existingUser->email ?? '') === strtolower($email)) {
                return [
                    'status' => false,
                    'message' => 'An account with this email address already exists. Please try signing in or use another email.'
                ];
            }
            return [
                'status' => false,
                'message' => 'This username is already taken. Please choose a different username.'
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

            if (!empty($company_name)) {
                $user_data['company_name'] = $company_name;
            }
            if (!empty($userdata['country'])) {
                $user_data['country'] = $userdata['country'];
            }
            if (!empty($userdata['gst_number'])) {
                $user_data['gst_number'] = $userdata['gst_number'];
            }
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

            DB::commit();

            // Send Client Welcome Email using database template
            self::sendWelcomeEmail($user_id, $name, $email);

            // Dispatch user notification
            try {
                NotificationService::notifyUser($user_id, 'user.registered', [
                    'title' => 'Welcome to Megabyte Circuits',
                    'message' => 'Your account has been registered successfully.',
                    'category' => 'system',
                    'theme' => 'success',
                    'entity_type' => 'user',
                    'entity_id' => $user_id,
                ]);

                NotificationService::notifyAdmins('user.registered', [
                    'title' => 'New Customer Registration',
                    'message' => "New customer registered: {$name} ({$email})",
                    'category' => 'system',
                    'theme' => 'info',
                    'entity_type' => 'user',
                    'entity_id' => $user_id,
                ]);
            } catch (\Throwable $notifErr) {}

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

    /**
     * Send Client Welcome Email using database template and log to email_logs table.
     */
    public static function sendWelcomeEmail($user_id, $name, $email)
    {
        try {
            $quoteUrl = config('app.cart_url', config('app.frontend_url', 'https://cart.megabytecircuit.com'));
            $loginUrl = rtrim($quoteUrl, '/') . '/login';

            $rendered = EmailTemplateService::render('client_welcome', null, [
                'name' => $name,
                'email' => $email,
                'login_url' => $loginUrl,
            ]);

            if ($rendered['success']) {
                $defaultFromAddress = CredentialService::get('mail', 'MAIL_GLOBAL_FROM_ADDRESS', 'MAIL_GLOBAL_FROM_ADDRESS', config('mail.from.address', 'quote@megabytecircuit.com'));
                $defaultFromName = CredentialService::get('mail', 'MAIL_GLOBAL_FROM_NAME', 'MAIL_GLOBAL_FROM_NAME', config('app.name', 'Megabyte Circuit'));

                $fromAddress = filter_var($rendered['from_email'] ?? '', FILTER_VALIDATE_EMAIL) ? $rendered['from_email'] : $defaultFromAddress;
                $fromName = !empty($rendered['from_name']) ? $rendered['from_name'] : $defaultFromName;

                \Illuminate\Support\Facades\Config::set('mail.mailers.smtp_global.transport', 'smtp');
                \Illuminate\Support\Facades\Config::set('mail.mailers.smtp_global.host', CredentialService::get('mail', 'MAIL_GLOBAL_HOST', 'MAIL_GLOBAL_HOST', config('mail.mailers.smtp.host', 'smtp.gmail.com')));
                \Illuminate\Support\Facades\Config::set('mail.mailers.smtp_global.port', CredentialService::get('mail', 'MAIL_GLOBAL_PORT', 'MAIL_GLOBAL_PORT', config('mail.mailers.smtp.port', 587)));
                \Illuminate\Support\Facades\Config::set('mail.mailers.smtp_global.username', CredentialService::get('mail', 'MAIL_GLOBAL_USERNAME', 'MAIL_GLOBAL_USERNAME', config('mail.mailers.smtp.username')));
                \Illuminate\Support\Facades\Config::set('mail.mailers.smtp_global.password', CredentialService::get('mail', 'MAIL_GLOBAL_PASSWORD', 'MAIL_GLOBAL_PASSWORD', config('mail.mailers.smtp.password')));
                \Illuminate\Support\Facades\Config::set('mail.mailers.smtp_global.encryption', CredentialService::get('mail', 'MAIL_GLOBAL_ENCRYPTION', 'MAIL_GLOBAL_ENCRYPTION', config('mail.mailers.smtp.encryption', 'tls')));

                \Illuminate\Support\Facades\Mail::mailer('smtp_global')->send([], [], function ($message) use ($email, $fromAddress, $fromName, $rendered) {
                    $message->to($email)
                        ->from($fromAddress, $fromName)
                        ->subject($rendered['subject'])
                        ->html($rendered['body']);
                });

                // Log welcome email in email_logs table
                try {
                    \App\Models\EmailLog::create([
                        'template_key' => 'client_welcome',
                        'email_type'   => 'welcome',
                        'customer_id'  => $user_id,
                        'from_email'   => $fromAddress,
                        'from_name'    => $fromName,
                        'to'           => $email,
                        'subject'      => $rendered['subject'],
                        'body'         => $rendered['body'],
                        'status'       => 'sent',
                        'sent_at'      => now(),
                        'is_test'      => false,
                    ]);
                } catch (\Throwable $logEx) {}

                return true;
            }
        } catch (\Throwable $emailErr) {
            \Illuminate\Support\Facades\Log::error("Failed to send welcome email to {$email}: " . $emailErr->getMessage());
            try {
                \App\Models\EmailLog::create([
                    'template_key'  => 'client_welcome',
                    'email_type'    => 'welcome',
                    'customer_id'   => $user_id,
                    'from_email'    => $fromAddress ?? config('mail.from.address', 'quote@megabytecircuit.com'),
                    'from_name'     => $fromName ?? config('app.name', 'Megabyte Circuit'),
                    'to'            => $email,
                    'subject'       => $rendered['subject'] ?? 'Welcome to Megabyte Circuits',
                    'body'          => $rendered['body'] ?? '',
                    'status'        => 'failed',
                    'failed_at'     => now(),
                    'error_message' => $emailErr->getMessage(),
                    'is_test'       => false,
                ]);
            } catch (\Throwable $logEx) {}
        }
        return false;
    }
}
