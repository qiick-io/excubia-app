<?php

declare(strict_types=1);

use App\Actions\ReconcileAlertWebhookHistoryAction;
use App\Jobs\RecordQueueHeartbeatJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The activity log is purged by qiick/laravel-audit's own daily `audit:purge` (365 days, 730 for
// the security logs: the maintainer's retention decision of 2026-11-23); nothing else deletes it.

// Keeps the newest reports of each project (setting "Reports kept per project").
Schedule::command('excubia:prune-reports')->daily()->withoutOverlapping()->onOneServer();

// User erasures (#381) interrupted or failed after their lock-out: resumed from their snapshot.
Schedule::command('excubia:resume-erasures')->hourly()->withoutOverlapping()->onOneServer();

// Runtime aggregates and exception groups past their retention (#48, #49), and in-app
// notifications older than 30 days (#296).
Schedule::command('excubia:prune-telemetry')->daily()->withoutOverlapping()->onOneServer();

// Runtime minutes past the per-minute window folded into hourly rows (#212).
Schedule::command('excubia:downsample-runtime')->hourly()->withoutOverlapping();

// Excubia checks itself and stores the report on the system project (#70).
Schedule::command('excubia:system-report')->daily();

// Excubia's own runtime and exceptions, moved from the core's buffers to the system project (#84).
Schedule::command('excubia:system-telemetry')->everyMinute()->when(fn (): bool => (bool) config('excubia.self_monitoring'));

// Alerts (#22): one project.stale per project that stopped reporting.
Schedule::command('excubia:alert-stale')->hourly();

// End-of-life alerts (#157): thresholds crossed by the calendar, not by a new report.
Schedule::command('excubia:alert-eol')->daily();

// Schedule monitoring (#110): late, missed and failed scheduled tasks of the projects; and the
// health alerts (#143) that need a clock: servers that stopped sending health samples.
Schedule::command('excubia:evaluate-schedules')->everyMinute()->withoutOverlapping();

// Optional runtime alert rules (#107, #114): cache hit ratio, failed mails and notifications, log
// spikes; and the AI usage rules (#129): monthly budget, spend spike, error rate.
Schedule::command('excubia:evaluate-runtime-alerts')->everyFifteenMinutes()->withoutOverlapping();

// Weekly email digest (#114) for the users who turned it on.
Schedule::command('excubia:send-digests')->weeklyOn(1, '08:00');

// Alert webhook (qiick/laravel-outbound): the first attempt is queued when the alert is stored;
// this run sends retries once a minute (no sub-minute task, so a scale-to-zero host can sleep).
Schedule::command('outbound:work-deliveries')->everyMinute()->withoutOverlapping()->onOneServer();
// Clears delivery bodies after 30 days and settled delivery rows after 90 (package defaults);
// then alerts whose delivery the prune gave up on, or already deleted, stop reading `queued`.
// Excubia schedules it itself for that callback, so the package's own schedule is off
// (config/outbound.php `webhooks.schedule`).
Schedule::command('outbound:prune-deliveries')->daily()->onOneServer()
    ->after(fn () => app(ReconcileAlertWebhookHistoryAction::class)->execute());

// SSO back-channel logouts (qiick/laravel-oidc): drop stored logout revocations once they expire
// (hourly, as oidc 1.0.0-beta.8 asks; the retention ceiling is oidc.backchannel_logout.max_retention_seconds).
Schedule::command('oidc:prune-logout-revocations')->hourly()->onOneServer();

// Personal API keys (#351): warn owners once when a key enters its expiry notice window
// (qiick/laravel-api-keys, claim-leased so overlapping runs cannot send twice).
Schedule::command('api-keys:notify-expiring')->daily();

// Queue worker heartbeat for the System page (#117); the scheduler's own is excubia-core's excubia:heartbeat.
Schedule::job(new RecordQueueHeartbeatJob)->everyMinute();
