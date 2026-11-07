<?php

use App\Services\SettingsRepositoryService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Drops activity log entries past the Data retention setting (default `activitylog.clean_after_days`).
// A closure, so the setting is read when the task runs, not on every artisan boot.
Schedule::call(fn () => Artisan::call('activitylog:clean', [
    '--days' => app(SettingsRepositoryService::class)->activityRetentionDays(),
    '--force' => true,
]))->name('activitylog:clean')->daily();

// Keeps the newest reports of each project (setting "Reports kept per project").
Schedule::command('excubia:prune-reports')->daily();

// Runtime aggregates and exception groups past their retention (#48, #49).
Schedule::command('excubia:prune-telemetry')->daily();

// Excubia checks itself and stores the report on the system project (#70).
Schedule::command('excubia:system-report')->daily();

// Excubia's own runtime and exceptions, moved from the core's buffers to the system project (#84).
Schedule::command('excubia:system-telemetry')->everyMinute()->when(fn (): bool => (bool) config('excubia.self_monitoring'));

// Alerts (#22): one project.stale per project that stopped reporting.
Schedule::command('excubia:alert-stale')->hourly();

// Schedule monitoring (#110): late, missed and failed scheduled tasks of the projects.
Schedule::command('excubia:evaluate-schedules')->everyMinute()->withoutOverlapping();
