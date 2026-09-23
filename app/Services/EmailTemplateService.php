<?php

namespace App\Services;

use App\Models\EmailTemplate;
use App\Models\PcbOrder;
use App\Models\PcbUser;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;

class EmailTemplateService
{
    /**
     * Parse and validate email address string into array of valid email addresses.
     */
    public static function parseEmails(?string $emailStr): array
    {
        if (empty($emailStr)) {
            return [];
        }

        $rawList = preg_split('/[\s,;]+/', $emailStr);
        $validEmails = [];

        foreach ($rawList as $email) {
            $email = trim($email);
            if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $validEmails[] = strtolower($email);
            }
        }

        return array_unique($validEmails);
    }

    /**
     * Build dynamic template variable map from real order data or sample data.
     */
    public static function buildVariables(?PcbOrder $order = null, array $overrideVars = []): array
    {
        $companyName    = CredentialService::get('company', 'COMPANY_NAME', 'COMPANY_NAME', CredentialService::get('mail', 'MAIL_GLOBAL_FROM_NAME', 'MAIL_GLOBAL_FROM_NAME', config('app.name', 'Megabyte Circuit')));
        $companyLogoUrl = CredentialService::get('company', 'COMPANY_LOGO_URL', 'COMPANY_LOGO_URL', 'https://megabytecircuit.com/images/logo.png');
        $companyEmail   = CredentialService::get('company', 'COMPANY_EMAIL', 'COMPANY_EMAIL', CredentialService::get('mail', 'MAIL_GLOBAL_FROM_ADDRESS', 'MAIL_GLOBAL_FROM_ADDRESS', 'quote@megabytecircuit.com'));
        $companyPhone   = CredentialService::get('company', 'COMPANY_PHONE', 'COMPANY_PHONE', '+91-9898842942');
        $companyAddress = CredentialService::get('company', 'COMPANY_ADDRESS', 'COMPANY_ADDRESS', 'Megabyte Circuit, Gujarat, India');
        $companyWebsite = CredentialService::get('company', 'COMPANY_WEBSITE', 'COMPANY_WEBSITE', config('app.main_url', 'https://megabytecircuit.com'));
        $supportEmail   = CredentialService::get('company', 'SUPPORT_EMAIL', 'SUPPORT_EMAIL', $companyEmail);
        $supportPhone   = CredentialService::get('company', 'SUPPORT_PHONE', 'SUPPORT_PHONE', '+91-9898842942');
        $facebookUrl    = CredentialService::get('company', 'FACEBOOK_URL', 'FACEBOOK_URL', '');
        $instagramUrl   = CredentialService::get('company', 'INSTAGRAM_URL', 'INSTAGRAM_URL', '');
        $linkedinUrl    = CredentialService::get('company', 'LINKEDIN_URL', 'LINKEDIN_URL', '');
        $twitterUrl     = CredentialService::get('company', 'TWITTER_URL', 'TWITTER_URL', '');

        $cartBaseUrl = config('app.cart_url', 'https://cart.megabytecircuit.com');

        $commonCompanyVars = [
            'company_name'     => $companyName,
            'company_logo_url' => $companyLogoUrl,
            'company_email'    => $companyEmail,
            'company_phone'    => $companyPhone,
            'company_address'  => $companyAddress,
            'company_website'  => $companyWebsite,
            'support_email'    => $supportEmail,
            'support_phone'    => $supportPhone,
            'facebook_url'     => $facebookUrl,
            'instagram_url'    => $instagramUrl,
            'linkedin_url'     => $linkedinUrl,
            'twitter_url'      => $twitterUrl,
            'current_year'     => date('Y'),
        ];

        if (!$order) {
            // Sample variables for preview/testing
            $vars = array_merge($commonCompanyVars, [
                'customer_name'    => 'John Doe',
                'customer_email'   => 'john.doe@example.com',
                'order_number'     => 'M00001',
                'order_date'       => Carbon::now()->format('d M Y'),
                'order_status'     => 'Pending',
                'order_total'      => '₹5,000.00',
                'order_url'        => rtrim($cartBaseUrl, '/') . '/orders',
                'board_name'       => 'Main Control Board v1.2',
                'gerber_file_name' => 'Main Control Board v1.2',
                'delivery_date'    => Carbon::now()->addDays(5)->format('d M Y'),
            ]);
        } else {
            // Real order variables
            $customerName = $order->customer_name;
            $customerEmail = $order->user_email;

            if (empty($customerName) && $order->user) {
                $customerName = $order->user->name ?? $order->user->company_name;
            }
            if (empty($customerEmail) && $order->user) {
                $customerEmail = $order->user->email;
            }
            if (empty($customerName)) {
                $customerName = !empty($customerEmail) ? strtok($customerEmail, '@') : 'Valued Customer';
            }

            $orderDateStr = $order->created_at ? $order->created_at->format('d M Y') : Carbon::now()->format('d M Y');
            $deliveryDateStr = $order->delivery_date ? Carbon::parse($order->delivery_date)->format('d M Y') : 'N/A';
            $orderTotalStr = '₹' . number_format($order->order_value ?? 0, 2);
            $boardName = $order->board_name ?? $order->gerber_file_name ?? $order->gerber_filename ?? 'PCB Design';

            $vars = array_merge($commonCompanyVars, [
                'customer_name'    => $customerName,
                'customer_email'   => $customerEmail ?? '',
                'order_number'     => $order->order_number ?? 'M' . $order->id,
                'order_date'       => $orderDateStr,
                'order_status'     => ucfirst($order->status ?? 'Pending'),
                'order_total'      => $orderTotalStr,
                'order_url'        => rtrim($cartBaseUrl, '/') . '/orders',
                'board_name'       => $boardName,
                'gerber_file_name' => $boardName,
                'delivery_date'    => $deliveryDateStr,
            ]);
        }

        return array_merge($vars, $overrideVars);
    }

    /**
     * Replace template placeholders {{var_name}} with actual variable values.
     */
    public static function replaceVariables(string $text, array $vars): string
    {
        foreach ($vars as $key => $val) {
            $replacement = is_array($val) ? json_encode($val) : (string)$val;
            $text = str_replace('{{' . $key . '}}', $replacement, $text);
            $text = str_replace('{{ ' . $key . ' }}', $replacement, $text);
        }
        return $text;
    }

    /**
     * Wrap rendered template body within centralized Blade email layout.
     */
    public static function wrapInLayout(string $bodyHtml, array $vars, string $subject = ''): string
    {
        try {
            $viewData = array_merge($vars, [
                'body_content' => $bodyHtml,
                'subject'      => $subject,
            ]);

            return view('emails.layout', $viewData)->render();
        } catch (\Throwable $e) {
            // Fallback header & footer wrapping if view rendering fails
            $logoHtml = !empty($vars['company_logo_url']) 
                ? '<img src="' . htmlspecialchars($vars['company_logo_url']) . '" alt="' . htmlspecialchars($vars['company_name'] ?? 'Megabyte Circuit') . '" style="max-width:180px;height:auto;" />'
                : '<h1 style="color:#10b981;margin:0;">' . htmlspecialchars($vars['company_name'] ?? 'Megabyte Circuit') . '</h1>';

            return '<div style="font-family:sans-serif;max-width:600px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">
                <div style="padding:20px;text-align:center;border-bottom:2px solid #10b981;">' . $logoHtml . '</div>
                <div style="padding:30px;">' . $bodyHtml . '</div>
                <div style="padding:20px;text-align:center;background:#f9fafb;border-top:1px solid #e5e7eb;font-size:12px;color:#6b7280;">
                    &copy; ' . date('Y') . ' ' . htmlspecialchars($vars['company_name'] ?? 'Megabyte Circuit') . '. All rights reserved.
                </div>
            </div>';
        }
    }

    /**
     * Render full template payload for a given key and optional order model.
     */
    public static function render(string $templateKey, ?PcbOrder $order = null, array $customVars = []): array
    {
        $template = EmailTemplate::where('key', $templateKey)->first();

        if (!$template) {
            return [
                'success' => false,
                'message' => "Email template '{$templateKey}' not found.",
                'is_active' => false,
            ];
        }

        if (!$template->is_active) {
            return [
                'success' => false,
                'message' => "Email template '{$templateKey}' is currently inactive.",
                'is_active' => false,
                'template' => $template,
            ];
        }

        $vars = self::buildVariables($order, $customVars);

        $renderedSubject   = self::replaceVariables($template->subject, $vars);
        $innerBody         = self::replaceVariables($template->body, $vars);
        $fullRenderedBody  = self::wrapInLayout($innerBody, $vars, $renderedSubject);

        $ccList  = self::parseEmails($template->cc);
        $bccList = self::parseEmails($template->bcc);

        $toEmail = $vars['customer_email'] ?? null;

        return [
            'success'          => true,
            'is_active'        => true,
            'template'         => $template,
            'template_key'     => $template->key,
            'to'               => $toEmail,
            'cc'               => $ccList,
            'bcc'              => $bccList,
            'subject'          => $renderedSubject,
            'body'             => $fullRenderedBody,
            'inner_body'       => $innerBody,
            'variables'        => $vars,
        ];
    }
}
