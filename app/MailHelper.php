<?php

namespace App;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Config;
use App\Mail\SendMail;
use App\Models\EmailLog;
use App\Services\CredentialService;
use Carbon\Carbon;

class MailHelper
{
    public static function send_email($email_to, $data)
    {
        $fromAddress = CredentialService::get('mail', 'MAIL_GLOBAL_FROM_ADDRESS', 'MAIL_GLOBAL_FROM_ADDRESS', config('mail.from.address', 'quote@megabytecircuit.com'));
        $fromName    = CredentialService::get('mail', 'MAIL_GLOBAL_FROM_NAME', 'MAIL_GLOBAL_FROM_NAME', config('app.name', 'Megabyte Circuit'));
        $loggingEnabled = filter_var(CredentialService::get('email_logs', 'logging_enabled', 'EMAIL_LOGGING_ENABLED', '1'), FILTER_VALIDATE_BOOLEAN);

        try {
            // Validation
            if (empty($email_to) || empty($data)) {
                return [
                    'success' => false,
                    'message' => 'Validation failed: Destination email or payload data is empty.'
                ];
            }

            if (is_string($email_to) && \App\Services\EmailTemplateService::isImportLocalEmail($email_to)) {
                \Illuminate\Support\Facades\Log::info("MailHelper: Skipped sending email to placeholder email {$email_to}.");
                if ($loggingEnabled && class_exists(EmailLog::class)) {
                    try {
                        EmailLog::create([
                            'template_key' => $data['template'] ?? 'general',
                            'email_type'   => 'Direct Email',
                            'from_email'   => $fromAddress,
                            'from_name'    => $fromName,
                            'to'           => (string)$email_to,
                            'subject'      => $data['subject'] ?? 'Notification',
                            'status'       => 'skipped',
                            'error_message'=> 'Email skipped: recipient email is an @import.local placeholder email.',
                            'provider'     => 'smtp_global',
                        ]);
                    } catch (\Throwable $logEx) {}
                }
                return [
                    'success' => false,
                    'message' => 'Email skipped: recipient email is an @import.local placeholder email.'
                ];
            }

            // Configure mailer using CredentialService (database priority with .env fallback)
            $mailer = 'smtp_global';
            Config::set('mail.mailers.smtp_global.host', CredentialService::get('mail', 'MAIL_GLOBAL_HOST', 'MAIL_GLOBAL_HOST', config('mail.mailers.smtp.host')));
            Config::set('mail.mailers.smtp_global.port', CredentialService::get('mail', 'MAIL_GLOBAL_PORT', 'MAIL_GLOBAL_PORT', config('mail.mailers.smtp.port', 587)));
            Config::set('mail.mailers.smtp_global.username', CredentialService::get('mail', 'MAIL_GLOBAL_USERNAME', 'MAIL_GLOBAL_USERNAME', config('mail.mailers.smtp.username')));
            Config::set('mail.mailers.smtp_global.password', CredentialService::get('mail', 'MAIL_GLOBAL_PASSWORD', 'MAIL_GLOBAL_PASSWORD', config('mail.mailers.smtp.password')));
            Config::set('mail.from.address', $fromAddress);
            Config::set('mail.from.name', $fromName);

            // Send email using selected mailer
            Mail::mailer($mailer)->to($email_to)->send(new SendMail($data));

            // Log email send success if logging enabled
            if ($loggingEnabled && class_exists(EmailLog::class)) {
                try {
                    EmailLog::create([
                        'template_key' => $data['template'] ?? 'general',
                        'email_type'   => 'Direct Email',
                        'from_email'   => $fromAddress,
                        'from_name'    => $fromName,
                        'to'           => is_array($email_to) ? implode(', ', $email_to) : (string)$email_to,
                        'subject'      => $data['subject'] ?? 'Notification',
                        'status'       => 'sent',
                        'provider'     => 'smtp_global',
                        'sent_at'      => Carbon::now(),
                    ]);
                } catch (\Throwable $logEx) {
                    // Prevent logging failure from breaking caller
                }
            }

            // Return success
            return [
                'success' => true,
                'message' => 'Email sent successfully.'
            ];
        } catch (\Throwable $th) {
            if ($loggingEnabled && class_exists(EmailLog::class)) {
                try {
                    EmailLog::create([
                        'template_key'  => $data['template'] ?? 'general',
                        'email_type'    => 'Direct Email',
                        'from_email'    => $fromAddress,
                        'from_name'     => $fromName,
                        'to'            => is_array($email_to) ? implode(', ', $email_to) : (string)$email_to,
                        'subject'       => $data['subject'] ?? 'Notification',
                        'status'        => 'failed',
                        'provider'      => 'smtp_global',
                        'error_message' => $th->getMessage(),
                        'failed_at'     => Carbon::now(),
                    ]);
                } catch (\Throwable $logEx) {}
            }

            // Return failure details
            return [
                'success' => false,
                'message' => $th->getMessage()
            ];
        }
    }
}