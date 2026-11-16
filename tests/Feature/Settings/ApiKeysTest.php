<?php

declare(strict_types=1);

use App\Actions\CreateApiKeyAction;
use App\Data\ApiKeyData;
use App\Enums\ApiKeyScopeEnum;
use App\Http\Middleware\AuthenticateApiKeyMiddleware;
use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/**
 * Personal API keys (#315): Settings → API keys lists, creates and revokes the signed-in user's
 * own keys; a key is shown once and stored hashed; AuthenticateApiKeyMiddleware lets a key act
 * as its owner only while the key is active, carries the scope and the owner is active.
 */
beforeEach(function () {
    $this->withoutVite();
    // A route behind the middleware, answering with the user the key signed in.
    Route::middleware(AuthenticateApiKeyMiddleware::class.':mcp:read')
        ->get('/__test/api-key', fn () => response()->json(['user_id' => auth()->id()]));
});

function plainApiKey(User $user, ?string $expiresAt = null): string
{
    return app(CreateApiKeyAction::class)->execute($user, new ApiKeyData('Claude Code', [ApiKeyScopeEnum::McpRead], $expiresAt === null ? null : Date::parse($expiresAt)->toImmutable()));
}

function callWithApiKey(?string $plainKey): TestResponse
{
    return test()->withHeaders($plainKey === null ? [] : ['Authorization' => 'Bearer '.$plainKey])->getJson('/__test/api-key');
}

it('creates a key, shows it once and stores only its hash', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('api-keys.store'), ['name' => 'Claude Code', 'scopes' => ['mcp:read'], 'expires_at' => '2099-01-31'])
        ->assertRedirect(route('api-keys.index'));

    $plainKey = session(SessionKey::FLASH_DATA)['apiKey'];
    $apiKey = ApiKey::query()->sole();

    expect($plainKey)->toStartWith(ApiKey::KEY_PREFIX)
        ->and(strlen($plainKey))->toBe(44)
        ->and($apiKey->user_id)->toBe($user->id)
        ->and($apiKey->key_hash)->toBe(hash('sha256', $plainKey))
        ->and($apiKey->key_prefix)->toBe(substr($plainKey, 0, 12))
        ->and($apiKey->scopes)->toBe(['mcp:read'])
        ->and($apiKey->expires_at?->toDateTimeString())->toBe('2099-01-31 23:59:59')
        ->and(json_encode(ApiKey::query()->toBase()->get()))->not->toContain($plainKey)
        ->and(json_encode(Activity::query()->get()))->not->toContain($plainKey)
        ->and(json_encode(Activity::query()->get()))->not->toContain($apiKey->key_hash);

    $this->actingAs($user)->get(route('api-keys.index'))
        ->assertInertia(fn (Assert $page) => $page->hasFlash('apiKey', $plainKey));

    $this->actingAs($user)->get(route('api-keys.index'))
        ->assertDontSee($plainKey)
        ->assertDontSee($apiKey->key_hash)
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/api-keys')
            ->missingFlash('apiKey')
            ->where('scopes', ['mcp:read'])
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
    'unknown scope' => [['scopes' => ['mcp:write']], 'scopes.0'],
    'past expiry' => [['expires_at' => '2020-01-01'], 'expires_at'],
]);

it('lists only the signed-in user\'s keys', function () {
    $user = User::factory()->create();
    ApiKey::factory()->for($user)->create(['name' => 'Mine']);
    ApiKey::factory()->create(['name' => 'Someone else\'s']);

    $this->actingAs($user)->get(route('api-keys.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('apiKeys.data', 1)
            ->where('apiKeys.data.0.name', 'Mine'));
});

it('lets a key act as its owner and logs its first use once', function () {
    $user = User::factory()->create();
    $plainKey = plainApiKey($user);

    callWithApiKey($plainKey)->assertOk()->assertExactJson(['user_id' => $user->id]);
    callWithApiKey($plainKey)->assertOk();

    $apiKey = ApiKey::query()->sole();

    expect($apiKey->last_used_at)->not->toBeNull()
        ->and(Activity::query()->where('event', 'api_key_first_used')->count())->toBe(1)
        ->and(Activity::query()->where('event', 'api_key_first_used')->sole()->causer_id)->toBe($user->id)
        ->and(Activity::query()->where('event', 'created')->where('subject_type', $apiKey->getMorphClass())->sole()->log_name)->toBe('auth');
});

it('rejects a missing, unknown, expired or revoked key', function () {
    Date::setTestNow('2026-11-16 12:00:00');
    $user = User::factory()->create();
    $expiredKey = plainApiKey($user, '2026-11-20');
    $revokedKey = plainApiKey($user);

    $this->actingAs($user)->delete(route('api-keys.destroy', ApiKey::query()->latest('id')->first()))->assertRedirect(route('api-keys.index'));
    Date::setTestNow('2026-11-21 00:00:01');

    callWithApiKey(null)->assertUnauthorized();
    callWithApiKey('exk_'.str_repeat('a', 40))->assertUnauthorized();
    callWithApiKey($expiredKey)->assertUnauthorized();
    callWithApiKey($revokedKey)->assertUnauthorized();

    expect(ApiKey::query()->latest('id')->first()->revoked_at)->not->toBeNull()
        ->and(Activity::query()->where('event', 'api_key_revoked')->count())->toBe(1);
});

it('rejects the key of a deactivated user at once, and of a deleted user', function () {
    $user = User::factory()->create();
    $plainKey = plainApiKey($user);
    callWithApiKey($plainKey)->assertOk();

    $user->forceFill(['is_active' => false])->save();
    callWithApiKey($plainKey)->assertUnauthorized();

    $user->delete();
    callWithApiKey($plainKey)->assertUnauthorized();

    expect(ApiKey::query()->count())->toBe(0);
});

it('rejects a key without the scope the route needs', function () {
    $user = User::factory()->create();
    $plainKey = plainApiKey($user);
    ApiKey::query()->sole()->forceFill(['scopes' => []])->save();

    callWithApiKey($plainKey)->assertForbidden();
});

it('does not let a user revoke someone else\'s key', function () {
    $otherKey = ApiKey::factory()->create();

    $this->actingAs(User::factory()->create())->delete(route('api-keys.destroy', $otherKey))->assertForbidden();

    expect($otherKey->fresh()->revoked_at)->toBeNull();
});

it('limits failed attempts per IP and requests per key', function () {
    for ($attempt = 0; $attempt < AuthenticateApiKeyMiddleware::FAILED_ATTEMPTS_PER_MINUTE; $attempt++) {
        callWithApiKey('exk_wrong')->assertUnauthorized();
    }

    callWithApiKey('exk_wrong')->assertTooManyRequests();

    // A valid key from the same IP is limited too: guessing must not continue with a real key.
    callWithApiKey(plainApiKey(User::factory()->create()))->assertTooManyRequests();
});

it('limits the requests of one key per minute', function () {
    config(['excubia.api_key_requests_per_minute' => 2]);
    $plainKey = plainApiKey(User::factory()->create());

    callWithApiKey($plainKey)->assertOk();
    callWithApiKey($plainKey)->assertOk();
    callWithApiKey($plainKey)->assertTooManyRequests()->assertHeader('Retry-After');
});
