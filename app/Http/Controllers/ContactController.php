<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\MailHelper;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Services\CredentialService;

class ContactController extends Controller
{
    /**
     * Get public reCAPTCHA configuration for frontend.
     */
    public function getRecaptchaConfig()
    {
        $enabledRaw = CredentialService::get('recaptcha', 'RECAPTCHA_ENABLED', 'RECAPTCHA_ENABLED', 'false');
        $enabled = filter_var($enabledRaw, FILTER_VALIDATE_BOOLEAN);
        $siteKey = (string)CredentialService::get('recaptcha', 'RECAPTCHA_SITE_KEY', 'RECAPTCHA_SITE_KEY', '');

        return response()->json([
            'success' => true,
            'enabled' => $enabled && !empty($siteKey),
            'site_key' => $siteKey,
        ]);
    }

    public function submitContact(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|min:2|max:200',
            'email' => 'required|email|max:200',
            'phone' => 'nullable|string|max:50',
            'company' => 'nullable|string|max:200',
            'serviceType' => 'required|string|max:100',
            'message' => 'required|string|min:10',
            'recaptcha_token' => 'nullable|string',
            'recaptchaToken' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Verify Google reCAPTCHA if enabled
        $recaptchaEnabledRaw = CredentialService::get('recaptcha', 'RECAPTCHA_ENABLED', 'RECAPTCHA_ENABLED', 'false');
        $recaptchaEnabled = filter_var($recaptchaEnabledRaw, FILTER_VALIDATE_BOOLEAN);
        $secretKey = (string)CredentialService::get('recaptcha', 'RECAPTCHA_SECRET_KEY', 'RECAPTCHA_SECRET_KEY', '');

        if ($recaptchaEnabled && !empty($secretKey)) {
            $token = $request->input('recaptcha_token')
                ?? $request->input('recaptchaToken')
                ?? $request->input('g-recaptcha-response');

            if (empty($token)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please complete the Google reCAPTCHA verification.',
                    'errors' => ['recaptcha' => ['Please complete the Google reCAPTCHA verification.']]
                ], 422);
            }

            try {
                $verifyResponse = Http::asForm()->timeout(10)->post('https://www.google.com/recaptcha/api/siteverify', [
                    'secret' => $secretKey,
                    'response' => $token,
                    'remoteip' => $request->ip(),
                ]);

                $resData = $verifyResponse->json();
                if (!($resData['success'] ?? false)) {
                    Log::warning('reCAPTCHA verification failed', [
                        'response' => $resData,
                        'ip' => $request->ip()
                    ]);
                    return response()->json([
                        'success' => false,
                        'message' => 'Google reCAPTCHA verification failed. Please try again.',
                        'errors' => ['recaptcha' => ['Google reCAPTCHA verification failed.']]
                    ], 422);
                }
            } catch (\Throwable $e) {
                Log::error('reCAPTCHA connection error: ' . $e->getMessage());
                return response()->json([
                    'success' => false,
                    'message' => 'Could not verify reCAPTCHA with Google servers. Please try again later.',
                ], 500);
            }
        }

        $validated = $validator->validated();

        // Destination admin email address resolved via CredentialService
        $adminEmail = \App\Services\CredentialService::get('mail', 'MAIL_BCC_ADDRESS', 'MAIL_BCC_ADDRESS', \App\Services\CredentialService::get('mail', 'MAIL_GLOBAL_FROM_ADDRESS', 'MAIL_GLOBAL_FROM_ADDRESS', 'quote@megabytecircuit.com'));

        $mailData = [
            'subject' => 'Website Inquiry: ' . ucfirst(str_replace('_', ' ', $validated['serviceType'])) . ' - ' . $validated['name'],
            'template' => 'emails.contact_inquiry',
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? 'N/A',
            'company' => $validated['company'] ?? 'N/A',
            'serviceType' => $validated['serviceType'],
            'message' => $validated['message'],
        ];

        // Send email via MailHelper
        $result = MailHelper::send_email($adminEmail, $mailData);

        if ($result['success']) {
            return response()->json([
                'success' => true,
                'message' => 'Thank you for reaching out! Our team will contact you within 24 hours.',
                'id' => 'CNT-' . time()
            ]);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Failed to send message: ' . ($result['message'] ?? 'SMTP Error'),
            ], 500);
        }
    }
}
