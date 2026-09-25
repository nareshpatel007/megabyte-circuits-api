<?php

namespace App\Services;

use App\Models\PasswordResetOtp;
use App\Models\User;
use App\Models\Admin;
use App\Services\EmailTemplateService;
use App\Services\CredentialService;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Config;

class PasswordResetService
{
    const OTP_EXPIRATION_MINUTES = 10;
    const OTP_LENGTH = 6;
    const OTP_MAX_ATTEMPTS = 5;
    const OTP_RESEND_COOLDOWN_SECONDS = 60;
    const RESET_TOKEN_EXPIRATION_MINUTES = 15;

    /**
     * Request a password reset OTP for client or admin.
     */
    public static function requestOtp(string $identifier, string $type = 'client', ?string $ip = null, ?string $userAgent = null): array
    {
        $identifier = trim($identifier);

        if (empty($identifier)) {
            return [
                'success' => false,
                'message' => 'Please enter your email address or username.'
            ];
        }

        // Look up recipient
        $user = null;
        $admin = null;
        $email = null;
        $name = null;

        if ($type === 'admin') {
            $adminQuery = DB::table('admins')
                ->where('email', $identifier)
                ->orWhere('username', $identifier);

            if (DB::getSchemaBuilder()->hasColumn('admins', 'mobile')) {
                $adminQuery->orWhere('mobile', $identifier);
            }

            $admin = $adminQuery->first();
            if ($admin) {
                $email = $admin->email;
                $name = $admin->name ?? strtok($admin->email, '@');
            }
        } else {
            // Client
            $user = DB::table('users')
                ->where('email', $identifier)
                ->orWhere('name', $identifier)
                ->first();

            if ($user) {
                $email = $user->email;
                $name = $user->name ?? trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
                if (empty($name)) {
                    $name = strtok($user->email, '@');
                }
            }
        }

        // Generic account enumeration protection
        $genericMessage = 'If an account exists with the provided information, a verification code has been sent.';

        if (!$email) {
            return [
                'success' => true,
                'message' => $genericMessage,
                'masked_email' => null
            ];
        }

        // Check account status if applicable
        $accountStatus = $type === 'admin' ? ($admin->status ?? 'active') : ($user->status ?? 'active');
        if (strtolower($accountStatus) !== 'active') {
            return [
                'success' => false,
                'message' => 'Your account is currently inactive. Please contact support.'
            ];
        }

        // Check resend cooldown
        $existingQuery = PasswordResetOtp::where('identifier', $identifier);
        if ($type === 'admin') {
            $existingQuery->where('admin_id', $admin->id);
        } else {
            $existingQuery->where('user_id', $user->id);
        }
        $latestOtp = $existingQuery->orderBy('created_at', 'desc')->first();

        if ($latestOtp && $latestOtp->last_sent_at) {
            $secondsSinceSent = now()->diffInSeconds($latestOtp->last_sent_at);
            if ($secondsSinceSent < self::OTP_RESEND_COOLDOWN_SECONDS) {
                $remaining = self::OTP_RESEND_COOLDOWN_SECONDS - $secondsSinceSent;
                return [
                    'success' => false,
                    'message' => "Please wait {$remaining} seconds before requesting a new verification code.",
                    'cooldown_remaining' => $remaining,
                    'masked_email' => self::maskEmail($email)
                ];
            }
        }

        // Invalidate prior active OTPs
        $invalidateQuery = PasswordResetOtp::where('identifier', $identifier);
        if ($type === 'admin') {
            $invalidateQuery->where('admin_id', $admin->id);
        } else {
            $invalidateQuery->where('user_id', $user->id);
        }
        $invalidateQuery->whereNull('used_at')->update(['used_at' => now()]);

        // Generate 6-digit cryptographically secure OTP
        $otp = (string) random_int(100000, 999999);
        $otpHash = hash('sha256', $otp);

        // Store OTP entry
        $otpRecord = PasswordResetOtp::create([
            'user_id' => $type === 'client' ? $user->id : null,
            'admin_id' => $type === 'admin' ? $admin->id : null,
            'identifier' => $identifier,
            'otp_hash' => $otpHash,
            'expires_at' => now()->addMinutes(self::OTP_EXPIRATION_MINUTES),
            'last_sent_at' => now(),
            'attempts' => 0,
            'max_attempts' => self::OTP_MAX_ATTEMPTS,
            'ip_address' => $ip,
            'user_agent' => substr((string)$userAgent, 0, 500),
        ]);

        // Render & Send Email
        self::sendOtpEmail($email, $name, $otp);

        // Record event in notification service
        NotificationService::dispatch('password_reset_requested', [
            'title' => 'Password Reset Requested',
            'message' => "Password reset requested for {$email}",
            'category' => 'system',
            'theme' => 'info',
            'entity_type' => 'password_reset_otp',
            'entity_id' => $otpRecord->id,
        ], $type === 'client' ? $user->id : $admin->id, $type);

        return [
            'success' => true,
            'message' => $genericMessage,
            'masked_email' => self::maskEmail($email)
        ];
    }

