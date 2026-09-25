<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use App\Models\PendingRegistration;
use App\Models\EmailLog;
use App\Services\EmailTemplateService;
use App\Services\CredentialService;
use Carbon\Carbon;

class SendClientSignupOtpEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = [10, 60, 300];

    protected int $pendingRegistrationId;
    protected string $otp;

    /**
     * Create a new job instance.
     */
    public function __construct(int $pendingRegistrationId, string $otp)
    {
        $this->pendingRegistrationId = $pendingRegistrationId;
        $this->otp = $otp;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $pending = PendingRegistration::find($this->pendingRegistrationId);

        if (!$pending) {
            Log::warning("SendClientSignupOtpEmailJob: Pending registration #{$this->pendingRegistrationId} not found.");
            return;
        }

        self::sendOtp($pending, $this->otp);
    }

    /**
     * Static method for sending OTP email directly (synchronously or fallback).
     */
    public static function sendOtp(PendingRegistration $pending, string $otp): bool
    {
        try {
            $appName = CredentialService::get('company', 'COMPANY_NAME', 'COMPANY_NAME', config('app.name', 'Megabyte Circuits'));

            $customVars = [
                'name'               => $pending->name,
                'email'              => $pending->email,
                'otp'                => $otp,
                'otp_expiry_minutes' => 10,
                'app_name'           => $appName,
            ];

            $rendered = EmailTemplateService::render('client_signup_otp', null, $customVars);

            $toEmail = $pending->email;
            $defaultFromAddress = CredentialService::get('mail', 'MAIL_GLOBAL_FROM_ADDRESS', 'MAIL_GLOBAL_FROM_ADDRESS', config('mail.from.address', 'quote@megabytecircuit.com'));
            $defaultFromName    = CredentialService::get('mail', 'MAIL_GLOBAL_FROM_NAME', 'MAIL_GLOBAL_FROM_NAME', config('app.name', 'Megabyte Circuit'));

            $fromEmail = !empty($rendered['from_email']) && filter_var($rendered['from_email'], FILTER_VALIDATE_EMAIL)
                ? $rendered['from_email']
                : $defaultFromAddress;
            $fromName  = !empty($rendered['from_name']) ? $rendered['from_name'] : $defaultFromName;

            // Configure SMTP dynamically via CredentialService
            Config::set('mail.mailers.smtp_global.transport', 'smtp');
            Config::set('mail.mailers.smtp_global.host', CredentialService::get('mail', 'MAIL_GLOBAL_HOST', 'MAIL_GLOBAL_HOST', config('mail.mailers.smtp.host', 'smtp.gmail.com')));
            Config::set('mail.mailers.smtp_global.port', CredentialService::get('mail', 'MAIL_GLOBAL_PORT', 'MAIL_GLOBAL_PORT', config('mail.mailers.smtp.port', 587)));
            Config::set('mail.mailers.smtp_global.username', CredentialService::get('mail', 'MAIL_GLOBAL_USERNAME', 'MAIL_GLOBAL_USERNAME', config('mail.mailers.smtp.username')));
            Config::set('mail.mailers.smtp_global.password', CredentialService::get('mail', 'MAIL_GLOBAL_PASSWORD', 'MAIL_GLOBAL_PASSWORD', config('mail.mailers.smtp.password')));
            Config::set('mail.mailers.smtp_global.encryption', CredentialService::get('mail', 'MAIL_GLOBAL_ENCRYPTION', 'MAIL_GLOBAL_ENCRYPTION', config('mail.mailers.smtp.encryption', 'tls')));
            Config::set('mail.from.address', $fromEmail);
            Config::set('mail.from.name', $fromName);

            Mail::mailer('smtp_global')->send([], [], function ($message) use ($toEmail, $fromEmail, $fromName, $rendered) {
                $message->to($toEmail)
                    ->from($fromEmail, $fromName)
                    ->subject($rendered['subject'] ?? 'Verify your Megabyte account')
                    ->html($rendered['body']);
            });

            // Log entry in email_logs
            try {
                EmailLog::create([
                    'template_key' => 'client_signup_otp',
                    'email_type'   => 'signup_otp',
                    'from_email'   => $fromEmail,
                    'from_name'    => $fromName,
                    'to'           => $toEmail,
                    'subject'      => $rendered['subject'] ?? 'Verify your Megabyte account',
                    'body'         => $rendered['body'] ?? null,
                    'status'       => 'sent',
                    'provider'     => 'smtp_global',
                    'sent_at'      => Carbon::now(),
                    'is_test'      => false,
                ]);
            } catch (\Throwable $logEx) {}

            Log::info("SendClientSignupOtpEmailJob: OTP email sent to {$toEmail}");
            return true;
        } catch (\Throwable $th) {
            Log::error("SendClientSignupOtpEmailJob error sending to {$pending->email}: " . $th->getMessage());
            try {
                EmailLog::create([
                    'template_key'  => 'client_signup_otp',
                    'email_type'    => 'signup_otp',
                    'from_email'    => $fromEmail ?? config('mail.from.address', 'quote@megabytecircuit.com'),
                    'from_name'     => $fromName ?? config('app.name', 'Megabyte Circuit'),
                    'to'            => $pending->email,
                    'subject'       => 'Verify your Megabyte account',
                    'status'        => 'failed',
                    'failed_at'     => Carbon::now(),
                    'error_message' => $th->getMessage(),
                    'provider'      => 'smtp_global',
                    'is_test'       => false,
                ]);
            } catch (\Throwable $logEx) {}
            return false;
        }
    }
}
