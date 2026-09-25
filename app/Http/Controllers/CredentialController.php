<?php

namespace App\Http\Controllers;

use App\Models\Credential;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class CredentialController extends Controller
{
    /**
     * Get all credentials grouped by service with values masked.
     */
    public function index()
    {
        $allCredentials = Credential::all();

        $grouped = [];

        foreach ($allCredentials as $cred) {
            $decrypted = $cred->decrypted_value;
            $grouped[$cred->group][$cred->key] = [
                'masked' => $decrypted,
                'is_set' => !empty($decrypted),
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $grouped
        ]);
    }

    /**
     * Update credentials for a group or bulk updates.
     */
    public function update(Request $request)
    {
        $data = $request->json()->all();

        // Data expected format: { "razorpay": { "RAZORPAY_TEST_KEY_ID": "...", ... }, "jlcpcb": { ... } }
        // OR simple flat key-value pairs: { "RAZORPAY_TEST_KEY_ID": "...", "group": "razorpay" }

        if (empty($data)) {
            return response()->json(['success' => false, 'message' => 'No data provided'], 400);
        }

        $updatedKeys = [];

        foreach ($data as $groupOrKey => $payload) {
            if (is_array($payload)) {
                $group = $groupOrKey;
                foreach ($payload as $key => $value) {
                    $this->updateSingleCredential($group, $key, $value);
                    $updatedKeys[] = $key;
                }
            } else {
                // Flat format: group field required in payload or request
                $group = $request->input('group', 'general');
                $key = $groupOrKey;
                if ($key !== 'group') {
                    $this->updateSingleCredential($group, $key, $payload);
                    $updatedKeys[] = $key;
                }
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Credentials updated successfully',
            'updated' => $updatedKeys
        ]);
    }

    private function updateSingleCredential(string $group, string $key, ?string $value)
    {
        if ($value === null) {
            return;
        }

        // If the value provided matches the masked pattern, it means user didn't change it -> skip update
        if (Credential::isMasked($value)) {
            return;
        }

        $credential = Credential::firstOrNew(['key' => $key]);
        $credential->group = $group;

        if (trim($value) === '') {
            $credential->value = null;
        } else {
            $credential->setEncryptedValue(trim($value));
        }

        $credential->save();

        if ($key === 'GST_PERCENTAGE' && \Illuminate\Support\Facades\Schema::hasTable('pcb_pricing_settings')) {
            \App\Models\PcbPricingSetting::updateOrCreate(
                ['key' => 'gst_percentage'],
                ['value' => ['percentage' => (float)$value], 'description' => 'GST Percentage for PCB calculations']
            );
        }

        if ($key === 'JLCPCB_MARGIN' && \Illuminate\Support\Facades\Schema::hasTable('pcb_pricing_settings')) {
            \App\Models\PcbPricingSetting::updateOrCreate(
                ['key' => 'jlcpcb_margin'],
                ['value' => ['margin' => (float)$value], 'description' => 'JLCPCB Admin Margin Percentage']
            );
        }
    }

    /**
     * Test SMTP configuration using form-provided or stored credentials.
     */
    public function testSmtp(Request $request)
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'recipient_email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Please provide a valid test recipient email address.',
                'errors'  => $validator->errors()
            ], 422);
        }

        $recipientEmail = trim($request->input('recipient_email'));

        // Extract credentials from request or fallback to DB/env via CredentialService
        $host = trim((string)($request->input('MAIL_HOST') ?: \App\Services\CredentialService::get('smtp', 'MAIL_HOST', 'MAIL_HOST', config('mail.mailers.smtp.host', 'smtp.gmail.com'))));
        $port = trim((string)($request->input('MAIL_PORT') ?: \App\Services\CredentialService::get('smtp', 'MAIL_PORT', 'MAIL_PORT', config('mail.mailers.smtp.port', 587))));
        $username = trim((string)($request->input('MAIL_USERNAME') ?: \App\Services\CredentialService::get('smtp', 'MAIL_USERNAME', 'MAIL_USERNAME', config('mail.mailers.smtp.username'))));

        $rawPassword = $request->input('MAIL_PASSWORD');
        if ($rawPassword === null || $rawPassword === '' || Credential::isMasked((string)$rawPassword)) {
            $password = (string)\App\Services\CredentialService::get('smtp', 'MAIL_PASSWORD', 'MAIL_PASSWORD', config('mail.mailers.smtp.password'));
        } else {
            $password = (string)$rawPassword;
        }

        $rawEncryption = $request->input('MAIL_ENCRYPTION');
        if ($rawEncryption === null || trim((string)$rawEncryption) === '') {
            $encryption = \App\Services\CredentialService::get('smtp', 'MAIL_ENCRYPTION', 'MAIL_ENCRYPTION', ($port === '465' ? 'ssl' : 'tls'));
        } else {
            $encryption = trim((string)$rawEncryption);
        }

        $fromAddress = trim((string)($request->input('MAIL_FROM_ADDRESS') ?: \App\Services\CredentialService::get('smtp', 'MAIL_FROM_ADDRESS', 'MAIL_FROM_ADDRESS', config('mail.from.address', 'quote@megabytecircuit.com'))));
        $fromName = trim((string)($request->input('MAIL_FROM_NAME') ?: \App\Services\CredentialService::get('smtp', 'MAIL_FROM_NAME', 'MAIL_FROM_NAME', config('mail.from.name', 'Megabyte Circuit'))));

        if (empty($host) || empty($port) || empty($username) || empty($password)) {
            return response()->json([
                'success' => false,
                'message' => 'Incomplete SMTP configuration. Please ensure SMTP Host, Port, Username, and Password are provided.'
            ], 422);
        }

        $normalizedEnc = strtolower($encryption);
        if ($normalizedEnc === 'null' || $normalizedEnc === 'none' || $normalizedEnc === 'off') {
            $normalizedEnc = null;
        }

        // Configure dynamic test mailer instance
        \Illuminate\Support\Facades\Config::set('mail.mailers.test_smtp_dynamic', [
            'transport'  => 'smtp',
            'host'       => $host,
            'port'       => (int)$port,
            'encryption' => $normalizedEnc,
            'username'   => $username,
            'password'   => $password,
            'timeout'    => 15,
        ]);
        \Illuminate\Support\Facades\Config::set('mail.from.address', $fromAddress);
        \Illuminate\Support\Facades\Config::set('mail.from.name', $fromName);

        try {
            $subject = 'SMTP Configuration Test - Megabyte Circuit';
            $timestamp = now()->format('Y-m-d H:i:s T');
            $htmlContent = "
            <div style=\"font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 24px; border: 1px solid #e2e8f0; border-radius: 12px; background-color: #ffffff;\">
                <div style=\"background: linear-gradient(135deg, #10b981 0%, #059669 100%); padding: 20px; border-radius: 8px; text-align: center;\">
                    <h2 style=\"color: #ffffff; margin: 0; font-size: 22px; font-weight: 700;\">SMTP Connection Successful!</h2>
                </div>
                <div style=\"padding: 24px 8px; color: #334155; line-height: 1.6;\">
                    <p style=\"font-size: 15px; margin-top: 0;\">Hello,</p>
                    <p style=\"font-size: 14px;\">This test email verifies that your <strong>SMTP / Email Server configuration</strong> on Megabyte Circuit Admin Panel is working properly.</p>
                    
                    <div style=\"background-color: #f8fafc; border: 1px solid #cbd5e1; border-left: 4px solid #10b981; padding: 16px; margin: 24px 0; border-radius: 6px;\">
                        <p style=\"margin: 0 0 12px 0; font-weight: 700; font-size: 14px; color: #0f172a;\">Tested SMTP Credentials:</p>
                        <table style=\"width: 100%; font-size: 13px; border-collapse: collapse;\">
                            <tr><td style=\"padding: 4px 0; color: #64748b; width: 130px;\"><strong>SMTP Host:</strong></td><td style=\"color: #0f172a;\">{$host}</td></tr>
                            <tr><td style=\"padding: 4px 0; color: #64748b;\"><strong>Port:</strong></td><td style=\"color: #0f172a;\">{$port}</td></tr>
                            <tr><td style=\"padding: 4px 0; color: #64748b;\"><strong>Username:</strong></td><td style=\"color: #0f172a;\">{$username}</td></tr>
                            <tr><td style=\"padding: 4px 0; color: #64748b;\"><strong>Encryption:</strong></td><td style=\"color: #0f172a;\">" . ($encryption ?: 'None') . "</td></tr>
                            <tr><td style=\"padding: 4px 0; color: #64748b;\"><strong>From Address:</strong></td><td style=\"color: #0f172a;\">{$fromAddress}</td></tr>
                            <tr><td style=\"padding: 4px 0; color: #64748b;\"><strong>From Name:</strong></td><td style=\"color: #0f172a;\">{$fromName}</td></tr>
                            <tr><td style=\"padding: 4px 0; color: #64748b;\"><strong>Timestamp:</strong></td><td style=\"color: #0f172a;\">{$timestamp}</td></tr>
                        </table>
                    </div>

                    <p style=\"font-size: 13px; color: #64748b; margin-bottom: 0;\">If you received this message, your email server is ready to handle transactional notifications and emails.</p>
                </div>
                <div style=\"border-top: 1px solid #e2e8f0; padding-top: 16px; text-align: center; font-size: 12px; color: #94a3b8;\">
                    &copy; " . date('Y') . " Megabyte Circuit. All rights reserved.
                </div>
            </div>
            ";

            \Illuminate\Support\Facades\Mail::mailer('test_smtp_dynamic')
                ->send([], [], function ($message) use ($recipientEmail, $fromAddress, $fromName, $subject, $htmlContent) {
                    $message->to($recipientEmail)
                        ->from($fromAddress, $fromName)
                        ->subject($subject)
                        ->html($htmlContent);
                });

            // Record log if EmailLog model exists
            if (class_exists(\App\Models\EmailLog::class)) {
                try {
                    \App\Models\EmailLog::create([
                        'template_key' => 'smtp_test',
                        'email_type'   => 'Test Email',
                        'from_email'   => $fromAddress,
                        'from_name'    => $fromName,
                        'to'           => $recipientEmail,
                        'subject'      => $subject,
                        'status'       => 'sent',
                        'provider'     => 'smtp_custom',
                        'sent_at'      => now(),
                    ]);
                } catch (\Throwable $logEx) {}
            }

            return response()->json([
                'success' => true,
                'message' => "Test email sent successfully to {$recipientEmail}!",
                'details' => [
                    'recipient' => $recipientEmail,
                    'host'      => $host,
                    'port'      => $port,
                    'username'  => $username,
                    'from'      => $fromAddress,
                ]
            ]);

        } catch (\Throwable $th) {
            // Record failure log if EmailLog model exists
            if (class_exists(\App\Models\EmailLog::class)) {
                try {
                    \App\Models\EmailLog::create([
                        'template_key'  => 'smtp_test',
                        'email_type'    => 'Test Email',
                        'from_email'    => $fromAddress,
                        'from_name'     => $fromName,
                        'to'            => $recipientEmail,
                        'subject'       => 'SMTP Test Email (Failed)',
                        'status'        => 'failed',
                        'provider'      => 'smtp_custom',
                        'error_message' => $th->getMessage(),
                        'failed_at'     => now(),
                    ]);
                } catch (\Throwable $logEx) {}
            }

            return response()->json([
                'success' => false,
                'message' => 'SMTP Test Failed: ' . $th->getMessage(),
                'error'   => $th->getMessage()
            ], 500);
        }
    }
}