    /**
     * Verify submitted 6-digit OTP code.
     */
    public static function verifyOtp(string $identifier, string $otp, string $type = 'client'): array
    {
        $identifier = trim($identifier);
        $otp = trim($otp);

        if (empty($identifier) || empty($otp)) {
            return [
                'success' => false,
                'message' => 'Identifier and verification code are required.'
            ];
        }

        if (!preg_match('/^\d{6}$/', $otp)) {
            return [
                'success' => false,
                'message' => 'Verification code must be exactly 6 digits.'
            ];
        }

        // Find active OTP record
        $otpRecord = PasswordResetOtp::where(function ($q) use ($identifier) {
            $q->where('identifier', $identifier);
            $q->orWhereHas('user', function ($uq) use ($identifier) {
                $uq->where('email', $identifier)->orWhere('name', $identifier);
            });
            $q->orWhereHas('admin', function ($aq) use ($identifier) {
                $aq->where('email', $identifier)->orWhere('username', $identifier);
            });
        })
        ->whereNull('used_at')
        ->where('expires_at', '>=', now())
        ->orderBy('created_at', 'desc')
        ->first();

        if (!$otpRecord) {
            return [
                'success' => false,
                'message' => 'Verification code is invalid or has expired. Please request a new OTP.'
            ];
        }

        // Check attempt limit
        if ($otpRecord->attempts >= $otpRecord->max_attempts) {
            $otpRecord->update(['used_at' => now()]);
            return [
                'success' => false,
                'message' => 'Too many failed attempts. This verification code is locked. Please request a new OTP.'
            ];
        }

        // Verify OTP Hash
        $submittedHash = hash('sha256', $otp);

        if (!hash_equals($otpRecord->otp_hash, $submittedHash)) {
            $otpRecord->increment('attempts');
            $remaining = $otpRecord->max_attempts - $otpRecord->attempts;

            if ($remaining <= 0) {
                $otpRecord->update(['used_at' => now()]);
                return [
                    'success' => false,
                    'message' => 'Too many failed attempts. Please request a new OTP.'
                ];
            }

            return [
                'success' => false,
                'message' => "Invalid verification code. You have {$remaining} attempt(s) remaining."
            ];
        }

        // OTP Validated Successfully! Generate temporary reset token
        $resetToken = bin2hex(random_bytes(32));
        $resetTokenHash = hash('sha256', $resetToken);

        $otpRecord->update([
            'verified_at' => now(),
            'reset_token_hash' => $resetTokenHash,
            'reset_token_expires_at' => now()->addMinutes(self::RESET_TOKEN_EXPIRATION_MINUTES),
        ]);

        NotificationService::dispatch('password_reset_otp_verified', [
            'title' => 'OTP Verified',
            'message' => "OTP code verified successfully for password reset.",
            'category' => 'system',
            'theme' => 'success',
            'entity_type' => 'password_reset_otp',
            'entity_id' => $otpRecord->id,
        ], $otpRecord->user_id ?: $otpRecord->admin_id, $type);

        return [
            'success' => true,
            'message' => 'Verification code verified successfully.',
            'reset_token' => $resetToken,
        ];
    }

