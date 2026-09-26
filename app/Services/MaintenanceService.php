<?php

namespace App\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;

class MaintenanceService
{
    /**
     * Map of safe allowlisted maintenance actions to Artisan commands.
     */
    protected array $allowlist = [
        'clear_app_cache' => [
            'command' => 'cache:clear',
            'system' => 'Cache',
            'label' => 'Clear Application Cache',
            'description' => 'Flushes the main application cache store.',
        ],
        'clear_config_cache' => [
            'command' => 'config:clear',
            'system' => 'Configuration',
            'label' => 'Clear Config Cache',
            'description' => 'Removes cached configuration file.',
        ],
        'clear_route_cache' => [
            'command' => 'route:clear',
            'system' => 'Routing',
            'label' => 'Clear Route Cache',
            'description' => 'Removes compiled route cache file.',
        ],
        'clear_view_cache' => [
            'command' => 'view:clear',
            'system' => 'Views',
            'label' => 'Clear View Cache',
            'description' => 'Clears all compiled Blade view templates.',
        ],
        'clear_event_cache' => [
            'command' => 'event:clear',
            'system' => 'Events',
            'label' => 'Clear Event Cache',
            'description' => 'Clears cached events and listeners.',
        ],
        'clear_optimize_cache' => [
            'command' => 'optimize:clear',
            'system' => 'Optimization',
            'label' => 'Clear Optimization Cache',
            'description' => 'Clears bootstrap configuration, routes, and compiled views.',
        ],
        'optimize_app' => [
            'command' => 'optimize',
            'system' => 'Optimization',
            'label' => 'Optimize Application',
            'description' => 'Caches framework bootstrap files (config & routes) for production speed.',
        ],
        'restart_queue_workers' => [
            'command' => 'queue:restart',
            'system' => 'Queue',
            'label' => 'Restart Queue Workers',
            'description' => 'Signals queue worker processes to restart after finishing current jobs.',
        ],
    ];

    /**
     * Get list of supported maintenance actions for frontend display.
     */
    public function getAvailableActions(): array
    {
        $actions = [];
        foreach ($this->allowlist as $key => $meta) {
            $actions[] = [
                'key' => $key,
                'label' => $meta['label'],
                'system' => $meta['system'],
                'description' => $meta['description'],
            ];
        }
        return $actions;
    }

    /**
     * Safely execute an allowlisted maintenance operation.
     */
    public function executeAction(string $actionKey, ?Request $request = null): array
    {
        if (!isset($this->allowlist[$actionKey])) {
            throw new \InvalidArgumentException("Unauthorized or unknown maintenance operation: {$actionKey}");
        }

        $meta = $this->allowlist[$actionKey];
        $command = $meta['command'];
        $startedAt = now();
        $startMicro = microtime(true);
        $status = 'success';
        $errorMessage = null;
        $output = '';

        try {
            $exitCode = Artisan::call($command);
            $output = trim(Artisan::output());

            if ($exitCode !== 0) {
                $status = 'failed';
                $errorMessage = "Command returned non-zero exit code: {$exitCode}. Output: {$output}";
            }
        } catch (\Throwable $e) {
            $status = 'failed';
            $errorMessage = $e->getMessage();
        }

        $completedAt = now();
        $durationMs = (int) round((microtime(true) - $startMicro) * 1000);

        // Audit Logging
        $adminId = $request ? $request->attributes->get('admin_id') : null;
        $adminName = $request ? $request->attributes->get('admin_name') : 'System Admin';
        $ip = $request ? $request->ip() : null;
        $ua = $request ? substr($request->userAgent() ?? '', 0, 500) : null;

        $this->logAuditRecord([
            'admin_id' => $adminId,
            'admin_name' => $adminName,
            'action' => $actionKey,
            'target_system' => $meta['system'],
            'status' => $status,
            'started_at' => $startedAt,
            'completed_at' => $completedAt,
            'duration_ms' => $durationMs,
            'ip_address' => $ip,
            'user_agent' => $ua,
            'error_message' => $errorMessage,
            'metadata' => json_encode(['command' => $command, 'output' => substr($output, 0, 1000)]),
        ]);

        return [
            'success' => $status === 'success',
            'action' => $actionKey,
            'label' => $meta['label'],
            'status' => $status,
            'duration_ms' => $durationMs,
            'started_at' => $startedAt->toIso8601String(),
            'completed_at' => $completedAt->toIso8601String(),
            'output' => $output,
            'error_message' => $errorMessage,
        ];
    }

    /**
     * Store audit log record in system_maintenance_logs table.
     */
    public function logAuditRecord(array $data): void
    {
        try {
            if (Schema::hasTable('system_maintenance_logs')) {
                DB::table('system_maintenance_logs')->insert(array_merge($data, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }
        } catch (\Throwable $e) {
            // Ensure logging failure does not halt operation
        }
    }
}
