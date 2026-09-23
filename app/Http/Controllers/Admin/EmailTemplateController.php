<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Config;
use App\Models\EmailTemplate;
use App\Models\EmailLog;
use App\Models\PcbOrder;
use App\Services\EmailTemplateService;
use App\Services\CredentialService;
use Carbon\Carbon;

class EmailTemplateController extends Controller
{
    /**
     * List all email templates.
     */
    public function index()
    {
        try {
            $templates = EmailTemplate::orderBy('id', 'asc')->get();

            return response()->json([
                'success' => true,
                'data'    => $templates,
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch email templates: ' . $th->getMessage()
            ], 500);
        }
    }

    /**
     * Show a single email template.
     */
    public function show($id)
    {
        try {
            $template = EmailTemplate::where('id', $id)->orWhere('key', $id)->firstOrFail();

            $availableVariables = EmailTemplateService::getAvailableVariables($template->key);

            return response()->json([
                'success' => true,
                'data'    => $template,
                'available_variables' => $availableVariables,
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => 'Email template not found: ' . $th->getMessage()
            ], 404);
        }
    }

    /**
     * Update an email template.
     */
    public function update(Request $request, $id)
    {
        $template = EmailTemplate::where('id', $id)->orWhere('key', $id)->firstOrFail();

        $validator = Validator::make($request->all(), [
            'name'       => 'sometimes|required|string|max:255',
            'subject'    => 'sometimes|required|string|max:255',
            'body'       => 'sometimes|required|string',
            'to'         => 'nullable|string',
            'from_email' => 'nullable|string',
            'from_name'  => 'nullable|string',
            'cc'         => 'nullable|string',
            'bcc'        => 'nullable|string',
            'is_active'  => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $validator->errors()
            ], 422);
        }

        // Validate From Email if provided (and not a placeholder)
        if ($request->filled('from_email')) {
            $fromEmailVal = trim($request->from_email);
            if (!empty($fromEmailVal) && !str_contains($fromEmailVal, '{{') && !filter_var($fromEmailVal, FILTER_VALIDATE_EMAIL)) {
                return response()->json([
                    'success' => false,
                    'message' => "Invalid FROM email address format: '{$fromEmailVal}'"
                ], 422);
            }
        }

        // Validate individual CC emails if provided
        if ($request->filled('cc')) {
            $ccList = preg_split('/[\s,;]+/', $request->cc);
            foreach ($ccList as $email) {
                $email = trim($email);
                if (!empty($email) && !str_contains($email, '{{') && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    return response()->json([
                        'success' => false,
                        'message' => "Invalid CC email address format: '{$email}'"
                    ], 422);
                }
            }
        }

        // Validate individual BCC emails if provided
        if ($request->filled('bcc')) {
            $bccList = preg_split('/[\s,;]+/', $request->bcc);
            foreach ($bccList as $email) {
                $email = trim($email);
                if (!empty($email) && !str_contains($email, '{{') && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    return response()->json([
                        'success' => false,
                        'message' => "Invalid BCC email address format: '{$email}'"
                    ], 422);
                }
            }
        }

        if ($request->has('name')) $template->name = $request->name;
        if ($request->has('subject')) $template->subject = $request->subject;
        if ($request->has('body')) $template->body = $request->body;
        if ($request->has('to')) $template->to = $request->to;
        if ($request->has('from_email')) $template->from_email = $request->from_email;
        if ($request->has('from_name')) $template->from_name = $request->from_name;
        if ($request->has('cc')) $template->cc = $request->cc;
        if ($request->has('bcc')) $template->bcc = $request->bcc;
        if ($request->has('is_active')) $template->is_active = $request->is_active;

        $template->save();

