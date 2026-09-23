<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\DailyReportService;

class SendDailyReportsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reports:send-daily {--date= : Target report date (YYYY-MM-DD)} {--force : Force send even if already sent today}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate and send daily order progress and daily inventory email reports';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $targetDate = $this->option('date');
        $force = (bool)$this->option('force');

        $this->info("Starting daily report generation" . ($targetDate ? " for date: {$targetDate}" : "") . "...");

        // 1. Send Daily Order Progress Report
        $this->info("Sending Daily Order Progress Report...");
        $orderRes = DailyReportService::sendDailyOrderReport($targetDate, $force);
        if ($orderRes['success']) {
            $this->info("✔ " . $orderRes['message']);
        } else {
            $this->error("❌ " . $orderRes['message']);
        }

        // 2. Send Daily Inventory Report
        $this->info("Sending Daily Inventory & Stock Movement Report...");
        $inventoryRes = DailyReportService::sendDailyInventoryReport($targetDate, $force);
        if ($inventoryRes['success']) {
            $this->info("✔ " . $inventoryRes['message']);
        } else {
            $this->error("❌ " . $inventoryRes['message']);
        }

        $this->info("Daily reports processing completed.");
        return 0;
    }
}
