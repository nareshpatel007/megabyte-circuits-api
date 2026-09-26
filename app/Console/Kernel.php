<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->call(function () {
            \Illuminate\Support\Facades\Cache::put('system_health_scheduler_heartbeat', now()->timestamp, 86400);
        })->everyMinute();
        $schedule->command('gerber:clean-unattached')->dailyAt('00:00');
        $schedule->command('digikey:sync-manufacturers')->weekly();
        $schedule->command('digikey:sync-categories')->weekly();
        $schedule->command('digikey:sync')->dailyAt('01:00');
        $schedule->call(function () {
            \App\Services\DeliveryCalendarService::cleanupPastHolidays();
        })->dailyAt('00:05');
        $schedule->command('reports:send-daily')->dailyAt('23:00');
        $schedule->command('email-logs:cleanup')->dailyAt('02:00');
        $schedule->command('notifications:cleanup')->dailyAt('02:30');
    }


    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
