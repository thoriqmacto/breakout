<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('asset:sync --eod')
    ->dailyAt('13:00')
    ->timezone('Asia/Qatar')
    ->sendOutputTo(storage_path('logs/asset_sync.log'));
