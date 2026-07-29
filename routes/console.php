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
