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

/*
|--------------------------------------------------------------------------
| Settlement
|--------------------------------------------------------------------------
|
| Dashen send nothing unprompted — no webhook, only a JavaScript callback in
| the member's own browser that carries no status and proves nothing. So the
| only way a contribution gets confirmed for a member who closed the app, lost
| signal or whose phone died between the PIN and the callback is for us to ask.
|
| payments:reconcile is that ask, and until now nothing ran it. The command has
| existed for a while with the cron line written in its own docblock, and it
| was never registered here — which means every member in that situation was
| debited by the bank and left showing unpaid until somebody noticed by hand.
| That is the single largest gap in the payment chain and this is the fix.
|
| Every five minutes, one API call per unsettled reference, and everything
| underneath is idempotent: a reference whose rows have left the pending state
| is skipped before the bank is called at all.
|
*/
Schedule::command('payments:reconcile')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

/*
| Matching imported statements, and rebuilding the daily control totals.
|
| Hourly rather than by the minute: a statement is imported by hand a few times
| a week, and matching improves only as the settlement sweep above writes bank
| transaction ids onto contributions. Running it more often would mostly
| re-examine the same unmatched lines.
|
| The import screen runs this immediately for the statement just loaded, so
| nobody waits an hour to see their file matched.
*/
Schedule::command('payments:match-statements')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

// Report printing is checked every minute rather than at fixed times: each
// schedule carries its own run_at, so the command decides what is due. Nothing
// happens on a tick with no due schedules beyond one indexed query.
Schedule::command('reports:run-scheduled-prints')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();
