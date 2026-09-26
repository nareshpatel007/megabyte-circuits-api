<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SystemHealthService;
use App\Services\MaintenanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

class SystemHealthController extends Controller
{
    protected SystemHealthService $healthService;
    protected MaintenanceService $maintenanceService;

    public function __construct(SystemHealthService $healthService, MaintenanceService $maintenanceService)
    {
        $this->healthService = $healthService;
        $this->maintenanceService = $maintenanceService;
    }

    /**
     * Get structured system health summary.
     */
    public function index(Request $request)
    {
        try {
            $forceRefresh = $request->query('refresh') == '1';
            $cacheKey = 'system_health_dashboard_summary';

            if ($forceRefresh) {
                Cache::forget($cacheKey);
            }

            $healthData = Cache::remember($cacheKey, 15, function () {
                return $this->healthService->getOverallHealth();
            });

            return response()->json([
                'success' => true,
                'data' => $healthData,
                'available_maintenance_actions' => $this->maintenanceService->getAvailableActions(),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch system health stats: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get paginated recent application logs.
     */
    public function logs(Request $request)
    {
        try {
            $logPath = storage_path('logs/laravel.log');
            if (!file_exists($logPath)) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'log_size_mb' => 0,
                    'message' => 'No log file found at storage/logs/laravel.log',
                ]);
            }

            $levelFilter = strtolower($request->query('level', 'all'));
            $search = strtolower($request->query('search', ''));
            $linesCount = (int) $request->query('limit', 150);
            $linesCount = min(max($linesCount, 10), 500);

            $fileContent = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($fileContent === false) {
                $fileContent = [];
            }

            $logEntries = [];
            $currentEntry = null;

            // Process log entries from bottom to top
            $reversed = array_reverse($fileContent);
            foreach ($reversed as $line) {
                // Matches standard Laravel log line start format: [2026-09-26 10:20:00] local.ERROR: ...
                if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] (\w+)\.(\w+): (.*)$/', $line, $matches)) {
                    if ($currentEntry) {
                        $logEntries[] = $currentEntry;
                    }

                    $level = strtolower($matches[3]); // ERROR, WARNING, INFO, DEBUG
                    $message = $matches[4];

                    $currentEntry = [
                        'timestamp' => $matches[1],
                        'environment' => $matches[2],
                        'level' => strtoupper($level),
                        'message' => $this->healthService->maskSecrets($message),
                        'trace' => '',
                    ];
                } else {
                    if ($currentEntry) {
                        $currentEntry['trace'] = $this->healthService->maskSecrets($line) . "\n" . $currentEntry['trace'];
                    }
                }

                if (count($logEntries) >= $linesCount) {
                    break;
                }
            }

            if ($currentEntry && count($logEntries) < $linesCount) {
                $logEntries[] = $currentEntry;
            }

            // Filter by level & search keyword
            $filtered = array_values(array_filter($logEntries, function ($entry) use ($levelFilter, $search) {
                if ($levelFilter !== 'all' && strtolower($entry['level']) !== $levelFilter) {
                    return false;
                }
                if ($search !== '') {
                    $haystack = strtolower($entry['message'] . ' ' . $entry['trace'] . ' ' . $entry['timestamp']);
                    if (!str_contains($haystack, $search)) {
                        return false;
                    }
                }
                return true;
            }));

            return response()->json([
                'success' => true,
                'data' => $filtered,
                'log_size_mb' => round(filesize($logPath) / 1048576, 2),
                'total_entries' => count($filtered),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch application logs: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get maintenance execution audit logs.
     */
    public function audit(Request $request)
    {
        try {
            if (!Schema::hasTable('system_maintenance_logs')) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'total' => 0,
                ]);
            }

            $perPage = (int) $request->query('per_page', 20);
            $logs = DB::table('system_maintenance_logs')
                ->orderBy('created_at', 'desc')
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $logs->items(),
                'total' => $logs->total(),
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch maintenance audit logs: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get failed queue jobs.
     */
    public function failedJobs(Request $request)
    {
        try {
            if (!Schema::hasTable('failed_jobs')) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'total' => 0,
                ]);
            }

            $perPage = (int) $request->query('per_page', 20);
            $jobs = DB::table('failed_jobs')
                ->orderBy('failed_at', 'desc')
                ->paginate($perPage);

            // Clean exception messages for display
            $items = array_map(function ($job) {
                $job->exception_summary = substr($this->healthService->maskSecrets($job->exception), 0, 400);
                return $job;
            }, $jobs->items());

            return response()->json([
                'success' => true,
                'data' => $items,
                'total' => $jobs->total(),
                'current_page' => $jobs->currentPage(),
                'last_page' => $jobs->lastPage(),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch failed jobs: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Execute an allowlisted safe maintenance operation.
     */
    public function executeMaintenance(Request $request)
    {
        $action = $request->input('action');
        if (empty($action)) {
            return response()->json([
                'success' => false,
                'message' => 'Maintenance action parameter is required.',
            ], 422);
        }

        // Rate limiting check
        $adminId = $request->attributes->get('admin_id') ?: $request->ip();
        $rateKey = "maintenance_rate_limit_{$adminId}";
        if (Cache::has($rateKey)) {
            return response()->json([
                'success' => false,
                'message' => 'Maintenance action rate limited. Please wait a few seconds before running another operation.',
            ], 429);
        }
        Cache::put($rateKey, true, 3); // 3 seconds cooldown between maintenance operations

        try {
            $result = $this->maintenanceService->executeAction($action, $request);
            Cache::forget('system_health_dashboard_summary');

            return response()->json([
                'success' => $result['success'],
                'data' => $result,
                'message' => $result['success'] 
                    ? "Successfully executed maintenance: {$result['label']}" 
                    : "Maintenance operation failed: {$result['error_message']}",
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Maintenance operation error: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Perform failed queue job action (retry, delete, flush).
     */
    public function failedJobsAction(Request $request)
    {
        $operation = $request->input('operation'); // retry, retry_all, delete, flush
        $id = $request->input('id');

        try {
            $output = '';
            $status = 'success';

            if ($operation === 'retry' && $id) {
                Artisan::call("queue:retry {$id}");
                $output = Artisan::output();
            } elseif ($operation === 'retry_all') {
                Artisan::call('queue:retry all');
                $output = Artisan::output();
            } elseif ($operation === 'delete' && $id) {
                Artisan::call("queue:forget {$id}");
                $output = Artisan::output();
            } elseif ($operation === 'flush') {
                Artisan::call('queue:flush');
                $output = Artisan::output();
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid failed job operation or missing ID parameter.',
                ], 422);
            }

            // Log action into maintenance audit
            $this->maintenanceService->logAuditRecord([
                'admin_id' => $request->attributes->get('admin_id'),
                'admin_name' => $request->attributes->get('admin_name', 'Admin'),
                'action' => "queue_failed_jobs_{$operation}",
                'target_system' => 'Queue',
                'status' => 'success',
                'started_at' => now(),
                'completed_at' => now(),
                'duration_ms' => 10,
                'ip_address' => $request->ip(),
                'user_agent' => substr($request->userAgent() ?? '', 0, 500),
                'error_message' => null,
                'metadata' => json_encode(['operation' => $operation, 'id' => $id, 'output' => trim($output)]),
            ]);

            Cache::forget('system_health_dashboard_summary');

            return response()->json([
                'success' => true,
                'message' => "Queue action '{$operation}' completed successfully.",
                'output' => trim($output),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed queue operation error: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Send test email to verify Mail SMTP configuration.
     */
    public function testMail(Request $request)
    {
        $recipientEmail = $request->input('recipient_email');
        if (empty($recipientEmail) || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            return response()->json([
                'success' => false,
                'message' => 'Valid recipient email address is required.',
            ], 422);
        }

        $startMicro = microtime(true);
        $startedAt = now();
        $status = 'success';
        $errorMessage = null;

        try {
            Mail::raw("System Health Mail Test\n\nThis is a test email sent from the Laravel Admin System Health Center.\nTime: " . date('Y-m-d H:i:s'), function ($message) use ($recipientEmail) {
                $message->to($recipientEmail)
                    ->subject('Laravel System Health: SMTP Test Email');
            });
        } catch (\Throwable $e) {
            $status = 'failed';
            $errorMessage = $e->getMessage();
        }

        $durationMs = (int) round((microtime(true) - $startMicro) * 1000);

        // Audit Logging
        $this->maintenanceService->logAuditRecord([
            'admin_id' => $request->attributes->get('admin_id'),
            'admin_name' => $request->attributes->get('admin_name', 'Admin'),
            'action' => 'send_test_email',
            'target_system' => 'Mail',
            'status' => $status,
            'started_at' => $startedAt,
            'completed_at' => now(),
            'duration_ms' => $durationMs,
            'ip_address' => $request->ip(),
            'user_agent' => substr($request->userAgent() ?? '', 0, 500),
            'error_message' => $errorMessage,
            'metadata' => json_encode(['recipient' => $recipientEmail]),
        ]);

        if ($status === 'failed') {
            return response()->json([
                'success' => false,
                'message' => "Failed to send test email: {$errorMessage}",
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => "Test email successfully sent to {$recipientEmail}",
            'duration_ms' => $durationMs,
        ]);
    }

    /**
     * Perform explicit connectivity check for third-party services.
     */
    public function testExternalService(Request $request)
    {
        $service = $request->input('service'); // gerber, razorpay, digikey

        if ($service === 'gerber') {
            $gerberUrl = config('services.python_gerber.url', env('PYTHON_GERBER_API_URL', 'http://127.0.0.1:8000'));
            $start = microtime(true);
            try {
                $res = Http::timeout(5)->get(rtrim($gerberUrl, '/') . '/health');
                $ms = round((microtime(true) - $start) * 1000, 2);
                if ($res->successful()) {
                    return response()->json([
                        'success' => true,
                        'message' => 'Gerber Python Service is online and healthy.',
                        'response_time_ms' => $ms,
                        'details' => $res->json(),
                    ]);
                } else {
                    return response()->json([
                        'success' => false,
                        'message' => "Gerber Service responded with status HTTP {$res->status()}.",
                        'response_time_ms' => $ms,
                    ], 502);
                }
            } catch (\Throwable $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'Could not connect to Gerber Python Service: ' . $e->getMessage(),
                ], 502);
            }
        }

        if ($service === 'razorpay') {
            $keyId = \App\Services\CredentialService::get('razorpay', 'RAZORPAY_KEY_ID');
            if (empty($keyId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Razorpay API Key ID is not configured in credentials or environment.',
                ], 422);
            }
            $mode = \App\Services\CredentialService::get('razorpay', 'RAZORPAY_MODE', 'RAZORPAY_MODE', config('services.razorpay.mode', 'sandbox'));
            return response()->json([
                'success' => true,
                'message' => 'Razorpay credentials present and configured.',
                'key_id_preview' => (strlen($keyId) > 8 ? substr($keyId, 0, 8) : substr($keyId, 0, 3)) . '********',
                'mode' => $mode,
            ]);
        }

        if ($service === 'digikey') {
            $clientId = \App\Services\CredentialService::get('digikey', 'DIGIKEY_CLIENT_ID');
            if (empty($clientId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'DigiKey Client ID is not configured in credentials or environment.',
                ], 422);
            }
            return response()->json([
                'success' => true,
                'message' => 'DigiKey API credentials present and configured.',
                'client_id_preview' => (strlen($clientId) > 6 ? substr($clientId, 0, 6) : substr($clientId, 0, 3)) . '********',
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Unknown service specified for testing.',
        ], 422);
    }

    /**
     * Unauthenticated lightweight public health check endpoint (/api/health).
     */
    public function publicHealth()
    {
        try {
            // Lightweight 1ms DB check
            DB::select('SELECT 1');

            return response()->json([
                'status' => 'ok',
                'timestamp' => now()->toIso8601String(),
                'environment' => config('app.env'),
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'degraded',
                'timestamp' => now()->toIso8601String(),
                'error' => 'Database connection failed',
            ], 500);
        }
    }
}
