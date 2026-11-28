<?php

declare(strict_types=1);

use App\Models\Activity;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Activity log retention (#94): qiick/laravel-audit's daily `audit:purge`, with the maintainer's
 * fixed floors (365 days, 730 for the security logs, decision of 2026-11-23). No Excubia setting
 * or schedule deletes activity rows sooner.
 */
it('purges activity rows past their floor, keeping the security logs twice as long', function () {
    Date::setTestNow('2026-11-05 12:00:00');

    activity()->log('recent');
    activity()->log('old');
    activity()->useLog('auth')->log('old login');
    activity()->useLog('auth')->log('ancient login');
    // Rows are write-once through the model: age them with the query builder.
    DB::table('activity_log')->where('description', 'recent')->update(['created_at' => now()->subDays(300)]);
    DB::table('activity_log')->where('description', 'old')->update(['created_at' => now()->subDays(400)]);
    DB::table('activity_log')->where('description', 'old login')->update(['created_at' => now()->subDays(400)]);
    DB::table('activity_log')->where('description', 'ancient login')->update(['created_at' => now()->subDays(800)]);

    $events = collect(app(Schedule::class)->events());

    expect($events->first(fn ($event): bool => str_contains((string) $event->command, 'audit:purge')))->not->toBeNull()
        ->and($events->first(fn ($event): bool => $event->description === 'activitylog:clean'))->toBeNull();

    $this->artisan('audit:purge')->assertSuccessful();

    expect(Activity::query()->orderBy('id')->pluck('description')->all())->toBe(['recent', 'old login']);
});
