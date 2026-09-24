<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\EmailLog;
use App\Services\CredentialService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class CleanupEmailLogsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'email-logs:cleanup {--days= : Custom retention days override}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Automatically delete expired email logs based on configured retention policy.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $daysOption = $this->option('days');
        
        if ($daysOption !== null) {
            $retentionSetting = $daysOption;
        } else {
            $retentionSetting = CredentialService::get('email_logs', 'retention_days', 'EMAIL_LOG_RETENTION_DAYS', '90');
        }

        if (empty($retentionSetting) || strtolower((string)$retentionSetting) === 'never' || (int)$retentionSetting <= 0) {
            $this->info('Email log retention policy is set to "Never" or disabled. No logs deleted.');
            Log::info('Email log cleanup skipped: Retention policy is set to Never.');
            return 0;
        }

        $retentionDays = (int)$retentionSetting;
        $cutoffDate = Carbon::now()->subDays($retentionDays);

        $this->info("Starting email logs cleanup for records older than {$retentionDays} days (Cutoff: {$cutoffDate->toDateTimeString()})...");

        $deletedTotal = 0;
        
        do {
            $deleted = EmailLog::where('created_at', '<', $cutoffDate)
                ->limit(1000)
                ->delete();
            $deletedTotal += $deleted;
        } while ($deleted > 0);

        $summary = sprintf(
            "Email log cleanup completed.\nDeleted: %s logs\nRetention: %d days\nCutoff date: %s",
            number_format($deletedTotal),
            $retentionDays,
            $cutoffDate->toDateTimeString()
        );

        $this->info($summary);
        Log::info("EmailLog Cleanup: Deleted {$deletedTotal} logs older than {$retentionDays} days (Cutoff: {$cutoffDate->toDateTimeString()}).");

        return 0;
    }
}
