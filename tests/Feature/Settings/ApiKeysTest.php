<?php

declare(strict_types=1);

use App\Actions\CreateApiKeyAction;
use App\Actions\RotateApiKeyAction;
use App\Data\ApiKeyData;
use App\Enums\ApiKeyScopeEnum;
use App\Http\Middleware\AuthenticateApiKeyMiddleware;
use App\Models\ApiKey;
use App\Models\User;
use App\Notifications\ApiKeyExpiringNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/**
 * Personal API keys (#315, #351): Settings → API keys (web session only); AuthenticateApiKeyMiddleware
 * on the `api_key` guard; rotation grace; RFC 6750 refusals; write⇒read; expiry notify.
 */
beforeEach(function () {
    $this->withoutVite();
    Route::middleware(AuthenticateApiKeyMiddleware::class.':mcp:read')
        ->get('/__test/api-key', fn () => response()->json([
            'user_id' => Auth::guard('api_key')->id(),
            'api_key_authenticated' => Auth::guard('api_key')->check(),
            'web_user' => Auth::guard('web')->id(),
        ]));
    Route::middleware(AuthenticateApiKeyMiddleware::class.':mcp:write')
        ->get('/__test/api-key-write', fn () => response()->json(['ok' => true]));
});

function plainApiKey(User $user, ?string $expiresAt = null, array $scopes = [ApiKeyScopeEnum::McpRead]): string
{
    return app(CreateApiKeyAction::class)->execute(
        $user,
        new ApiKeyData('Claude Code', $scopes, $expiresAt === null ? null : Date::parse($expiresAt)->toImmutable()),
    );
}

function callWithApiKey(?string $plainKey, string $path = '/__test/api-key'): TestResponse
{
    return test()->withHeaders($plainKey === null ? [] : ['Authorization' => 'Bearer '.$plainKey])->getJson($path);
}

it('creates a key, shows it once and stores only the hash of the full token', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('api-keys.store'), ['name' => 'Claude Code', 'scopes' => ['mcp:read'], 'expires_at' => '2099-01-31'])
        ->assertRedirect(route('api-keys.index'));

    $plainKey = session(SessionKey::FLASH_DATA)['apiKey'];
    $apiKey = ApiKey::query()->sole();

    expect($plainKey)->toMatch(ApiKey::TOKEN_PATTERN)
        ->and($apiKey->owner_id)->toBe($user->id)
        ->and($apiKey->created_by)->toBe($user->id)
        ->and($apiKey->uuid)->not->toBeEmpty()
        ->and($apiKey->key_hash)->toBe(hash('sha256', $plainKey))
        ->and($apiKey->key_prefix)->toBe(ApiKey::publicPrefixFromToken($plainKey))
        ->and($apiKey->key_prefix)->toStartWith('exc_test_')
        ->and(strlen($apiKey->key_prefix))->toBe(strlen('exc_test_') + ApiKey::PUBLIC_ID_LENGTH)
        ->and($apiKey->scopes)->toBe(['mcp:read'])
        ->and($apiKey->expires_at?->toDateTimeString())->toBe('2099-01-31 23:59:59')
        ->and($apiKey->getAttributes())->not->toContain($plainKey)
        ->and(json_encode(ApiKey::query()->toBase()->get()))->not->toContain($plainKey)
        ->and(json_encode(Activity::query()->get()))->not->toContain($plainKey)
        ->and(json_encode(Activity::query()->get()))->not->toContain($apiKey->key_hash)
        ->and(Activity::query()->where('log_name', 'api-keys')->where('event', 'created')->count())->toBe(1);

    $this->actingAs($user)->get(route('api-keys.index'))
        ->assertInertia(fn (Assert $page) => $page->hasFlash('apiKey', $plainKey));

    $this->actingAs($user)->get(route('api-keys.index'))
        ->assertDontSee($plainKey)
        ->assertDontSee($apiKey->key_hash)
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/api-keys')
            ->missingFlash('apiKey')
            ->where('scopes', ['mcp:read', 'mcp:write'])
            ->where('apiKeys.data.0.name', 'Claude Code')
            ->where('apiKeys.data.0.is_active', true)
            ->missing('apiKeys.data.0.key_hash'));
});

