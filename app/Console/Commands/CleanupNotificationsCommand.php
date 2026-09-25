<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Notification;
use App\Services\CredentialService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class CleanupNotificationsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notifications:cleanup {--days= : Custom retention days override} {--all : Purge unread notifications as well (default is read notifications only)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Automatically delete expired notifications based on configured retention policy.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $daysOption = $this->option('days');
        
        if ($daysOption !== null) {
            $retentionSetting = $daysOption;
        } else {
            $retentionSetting = CredentialService::get('notifications', 'retention_days', 'NOTIFICATION_RETENTION_DAYS', '30');
        }

        if (empty($retentionSetting) || strtolower((string)$retentionSetting) === 'never' || (int)$retentionSetting <= 0) {
            $this->info('Notification retention policy is set to "Never" or disabled. No notifications deleted.');
            Log::info('Notification cleanup skipped: Retention policy is set to Never.');
            return 0;
        }

        $retentionDays = (int)$retentionSetting;
        $cutoffDate = Carbon::now()->subDays($retentionDays);
        $purgeAll = (bool)$this->option('all');

        $modeText = $purgeAll ? 'all (read and unread)' : 'read-only';
        $this->info("Starting notifications cleanup ({$modeText}) for records older than {$retentionDays} days (Cutoff: {$cutoffDate->toDateTimeString()})...");

        $deletedTotal = 0;
        
        do {
            $query = Notification::where('created_at', '<', $cutoffDate);
            if (!$purgeAll) {
                $query->where('is_read', true);
            }

            $deleted = $query->limit(1000)->delete();
            $deletedTotal += $deleted;
        } while ($deleted > 0);

        $summary = sprintf(
            "Notification cleanup completed.\nDeleted: %s notifications (%s)\nRetention: %d days\nCutoff date: %s",
            number_format($deletedTotal),
            $modeText,
            $retentionDays,
            $cutoffDate->toDateTimeString()
        );

        $this->info($summary);
        Log::info("Notification Cleanup: Deleted {$deletedTotal} notifications ({$modeText}) older than {$retentionDays} days (Cutoff: {$cutoffDate->toDateTimeString()}).");

        return 0;
    }
}
