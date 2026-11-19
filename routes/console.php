<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Stamps the POS couldn't deliver (needs "php artisan schedule:run" every
// minute from cron in production; locally "php artisan schedule:work").
Schedule::command('loyalty:sync-stamps')->everyFiveMinutes()->withoutOverlapping();
