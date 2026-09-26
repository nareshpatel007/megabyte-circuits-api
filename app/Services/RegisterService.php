<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use App\Models\PendingRegistration;
use App\Jobs\SendClientSignupOtpEmailJob;
use App\Jobs\SendClientWelcomeEmailJob;

class RegisterService
{
    const OTP_EXPIRATION_MINUTES = 10;
    const OTP_LENGTH = 6;
    const OTP_MAX_ATTEMPTS = 5;
    const OTP_RESEND_COOLDOWN_SECONDS = 60;

    /**
     * Legacy register method wrapper.
     */
    public function register($userdata = [])
    {
        return $this->initiateRegistration($userdata, request()->ip(), request()->userAgent());
    }

    /**
     * Initiate custom email signup: validate details, store pending registration, generate & send OTP.
     * NOTE: Does NOT create user in database yet!
     */
    public function initiateRegistration($userdata = [], $ip = null, $userAgent = null)
    {
        $name = $userdata['name'] ?? null;
        $first_name = $userdata['first_name'] ?? null;
        $last_name = $userdata['last_name'] ?? null;
        $email = $userdata['email'] ?? null;
        $password = $userdata['password'] ?? null;
        $company_name = $userdata['company_name'] ?? null;
        $phone = $userdata['phone'] ?? null;
        $referral_source = $userdata['referral_source'] ?? null;

        if (empty($name)) {
            $name = trim(($first_name ?? '') . ' ' . ($last_name ?? ''));
        }

        // Validation rules
        if (empty($name)) {
            return [
                'status' => false,
                'message' => 'Name is required.'
            ];
        } else if (empty($email)) {
            return [
                'status' => false,
                'message' => 'Email address is required.'
            ];
        } else if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [
                'status' => false,
                'message' => 'Please enter a valid email address.'
            ];
        } else if (empty($password)) {
            return [
                'status' => false,
                'message' => 'Password is required.'
            ];
        }

        // Check if user already exists by email or username/name in users table
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

        // Check if invite token is provided and valid
        $inviteToken = $userdata['invite_token'] ?? null;
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

        // Remove any old unexpired pending registration for this same email to avoid clutter
        PendingRegistration::where('email', $email)
            ->whereNull('used_at')
            ->delete();

        // Generate Secure Registration Token (64 chars)
        $registrationToken = Str::random(64);
        $registrationTokenHash = hash('sha256', $registrationToken);

        // Generate Cryptographically Secure 6-Digit OTP
        $otp = (string) random_int(100000, 999999);
        $otpHash = hash('sha256', $otp);

        // Hash Password - NEVER store plain password
        $passwordHash = password_hash($password, PASSWORD_BCRYPT);

        // Store Pending Registration Data
        $pending = PendingRegistration::create([
            'registration_token_hash' => $registrationTokenHash,
            'email'                   => $email,
            'username'                => $username,
            'name'                    => $name,
            'first_name'              => $first_name,
            'last_name'               => $last_name,
            'password_hash'           => $passwordHash,
            'company_name'            => $company_name,
            'country'                 => $userdata['country'] ?? null,
            'gst_number'              => $userdata['gst_number'] ?? null,
            'phone'                   => $phone,
            'referral_source'         => $referral_source,
            'invite_token'            => $inviteToken,
            'payload'                 => $userdata,
            'otp_hash'                => $otpHash,
            'otp_expires_at'          => now()->addMinutes(self::OTP_EXPIRATION_MINUTES),
            'otp_attempts'            => 0,
            'max_attempts'            => self::OTP_MAX_ATTEMPTS,
            'last_otp_sent_at'        => now(),
            'ip_address'              => $ip,
            'user_agent'              => $userAgent,
        ]);

        // Send OTP Email via Job & Fallback
        try {
            SendClientSignupOtpEmailJob::dispatch($pending->id, $otp);
        } catch (\Throwable $jobErr) {}
        
        // Execute synchronous send to guarantee delivery if queue runner is not active
        SendClientSignupOtpEmailJob::sendOtp($pending, $otp);

