<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Drops activity log entries older than `activitylog.clean_after_days`, as in qiick-io/externa-core.
Schedule::command('activitylog:clean')->daily();

// Keeps the newest reports of each project (setting "Reports kept per project").
Schedule::command('excubia:prune-reports')->daily();

// Runtime aggregates and exception groups past their retention (#48, #49).
Schedule::command('excubia:prune-telemetry')->daily();

// Excubia checks itself and stores the report on the system project (#70).
Schedule::command('excubia:system-report')->daily();
