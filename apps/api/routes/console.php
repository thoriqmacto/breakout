<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| The one static scheduler entry
|--------------------------------------------------------------------------
|
| Everything this application schedules lives in the `scheduled_tasks` table
| and is dispatched by `scheduler:dispatch`, which reads that table every
| minute and runs whatever is due. That is what lets an automation be created,
| edited, enabled, disabled or deleted from /dashboard/automation without a
| deploy and without touching this file.
|
| There is deliberately exactly one entry here. A second scheduling mechanism
| alongside the database one would mean two places to look when a job does not
| run, and two things to keep in step.
|
| The previous hard-coded `asset:sync --eod` at 15:00 Asia/Qatar was removed
| when this landed: it competed with the database-managed daily OHLCV sync,
| which runs at 16:00 Asia/Jakarta and only on days the IDX actually traded.
|
| Production still needs cron to invoke `php artisan schedule:run` every
| minute -- see the deployment notes in the README.
|
*/
Schedule::command('scheduler:dispatch')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();