        return response()->json([
            'success' => true,
            'message' => 'Email template updated successfully',
            'data'    => $template
        ]);
    }

    /**
     * Preview template rendering with sample or specific entity.
     */
    public function preview(Request $request, $id)
    {
        try {
            $template = EmailTemplate::where('id', $id)->orWhere('key', $id)->firstOrFail();

            $subjectText   = $request->input('subject', $template->subject);
            $bodyText      = $request->input('body', $template->body);
            $toText        = $request->input('to', $template->to);
            $fromEmailText = $request->input('from_email', $template->from_email);
            $fromNameText  = $request->input('from_name', $template->from_name);
            $ccText        = $request->input('cc', $template->cc);
            $bccText       = $request->input('bcc', $template->bcc);

            if ($template->key === 'daily_order_progress_report') {
                $dateStr = Carbon::now()->format('d F Y');
                $vars = \App\Services\DailyReportService::getSampleOrderReportVariables($dateStr);
            } else if ($template->key === 'daily_inventory_report') {
                $dateStr = Carbon::now()->format('d F Y');
                $vars = \App\Services\DailyReportService::getSampleInventoryReportVariables($dateStr);
            } else if (str_starts_with($template->key, 'inventory_')) {
                $vars = EmailTemplateService::buildInventoryVariables(null, null);
            } else {
                $orderId = $request->input('order_id');
                $order = $orderId ? PcbOrder::with('user')->find($orderId) : null;
                $vars = EmailTemplateService::buildVariables($order);
            }

            $renderedSubject  = EmailTemplateService::resolveTemplateField($subjectText, $vars);
            $innerBody        = EmailTemplateService::resolveTemplateField($bodyText, $vars);
            $fullRenderedBody = EmailTemplateService::wrapInLayout($innerBody, $vars, $renderedSubject);

            $defaultFromAddress = CredentialService::get('mail', 'MAIL_GLOBAL_FROM_ADDRESS', 'MAIL_GLOBAL_FROM_ADDRESS', config('mail.from.address', 'quote@megabytecircuit.com'));
            $defaultFromName    = CredentialService::get('mail', 'MAIL_GLOBAL_FROM_NAME', 'MAIL_GLOBAL_FROM_NAME', config('mail.from.name', 'Megabyte Circuit'));

            $renderedFromEmailStr = !empty($fromEmailText) ? EmailTemplateService::resolveTemplateField($fromEmailText, $vars) : '';
            $parsedFromEmails     = EmailTemplateService::parseEmails($renderedFromEmailStr);
            $renderedFromEmail    = !empty($parsedFromEmails) ? $parsedFromEmails[0] : $defaultFromAddress;

            $renderedFromName = !empty($fromNameText) ? EmailTemplateService::resolveTemplateField($fromNameText, $vars) : $defaultFromName;

            $toEmails  = EmailTemplateService::resolveVariablesAndParseEmails($toText, $vars);
            $ccEmails  = EmailTemplateService::resolveVariablesAndParseEmails($ccText, $vars);
            $bccEmails = EmailTemplateService::resolveVariablesAndParseEmails($bccText, $vars);

            return response()->json([
                'success' => true,
                'data' => [
                    'key'              => $template->key,
                    'name'             => $template->name,
                    'is_active'        => (bool)($request->has('is_active') ? $request->is_active : $template->is_active),
                    'rendered_subject' => $renderedSubject,
                    'rendered_body'    => $fullRenderedBody,
                    'from_email'       => $renderedFromEmail,
                    'from_name'        => $renderedFromName,
                    'to'               => !empty($toEmails) ? implode(', ', $toEmails) : 'N/A (Missing recipient variable)',
                    'cc'               => $ccEmails,
                    'bcc'              => $bccEmails,
                    'variables'        => $vars,
                ]
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to render preview: ' . $th->getMessage()
            ], 500);
        }
    }

    /**
     * Send test email to specified recipient.
     */
    public function testEmail(Request $request, $id)
    {
        $recipientEmail = $request->input('recipient_email') ?: $request->input('email');

        if (empty($recipientEmail) || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            return response()->json([
                'success' => false,
                'message' => 'Please enter a valid email address.'
            ], 422);
        }

        try {
            $template = EmailTemplate::where('id', $id)->orWhere('key', $id)->firstOrFail();

            // Support current unsaved/edited template parameters from request
            $subjectText   = $request->input('subject', $template->subject);
            $bodyText      = $request->input('body', $template->body);
            $fromEmailText = $request->input('from_email', $template->from_email);
            $fromNameText  = $request->input('from_name', $template->from_name);
            $ccStr         = $request->input('cc', $template->cc);
            $bccStr        = $request->input('bcc', $template->bcc);

            if (empty($subjectText) || empty($bodyText)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Template must have a subject and body before sending a test email.'
                ], 422);
            }

            // Build dummy variables
            if ($template->key === 'daily_order_progress_report') {
                $dateStr = Carbon::now()->format('d F Y');
                $dummyVars = \App\Services\DailyReportService::getSampleOrderReportVariables($dateStr);
            } else if ($template->key === 'daily_inventory_report') {
                $dateStr = Carbon::now()->format('d F Y');
                $dummyVars = \App\Services\DailyReportService::getSampleInventoryReportVariables($dateStr);
            } else if (str_starts_with($template->key, 'inventory_')) {
                $dummyVars = EmailTemplateService::buildInventoryVariables(null, null);
            } else {
                $dummyVars = EmailTemplateService::buildVariables(null, [
                    'customer_name'         => 'John Doe',
                    'customer_email'        => $recipientEmail,
                    'order_number'          => 'ORD-TEST-10001',
                    'order_date'            => Carbon::now()->format('d M Y'),
                    'previous_order_status' => 'Pending',
                    'order_status'          => (in_array($template->key, ['order_production_film_not_applied', 'order_status_updated']) ? 'In Production' : 'Completed'),
                    'film_applied'          => 'No',
                    'order_total'           => '₹5,000.00',
                    'order_url'             => config('app.frontend_url', 'http://localhost:3000') . '/dashboard/orders',
                ]);
            }

            // CC/BCC controls (Off by default for test emails for safety)
            $useConfiguredCcBcc = filter_var($request->input('use_configured_cc_bcc', false), FILTER_VALIDATE_BOOLEAN);

            $ccEmails  = $useConfiguredCcBcc ? EmailTemplateService::resolveVariablesAndParseEmails($ccStr, $dummyVars) : [];
            $bccEmails = $useConfiguredCcBcc ? EmailTemplateService::resolveVariablesAndParseEmails($bccStr, $dummyVars) : [];

            $defaultFromAddress = CredentialService::get('mail', 'MAIL_GLOBAL_FROM_ADDRESS', 'MAIL_GLOBAL_FROM_ADDRESS', config('mail.from.address', 'quote@megabytecircuit.com'));
            $defaultFromName    = CredentialService::get('mail', 'MAIL_GLOBAL_FROM_NAME', 'MAIL_GLOBAL_FROM_NAME', config('mail.from.name', 'Megabyte Circuit'));

            $resolvedFromEmailStr = !empty($fromEmailText) ? EmailTemplateService::resolveTemplateField($fromEmailText, $dummyVars) : '';
            $parsedFromEmails     = EmailTemplateService::parseEmails($resolvedFromEmailStr);
            $fromEmail            = !empty($parsedFromEmails) ? $parsedFromEmails[0] : $defaultFromAddress;
            $fromName             = !empty($fromNameText) ? EmailTemplateService::resolveTemplateField($fromNameText, $dummyVars) : $defaultFromName;

            $renderedSubject = EmailTemplateService::resolveTemplateField($subjectText, $dummyVars);
            $innerBody       = EmailTemplateService::resolveTemplateField($bodyText, $dummyVars);

            // Append Test Notice Banner inside email body content
            $testBannerHtml = '<div style="margin-top: 30px; padding: 12px; background-color: #fef3c7; border: 1px solid #f59e0b; border-radius: 6px; text-align: center; font-size: 12px; color: #92400e; font-family: sans-serif;">
                <strong>TEST EMAIL:</strong> This is a test email generated from the Admin Email Template settings using sample data.
            </div>';

            $bodyWithBanner = $innerBody . $testBannerHtml;
            $finalTestBody  = EmailTemplateService::wrapInLayout($bodyWithBanner, $dummyVars, $renderedSubject);

            // Configure dynamic mailer
            $mailer = 'smtp_global';
            Config::set('mail.mailers.smtp_global.host', CredentialService::get('mail', 'MAIL_GLOBAL_HOST', 'MAIL_GLOBAL_HOST', config('mail.mailers.smtp.host')));
            Config::set('mail.mailers.smtp_global.port', CredentialService::get('mail', 'MAIL_GLOBAL_PORT', 'MAIL_GLOBAL_PORT', config('mail.mailers.smtp.port', 587)));
            Config::set('mail.mailers.smtp_global.username', CredentialService::get('mail', 'MAIL_GLOBAL_USERNAME', 'MAIL_GLOBAL_USERNAME', config('mail.mailers.smtp.username')));
            Config::set('mail.mailers.smtp_global.password', CredentialService::get('mail', 'MAIL_GLOBAL_PASSWORD', 'MAIL_GLOBAL_PASSWORD', config('mail.mailers.smtp.password')));
            Config::set('mail.from.address', $fromEmail);
            Config::set('mail.from.name', $fromName);

            Mail::mailer($mailer)->send([], [], function ($message) use ($recipientEmail, $fromEmail, $fromName, $renderedSubject, $finalTestBody, $ccEmails, $bccEmails) {
                $message->to($recipientEmail)
                    ->from($fromEmail, $fromName)
                    ->subject($renderedSubject)
                    ->html($finalTestBody);

                if (!empty($ccEmails)) {
                    $message->cc($ccEmails);
                }
                if (!empty($bccEmails)) {
                    $message->bcc($bccEmails);
                }
            });

            // Log test email in email_logs table with is_test = true
            EmailLog::create([
                'template_key'  => $template->key,
                'order_id'      => null,
                'customer_id'   => null,
                'from_email'    => $fromEmail,
                'from_name'     => $fromName,
                'to'            => $recipientEmail,
                'cc'            => implode(', ', $ccEmails),
                'bcc'           => implode(', ', $bccEmails),
                'subject'       => $renderedSubject,
                'status'        => 'sent',
                'is_test'       => true,
                'sent_at'       => Carbon::now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => "Test email sent successfully to {$recipientEmail}.",
            ]);
        } catch (\Throwable $th) {
            // Log failed test email entry
            try {
                EmailLog::create([
                    'template_key'  => $id,
                    'order_id'      => null,
                    'customer_id'   => null,
                    'to'            => $recipientEmail,
                    'cc'            => '',
                    'bcc'           => '',
                    'subject'       => $subjectText ?? 'Test Email',
                    'status'        => 'failed',
                    'is_test'       => true,
                    'error_message' => $th->getMessage(),
                ]);
            } catch (\Throwable $logEx) {}

            return response()->json([
                'success' => false,
                'message' => 'Unable to send test email. Please check the email configuration and try again.',
                'error'   => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Send real daily report now for specified date or today.
     */
    public function sendNow(Request $request, $id)
    {
        try {
            $template = EmailTemplate::where('id', $id)->orWhere('key', $id)->firstOrFail();
            $targetDate = $request->input('report_date') ?: $request->input('date');

            if ($template->key === 'daily_order_progress_report') {
                $res = \App\Services\DailyReportService::sendDailyOrderReport($targetDate, true);
                return response()->json($res, $res['success'] ? 200 : 400);
            } else if ($template->key === 'daily_inventory_report') {
                $res = \App\Services\DailyReportService::sendDailyInventoryReport($targetDate, true);
                return response()->json($res, $res['success'] ? 200 : 400);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => "The 'Send Report Now' feature is only applicable for daily report templates."
                ], 400);
            }
        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to send daily report: ' . $th->getMessage()
            ], 500);
        }
    }
}
