<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Services\CredentialService;
use App\Models\Credential;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class EmailLogController extends Controller
{
    /**
     * Display a listing of email logs with filters, search, and pagination.
     */
    public function index(Request $request)
    {
        $query = EmailLog::query();

        // 1. Search (Recipient email, name, subject, template_key, email_type, error_message)
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('to', 'like', "%{$search}%")
                  ->orWhere('from_email', 'like', "%{$search}%")
                  ->orWhere('from_name', 'like', "%{$search}%")
                  ->orWhere('subject', 'like', "%{$search}%")
                  ->orWhere('template_key', 'like', "%{$search}%")
                  ->orWhere('email_type', 'like', "%{$search}%")
                  ->orWhere('error_message', 'like', "%{$search}%");
            });
        }

        // 2. Filter by Status
        if ($status = $request->input('status')) {
            if ($status !== 'all') {
                $query->where('status', $status);
            }
        }

        // 3. Filter by Template Key
        if ($templateKey = $request->input('template_key')) {
            if ($templateKey !== 'all') {
                $query->where('template_key', $templateKey);
            }
        }

        // 4. Filter by Email Type
        if ($emailType = $request->input('email_type')) {
            if ($emailType !== 'all') {
                $query->where('email_type', $emailType);
            }
        }

        // 5. Filter by Sender (from_email)
        if ($fromEmail = $request->input('from_email')) {
            if ($fromEmail !== 'all') {
                $query->where('from_email', $fromEmail);
            }
        }

        // 6. Filter by Test Emails
        if ($request->has('is_test') && $request->input('is_test') !== null && $request->input('is_test') !== 'all') {
            $isTest = filter_var($request->input('is_test'), FILTER_VALIDATE_BOOLEAN);
            $query->where('is_test', $isTest);
        }

        // 7. Date Range Filter
        $datePreset = $request->input('date_range');
        if ($datePreset) {
            $now = Carbon::now();
            if ($datePreset === 'today') {
                $query->whereDate('created_at', $now->toDateString());
            } elseif ($datePreset === 'yesterday') {
                $query->whereDate('created_at', $now->copy()->subDay()->toDateString());
            } elseif ($datePreset === '7days') {
                $query->where('created_at', '>=', $now->copy()->subDays(7)->startOfDay());
            } elseif ($datePreset === '30days') {
                $query->where('created_at', '>=', $now->copy()->subDays(30)->startOfDay());
            } elseif ($datePreset === '90days') {
                $query->where('created_at', '>=', $now->copy()->subDays(90)->startOfDay());
            } elseif ($datePreset === 'custom') {
                if ($startDate = $request->input('start_date')) {
                    $query->where('created_at', '>=', Carbon::parse($startDate)->startOfDay());
                }
                if ($endDate = $request->input('end_date')) {
                    $query->where('created_at', '<=', Carbon::parse($endDate)->endOfDay());
                }
            }
        }

        // 8. Sorting
        $sortBy = $request->input('sort_by', 'created_at');
        $sortDir = strtolower($request->input('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        $allowedSorts = ['id', 'created_at', 'sent_at', 'status', 'to', 'subject', 'template_key'];
        if (!in_array($sortBy, $allowedSorts)) {
            $sortBy = 'created_at';
        }

        $query->orderBy($sortBy, $sortDir);

        // Lightweight selection for table view (omitting full HTML body)
        $perPage = max(10, min(100, (int)$request->input('per_page', 15)));

        $logs = $query->select([
            'id',
            'template_key',
            'email_type',
            'order_id',
            'inventory_item_id',
            'customer_id',
            'from_email',
            'from_name',
            'to',
            'cc',
            'bcc',
            'subject',
            'status',
            'provider',
            'retry_count',
            'is_test',
            'error_message',
            'sent_at',
            'failed_at',
            'created_at'
        ])->paginate($perPage);

        // Fetch distinct metadata for filter dropdowns
        $availableTemplates = EmailTemplate::select('key', 'name')->orderBy('name')->get();
        $availableSenders   = EmailLog::distinct()->whereNotNull('from_email')->pluck('from_email')->values();
        $availableTypes     = EmailLog::distinct()->whereNotNull('email_type')->pluck('email_type')->values();

        return response()->json([
            'success' => true,
            'data'    => $logs->items(),
            'meta'    => [
                'current_page' => $logs->currentPage(),
                'last_page'    => $logs->lastPage(),
                'per_page'     => $logs->perPage(),
                'total'        => $logs->total(),
                'from'         => $logs->firstItem(),
                'to'           => $logs->lastItem(),
            ],
            'filters' => [
                'templates' => $availableTemplates,
                'senders'   => $availableSenders,
                'types'     => $availableTypes,
            ]
        ]);
    }

    /**
     * Get aggregate statistics for email log dashboard cards.
     */
    public function statistics()
    {
        $todayStr = Carbon::today()->toDateString();

        $stats = EmailLog::selectRaw("
            COUNT(*) as total,
            SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent_count,
            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count,
            SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) as queued_count,
            SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing_count,
            SUM(CASE WHEN status = 'skipped' THEN 1 ELSE 0 END) as skipped_count,
            SUM(CASE WHEN DATE(created_at) = ? THEN 1 ELSE 0 END) as today_count,
            SUM(CASE WHEN DATE(created_at) = ? AND status = 'failed' THEN 1 ELSE 0 END) as today_failed_count
        ", [$todayStr, $todayStr])->first();

        return response()->json([
            'success' => true,
            'data' => [
                'total_emails'    => (int)($stats->total ?? 0),
                'sent'            => (int)($stats->sent_count ?? 0),
                'failed'          => (int)($stats->failed_count ?? 0),
                'queued'          => (int)($stats->queued_count ?? 0),
                'processing'      => (int)($stats->processing_count ?? 0),
                'skipped'         => (int)($stats->skipped_count ?? 0),
                'today_count'     => (int)($stats->today_count ?? 0),
                'today_failed'    => (int)($stats->today_failed_count ?? 0),
            ]
        ]);
    }

    /**
     * Display full log details including rendered body and relationships.
     */
    public function show($id)
    {
        $log = EmailLog::with(['order', 'inventoryItem', 'customer', 'template'])->find($id);

        if (!$log) {
            return response()->json([
                'success' => false,
                'message' => 'Email log entry not found.'
            ], 404);
        }

        // If body is missing, attempt to render sample template preview dynamically
        $body = $log->body;
        if (empty($body) && $log->template_key) {
            try {
                if ($log->order_id && $log->order) {
                    $rendered = \App\Services\EmailTemplateService::render($log->template_key, $log->order);
                    $body = $rendered['body'] ?? null;
                } elseif ($log->inventory_item_id && $log->inventoryItem) {
                    $rendered = \App\Services\EmailTemplateService::renderInventory($log->template_key, $log->inventoryItem);
                    $body = $rendered['body'] ?? null;
                } else {
                    $rendered = \App\Services\EmailTemplateService::render($log->template_key);
                    $body = $rendered['body'] ?? null;
                }
            } catch (\Throwable $e) {
                $body = '<p style="color:#ef4444;">Unable to render preview for this email log.</p>';
            }
        }

        $logData = $log->toArray();
        $logData['body'] = $body;

        return response()->json([
            'success' => true,
            'data'    => $logData
        ]);
    }

    /**
     * Delete a single email log record.
     */
    public function destroy($id)
    {
        $log = EmailLog::find($id);

        if (!$log) {
            return response()->json([
                'success' => false,
                'message' => 'Email log not found.'
            ], 404);
        }

        $log->delete();

        return response()->json([
            'success' => true,
            'message' => 'Email log deleted successfully.'
        ]);
    }

    /**
     * Delete multiple selected email log records.
     */
    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer'
        ]);

        $ids = $request->input('ids');
        $deletedCount = EmailLog::whereIn('id', $ids)->delete();

        return response()->json([
            'success' => true,
            'message' => "Successfully deleted {$deletedCount} email log(s)."
        ]);
    }

    /**
     * Manually trigger cleanup of expired email logs based on configured retention period or input.
     */
    public function cleanup(Request $request)
    {
        $days = $request->input('retention_days');

        if ($days === null) {
            $days = CredentialService::get('email_logs', 'retention_days', 'EMAIL_LOG_RETENTION_DAYS', '90');
        }

        if (empty($days) || strtolower((string)$days) === 'never' || (int)$days <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Email log retention is set to Never. No logs were deleted.'
            ]);
        }

        $retentionDays = (int)$days;
        $cutoffDate = Carbon::now()->subDays($retentionDays);

        $deletedTotal = 0;
        do {
            $deleted = EmailLog::where('created_at', '<', $cutoffDate)
                ->limit(1000)
                ->delete();
            $deletedTotal += $deleted;
        } while ($deleted > 0);

        Log::info("Admin manual cleanup: Deleted {$deletedTotal} logs older than {$retentionDays} days (Cutoff: {$cutoffDate->toDateTimeString()}).");

        return response()->json([
            'success'        => true,
            'message'        => "Email log cleanup completed successfully.",
            'deleted_count'  => $deletedTotal,
            'retention_days' => $retentionDays,
            'cutoff_date'    => $cutoffDate->toDateTimeString(),
        ]);
    }

    /**
     * Export email logs to CSV format with active filters applied.
     */
    public function export(Request $request)
    {
        $query = EmailLog::query();

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('to', 'like', "%{$search}%")
                  ->orWhere('subject', 'like', "%{$search}%")
                  ->orWhere('template_key', 'like', "%{$search}%");
            });
        }

        if ($status = $request->input('status')) {
            if ($status !== 'all') $query->where('status', $status);
        }

        if ($templateKey = $request->input('template_key')) {
            if ($templateKey !== 'all') $query->where('template_key', $templateKey);
        }

        $datePreset = $request->input('date_range');
        if ($datePreset) {
            $now = Carbon::now();
            if ($datePreset === 'today') {
                $query->whereDate('created_at', $now->toDateString());
            } elseif ($datePreset === '30days') {
                $query->where('created_at', '>=', $now->copy()->subDays(30)->startOfDay());
            }
        }

        $logs = $query->orderBy('created_at', 'desc')->limit(5000)->get();

        $headers = [
            "Content-type"        => "text/csv",
            "Content-Disposition" => "attachment; filename=email_logs_" . date('Y-m-d_H-i-s') . ".csv",
            "Pragma"              => "no-cache",
            "Cache-Control"       => "must-revalidate, post-check=0, pre-check=0",
            "Expires"             => "0"
        ];

        $callback = function () use ($logs) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['ID', 'Template Key', 'Recipient Email', 'From Email', 'From Name', 'Subject', 'Status', 'Sent At', 'Error Message', 'Created At']);

            foreach ($logs as $log) {
                fputcsv($file, [
                    $log->id,
                    $log->template_key,
                    $log->to,
                    $log->from_email,
                    $log->from_name,
                    $log->subject,
                    $log->status,
                    $log->sent_at ? $log->sent_at->toDateTimeString() : 'N/A',
                    $log->error_message ?? '',
                    $log->created_at ? $log->created_at->toDateTimeString() : '',
                ]);
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Get email log configuration settings.
     */
    public function getSettings()
    {
        $retentionDays = CredentialService::get('email_logs', 'retention_days', 'EMAIL_LOG_RETENTION_DAYS', '90');
        $loggingEnabled = CredentialService::get('email_logs', 'logging_enabled', 'EMAIL_LOGGING_ENABLED', '1');
        $autoCleanup = CredentialService::get('email_logs', 'auto_cleanup', 'EMAIL_LOG_AUTO_CLEANUP', '1');

        return response()->json([
            'success' => true,
            'data' => [
                'retention_days'  => $retentionDays,
                'logging_enabled' => filter_var($loggingEnabled, FILTER_VALIDATE_BOOLEAN),
                'auto_cleanup'    => filter_var($autoCleanup, FILTER_VALIDATE_BOOLEAN),
            ]
        ]);
    }

    /**
     * Update email log configuration settings.
     */
    public function updateSettings(Request $request)
    {
        $request->validate([
            'retention_days'  => 'required|string',
            'logging_enabled' => 'required|boolean',
            'auto_cleanup'    => 'required|boolean',
        ]);

        $retentionDays = (string)$request->input('retention_days');
        $loggingEnabled = $request->input('logging_enabled') ? '1' : '0';
        $autoCleanup = $request->input('auto_cleanup') ? '1' : '0';

        $this->saveCredential('email_logs', 'retention_days', $retentionDays, 'Email log retention period in days (7, 15, 30, 60, 90, 180, 365, never)');
        $this->saveCredential('email_logs', 'logging_enabled', $loggingEnabled, 'Enable or disable storing email logs');
        $this->saveCredential('email_logs', 'auto_cleanup', $autoCleanup, 'Enable or disable scheduled email logs cleanup');

        return response()->json([
            'success' => true,
            'message' => 'Email log settings updated successfully.',
            'data'    => [
                'retention_days'  => $retentionDays,
                'logging_enabled' => (bool)$request->input('logging_enabled'),
                'auto_cleanup'    => (bool)$request->input('auto_cleanup'),
            ]
        ]);
    }

    private function saveCredential(string $group, string $key, string $value, string $description = '')
    {
        if (class_exists(Credential::class) && Schema::hasTable('credentials')) {
            Credential::updateOrCreate(
                ['group' => $group, 'key' => $key],
                [
                    'value'       => $value,
                    'is_active'   => true,
                    'description' => $description,
                ]
            );
        }
    }
}
