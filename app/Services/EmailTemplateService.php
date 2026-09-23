<?php

namespace App\Services;

use App\Models\EmailTemplate;
use App\Models\PcbOrder;
use App\Models\PcbUser;
use App\Models\User;
use App\Models\InventoryItem;
use App\Models\InventoryLog;
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
     * Replace placeholders in a string and return resolved text.
     */
    public static function resolveTemplateField(?string $templateStr, array $vars): string
    {
        if (empty($templateStr)) {
            return '';
        }
        return self::replaceVariables($templateStr, $vars);
    }

    /**
     * Resolve variables in email address field, split, validate, and deduplicate.
     */
    public static function resolveVariablesAndParseEmails(?string $templateStr, array $vars): array
    {
        if (empty($templateStr)) {
            return [];
        }

        $resolved = self::replaceVariables($templateStr, $vars);

        // If unreplaced variable tags remain (e.g. {{customer_email}}), return empty array
        if (preg_match('/\{\{\s*[a-zA-Z0-9_]+\s*\}\}/', $resolved)) {
            return [];
        }

        return self::parseEmails($resolved);
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

        $renderedSubject   = self::resolveTemplateField($template->subject, $vars);
        $innerBody         = self::resolveTemplateField($template->body, $vars);
        $fullRenderedBody  = self::wrapInLayout($innerBody, $vars, $renderedSubject);

        // Dynamic From Email & From Name
        $defaultFromAddress = CredentialService::get('mail', 'MAIL_GLOBAL_FROM_ADDRESS', 'MAIL_GLOBAL_FROM_ADDRESS', config('mail.from.address', 'quote@megabytecircuit.com'));
        $defaultFromName    = CredentialService::get('mail', 'MAIL_GLOBAL_FROM_NAME', 'MAIL_GLOBAL_FROM_NAME', config('mail.from.name', 'Megabyte Circuit'));

        $resolvedFromEmailStr = !empty($template->from_email) ? self::resolveTemplateField($template->from_email, $vars) : '';
        $parsedFromEmails     = self::parseEmails($resolvedFromEmailStr);
        $renderedFromEmail    = !empty($parsedFromEmails) ? $parsedFromEmails[0] : $defaultFromAddress;

        $renderedFromName = !empty($template->from_name) ? self::resolveTemplateField($template->from_name, $vars) : $defaultFromName;

        // Dynamic To, CC, BCC
        $toTemplateStr = !empty($template->to) ? $template->to : '{{customer_email}}';
        $toEmails      = self::resolveVariablesAndParseEmails($toTemplateStr, $vars);

        if (empty($toEmails) && !empty($vars['customer_email']) && filter_var($vars['customer_email'], FILTER_VALIDATE_EMAIL)) {
            $toEmails = [strtolower(trim($vars['customer_email']))];
        }

        $ccEmails  = self::resolveVariablesAndParseEmails($template->cc, $vars);
        $bccEmails = self::resolveVariablesAndParseEmails($template->bcc, $vars);

        if (empty($toEmails)) {
            return [
                'success'       => false,
                'message'       => "Unable to send email because recipient email address (TO) is missing or unresolved.",
                'is_active'     => true,
                'template'      => $template,
                'from_email'    => $renderedFromEmail,
                'from_name'     => $renderedFromName,
                'to'            => [],
                'cc'            => $ccEmails,
                'bcc'           => $bccEmails,
                'subject'       => $renderedSubject,
                'body'          => $fullRenderedBody,
            ];
        }

        return [
            'success'          => true,
            'is_active'        => true,
            'template'         => $template,
            'template_key'     => $template->key,
            'from_email'       => $renderedFromEmail,
            'from_name'        => $renderedFromName,
            'to'               => $toEmails[0],
            'to_all'           => $toEmails,
            'cc'               => $ccEmails,
            'bcc'              => $bccEmails,
            'subject'          => $renderedSubject,
            'body'             => $fullRenderedBody,
            'inner_body'       => $innerBody,
            'variables'        => $vars,
        ];
    }

    /**
     * Build dynamic template variable map from real inventory item/log data or sample data.
     */
    public static function buildInventoryVariables(?InventoryItem $item = null, ?InventoryLog $log = null, array $overrideVars = []): array
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

        if (!$item) {
            // Sample variables for preview/testing
            $vars = array_merge($commonCompanyVars, [
                'product_name'       => 'PCB Board - 4 Layer (STM32F407)',
                'product_id'         => '101',
                'sku'                => 'PCB-4L-STM32',
                'current_stock'      => '8',
                'available_stock'    => '8',
                'previous_stock'     => '15',
                'minimum_stock'      => '10',
                'maximum_stock'      => '1000',
                'quantity_added'     => '50',
                'quantity_removed'   => '7',
                'adjustment_quantity'=> '-7',
                'adjustment_reason'  => 'Assembly wastage & quality inspection',
                'supplier_name'      => 'Megabyte Components Supplier',
                'reference_number'   => 'LOG-#10042',
                'updated_by'         => 'Admin Administrator',
                'inventory_date'     => Carbon::now()->format('d M Y, h:i A'),
                'inventory_status'   => 'Low Stock',
            ]);
        } else {
            $prevQty = $log ? $log->previous_quantity : $item->available_quantity;
            $qtyAdded = ($log && $log->type === 'in') ? $log->quantity : 0;
            $qtyRemoved = ($log && $log->type === 'out') ? $log->quantity : 0;
            $adjQty = $log ? (($log->type === 'in' ? '+' : '-') . $log->quantity) : '0';
            $adjReason = $log ? ($log->note ?? 'Manual adjustment') : 'N/A';
            $updatedBy = $log ? ($log->created_by ?? 'Admin Administrator') : 'Admin Administrator';
            $invDate = $log ? ($log->created_at ? $log->created_at->format('d M Y, h:i A') : Carbon::now()->format('d M Y, h:i A')) : Carbon::now()->format('d M Y, h:i A');

            $vars = array_merge($commonCompanyVars, [
                'product_name'       => $item->name,
                'product_id'         => (string)$item->id,
                'sku'                => $item->sku,
                'current_stock'      => (string)$item->available_quantity,
                'available_stock'    => (string)$item->available_quantity,
                'previous_stock'     => (string)$prevQty,
                'minimum_stock'      => (string)$item->low_stock_threshold,
                'maximum_stock'      => 'N/A',
                'quantity_added'     => (string)$qtyAdded,
                'quantity_removed'   => (string)$qtyRemoved,
                'adjustment_quantity'=> (string)$adjQty,
                'adjustment_reason'  => $adjReason,
                'supplier_name'      => 'N/A',
                'reference_number'   => $log ? "LOG-#{$log->id}" : "ITEM-#{$item->id}",
                'updated_by'         => $updatedBy,
                'inventory_date'     => $invDate,
                'inventory_status'   => $item->status ?? 'In Stock',
            ]);
        }

        return array_merge($vars, $overrideVars);
    }

    /**
     * Get list of available variables formatted for template editor sidebar.
     */
    public static function getAvailableVariables(string $templateKey): array
    {
        if (str_starts_with($templateKey, 'inventory_')) {
            return [
                ['var' => '{{product_name}}', 'desc' => 'Component / Product Name'],
                ['var' => '{{product_id}}', 'desc' => 'Database Product ID'],
                ['var' => '{{sku}}', 'desc' => 'Product SKU / Part Code'],
                ['var' => '{{current_stock}}', 'desc' => 'Current Stock Quantity'],
                ['var' => '{{available_stock}}', 'desc' => 'Available Stock Quantity'],
                ['var' => '{{previous_stock}}', 'desc' => 'Stock Quantity Before Change'],
                ['var' => '{{minimum_stock}}', 'desc' => 'Minimum Stock / Low Stock Threshold'],
                ['var' => '{{quantity_added}}', 'desc' => 'Quantity Added in Operation'],
                ['var' => '{{quantity_removed}}', 'desc' => 'Quantity Removed in Operation'],
                ['var' => '{{adjustment_quantity}}', 'desc' => 'Stock Adjustment Quantity (+/-)'],
                ['var' => '{{adjustment_reason}}', 'desc' => 'Reason / Note for Adjustment'],
                ['var' => '{{updated_by}}', 'desc' => 'User who performed update'],
                ['var' => '{{inventory_date}}', 'desc' => 'Date and Time of Inventory Event'],
                ['var' => '{{inventory_status}}', 'desc' => 'Current Stock Status (In Stock, Low Stock, Out of Stock)'],
                ['var' => '{{company_name}}', 'desc' => 'Company Name from Settings'],
                ['var' => '{{company_email}}', 'desc' => 'Company Contact Email'],
                ['var' => '{{company_phone}}', 'desc' => 'Company Phone Number'],
            ];
        }

        return [
            ['var' => '{{customer_name}}', 'desc' => 'Customer Full Name or Company Name'],
            ['var' => '{{customer_email}}', 'desc' => 'Customer Email Address'],
            ['var' => '{{order_number}}', 'desc' => 'Unique Order Number (e.g. ORD-TEST-10001)'],
            ['var' => '{{order_date}}', 'desc' => 'Formatted Date Order Was Placed'],
            ['var' => '{{order_status}}', 'desc' => 'Current Order Status'],
            ['var' => '{{order_total}}', 'desc' => 'Total Amount Paid/Due (e.g. ₹5,000.00)'],
            ['var' => '{{company_name}}', 'desc' => 'Company Name from Settings'],
            ['var' => '{{order_url}}', 'desc' => 'Customer Dashboard Order URL'],
            ['var' => '{{board_name}}', 'desc' => 'Board / Design Name'],
            ['var' => '{{gerber_file_name}}', 'desc' => 'Gerber File / Board Name'],
            ['var' => '{{delivery_date}}', 'desc' => 'Estimated Delivery Date'],
        ];
    }

    /**
     * Render full inventory template payload.
     */
    public static function renderInventory(string $templateKey, ?InventoryItem $item = null, ?InventoryLog $log = null, array $customVars = []): array
    {
        $template = EmailTemplate::where('key', $templateKey)->first();

        if (!$template) {
            return [
                'success'   => false,
                'message'   => "Email template '{$templateKey}' not found.",
                'is_active' => false,
            ];
        }

        if (!$template->is_active) {
            return [
                'success'   => false,
                'message'   => "Email template '{$templateKey}' is currently inactive.",
                'is_active' => false,
                'template'  => $template,
            ];
        }

        $vars = self::buildInventoryVariables($item, $log, $customVars);

        $renderedSubject  = self::resolveTemplateField($template->subject, $vars);
        $innerBody        = self::resolveTemplateField($template->body, $vars);
        $fullRenderedBody = self::wrapInLayout($innerBody, $vars, $renderedSubject);

        // Dynamic From Email & From Name
        $defaultFromAddress = CredentialService::get('mail', 'MAIL_GLOBAL_FROM_ADDRESS', 'MAIL_GLOBAL_FROM_ADDRESS', config('mail.from.address', 'quote@megabytecircuit.com'));
        $defaultFromName    = CredentialService::get('mail', 'MAIL_GLOBAL_FROM_NAME', 'MAIL_GLOBAL_FROM_NAME', config('mail.from.name', 'Megabyte Circuit'));

        $resolvedFromEmailStr = !empty($template->from_email) ? self::resolveTemplateField($template->from_email, $vars) : '';
        $parsedFromEmails     = self::parseEmails($resolvedFromEmailStr);
        $renderedFromEmail    = !empty($parsedFromEmails) ? $parsedFromEmails[0] : $defaultFromAddress;

        $renderedFromName = !empty($template->from_name) ? self::resolveTemplateField($template->from_name, $vars) : $defaultFromName;

        // Dynamic To, CC, BCC
        $toTemplateStr = !empty($template->to) ? $template->to : '{{company_email}}';
        $toEmails      = self::resolveVariablesAndParseEmails($toTemplateStr, $vars);

        if (empty($toEmails) && !empty($vars['company_email']) && filter_var($vars['company_email'], FILTER_VALIDATE_EMAIL)) {
            $toEmails = [strtolower(trim($vars['company_email']))];
        }

        $ccEmails  = self::resolveVariablesAndParseEmails($template->cc, $vars);
        $bccEmails = self::resolveVariablesAndParseEmails($template->bcc, $vars);

        if (empty($toEmails)) {
            return [
                'success'       => false,
                'message'       => "Unable to send email because recipient email address (TO) is missing or unresolved.",
                'is_active'     => true,
                'template'      => $template,
                'from_email'    => $renderedFromEmail,
                'from_name'     => $renderedFromName,
                'to'            => [],
                'cc'            => $ccEmails,
                'bcc'           => $bccEmails,
                'subject'       => $renderedSubject,
                'body'          => $fullRenderedBody,
            ];
        }

        return [
            'success'          => true,
            'is_active'        => true,
            'template'         => $template,
            'template_key'     => $template->key,
            'from_email'       => $renderedFromEmail,
            'from_name'        => $renderedFromName,
            'to'               => $toEmails[0],
            'to_all'           => $toEmails,
            'cc'               => $ccEmails,
            'bcc'              => $bccEmails,
            'subject'          => $renderedSubject,
            'body'             => $fullRenderedBody,
            'inner_body'       => $innerBody,
            'variables'        => $vars,
        ];
    }
}
