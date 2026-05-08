<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Pull inventory from DealerTrack for all connected dealers every hour.
Schedule::command('inventory:sync-dealertrack')->hourly();

// Run workflow reminders/escalations for stale deals every 30 minutes.
Schedule::command('deals:run-workflow-automation')->everyThirtyMinutes();
