<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Console\Scheduling\Schedule;

class SystemHealthService
{
    /**
     * Return comprehensive system health metrics and statuses.
     */
    public function getOverallHealth(): array
    {
        $application = $this->checkApplication();
        $database = $this->checkDatabase();
        $cache = $this->checkCache();
        $queue = $this->checkQueue();
        $scheduler = $this->checkScheduler();
        $storage = $this->checkStorage();
        $php = $this->checkPhp();
        $logs = $this->checkLogs();
        $services = $this->checkExternalServices();

        $subsystems = [
            'application' => $application['status'],
            'database' => $database['status'],
            'cache' => $cache['status'],
            'queue' => $queue['status'],
            'scheduler' => $scheduler['status'],
            'storage' => $storage['status'],
            'php' => $php['status'],
            'logs' => $logs['status'],
            'services' => $services['status'],
        ];

        $overallStatus = 'healthy';
        if (in_array('critical', $subsystems, true)) {
            $overallStatus = 'critical';
        } elseif (in_array('warning', $subsystems, true)) {
            $overallStatus = 'warning';
        } elseif (in_array('unknown', $subsystems, true)) {
            $overallStatus = 'warning';
        }

        $issues = $this->compilePotentialIssues($application, $database, $cache, $queue, $scheduler, $storage, $logs, $services);

        return [
            'overall_status' => $overallStatus,
            'timestamp' => now()->toIso8601String(),
            'subsystem_statuses' => $subsystems,
            'potential_issues' => $issues,
            'application' => $application,
            'database' => $database,
            'cache' => $cache,
            'queue' => $queue,
            'scheduler' => $scheduler,
            'storage' => $storage,
            'php' => $php,
            'logs' => $logs,
            'services' => $services,
        ];
    }

    /**
     * Check Application info & environment settings.
     */
    public function checkApplication(): array
    {
        $status = 'healthy';
        $warnings = [];

        $env = config('app.env', 'unknown');
        $debug = (bool) config('app.debug', false);
        $appKey = config('app.key');

        if ($env === 'production' && $debug) {
            $status = 'warning';
            $warnings[] = 'APP_DEBUG is enabled in production environment.';
        }

        if (empty($appKey)) {
            $status = 'critical';
            $warnings[] = 'Application Key (APP_KEY) is not set or empty.';
        }

        $uptime = null;
        if (function_exists('shell_exec') && strtolower(substr(PHP_OS, 0, 3)) !== 'win') {
            try {
                $uptimeRaw = @shell_exec('uptime -p');
                if ($uptimeRaw) {
                    $uptime = trim($uptimeRaw);
                }
            } catch (\Throwable $e) {
                // Ignore shell restrictions
            }
        }

        return [
            'status' => $status,
            'laravel_version' => app()->version(),
            'php_version' => PHP_VERSION,
            'environment' => $env,
            'debug_mode' => $debug,
            'app_url' => config('app.url', 'http://localhost'),
            'app_key_configured' => !empty($appKey),
            'server_hostname' => gethostname() ?: 'Unknown',
            'current_time' => now()->toIso8601String(),
            'uptime' => $uptime,
            'warnings' => $warnings,
        ];
    }

    /**
     * Check Database connectivity, query execution time & connection driver.
     */
    public function checkDatabase(): array
    {
        $status = 'healthy';
        $responseTimeMs = 0;
        $error = null;
        $dbName = null;
        $driver = config('database.default', 'mysql');
        $serverVersion = 'Unknown';

        try {
            $start = microtime(true);
            DB::select('SELECT 1');
            $responseTimeMs = round((microtime(true) - $start) * 1000, 2);

            $dbName = DB::connection()->getDatabaseName();

            try {
                $pdo = DB::connection()->getPdo();
                if ($pdo) {
                    $serverVersion = $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);
                }
            } catch (\Throwable $e) {
                // Ignore PDO details failure
            }

            if ($responseTimeMs > 500) {
                $status = 'warning';
            }
        } catch (\Throwable $e) {
            $status = 'critical';
            $error = $this->maskSecrets($e->getMessage());
        }

