<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('equb:start-groups')
    ->dailyAt('9:00')
    ->withoutOverlapping();

Schedule::command('equb:process-automatic-draws')
    ->dailyAt('9:00')
    ->withoutOverlapping();

Schedule::command('app:check-completed-memberships')
    ->dailyAt('9:00')
    ->withoutOverlapping();

Schedule::command('equb:check-missed-payments')
    ->dailyAt('10:00')
    ->withoutOverlapping();

// Report printing is checked every minute rather than at fixed times: each
// schedule carries its own run_at, so the command decides what is due. Nothing
// happens on a tick with no due schedules beyond one indexed query.
Schedule::command('reports:run-scheduled-prints')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();
