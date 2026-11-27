<?php

declare(strict_types=1);

use App\Models\ApiKey;
use App\Models\User;
use App\Notifications\ApiKeyExpiringNotification;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;
use Qiick\ApiKeys\Contracts\ApiKeyRepository;

/**
 * Daily expiry warning (#351, qiick/laravel-api-keys `api-keys:notify-expiring`): once per key,
 * when its expires_at is within `api-keys.expiry.notice_days` (7).
 */
it('skips revoked keys and keys outside the warning window', function () {
    Notification::fake();
    Date::setTestNow('2026-11-17 10:00:00');
    $user = User::factory()->create();

    ApiKey::factory()->for($user, 'owner')->create([
        'created_by' => $user->id,
        'expires_at' => now()->addDays(6),
        'revoked_at' => now(),
    ]);
    ApiKey::factory()->for($user, 'owner')->create([
        'created_by' => $user->id,
        'expires_at' => null,
    ]);

    $this->artisan('api-keys:notify-expiring')->assertSuccessful();

    Notification::assertNothingSent();
});

it('notifies only the owner when the issuing user is gone', function () {
    Notification::fake();
    Date::setTestNow('2026-11-17 10:00:00');
    $owner = User::factory()->create();

    ApiKey::factory()->for($owner, 'owner')->create([
        'created_by' => null,
        'expires_at' => now()->addDays(6),
    ]);

    $this->artisan('api-keys:notify-expiring')->assertSuccessful();

    Notification::assertSentTo($owner, ApiKeyExpiringNotification::class);
});

it('skips keys whose owner has been soft-deleted', function () {
    Notification::fake();
    Date::setTestNow('2026-11-17 10:00:00');
    $owner = User::factory()->create();

    ApiKey::factory()->for($owner, 'owner')->create([
        'created_by' => $owner->id,
        'expires_at' => now()->addDays(6),
    ]);
    $owner->delete();

    $this->artisan('api-keys:notify-expiring')->assertSuccessful();

    Notification::assertNothingSent();
});

it('warns once even when the command runs again or the key stays in the window', function () {
    Notification::fake();
    Date::setTestNow('2026-11-17 10:00:00');
    $owner = User::factory()->create();
    $apiKey = ApiKey::factory()->for($owner, 'owner')->create([
        'created_by' => $owner->id,
        'expires_at' => now()->addDays(6),
    ]);

    $this->artisan('api-keys:notify-expiring')->assertSuccessful();
    Date::setTestNow('2026-11-18 10:00:00');
    $this->artisan('api-keys:notify-expiring')->assertSuccessful();

    Notification::assertSentToTimes($owner, ApiKeyExpiringNotification::class, 1);

    expect($apiKey->fresh()->expiry_notified_at?->toDateTimeString())->toBe('2026-11-17 10:00:00')
        ->and($apiKey->fresh()->expiry_notice_claimed_at)->toBeNull();
});

it('lets only one of two overlapping runs claim a notice until its lease lapses', function () {
    Date::setTestNow('2026-11-17 10:00:00');
    $apiKey = ApiKey::factory()->create(['expires_at' => now()->addDays(6)]);
    $repository = app(ApiKeyRepository::class);
    $now = now()->toDateTimeImmutable();

    expect($repository->claimExpiryNotice($apiKey, $now, 900, 'first'))->toBeTrue()
        ->and($repository->claimExpiryNotice($apiKey, $now, 900, 'second'))->toBeFalse()
        ->and($repository->claimExpiryNotice($apiKey, $now->modify('+901 seconds'), 900, 'third'))->toBeTrue()
        // The first worker lost its lease: it cannot stamp the notice any more.
        ->and($repository->markExpiryNotified($apiKey, $now, 'first'))->toBeFalse()
        ->and($repository->markExpiryNotified($apiKey, $now, 'third'))->toBeTrue()
        ->and($repository->claimExpiryNotice($apiKey, $now->modify('+1 day'), 900, 'fourth'))->toBeFalse();
});

it('never claims the notice of a revoked key', function () {
    Date::setTestNow('2026-11-17 10:00:00');
    $apiKey = ApiKey::factory()->create(['expires_at' => now()->addDays(6), 'revoked_at' => now()]);

    expect(app(ApiKeyRepository::class)->claimExpiryNotice($apiKey, now()->toDateTimeImmutable(), 900, 'token'))->toBeFalse();
});

it('drops a claim when the key changed expiry in between, as after a rotation', function () {
    Date::setTestNow('2026-11-17 10:00:00');
    $apiKey = ApiKey::factory()->create(['expires_at' => now()->addDays(6)]);
    $repository = app(ApiKeyRepository::class);
    $selected = ApiKey::query()->findOrFail($apiKey->id);

    expect($repository->claimExpiryNotice($selected, now()->toDateTimeImmutable(), 900, 'token'))->toBeTrue();

    $apiKey->forceFill(['expires_at' => now()->addDays(30)])->save();

    expect($repository->markExpiryNotified($selected, now()->toDateTimeImmutable(), 'token'))->toBeFalse()
        ->and($apiKey->fresh()->expiry_notified_at)->toBeNull();
});

it('is registered on the daily schedule', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains((string) $event->command, 'api-keys:notify-expiring'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 0 * * *');
});
