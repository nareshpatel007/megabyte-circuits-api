<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\MailHelper;

class ContactController extends Controller
{
    public function submitContact(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|min:2|max:200',
            'email' => 'required|email|max:200',
            'phone' => 'nullable|string|max:50',
            'company' => 'nullable|string|max:200',
            'serviceType' => 'required|string|max:100',
            'message' => 'required|string|min:10',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $validated = $validator->validated();

        // Destination admin email address
        $adminEmail = env('MAIL_BCC_ADDRESS', env('MAIL_GLOBAL_FROM_ADDRESS', 'quote@megabytecircuit.com'));

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