        return [
            'status' => $status,
            'connection' => $driver,
            'database_name' => $dbName ? basename($dbName) : 'Unknown',
            'response_time_ms' => $responseTimeMs,
            'server_version' => $serverVersion,
            'error' => $error,
        ];
    }

    /**
     * Check Cache read/write performance & status of Laravel cache files.
     */
    public function checkCache(): array
    {
        $status = 'healthy';
        $driver = config('cache.default', 'file');
        $responseTimeMs = 0;
        $testResult = false;
        $error = null;

        try {
            $testKey = 'system_health_test_' . time();
            $start = microtime(true);
            
            Cache::put($testKey, 'health_ok', 10);
            $val = Cache::get($testKey);
            Cache::forget($testKey);

            $responseTimeMs = round((microtime(true) - $start) * 1000, 2);

            if ($val === 'health_ok') {
                $testResult = true;
            } else {
                $status = 'warning';
                $error = 'Cache test key write/read verification failed.';
            }

            if ($responseTimeMs > 300) {
                $status = 'warning';
            }
        } catch (\Throwable $e) {
            $status = 'critical';
            $error = $this->maskSecrets($e->getMessage());
        }

        $cachesStatus = [
            'config_cached' => app()->configurationIsCached(),
            'routes_cached' => app()->routesAreCached(),
            'events_cached' => app()->eventsAreCached(),
            'views_cached' => File::exists(storage_path('framework/views')) && count(File::files(storage_path('framework/views'))) > 0,
        ];

        return [
            'status' => $status,
            'driver' => $driver,
            'response_time_ms' => $responseTimeMs,
            'test_passed' => $testResult,
            'error' => $error,
            'caches_status' => $cachesStatus,
        ];
    }

    /**
     * Check Queue status, pending jobs count & failed jobs count.
     */
    public function checkQueue(): array
    {
        $status = 'healthy';
        $driver = config('queue.default', 'sync');
        $pendingJobs = 0;
        $failedJobs = 0;
        $lastFailedJobAt = null;
        $oldestPendingAgeSec = null;

        try {
            if (Schema::hasTable('failed_jobs')) {
                $failedJobs = DB::table('failed_jobs')->count();
                $lastFailed = DB::table('failed_jobs')->latest('failed_at')->first();
                if ($lastFailed) {
                    $lastFailedJobAt = $lastFailed->failed_at;
                }
            }

            if (Schema::hasTable('jobs')) {
                $pendingJobs = DB::table('jobs')->count();
                $oldestJob = DB::table('jobs')->orderBy('created_at', 'asc')->first();
                if ($oldestJob) {
                    $oldestPendingAgeSec = now()->timestamp - $oldestJob->created_at;
                }
            }

            if ($failedJobs > 10 || ($oldestPendingAgeSec && $oldestPendingAgeSec > 3600)) {
                $status = 'warning';
            }
            if ($failedJobs > 50) {
                $status = 'critical';
            }
        } catch (\Throwable $e) {
            $status = 'unknown';
        }

        return [
            'status' => $status,
            'driver' => $driver,
            'pending_jobs' => $pendingJobs,
            'failed_jobs' => $failedJobs,
            'last_failed_job_at' => $lastFailedJobAt,
            'oldest_pending_age_sec' => $oldestPendingAgeSec,
        ];
    }

    /**
     * Check Scheduler status and heartbeat.
     */
    public function checkScheduler(): array
    {
        $status = 'healthy';
        $lastHeartbeat = Cache::get('system_health_scheduler_heartbeat');
        $minutesAgo = null;

        if ($lastHeartbeat) {
            $minutesAgo = round((now()->timestamp - (int) $lastHeartbeat) / 60, 1);
            if ($minutesAgo > 5) {
                $status = 'warning';
            }
            if ($minutesAgo > 30) {
                $status = 'critical';
            }
        } else {
            $status = 'unknown';
        }

        $scheduledTasksCount = 8; // Default scheduled tasks in Console/Kernel.php

        return [
            'status' => $status,
            'last_heartbeat_at' => $lastHeartbeat ? date('Y-m-d H:i:s', (int)$lastHeartbeat) : null,
            'minutes_since_last_heartbeat' => $minutesAgo,
            'scheduled_tasks_count' => $scheduledTasksCount,
        ];
    }

    /**
     * Check Storage space & directory writability.
     */
    public function checkStorage(): array
    {
        $status = 'healthy';
        $storagePath = storage_path();
        $isWritable = is_writable($storagePath);

        $diskTotalBytes = null;
        $diskFreeBytes = null;
        $usedPercentage = null;

        if (function_exists('disk_total_space') && function_exists('disk_free_space')) {
            try {
                $diskTotalBytes = @disk_total_space($storagePath);
                $diskFreeBytes = @disk_free_space($storagePath);

                if ($diskTotalBytes && $diskTotalBytes > 0) {
                    $usedBytes = $diskTotalBytes - $diskFreeBytes;
                    $usedPercentage = round(($usedBytes / $diskTotalBytes) * 100, 1);

                    if ($usedPercentage >= 90) {
                        $status = 'warning';
                    }
                    if ($usedPercentage >= 98) {
                        $status = 'critical';
                    }
                }
            } catch (\Throwable $e) {
                // Ignore disk space API failures
            }
        }

        if (!$isWritable) {
            $status = 'critical';
        }

        return [
            'status' => $status,
            'is_writable' => $isWritable,
            'disk_total_gb' => $diskTotalBytes ? round($diskTotalBytes / 1073741824, 2) : null,
            'disk_free_gb' => $diskFreeBytes ? round($diskFreeBytes / 1073741824, 2) : null,
            'used_percentage' => $usedPercentage,
            'directories' => [
                'storage' => is_writable(storage_path()),
                'cache' => is_writable(storage_path('framework/cache')),
                'views' => is_writable(storage_path('framework/views')),
                'logs' => is_writable(storage_path('logs')),
            ]
        ];
    }

    /**
     * Check PHP configuration & OPcache status.
     */
    public function checkPhp(): array
    {
        $opcacheEnabled = false;
        $opcacheStats = null;

        if (function_exists('opcache_get_status')) {
            try {
                $statusObj = @opcache_get_status(false);
                if (is_array($statusObj) && isset($statusObj['opcache_enabled'])) {
                    $opcacheEnabled = (bool) $statusObj['opcache_enabled'];
                    if (isset($statusObj['opcache_statistics'])) {
                        $stats = $statusObj['opcache_statistics'];
                        $hits = $stats['hits'] ?? 0;
                        $misses = $stats['misses'] ?? 0;
                        $total = $hits + $misses;
                        $hitRate = $total > 0 ? round(($hits / $total) * 100, 1) : 0;
                        $opcacheStats = [
                            'hit_rate' => $hitRate,
                            'hits' => $hits,
                            'misses' => $misses,
                        ];
                    }
                }
            } catch (\Throwable $e) {
                // Ignore OPcache error
            }
        }

        return [
            'status' => 'healthy',
            'version' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            'timezone' => date_default_timezone_get(),
            'opcache_enabled' => $opcacheEnabled,
            'opcache_stats' => $opcacheStats,
        ];
    }

    /**
     * Check Log files and count recent errors/warnings.
     */
    public function checkLogs(): array
    {
        $status = 'healthy';
        $logPath = storage_path('logs/laravel.log');
        $logSizeMb = 0;
        $errorCount = 0;
        $warningCount = 0;
        $latestErrorTimestamp = null;
        $latestErrorSummary = null;

        if (File::exists($logPath)) {
            $bytes = File::size($logPath);
            $logSizeMb = round($bytes / 1048576, 2);

            try {
                // Read last 200 lines of log safely
                $lines = $this->readLastLines($logPath, 200);
                foreach ($lines as $line) {
                    if (str_contains($line, '.ERROR:') || str_contains($line, '.CRITICAL:') || str_contains($line, '.EMERGENCY:')) {
                        $errorCount++;
                        if (!$latestErrorSummary) {
                            $latestErrorSummary = $this->maskSecrets(substr($line, 0, 300));
                            if (preg_match('/^\[(.*?)\]/', $line, $matches)) {
                                $latestErrorTimestamp = $matches[1];
                            }
                        }
                    } elseif (str_contains($line, '.WARNING:')) {
                        $warningCount++;
                    }
                }

                if ($errorCount > 20) {
                    $status = 'warning';
                }
                if ($logSizeMb > 100) {
                    $status = 'warning';
                }
            } catch (\Throwable $e) {
                // Ignore log read error
            }
        }

        return [
            'status' => $status,
            'channel' => config('logging.default', 'stack'),
            'log_size_mb' => $logSizeMb,
            'recent_errors_count' => $errorCount,
            'recent_warnings_count' => $warningCount,
            'latest_error_at' => $latestErrorTimestamp,
            'latest_error_summary' => $latestErrorSummary,
        ];
    }

    /**
     * Check External Services connectivity & configuration.
     */
    public function checkExternalServices(): array
    {
        $status = 'healthy';
        $services = [];

        // 1. Gerber Python Service
        $gerberUrl = config('services.python_gerber.url', env('PYTHON_GERBER_API_URL', 'http://127.0.0.1:8000'));
        $gerberStatus = 'unknown';
        $gerberResponseTimeMs = 0;

        try {
            $start = microtime(true);
            $res = Http::timeout(3)->get(rtrim($gerberUrl, '/') . '/health');
            $gerberResponseTimeMs = round((microtime(true) - $start) * 1000, 2);

            if ($res->successful()) {
                $gerberStatus = 'healthy';
            } else {
                $gerberStatus = 'warning';
            }
        } catch (\Throwable $e) {
            $gerberStatus = 'warning';
        }

        $services['gerber_python'] = [
            'name' => 'Gerber Python Analysis Service',
            'url' => $gerberUrl,
            'status' => $gerberStatus,
            'response_time_ms' => $gerberResponseTimeMs,
        ];

        // 2. DigiKey Service Config
        $digikeyConfigured = !empty(env('DIGIKEY_CLIENT_ID'));
        $services['digikey'] = [
            'name' => 'DigiKey API',
            'status' => $digikeyConfigured ? 'healthy' : 'unknown',
            'configured' => $digikeyConfigured,
        ];

        // 3. Razorpay Payment Gateway Config
        $razorpayConfigured = !empty(env('RAZORPAY_KEY_ID')) || !empty(CredentialService::get('razorpay', 'RAZORPAY_KEY_ID'));
        $services['razorpay'] = [
            'name' => 'Razorpay Gateway',
            'status' => $razorpayConfigured ? 'healthy' : 'warning',
            'configured' => $razorpayConfigured,
        ];

        // 4. Mail Service
        $mailer = config('mail.default', 'smtp');
        $mailHost = config('mail.mailers.smtp.host');
        $services['mail'] = [
            'name' => 'Mail Service',
            'status' => !empty($mailHost) ? 'healthy' : 'warning',
            'mailer' => $mailer,
            'configured' => !empty($mailHost),
        ];

        return [
            'status' => $status,
            'services' => $services,
        ];
    }

    /**
     * Compile potential issues list based on system checks.
     */
    protected function compilePotentialIssues(
        array $app,
        array $db,
        array $cache,
        array $queue,
        array $scheduler,
        array $storage,
        array $logs,
        array $services
    ): array {
        $issues = [];

        if ($app['environment'] === 'production' && $app['debug_mode']) {
            $issues[] = [
                'type' => 'warning',
                'category' => 'Application',
                'title' => 'Debug Mode Active in Production',
                'description' => 'APP_DEBUG is set to true. This may expose sensitive error details to end users.',
            ];
        }

        if ($db['status'] === 'critical') {
            $issues[] = [
                'type' => 'critical',
                'category' => 'Database',
                'title' => 'Database Connection Failure',
                'description' => $db['error'] ?? 'Could not establish connection to the database server.',
            ];
        } elseif ($db['response_time_ms'] > 500) {
            $issues[] = [
                'type' => 'warning',
                'category' => 'Database',
                'title' => 'High Database Response Latency',
                'description' => "Database response time is {$db['response_time_ms']} ms.",
            ];
        }

        if ($cache['status'] === 'critical' || !$cache['test_passed']) {
            $issues[] = [
                'type' => 'critical',
                'category' => 'Cache',
                'title' => 'Cache Storage Failure',
                'description' => $cache['error'] ?? 'Cache write/read operation failed.',
            ];
        }

        if ($queue['failed_jobs'] > 0) {
            $issues[] = [
                'type' => $queue['failed_jobs'] > 20 ? 'critical' : 'warning',
                'category' => 'Queue',
                'title' => "{$queue['failed_jobs']} Failed Queue Jobs Detected",
                'description' => 'Queue jobs are failing to execute. Check failed jobs list for error tracebacks.',
            ];
        }

        if ($scheduler['status'] === 'warning' || $scheduler['status'] === 'critical') {
            $issues[] = [
                'type' => 'warning',
                'category' => 'Scheduler',
                'title' => 'Stale Scheduler Heartbeat',
                'description' => $scheduler['minutes_since_last_heartbeat']
                    ? "Laravel cron scheduler last executed {$scheduler['minutes_since_last_heartbeat']} minutes ago."
                    : 'Scheduler heartbeat is unrecorded. Verify cron setup on the server.',
            ];
        }

        if ($storage['used_percentage'] && $storage['used_percentage'] > 85) {
            $issues[] = [
                'type' => $storage['used_percentage'] > 95 ? 'critical' : 'warning',
                'category' => 'Storage',
                'title' => "High Disk Space Usage ({$storage['used_percentage']}%)",
                'description' => 'Available disk space is dangerously low on the server host.',
            ];
        }

        if ($logs['recent_errors_count'] > 10) {
            $issues[] = [
                'type' => 'warning',
                'category' => 'Logs',
                'title' => "{$logs['recent_errors_count']} Error Logs in Recent Trajectory",
                'description' => 'Application has thrown multiple error exceptions recently. Latest: ' . ($logs['latest_error_summary'] ?? 'N/A'),
            ];
        }

        return $issues;
    }

    /**
     * Helper to mask sensitive tokens, passwords, keys in error outputs.
     */
    public function maskSecrets(string $text): string
    {
        $patterns = [
            '/(password|secret|app_key|token|jwt_secret|aws_secret|razorpay_secret|api_key)\s*=\s*[^\s,;\n]+/i' => '$1=********',
            '/("password"|"secret"|"app_key"|"token")\s*:\s*"[^"]+"/i' => '$1:"********"',
        ];

        return preg_replace(array_keys($patterns), array_values($patterns), $text);
    }

    /**
     * Safely read last N lines of a file.
     */
    protected function readLastLines(string $filepath, int $linesCount = 200): array
    {
        $handle = @fopen($filepath, 'r');
        if (!$handle) return [];

        $lines = [];
        $buffer = 4096;
        fseek($handle, 0, SEEK_END);
        $pos = ftell($handle);

        $currentLine = '';
        while ($pos > 0 && count($lines) < $linesCount) {
            $readSize = min($pos, $buffer);
            $pos -= $readSize;
            fseek($handle, $pos);
            $chunk = fread($handle, $readSize);
            
            $chunkLines = explode("\n", $chunk);
            if (!empty($currentLine)) {
                $chunkLines[count($chunkLines) - 1] .= $currentLine;
            }
            $currentLine = array_shift($chunkLines);

            for ($i = count($chunkLines) - 1; $i >= 0; $i--) {
                if (trim($chunkLines[$i]) !== '') {
                    array_unshift($lines, $chunkLines[$i]);
                    if (count($lines) >= $linesCount) break;
                }
            }
        }

        if (trim($currentLine) !== '' && count($lines) < $linesCount) {
            array_unshift($lines, $currentLine);
        }

        fclose($handle);
        return $lines;
    }
}
