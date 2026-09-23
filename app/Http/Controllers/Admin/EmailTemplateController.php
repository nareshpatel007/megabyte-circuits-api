<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Config;
use App\Models\EmailTemplate;
use App\Models\PcbOrder;
use App\Services\EmailTemplateService;
use App\Services\CredentialService;

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
                ['var' => '{{order_number}}', 'desc' => 'Unique Order Number (e.g. M00001)'],
                ['var' => '{{order_date}}', 'desc' => 'Formatted Date Order Was Placed'],
                ['var' => '{{order_status}}', 'desc' => 'Current Order Status'],
                ['var' => '{{order_total}}', 'desc' => 'Total Amount Paid/Due (e.g. ₹5,000.00)'],
                ['var' => '{{company_name}}', 'desc' => 'Company Name from Settings'],
                ['var' => '{{order_url}}', 'desc' => 'Customer Dashboard Order URL'],
                ['var' => '{{board_name}}', 'desc' => 'Board / Design Name'],
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
            $renderedBody    = EmailTemplateService::replaceVariables($bodyText, $vars);

            $ccEmails  = EmailTemplateService::parseEmails($request->input('cc', $template->cc));
            $bccEmails = EmailTemplateService::parseEmails($request->input('bcc', $template->bcc));

            return response()->json([
                'success' => true,
                'data' => [
                    'key'              => $template->key,
                    'name'             => $template->name,
                    'is_active'        => (bool)($request->has('is_active') ? $request->is_active : $template->is_active),
                    'rendered_subject' => $renderedSubject,
                    'rendered_body'    => $renderedBody,
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
        $validator = Validator::make($request->all(), [
            'recipient_email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Please provide a valid recipient email address for testing.'
            ], 422);
        }

        try {
            $template = EmailTemplate::where('id', $id)->orWhere('key', $id)->firstOrFail();
            $recipientEmail = $request->recipient_email;

            // Build rendered email using template or request overrides
            $subjectText = $request->input('subject', $template->subject);
            $bodyText    = $request->input('body', $template->body);
            $ccStr       = $request->input('cc', $template->cc);
            $bccStr      = $request->input('bcc', $template->bcc);

            $vars = EmailTemplateService::buildVariables(null, ['customer_email' => $recipientEmail]);

            $renderedSubject = '[TEST] ' . EmailTemplateService::replaceVariables($subjectText, $vars);
            $renderedBody    = EmailTemplateService::replaceVariables($bodyText, $vars);

            $ccEmails  = EmailTemplateService::parseEmails($ccStr);
            $bccEmails = EmailTemplateService::parseEmails($bccStr);

            // Configure dynamic mailer
            $mailer = 'smtp_global';
            Config::set('mail.mailers.smtp_global.host', CredentialService::get('mail', 'MAIL_GLOBAL_HOST', 'MAIL_GLOBAL_HOST', config('mail.mailers.smtp.host')));
            Config::set('mail.mailers.smtp_global.port', CredentialService::get('mail', 'MAIL_GLOBAL_PORT', 'MAIL_GLOBAL_PORT', config('mail.mailers.smtp.port', 587)));
            Config::set('mail.mailers.smtp_global.username', CredentialService::get('mail', 'MAIL_GLOBAL_USERNAME', 'MAIL_GLOBAL_USERNAME', config('mail.mailers.smtp.username')));
            Config::set('mail.mailers.smtp_global.password', CredentialService::get('mail', 'MAIL_GLOBAL_PASSWORD', 'MAIL_GLOBAL_PASSWORD', config('mail.mailers.smtp.password')));
            Config::set('mail.from.address', CredentialService::get('mail', 'MAIL_GLOBAL_FROM_ADDRESS', 'MAIL_GLOBAL_FROM_ADDRESS', config('mail.from.address')));
            Config::set('mail.from.name', CredentialService::get('mail', 'MAIL_GLOBAL_FROM_NAME', 'MAIL_GLOBAL_FROM_NAME', config('mail.from.name')));

            Mail::mailer($mailer)->send([], [], function ($message) use ($recipientEmail, $renderedSubject, $renderedBody, $ccEmails, $bccEmails) {
                $message->to($recipientEmail)
                    ->subject($renderedSubject)
                    ->html($renderedBody);

                if (!empty($ccEmails)) {
                    $message->cc($ccEmails);
                }
                if (!empty($bccEmails)) {
                    $message->bcc($bccEmails);
                }
            });

            return response()->json([
                'success' => true,
                'message' => "Test email successfully sent to '{$recipientEmail}'!",
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to send test email: ' . $th->getMessage()
            ], 500);
        }
    }
}