        return [
            'status'             => true,
            'success'            => true,
            'message'            => 'Verification code sent to your email.',
            'registration_token' => $registrationToken,
            'expires_in'         => self::OTP_EXPIRATION_MINUTES * 60,
        ];
    }

    /**
     * Verify OTP and Create Verified User Account.
     * Transactional and Idempotent.
     */
    public function verifyOtpAndCreateUser(string $registrationToken, string $otp)
    {
        $registrationToken = trim($registrationToken);
        $otp = trim($otp);

        if (empty($registrationToken) || empty($otp)) {
            return [
                'status'  => false,
                'message' => 'Registration token and verification code are required.'
            ];
        }

        $tokenHash = hash('sha256', $registrationToken);

        $pending = PendingRegistration::where('registration_token_hash', $tokenHash)
            ->whereNull('used_at')
            ->first();

        if (!$pending) {
            return [
                'status'  => false,
                'message' => 'Invalid or expired registration session. Please start signup again.'
            ];
        }

        // Check if OTP has expired
        if ($pending->otp_expires_at && $pending->otp_expires_at->isPast()) {
            return [
                'status'  => false,
                'message' => 'This verification code has expired. Please request a new code.'
            ];
        }

        // Check if maximum attempts exceeded
        if ($pending->otp_attempts >= $pending->max_attempts) {
            return [
                'status'  => false,
                'message' => 'Too many attempts. Please request a new verification code.'
            ];
        }

        // Validate OTP
        $inputOtpHash = hash('sha256', $otp);
        if ($inputOtpHash !== $pending->otp_hash && !password_verify($otp, $pending->otp_hash)) {
            $pending->increment('otp_attempts');
            return [
                'status'  => false,
                'message' => 'Invalid verification code.'
            ];
        }

        // Check if email already registered in interim
        $existing = DB::table('users')->where('email', $pending->email)->first();
        if ($existing) {
            $pending->update(['used_at' => now(), 'verified_at' => now()]);
            return [
                'status'  => false,
                'message' => 'An account with this email address already exists. Please sign in.'
            ];
        }

        // Begin Transaction for atomic account creation
        DB::beginTransaction();

        try {
            // Mark pending registration as verified and used
            $pending->update([
                'verified_at' => now(),
                'used_at'     => now(),
            ]);

            // Generate unique referral code
            do {
                $referralCode = strtoupper(Str::random(8));
            } while (DB::table('users')->where('referral_code', $referralCode)->exists());

            $token = md5(uniqid());

            $user_data = [
                'name'              => $pending->name,
                'first_name'        => $pending->first_name,
                'last_name'         => $pending->last_name,
                'email'             => $pending->email,
                'password_hash'     => $pending->password_hash,
                'token'             => $token,
                'referral_code'     => $referralCode,
                'available_credits' => 50,
                'status'            => 'active',
                'created_at'        => date('Y-m-d H:i:s'),
                'updated_at'        => date('Y-m-d H:i:s')
            ];

            if (!empty($pending->company_name) && Schema::hasColumn('users', 'company_name')) {
                $user_data['company_name'] = $pending->company_name;
            }
            if (!empty($pending->country) && Schema::hasColumn('users', 'country')) {
                $user_data['country'] = $pending->country;
            }
            if (!empty($pending->gst_number) && Schema::hasColumn('users', 'gst_number')) {
                $user_data['gst_number'] = $pending->gst_number;
            }
            if (!empty($pending->phone) && Schema::hasColumn('users', 'phone_number')) {
                $user_data['phone_number'] = $pending->phone;
            }
            if (!empty($pending->referral_source) && Schema::hasColumn('users', 'referral_source')) {
                $user_data['referral_source'] = $pending->referral_source;
            }

            $user_id = DB::table('users')->insertGetId($user_data);

            // Wallet transaction for starting credits
            if (Schema::hasTable('wallet_transactions')) {
                DB::table('wallet_transactions')->insert([
                    'user_id'          => $user_id,
                    'transaction_type' => 'signup_bonus',
                    'credit_type'       => 'credit',
                    'credits'          => 50,
                    'opening_balance'  => 0,
                    'closing_balance'  => 50,
                    'remarks'          => '50 FREE Credits added on successful registration.',
                    'created_at'       => date('Y-m-d H:i:s')
                ]);
            }

            // Activity Log
            if (Schema::hasTable('activity_logs')) {
                DB::table('activity_logs')->insert([
                    'user_id'    => $user_id,
                    'action'     => 'register',
                    'module'     => 'Authentication',
                    'log_data'   => json_encode(['description' => 'New user registered after OTP email verification.']),
                    'ip_address' => $pending->ip_address,
                    'user_agent' => $pending->user_agent,
                    'created_at' => date('Y-m-d H:i:s')
                ]);
            }

            DB::commit();

            // Dispatch Welcome Email via Queue & Fallback
            try {
                SendClientWelcomeEmailJob::dispatch($user_id);
            } catch (\Throwable $jobEx) {}
            self::sendWelcomeEmail($user_id, $pending->name, $pending->email);

            // Trigger Notifications
            try {
                NotificationService::notifyUser($user_id, 'user.registered', [
                    'title'       => 'Welcome to Megabyte Circuits',
                    'message'     => 'Your account has been registered successfully.',
                    'category'    => 'system',
                    'theme'       => 'success',
                    'entity_type' => 'user',
                    'entity_id'   => $user_id,
                ]);

                NotificationService::notifyAdmins('user.registered', [
                    'title'       => 'New Customer Registration',
                    'message'     => "New customer registered: {$pending->name} ({$pending->email})",
                    'category'    => 'system',
                    'theme'       => 'info',
                    'entity_type' => 'user',
                    'entity_id'   => $user_id,
                ]);
            } catch (\Throwable $notifErr) {}

            // Generate JWT Token for immediate auto login
            $payload = [
                'user_id' => $user_id,
                'name'    => $pending->name,
                'email'   => $pending->email,
            ];
            $jwt_token = \Firebase\JWT\JWT::encode($payload, env('JWT_SECRET', '7+18EvAjOct+KzCCwJLpuwEjtXlzevAk4n09YeUkgfA='), 'HS256');

            return [
                'status'  => true,
                'success' => true,
                'message' => 'Account created successfully!',
                'data'    => [
                    'access_token' => $jwt_token,
                    'user_id'      => $user_id,
                    'name'         => $pending->name,
                    'email'        => $pending->email
                ]
            ];

        } catch (\Throwable $th) {
            DB::rollBack();
            return [
                'status'  => false,
                'message' => 'Account creation failed: ' . $th->getMessage()
            ];
        }
    }

    /**
     * Resend Signup Verification OTP.
     */
    public function resendOtp(string $registrationToken)
    {
        $registrationToken = trim($registrationToken);

        if (empty($registrationToken)) {
            return [
                'status'  => false,
                'message' => 'Registration token is required.'
            ];
        }

        $tokenHash = hash('sha256', $registrationToken);

        $pending = PendingRegistration::where('registration_token_hash', $tokenHash)
            ->whereNull('used_at')
            ->first();

        if (!$pending) {
            return [
                'status'  => false,
                'message' => 'Invalid or expired registration session.'
            ];
        }

        // Rate limiting: check resend cooldown (60s)
        if ($pending->last_otp_sent_at) {
            $secondsSinceLastSent = now()->diffInSeconds($pending->last_otp_sent_at);
            if ($secondsSinceLastSent < self::OTP_RESEND_COOLDOWN_SECONDS) {
                $remaining = self::OTP_RESEND_COOLDOWN_SECONDS - $secondsSinceLastSent;
                return [
                    'status'    => false,
                    'message'   => "Please wait {$remaining} seconds before requesting a new code.",
                    'remaining' => $remaining,
                ];
            }
        }

        // Generate new OTP
        $newOtp = (string) random_int(100000, 999999);
        $newOtpHash = hash('sha256', $newOtp);

        $pending->update([
            'otp_hash'         => $newOtpHash,
            'otp_expires_at'    => now()->addMinutes(self::OTP_EXPIRATION_MINUTES),
            'otp_attempts'      => 0,
            'last_otp_sent_at'  => now(),
        ]);

        // Send new OTP email via Job & Fallback
        try {
            SendClientSignupOtpEmailJob::dispatch($pending->id, $newOtp);
        } catch (\Throwable $jobErr) {}
        SendClientSignupOtpEmailJob::sendOtp($pending, $newOtp);

        return [
            'status'     => true,
            'success'    => true,
            'message'    => 'A new verification code has been sent to your email.',
            'expires_in' => self::OTP_EXPIRATION_MINUTES * 60,
        ];
    }

    /**
     * Send Client Welcome Email using database template and log to email_logs table.
     */
    public static function sendWelcomeEmail($user_id, $name, $email)
    {
        if (EmailTemplateService::isImportLocalEmail($email)) {
            \Illuminate\Support\Facades\Log::info("RegisterService: Skipped sending welcome email to placeholder email {$email}.");
            return false;
        }

        try {
            $quoteUrl = config('app.cart_url', config('app.frontend_url', 'https://cart.megabytecircuit.com'));
            $loginUrl = rtrim($quoteUrl, '/') . '/login';

            $rendered = EmailTemplateService::render('client_welcome', null, [
                'name'      => $name,
                'email'     => $email,
                'login_url' => $loginUrl,
            ]);

            if ($rendered['success']) {
                $defaultFromAddress = CredentialService::get('mail', 'MAIL_GLOBAL_FROM_ADDRESS', 'MAIL_GLOBAL_FROM_ADDRESS', config('mail.from.address', 'quote@megabytecircuit.com'));
                $defaultFromName    = CredentialService::get('mail', 'MAIL_GLOBAL_FROM_NAME', 'MAIL_GLOBAL_FROM_NAME', config('app.name', 'Megabyte Circuit'));

                $fromAddress = filter_var($rendered['from_email'] ?? '', FILTER_VALIDATE_EMAIL) ? $rendered['from_email'] : $defaultFromAddress;
                $fromName    = !empty($rendered['from_name']) ? $rendered['from_name'] : $defaultFromName;

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
