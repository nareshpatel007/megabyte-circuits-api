<?php

namespace App;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Config;
use App\Mail\SendMail;

class MailHelper
{
    public static function send_email($email_to, $data)
    {
        try {
            // Validation
            if (empty($email_to) || empty($data)) {
                return [
                    'success' => false,
                    'message' => 'Validation failed: Destination email or payload data is empty.'
                ];
            }

            // Configure mailer using CredentialService (database priority with .env fallback)
            $mailer = 'smtp_global';
            Config::set('mail.mailers.smtp_global.host', \App\Services\CredentialService::get('mail', 'MAIL_GLOBAL_HOST', 'MAIL_GLOBAL_HOST', config('mail.mailers.smtp.host')));
            Config::set('mail.mailers.smtp_global.port', \App\Services\CredentialService::get('mail', 'MAIL_GLOBAL_PORT', 'MAIL_GLOBAL_PORT', config('mail.mailers.smtp.port', 587)));
            Config::set('mail.mailers.smtp_global.username', \App\Services\CredentialService::get('mail', 'MAIL_GLOBAL_USERNAME', 'MAIL_GLOBAL_USERNAME', config('mail.mailers.smtp.username')));
            Config::set('mail.mailers.smtp_global.password', \App\Services\CredentialService::get('mail', 'MAIL_GLOBAL_PASSWORD', 'MAIL_GLOBAL_PASSWORD', config('mail.mailers.smtp.password')));
            Config::set('mail.from.address', \App\Services\CredentialService::get('mail', 'MAIL_GLOBAL_FROM_ADDRESS', 'MAIL_GLOBAL_FROM_ADDRESS', config('mail.from.address')));
            Config::set('mail.from.name', \App\Services\CredentialService::get('mail', 'MAIL_GLOBAL_FROM_NAME', 'MAIL_GLOBAL_FROM_NAME', config('mail.from.name')));

            // Send email using selected mailer
            Mail::mailer($mailer)->to($email_to)->send(new SendMail($data));

            // Return success
            return [
                'success' => true,
                'message' => 'Email sent successfully.'
            ];
        } catch (\Throwable $th) {
            // Return failure details
            return [
                'success' => false,
                'message' => $th->getMessage()
            ];
        }
    }
}