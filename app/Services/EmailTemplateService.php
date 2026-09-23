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
        $companyName = CredentialService::get('mail', 'MAIL_GLOBAL_FROM_NAME', 'MAIL_GLOBAL_FROM_NAME', config('app.name', 'Megabyte Circuits'));

        if (!$order) {
            // Sample variables for preview/testing
            $vars = [
                'customer_name'  => 'John Doe',
                'customer_email' => 'john.doe@example.com',
                'order_number'   => 'M00001',
                'order_date'     => Carbon::now()->format('d M Y'),
                'order_status'   => 'Pending',
                'order_total'    => '₹5,000.00',
                'company_name'   => $companyName,
                'order_url'      => config('app.frontend_url', 'http://localhost:3000') . '/dashboard/orders',
                'board_name'       => 'Main Control Board v1.2',
                'gerber_file_name' => 'Main Control Board v1.2',
                'delivery_date'    => Carbon::now()->addDays(5)->format('d M Y'),
            ];
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

            $vars = [
                'customer_name'    => $customerName,
                'customer_email'   => $customerEmail ?? '',
                'order_number'     => $order->order_number ?? 'M' . $order->id,
                'order_date'       => $orderDateStr,
                'order_status'     => ucfirst($order->status ?? 'Pending'),
                'order_total'      => $orderTotalStr,
                'company_name'     => $companyName,
                'order_url'        => config('app.frontend_url', 'http://localhost:3000') . '/dashboard/orders',
                'board_name'       => $boardName,
                'gerber_file_name' => $boardName,
                'delivery_date'    => $deliveryDateStr,
            ];
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

        $renderedSubject = self::replaceVariables($template->subject, $vars);
        $renderedBody    = self::replaceVariables($template->body, $vars);

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
            'body'             => $renderedBody,
            'variables'        => $vars,
        ];
    }
}
