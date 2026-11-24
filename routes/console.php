<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Remove generated report exports daily so storage does not fill up.
Schedule::command('exports:purge --days=7')->dailyAt('02:00');

// Terminate lapsed trials and honour subscriptions cancelled at period end.
Schedule::command('subscriptions:expire')->hourly();