it('validates the name, the scopes and the expiry date', function (array $body, string $field) {
    $this->actingAs(User::factory()->create())
        ->post(route('api-keys.store'), [...['name' => 'Key', 'scopes' => ['mcp:read']], ...$body])
        ->assertSessionHasErrors($field);

    expect(ApiKey::query()->count())->toBe(0);
})->with([
    'no name' => [['name' => ''], 'name'],
    'no scope' => [['scopes' => []], 'scopes'],
    'unknown scope' => [['scopes' => ['rest:read']], 'scopes.0'],
    'past expiry' => [['expires_at' => '2020-01-01'], 'expires_at'],
]);

it('stores mcp:write alone and treats it as satisfying mcp:read', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('api-keys.store'), ['name' => 'Writer', 'scopes' => ['mcp:write']])
        ->assertRedirect(route('api-keys.index'));

    $apiKey = ApiKey::query()->sole();
    $plainKey = session(SessionKey::FLASH_DATA)['apiKey'];

    expect($apiKey->scopes)->toBe(['mcp:write'])
        ->and($apiKey->hasScope(ApiKeyScopeEnum::McpRead))->toBeTrue()
        ->and($apiKey->hasScope(ApiKeyScopeEnum::McpWrite))->toBeTrue();

    callWithApiKey($plainKey)->assertOk();
    callWithApiKey($plainKey, '/__test/api-key-write')->assertOk();
});

it('does not let mcp:read satisfy mcp:write', function () {
    $plainKey = plainApiKey(User::factory()->create());

    callWithApiKey($plainKey, '/__test/api-key-write')
        ->assertForbidden()
        ->assertJsonPath('error', 'insufficient_scope');
});

