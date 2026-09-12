<?php

use Illuminate\Support\Facades\Artisan;
use App\Services\DeliveryCalendarService;

Artisan::command('holidays:cleanup', function () {
    DeliveryCalendarService::cleanupPastHolidays();
    $this->info('Past holidays cleaned up successfully.');
})->purpose('Auto-delete past holidays from database');