    /**
     * Update password using validated server-side reset token.
     */
    public static function resetPassword(string $resetToken, string $newPassword, string $passwordConfirmation, string $type = 'client'): array
    {
        $resetToken = trim($resetToken);

        if (empty($resetToken)) {
            return [
                'success' => false,
                'message' => 'Reset token is required.'
            ];
        }

        if (empty($newPassword)) {
            return [
                'success' => false,
                'message' => 'New password is required.'
            ];
        }

        if ($newPassword !== $passwordConfirmation) {
            return [
                'success' => false,
                'message' => 'Password and confirmation password do not match.'
            ];
        }

        // Password complexity check
        if (strlen($newPassword) < 8) {
            return [
                'success' => false,
                'message' => 'Password must be at least 8 characters long.'
            ];
        }

        if (!preg_match('/[A-Z]/', $newPassword) || !preg_match('/[a-z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
            return [
                'success' => false,
                'message' => 'Password must contain at least one uppercase letter, one lowercase letter, and one number.'
            ];
        }

        // Validate Token
        $resetTokenHash = hash('sha256', $resetToken);

        $otpRecord = PasswordResetOtp::where('reset_token_hash', $resetTokenHash)
            ->whereNotNull('verified_at')
            ->whereNull('used_at')
            ->where('reset_token_expires_at', '>=', now())
            ->first();

        if (!$otpRecord) {
            return [
                'success' => false,
                'message' => 'Invalid or expired password reset session. Please request a new OTP.'
            ];
        }

        // Update target password
        DB::beginTransaction();
        try {
            $email = null;
            $name = null;

            if ($otpRecord->admin_id || $type === 'admin') {
                $adminId = $otpRecord->admin_id;
                $admin = DB::table('admins')->where('id', $adminId)->first();
                if (!$admin) {
                    throw new \Exception("Admin account not found.");
                }

                $email = $admin->email;
                $name = $admin->name ?? strtok($admin->email, '@');

                DB::table('admins')->where('id', $adminId)->update([
                    'password_hash' => password_hash($newPassword, PASSWORD_BCRYPT),
                    'updated_at' => now(),
                ]);
            } else {
                $userId = $otpRecord->user_id;
                $user = DB::table('users')->where('id', $userId)->first();
                if (!$user) {
                    throw new \Exception("Client account not found.");
                }

                $email = $user->email;
                $name = $user->name ?? trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
                if (empty($name)) {
                    $name = strtok($user->email, '@');
                }

                $updateData = [
                    'password_hash' => password_hash($newPassword, PASSWORD_BCRYPT),
                    'password' => md5($newPassword),
                    'updated_at' => now(),
                ];

                DB::table('users')->where('id', $userId)->update($updateData);

                if (DB::getSchemaBuilder()->hasTable('pcb_users')) {
                    DB::table('pcb_users')->where('id', $userId)->update(['updated_at' => now()]);
                }
            }

            // Invalidate OTP & reset token
            $otpRecord->update([
                'used_at' => now(),
                'reset_token_hash' => null,
            ]);

            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error("Password reset error: " . $th->getMessage());
            return [
                'success' => false,
                'message' => 'An error occurred while updating password: ' . $th->getMessage()
            ];
        }

        // Send confirmation email
        if ($email) {
            self::sendPasswordResetSuccessEmail($email, $name);
        }

        // Audit Log Notification
        NotificationService::dispatch('password_changed', [
            'title' => 'Password Changed Successfully',
            'message' => "Your password was updated successfully.",
            'category' => 'system',
            'theme' => 'success',
            'entity_type' => 'user',
            'entity_id' => $otpRecord->user_id ?: $otpRecord->admin_id,
        ], $otpRecord->user_id ?: $otpRecord->admin_id, $type);

        return [
            'success' => true,
            'message' => 'Password updated successfully. You can now sign in with your new password.'
        ];
    }

    /**
     * Send OTP email using database template or fallback.
     */

    private static function sendOtpEmail(string $email, string $name, string $otp): void
    {
        try {
            $rendered = EmailTemplateService::render('password_reset_otp', null, [
                'name' => $name,
                'email' => $email,
                'otp' => $otp,
                'otp_expiry_minutes' => self::OTP_EXPIRATION_MINUTES,
            ]);

            if ($rendered['success']) {
                self::dispatchSmtpMail($email, $rendered['subject'], $rendered['body'], $rendered['from_email'], $rendered['from_name']);
            } else {
                // Fallback email content if template is disabled or missing
                $appName = config('app.name', 'Megabyte Circuits');
                $subject = "Your {$appName} password reset verification code";
                $body = "<div style='font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;border:1px solid #e5e7eb;border-radius:8px;'>
                    <h2 style='color:#10b981;'>Password Reset Code</h2>
                    <p>Hello <strong>" . htmlspecialchars($name) . "</strong>,</p>
                    <p>Your verification code is:</p>
                    <div style='background:#f3f4f6;padding:16px;text-align:center;font-size:28px;font-weight:bold;letter-spacing:4px;color:#047857;border-radius:6px;'>" . htmlspecialchars($otp) . "</div>
                    <p>This code will expire in " . self::OTP_EXPIRATION_MINUTES . " minutes.</p>
                </div>";

                self::dispatchSmtpMail($email, $subject, $body);
            }
        } catch (\Throwable $th) {
            Log::error("Failed to send OTP email to {$email}: " . $th->getMessage());
        }
    }

    /**
     * Send password reset success confirmation email.
     */
    private static function sendPasswordResetSuccessEmail(string $email, string $name): void
    {
        try {
            $rendered = EmailTemplateService::render('password_reset_success', null, [
                'name' => $name,
                'email' => $email,
            ]);

            if ($rendered['success']) {
                self::dispatchSmtpMail($email, $rendered['subject'], $rendered['body'], $rendered['from_email'], $rendered['from_name']);
            } else {
                $appName = config('app.name', 'Megabyte Circuits');
                $subject = "Your {$appName} password has been updated";
                $body = "<div style='font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;border:1px solid #e5e7eb;border-radius:8px;'>
                    <h2 style='color:#059669;'>Password Updated</h2>
                    <p>Hello <strong>" . htmlspecialchars($name) . "</strong>,</p>
                    <p>Your password was updated successfully.</p>
                </div>";

                self::dispatchSmtpMail($email, $subject, $body);
            }
        } catch (\Throwable $th) {
            Log::error("Failed to send password reset success email to {$email}: " . $th->getMessage());
        }
    }

    /**
     * Helper to dispatch mail with SMTP credentials configured in CredentialService.
     */
    private static function dispatchSmtpMail(string $toEmail, string $subject, string $htmlBody, ?string $fromEmail = null, ?string $fromName = null): void
    {
        $defaultFromAddress = CredentialService::get('mail', 'MAIL_GLOBAL_FROM_ADDRESS', 'MAIL_GLOBAL_FROM_ADDRESS', config('mail.from.address', 'quote@megabytecircuit.com'));
        $defaultFromName = CredentialService::get('mail', 'MAIL_GLOBAL_FROM_NAME', 'MAIL_GLOBAL_FROM_NAME', config('app.name', 'Megabyte Circuit'));

        $fromAddress = filter_var($fromEmail, FILTER_VALIDATE_EMAIL) ? $fromEmail : $defaultFromAddress;
        $fromNameStr = !empty($fromName) ? $fromName : $defaultFromName;

        Config::set('mail.mailers.smtp_global.host', CredentialService::get('mail', 'MAIL_GLOBAL_HOST', 'MAIL_GLOBAL_HOST', config('mail.mailers.smtp.host')));
        Config::set('mail.mailers.smtp_global.port', CredentialService::get('mail', 'MAIL_GLOBAL_PORT', 'MAIL_GLOBAL_PORT', config('mail.mailers.smtp.port', 587)));
        Config::set('mail.mailers.smtp_global.username', CredentialService::get('mail', 'MAIL_GLOBAL_USERNAME', 'MAIL_GLOBAL_USERNAME', config('mail.mailers.smtp.username')));
        Config::set('mail.mailers.smtp_global.password', CredentialService::get('mail', 'MAIL_GLOBAL_PASSWORD', 'MAIL_GLOBAL_PASSWORD', config('mail.mailers.smtp.password')));

        Mail::mailer('smtp_global')->send([], [], function ($message) use ($toEmail, $fromAddress, $fromNameStr, $subject, $htmlBody) {
            $message->to($toEmail)
                ->from($fromAddress, $fromNameStr)
                ->subject($subject)
                ->html($htmlBody);
        });
    }

    /**
     * Mask email address for privacy. (e.g. h***e@example.com)
     */
    public static function maskEmail(string $email): string
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $email;
        }

        list($user, $domain) = explode('@', $email);
        $len = strlen($user);

        if ($len <= 2) {
            $maskedUser = substr($user, 0, 1) . '*';
        } else {
            $maskedUser = substr($user, 0, 1) . str_repeat('*', min($len - 2, 5)) . substr($user, -1);
        }

        return $maskedUser . '@' . $domain;
    }
}
