<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('prodelya:heartbeat-scheduler')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('product-data-hub:sync-sources --frequency=hourly')
    ->hourly()
    ->withoutOverlapping();

Schedule::command('product-data-hub:sync-sources --frequency=daily')
    ->dailyAt('03:00')
    ->withoutOverlapping();

Schedule::command('product-data-hub:sync-sources --frequency=weekly')
    ->weeklyOn(1, '04:00')
    ->withoutOverlapping();

if (config('prodelya_currency.sync.schedule.enabled')) {
    Schedule::command('prodelya:currency-rates-sync')
        ->dailyAt((string) config('prodelya_currency.sync.schedule.time', '07:30'))
        ->timezone((string) config('prodelya_currency.sync.schedule.timezone', 'Europe/Istanbul'))
        ->withoutOverlapping();
}
