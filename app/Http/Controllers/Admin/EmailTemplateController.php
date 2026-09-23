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

            $availableVariables = [
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
            'name'      => 'sometimes|required|string|max:255',
            'subject'   => 'sometimes|required|string|max:255',
            'body'      => 'sometimes|required|string',
            'cc'        => 'nullable|string',
            'bcc'       => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $validator->errors()
            ], 422);
        }

        // Validate individual CC emails if provided
        if ($request->filled('cc')) {
            $ccList = preg_split('/[\s,;]+/', $request->cc);
            foreach ($ccList as $email) {
                $email = trim($email);
                if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
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
                if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
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
     * Preview template rendering with sample or specific order.
     */
    public function preview(Request $request, $id)
    {
        try {
            $template = EmailTemplate::where('id', $id)->orWhere('key', $id)->firstOrFail();

            $orderId = $request->input('order_id');
            $order = $orderId ? PcbOrder::with('user')->find($orderId) : null;

            // Optional custom override of template subject or body from request for instant editor live-preview
            $subjectText = $request->input('subject', $template->subject);
            $bodyText    = $request->input('body', $template->body);

            $vars = EmailTemplateService::buildVariables($order);

            $renderedSubject = EmailTemplateService::replaceVariables($subjectText, $vars);
            $innerBody       = EmailTemplateService::replaceVariables($bodyText, $vars);
            $fullRenderedBody = EmailTemplateService::wrapInLayout($innerBody, $vars, $renderedSubject);

            $ccEmails  = EmailTemplateService::parseEmails($request->input('cc', $template->cc));
            $bccEmails = EmailTemplateService::parseEmails($request->input('bcc', $template->bcc));

            return response()->json([
                'success' => true,
                'data' => [
                    'key'              => $template->key,
                    'name'             => $template->name,
                    'is_active'        => (bool)($request->has('is_active') ? $request->is_active : $template->is_active),
                    'rendered_subject' => $renderedSubject,
                    'rendered_body'    => $fullRenderedBody,
                    'to'               => $vars['customer_email'],
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
            $subjectText = $request->input('subject', $template->subject);
            $bodyText    = $request->input('body', $template->body);
            $ccStr       = $request->input('cc', $template->cc);
            $bccStr      = $request->input('bcc', $template->bcc);

            if (empty($subjectText) || empty($bodyText)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Template must have a subject and body before sending a test email.'
                ], 422);
            }

            // CC/BCC controls (Off by default for test emails for safety)
            $useConfiguredCcBcc = filter_var($request->input('use_configured_cc_bcc', false), FILTER_VALIDATE_BOOLEAN);

            $ccEmails  = $useConfiguredCcBcc ? EmailTemplateService::parseEmails($ccStr) : [];
            $bccEmails = $useConfiguredCcBcc ? EmailTemplateService::parseEmails($bccStr) : [];

            // Build dummy variables
            $dummyVars = EmailTemplateService::buildVariables(null, [
                'customer_name'  => 'John Doe',
                'customer_email' => $recipientEmail,
                'order_number'   => 'ORD-TEST-10001',
                'order_date'     => Carbon::now()->format('d M Y'),
                'order_status'   => 'Completed',
                'order_total'    => '₹5,000.00',
                'order_url'      => config('app.frontend_url', 'http://localhost:3000') . '/dashboard/orders',
            ]);

            $renderedSubject = EmailTemplateService::replaceVariables($subjectText, $dummyVars);
            $innerBody       = EmailTemplateService::replaceVariables($bodyText, $dummyVars);

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
            Config::set('mail.from.address', CredentialService::get('mail', 'MAIL_GLOBAL_FROM_ADDRESS', 'MAIL_GLOBAL_FROM_ADDRESS', config('mail.from.address')));
            Config::set('mail.from.name', CredentialService::get('mail', 'MAIL_GLOBAL_FROM_NAME', 'MAIL_GLOBAL_FROM_NAME', config('mail.from.name')));

            Mail::mailer($mailer)->send([], [], function ($message) use ($recipientEmail, $renderedSubject, $finalTestBody, $ccEmails, $bccEmails) {
                $message->to($recipientEmail)
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
}