it('lists only the signed-in user\'s keys', function () {
    $user = User::factory()->create();
    ApiKey::factory()->for($user, 'owner')->create(['name' => 'Mine', 'created_by' => $user->id]);
    ApiKey::factory()->create(['name' => 'Someone else\'s']);

    $this->actingAs($user)->get(route('api-keys.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('apiKeys.data', 1)
            ->where('apiKeys.data.0.name', 'Mine'));
});

it('lets a key act as its owner on the api_key guard and logs its first use once', function () {
    $user = User::factory()->create();
    $plainKey = plainApiKey($user);

    callWithApiKey($plainKey)
        ->assertOk()
        ->assertJsonPath('user_id', $user->id)
        ->assertJsonPath('api_key_authenticated', true)
        ->assertJsonPath('web_user', null);

    callWithApiKey($plainKey)->assertOk();

    $apiKey = ApiKey::query()->sole();

    expect($apiKey->last_used_at)->not->toBeNull()
        ->and($apiKey->last_used_ip)->not->toBeNull()
        ->and(Activity::query()->where('event', 'api_key_first_used')->count())->toBe(1)
        ->and(Activity::query()->where('event', 'api_key_first_used')->sole()->causer_id)->toBe($user->id);
});

it('refuses a presented token with a trailing newline at the pattern boundary', function () {
    $user = User::factory()->create();
    $plainKey = plainApiKey($user);

    expect(ApiKey::publicPrefixFromToken($plainKey."\n"))->toBeNull()
        ->and(preg_match(ApiKey::TOKEN_PATTERN, $plainKey."\n"))->toBe(0);

    callWithApiKey($plainKey."\n")->assertUnauthorized()->assertJsonPath('error', 'invalid_token');
});

it('rejects a missing, unknown, expired or revoked key with invalid_token', function () {
    Date::setTestNow('2026-11-16 12:00:00');
    $user = User::factory()->create();
    $expiredKey = plainApiKey($user, '2026-11-20');
    $revokedKey = plainApiKey($user);
    $revoked = ApiKey::query()->latest('id')->first();

    $this->actingAs($user)->delete(route('api-keys.destroy', $revoked))->assertRedirect(route('api-keys.index'));
    Date::setTestNow('2026-11-21 00:00:01');

    callWithApiKey(null)->assertUnauthorized()->assertJsonPath('error', 'invalid_token');
    callWithApiKey('exc_test_'.str_repeat('a', 40))->assertUnauthorized()->assertJsonPath('error', 'invalid_token');
    callWithApiKey($expiredKey)
        ->assertUnauthorized()
        ->assertJsonPath('error', 'invalid_token')
        ->assertJsonPath('message', 'Expired API key.')
        ->assertHeader('WWW-Authenticate', 'Bearer error="invalid_token"');
    callWithApiKey($revokedKey)
        ->assertUnauthorized()
        ->assertJsonPath('error', 'invalid_token')
        ->assertJsonPath('message', 'Revoked API key.');

    expect($revoked->fresh()->revoked_at)->not->toBeNull()
        ->and($revoked->fresh()->previous_key_hash)->toBeNull()
        ->and(Activity::query()->where('event', 'revoked')->count())->toBe(1);
});

it('rejects the key of a deactivated user at once, and of a deleted user', function () {
    $user = User::factory()->create();
    $plainKey = plainApiKey($user);
    callWithApiKey($plainKey)->assertOk();

    $user->forceFill(['is_active' => false])->save();
    callWithApiKey($plainKey)->assertUnauthorized()->assertJsonPath('error', 'invalid_token');

    $user->delete();
    callWithApiKey($plainKey)->assertUnauthorized();

    expect(ApiKey::query()->count())->toBe(0);
});

it('rejects a key without the scope the route needs, including an empty scope list', function () {
    $user = User::factory()->create();
    $plainKey = plainApiKey($user);
    ApiKey::query()->sole()->forceFill(['scopes' => []])->save();

    callWithApiKey($plainKey)
        ->assertForbidden()
        ->assertJsonPath('error', 'insufficient_scope');
});

it('rejects an address outside the IP allowlist', function () {
    $user = User::factory()->create();
    $plainKey = plainApiKey($user);
    ApiKey::query()->sole()->forceFill(['ip_allowlist' => ['203.0.113.10']])->save();

    callWithApiKey($plainKey)->assertForbidden();
});

it('does not let a user revoke someone else\'s key', function () {
    $otherKey = ApiKey::factory()->create();

    $this->actingAs(User::factory()->create())->delete(route('api-keys.destroy', $otherKey))->assertForbidden();

    expect($otherKey->fresh()->revoked_at)->toBeNull();
});

it('refuses key management over API-key auth', function () {
    $user = User::factory()->create();
    $plainKey = plainApiKey($user);
    $apiKey = ApiKey::query()->sole();

    $this->withToken($plainKey)->postJson(route('api-keys.store'), [
        'name' => 'Minted',
        'scopes' => ['mcp:read'],
    ])->assertUnauthorized();

    $this->withToken($plainKey)->postJson(route('api-keys.rotate', $apiKey))->assertUnauthorized();
    $this->withToken($plainKey)->deleteJson(route('api-keys.destroy', $apiKey))->assertUnauthorized();

    expect(ApiKey::query()->count())->toBe(1)
        ->and($apiKey->fresh()->revoked_at)->toBeNull();
});

it('rotates a key: previous secret works in the grace window and not after', function () {
    Date::setTestNow('2026-11-17 12:00:00');
    $user = User::factory()->create();
    $oldPlain = plainApiKey($user);
    $apiKey = ApiKey::query()->sole();

    $this->actingAs($user)->post(route('api-keys.rotate', $apiKey))->assertRedirect(route('api-keys.index'));

    $newPlain = session(SessionKey::FLASH_DATA)['apiKey'];
    $apiKey->refresh();

    expect($newPlain)->not->toBe($oldPlain)
        ->and($newPlain)->toStartWith($apiKey->key_prefix)
        ->and($apiKey->previous_key_hash)->toBe(hash('sha256', $oldPlain))
        ->and($apiKey->previous_key_valid_until?->toDateTimeString())->toBe('2026-11-18 12:00:00')
        ->and(Activity::query()->where('event', 'rotated')->count())->toBe(1)
        ->and(json_encode(Activity::query()->get()))->not->toContain($newPlain)
        ->and(json_encode(Activity::query()->get()))->not->toContain($oldPlain);

    callWithApiKey($oldPlain)->assertOk();
    callWithApiKey($newPlain)->assertOk();

    Date::setTestNow('2026-11-18 12:00:01');
    callWithApiKey($oldPlain)->assertUnauthorized()->assertJsonPath('error', 'invalid_token');
    callWithApiKey($newPlain)->assertOk();
});

it('revokes immediately so the previous secret stops working too', function () {
    $user = User::factory()->create();
    $oldPlain = plainApiKey($user);
    $apiKey = ApiKey::query()->sole();
    $newPlain = app(RotateApiKeyAction::class)->execute($apiKey, $user);

    $this->actingAs($user)->delete(route('api-keys.destroy', $apiKey))->assertRedirect();

    callWithApiKey($oldPlain)->assertUnauthorized();
    callWithApiKey($newPlain)->assertUnauthorized();
});

it('enforces the per-key rate_limit_per_minute column', function () {
    $user = User::factory()->create();
    $plainKey = plainApiKey($user);
    ApiKey::query()->sole()->forceFill(['rate_limit_per_minute' => 2])->save();

    callWithApiKey($plainKey)->assertOk();
    callWithApiKey($plainKey)->assertOk();
    callWithApiKey($plainKey)->assertTooManyRequests()->assertHeader('Retry-After');
});

it('limits failed attempts per IP and requests per key via the default config', function () {
    for ($attempt = 0; $attempt < AuthenticateApiKeyMiddleware::FAILED_ATTEMPTS_PER_MINUTE; $attempt++) {
        callWithApiKey('exc_test_'.str_repeat('x', 40))->assertUnauthorized();
    }

    callWithApiKey('exc_test_'.str_repeat('x', 40))->assertTooManyRequests();

    callWithApiKey(plainApiKey(User::factory()->create()))->assertTooManyRequests();
});

it('limits the requests of one key per minute from the app default', function () {
    config(['excubia.api_key_requests_per_minute' => 2]);
    $plainKey = plainApiKey(User::factory()->create());

    callWithApiKey($plainKey)->assertOk();
    callWithApiKey($plainKey)->assertOk();
    callWithApiKey($plainKey)->assertTooManyRequests()->assertHeader('Retry-After');
});

it('notifies the owner once for keys in the seven-to-eight-day expiry window', function () {
    Notification::fake();
    Date::setTestNow('2026-11-17 10:00:00');
    $owner = User::factory()->create();
    $issuer = User::factory()->create();

    $inWindow = ApiKey::factory()->for($owner, 'owner')->create([
        'created_by' => $issuer->id,
        'expires_at' => now()->addDays(7)->addHours(12),
    ]);
    ApiKey::factory()->for($owner, 'owner')->create([
        'created_by' => $owner->id,
        'expires_at' => now()->addDays(3),
    ]);
    ApiKey::factory()->for($owner, 'owner')->create([
        'created_by' => $owner->id,
        'expires_at' => now()->addDays(10),
    ]);

    $this->artisan('excubia:notify-expiring-api-keys')->assertSuccessful();

    Notification::assertSentTo($owner, ApiKeyExpiringNotification::class, fn (ApiKeyExpiringNotification $notification): bool => $notification->apiKey->is($inWindow));
    Notification::assertSentTo($issuer, ApiKeyExpiringNotification::class);
    Notification::assertCount(2);

    $this->artisan('excubia:notify-expiring-api-keys')->assertSuccessful();
    // Same calendar window → would notify again without extra state; the daily schedule and
    // [7,8) window mean a key is only selected on one calendar day in practice.
});
