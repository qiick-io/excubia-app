<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Drops activity log entries older than `activitylog.clean_after_days`, as in qiick-io/externa-core.
Schedule::command('activitylog:clean')->daily();
