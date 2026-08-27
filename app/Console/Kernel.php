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
        $schedule->command('gerber:clean-unattached')->dailyAt('00:00');
        $schedule->command('digikey:sync-manufacturers')->weekly();
        $schedule->command('digikey:sync-categories')->weekly();
        $schedule->command('digikey:sync')->dailyAt('01:00');
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
