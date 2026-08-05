<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\CommonHelper as CommonHelper;
use App\MailHelper as MailHelper;
use Illuminate\Support\Facades\DB;

class CronController extends Controller
{
    // Send bulk email (Using Cron)
    public function send_bulk_email_cron(Request $request)
    {
        // Security check from public access
        if (!app()->runningInConsole()) {
            $cron_key = config('cron.secret_key');
            $request_key = $request->header('X-Cron-Token') ?? $request->query('token') ?? $request->bearerToken();

            if (empty($cron_key) || $request_key !== $cron_key) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized access.'
                ], 401);
            }
        }

        try {
            // Limit of emails per batch (configurable via env/config)
            $limit = config('cron.email_limit', 3);

            // Fetch queued emails that are ready to be sent (including scheduled checks)
            $queued_emails = DB::table('email_queue')
                ->where('is_sent', 0)
                ->where(function ($query) {
                    $query->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', date('Y-m-d H:i:s'));
                })
                ->orderBy('id', 'ASC')
                ->limit($limit)
                ->get()
                ->toArray();

            // If no queued emails
            if (empty($queued_emails)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No queued emails found.'
                ], 200);
            }

            // Loop queued emails
            foreach ($queued_emails as $row) {
                // Filter value
                $send_to = trim(strtolower($row->to));
                $template_path = $row->template_path;
                $need_cc = $row->need_cc ?? false;
                $cc_email = $row->cc_email ?? '';
                $payload_data = json_decode($row->data, true) ?? [];

                // If empty CC, fallback to configurable address or global from address
                if (empty($cc_email)) {
                    $cc_email = config('mail.from.address', 'support@connectly360.com');
                }

                // Check is valid email format
                if (!filter_var($send_to, FILTER_VALIDATE_EMAIL)) {
                    DB::table('email_queue')->where('id', $row->id)->update([
                        'is_sent' => 1,
                        'remark' => 'Invalid email format'
                    ]);
                    continue;
                }

                // Generate unique track ID
                $track_id = CommonHelper::generate_random_string(8) . time();

                // Find unsubscribe email
                $unsubscribe_email = DB::table('email_unsubscribe')
                    ->select('*')
                    ->where('email', $send_to)
                    ->first();

                // If unsubscribe email found
                if (!empty($unsubscribe_email)) {
                    DB::table('email_queue')->where('id', $row->id)->update([
                        'is_sent' => 1,
                        'remark' => 'Unsubscribe email found'
                    ]);
                    continue;
                }

                // Set data
                $payload_data['subject'] = $row->subject;
                $payload_data['template'] = $template_path;
                $payload_data['unsubscribe_token'] = CommonHelper::encrypt_string($send_to);
                $payload_data['track_id'] = $track_id;

                // If attachments exist
                if (!empty($payload_data['attachments'])) {
                    // Define array
                    $new_attachments = [];

                    // Remove empty value from array
                    $payload_data['attachments'] = array_filter($payload_data['attachments']);

                    // Loop every attachment
                    foreach ($payload_data['attachments'] as $key => $attachment) {
                        // If attachment is valid
                        if (strpos($attachment, 'https://') !== false) {
                            $new_attachments[] = $attachment;
                        }
                    }

                    // Update attachments
                    $payload_data['attachments'] = $new_attachments;
                } else {
                    // Update attachments
                    $payload_data['attachments'] = [];
                }

                // If need CC for this email
                if ($need_cc) {
                    $payload_data['cc'] = $cc_email;
                }

                try {
                    // Send email using Mailable
                    $result = MailHelper::send_email($send_to, $payload_data);

                    // If email sent
                    if (is_array($result) && !empty($result['success'])) {
                        // Update is_sent
                        DB::table('email_queue')->where('id', $row->id)->update([
                            'is_sent' => 1,
                            'remark' => NULL,
                            'sent_at' => date('Y-m-d H:i:s')
                        ]);

                        // Send email log
                        DB::table('email_log')->insert([
                            'track_id' => $track_id,
                            'to' => $send_to,
                            'subject' => $row->subject,
                            'template_path' => $row->template_path,
                            'attachments' => !empty($payload_data['attachments']) ? json_encode($payload_data['attachments']) : '[]',
                            'payload_data' => $row->data,
                            'created_at' => date('Y-m-d H:i:s')
                        ]);
                    } else {
                        // Update is_sent with specific error message
                        $error_msg = is_array($result) ? ($result['message'] ?? 'Failed to send email') : 'Unknown failure';
                        DB::table('email_queue')->where('id', $row->id)->update([
                            'is_sent' => 1,
                            'remark' => $error_msg
                        ]);
                    }
                } catch (\Throwable $th) {
                    // Update is_sent
                    DB::table('email_queue')->where('id', $row->id)->update([
                        'is_sent' => 1,
                        'remark' => $th->getMessage()
                    ]);
                }
            }

            // Send response
            return response()->json([
                'success' => true,
                'message' => 'Email has been sent successfully.'
            ]);
        } catch (\Throwable $th) {
            // Send response
            return response()->json([
                'success' => false,
                'message' => 'API request failed. Please try again.'
            ], 500);
        }
    }
}
