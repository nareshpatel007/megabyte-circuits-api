<?php

namespace App;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Config;
use App\Mail\SendMail;
use DB;

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

            // Configure mailer using global environment variables
            $mailer = 'smtp_global';
            Config::set('mail.mailers.smtp_global.host', env('MAIL_GLOBAL_HOST'));
            Config::set('mail.mailers.smtp_global.port', env('MAIL_GLOBAL_PORT'));
            Config::set('mail.mailers.smtp_global.username', env('MAIL_GLOBAL_USERNAME'));
            Config::set('mail.mailers.smtp_global.password', env('MAIL_GLOBAL_PASSWORD'));
            Config::set('mail.from.address', env('MAIL_GLOBAL_FROM_ADDRESS'));
            Config::set('mail.from.name', env('MAIL_GLOBAL_FROM_NAME'));

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