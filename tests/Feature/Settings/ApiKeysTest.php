<?php

declare(strict_types=1);

use App\Actions\CreateApiKeyAction;
use App\Actions\RotateApiKeyAction;
use App\Data\ApiKeyData;
use App\Enums\ApiKeyScopeEnum;
use App\Enums\RoleEnum;
use App\Http\Middleware\AuthenticateApiKeyMiddleware;
use App\Models\Activity;
use App\Models\ApiKey;
use App\Models\User;
use App\Notifications\ApiKeyExpiringNotification;
use App\Services\ApiKeySecretOnceService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;
use Qiick\ApiKeys\TokenCodec;

/**
 * Personal API keys (#315, #351): Settings → API keys (web session only); AuthenticateApiKeyMiddleware
 * on the `api_key` guard; rotation grace; RFC 6750 refusals; write⇒read; expiry notify.
 * A key never holds a scope its creator lacks; at use time it is narrowed to the owner's
 * current permissions (key scopes ∩ owner's permissions).
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
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

/**
 * An owner who may grant and use every mcp scope (admin holds can-use-ai).
 */
function apiKeyOwner(): User
{
    return User::factory()->create()->assignRole(RoleEnum::Admin->value);
}

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

/**
 * Peek at the encrypted one-time secret still in the session after create/rotate
 * (before the index GET pulls it).
 */
function pendingIssuedApiKeySecret(): string
{
    $encrypted = session()->get(ApiKeySecretOnceService::SESSION_KEY);

    expect($encrypted)->toBeString();

    /** @var array{secret?: mixed} $payload */
    $payload = json_decode(Crypt::decryptString($encrypted), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['secret'] ?? null)->toBeString();

    return $payload['secret'];
}

it('creates a key, shows it once and stores only the hash of the full token', function () {
    $user = apiKeyOwner();

    $this->actingAs($user)
        ->post(route('api-keys.store'), [
            'name' => 'Claude Code',
            'scopes' => ['mcp:read'],
            'expires_at' => '2099-01-31T09:30:00+01:00',
        ])
        ->assertRedirect(route('api-keys.index'));

    $plainKey = pendingIssuedApiKeySecret();
    $apiKey = ApiKey::query()->sole();

    expect($plainKey)->toMatch(EXCUBIA_TOKEN_PATTERN)
        ->and($apiKey->owner_id)->toBe($user->id)
        ->and($apiKey->created_by)->toBe($user->id)
        ->and($apiKey->uuid)->not->toBeEmpty()
        ->and($apiKey->key_hash)->toBe(hash('sha256', $plainKey))
        ->and($apiKey->key_prefix)->toBe(apiKeyPrefixOf($plainKey))
        ->and($apiKey->key_prefix)->toStartWith('exc_test_')
        ->and(strlen($apiKey->key_prefix))->toBe(strlen('exc_test_') + 8)
        ->and($apiKey->token_version)->toBe(2)
        ->and($apiKey->hash_algorithm)->toBe('sha256')
        ->and($apiKey->scopes)->toBe(['mcp:read'])
        ->and($apiKey->expires_at?->toDateTimeString())->toBe('2099-01-31 08:30:00')
        ->and($apiKey->getAttributes())->not->toContain($plainKey)
        ->and(json_encode(ApiKey::query()->toBase()->get()))->not->toContain($plainKey)
        ->and(json_encode(Activity::query()->get()))->not->toContain($plainKey)
        ->and(json_encode(Activity::query()->get()))->not->toContain($apiKey->key_hash)
        ->and(Activity::query()->where('log_name', 'api-keys')->where('event', 'created')->count())->toBe(1);

    $this->actingAs($user)->get(route('api-keys.index'))
        ->assertHeaderContains('Cache-Control', 'no-store')
        ->assertInertia(fn (Assert $page) => $page
            ->missing('issuedApiKey')
            ->hasFlash('issuedApiKey.secret', $plainKey)
            ->hasFlash('issuedApiKey.name', 'Claude Code')
            ->hasFlash('issuedApiKey.reason', 'created')
            ->where('apiKeys.data.0.expires_at', '2099-01-31T08:30:00+00:00'));

    $this->actingAs($user)->get(route('api-keys.index'))
        ->assertDontSee($plainKey)
        ->assertDontSee($apiKey->key_hash)
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/api-keys')
            ->missingFlash('issuedApiKey')
            ->where('scopes', ['mcp:read', 'mcp:write'])
            ->where('apiKeys.data.0.name', 'Claude Code')
            ->where('apiKeys.data.0.is_active', true)
            ->missing('apiKeys.data.0.key_hash'));
});

it('validates the name, the scopes and the expiry date', function (array $body, string $field) {
    Date::setTestNow('2026-11-22 12:00:00');

    $this->actingAs(apiKeyOwner())
        ->post(route('api-keys.store'), [...['name' => 'Key', 'scopes' => ['mcp:read']], ...$body])
        ->assertSessionHasErrors($field);

    expect(ApiKey::query()->count())->toBe(0);
})->with([
    'no name' => [['name' => ''], 'name'],
    'no scope' => [['scopes' => []], 'scopes'],
    'unknown scope' => [['scopes' => ['rest:read']], 'scopes.0'],
    'past expiry' => [['expires_at' => '2020-01-01T09:30:00+01:00'], 'expires_at'],
    'earlier today' => [['expires_at' => '2026-11-22T11:00:00+00:00'], 'expires_at'],
]);

it('refuses a scope the creator does not hold on the settings form', function () {
    $creator = User::factory()->create()->assignRole(RoleEnum::Reader->value);

    expect(ApiKeyScopeEnum::scopesCreatorMayGrant($creator))->toBe([]);

    $this->actingAs($creator)->post(route('api-keys.store'), [
        'name' => 'Escalated',
        'scopes' => ['mcp:read'],
    ])->assertSessionHasErrors('scopes.0');

    expect(ApiKey::query()->count())->toBe(0);
});

it('refuses a scope the creator does not hold over the JSON API', function () {
    $creator = User::factory()->create()->assignRole(RoleEnum::Reader->value);

    $this->actingAs($creator)->postJson(route('api-keys.store'), [
        'name' => 'Escalated',
        'scopes' => ['mcp:write'],
    ])->assertUnprocessable()->assertJsonValidationErrors('scopes.0');

    expect(ApiKey::query()->count())->toBe(0);
});

it('narrows a key at use time when the owner loses the permission the scope needs', function () {
    $owner = apiKeyOwner();
    $plainKey = plainApiKey($owner, scopes: [ApiKeyScopeEnum::McpRead, ApiKeyScopeEnum::McpWrite]);

    callWithApiKey($plainKey)->assertOk();
    callWithApiKey($plainKey, '/__test/api-key-write')->assertOk();

    $owner->syncRoles([RoleEnum::Reader->value]);

    // Every scope narrows away, so the package answers that the key holds no usable scope.
    callWithApiKey($plainKey)
        ->assertForbidden()
        ->assertJsonPath('error', 'insufficient_scope')
        ->assertJsonPath('message', 'The API key holds no scopes.');
    callWithApiKey($plainKey, '/__test/api-key-write')
        ->assertForbidden()
        ->assertJsonPath('error', 'insufficient_scope');
});

it('stores mcp:write alone and treats it as satisfying mcp:read', function () {
    $user = apiKeyOwner();

    $this->actingAs($user)
        ->post(route('api-keys.store'), ['name' => 'Writer', 'scopes' => ['mcp:write']])
        ->assertRedirect(route('api-keys.index'));

    $apiKey = ApiKey::query()->sole();
    $plainKey = pendingIssuedApiKeySecret();

    expect($apiKey->scopes)->toBe(['mcp:write'])
        ->and($apiKey->hasScope(ApiKeyScopeEnum::McpRead))->toBeTrue()
        ->and($apiKey->hasScope(ApiKeyScopeEnum::McpWrite))->toBeTrue();

    callWithApiKey($plainKey)->assertOk();
    callWithApiKey($plainKey, '/__test/api-key-write')->assertOk();
});

it('does not let mcp:read satisfy mcp:write', function () {
    $plainKey = plainApiKey(apiKeyOwner());

    callWithApiKey($plainKey, '/__test/api-key-write')
        ->assertForbidden()
        ->assertJsonPath('error', 'insufficient_scope');
});

it('lists only the signed-in user\'s keys', function () {
    $user = apiKeyOwner();
    ApiKey::factory()->for($user, 'owner')->create(['name' => 'Mine', 'created_by' => $user->id]);
    ApiKey::factory()->create(['name' => 'Someone else\'s']);

    $this->actingAs($user)->get(route('api-keys.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('apiKeys.data', 1)
            ->where('apiKeys.data.0.name', 'Mine'));
});

it('lets a key act as its owner on the api_key guard and logs its first use once', function () {
    $user = apiKeyOwner();
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
        ->and(Activity::query()->where('event', 'api_key_first_used')->sole()->causer_id)->toBe((string) $user->id);
});

it('refuses a presented token with a trailing newline at the pattern boundary', function () {
    $user = apiKeyOwner();
    $plainKey = plainApiKey($user);

    expect(app(TokenCodec::class)->prefixOf($plainKey."\n", now()->toDateTimeImmutable()))->toBeNull()
        ->and(preg_match(EXCUBIA_TOKEN_PATTERN, $plainKey."\n"))->toBe(0);

    callWithApiKey($plainKey."\n")->assertUnauthorized()->assertJsonPath('error', 'invalid_token');
});

it('rejects a missing, unknown, expired or revoked key with invalid_token', function () {
    Date::setTestNow('2026-11-16 12:00:00');
    $user = apiKeyOwner();
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
        ->assertJsonPath('message', 'The API key has expired.')
        ->assertHeader('WWW-Authenticate', 'Bearer error="invalid_token"');
    callWithApiKey($revokedKey)
        ->assertUnauthorized()
        ->assertJsonPath('error', 'invalid_token')
        ->assertJsonPath('message', 'The API key has been revoked.');

    expect($revoked->fresh()->revoked_at)->not->toBeNull()
        ->and($revoked->fresh()->previous_key_hash)->toBeNull()
        ->and(Activity::query()->where('event', 'revoked')->count())->toBe(1);
});

it('rejects the key of a deactivated user at once, and of a deleted user', function () {
    $user = apiKeyOwner();
    $plainKey = plainApiKey($user);
    callWithApiKey($plainKey)->assertOk();

    $user->forceFill(['is_active' => false])->save();
    callWithApiKey($plainKey)->assertUnauthorized()->assertJsonPath('error', 'invalid_token');

    $user->delete();
    callWithApiKey($plainKey)
        ->assertUnauthorized()
        ->assertJsonPath('error', 'invalid_token')
        ->assertJsonPath('message', 'The API key owner is no longer active.');

    expect(ApiKey::query()->count())->toBe(1)
        ->and(ApiKey::query()->sole()->owner)->toBeNull();
});

it('rejects a key without the scope the route needs, including an empty scope list', function () {
    $user = apiKeyOwner();
    $plainKey = plainApiKey($user);
    ApiKey::query()->sole()->forceFill(['scopes' => []])->save();

    callWithApiKey($plainKey)
        ->assertForbidden()
        ->assertJsonPath('error', 'insufficient_scope');
});

it('rejects an address outside the IP allowlist with ip_not_allowed', function () {
    $user = apiKeyOwner();
    $plainKey = plainApiKey($user);
    ApiKey::query()->sole()->forceFill(['ip_allowlist' => ['203.0.113.10']])->save();

    callWithApiKey($plainKey)
        ->assertForbidden()
        ->assertJsonPath('error', 'ip_not_allowed')
        ->assertJsonPath('message', 'API key is not allowed from this IP address.')
        ->assertHeaderMissing('WWW-Authenticate');
});

it('answers every API-key refusal with the fleet RFC 6750 shape', function () {
    Date::setTestNow('2026-11-16 12:00:00');
    $user = apiKeyOwner();
    $expiredKey = plainApiKey($user, '2026-11-15');
    $revokedKey = plainApiKey($user);
    $scopedKey = plainApiKey($user);
    $ipKey = plainApiKey($user);
    $readKey = plainApiKey($user);
    ApiKey::query()->where('key_prefix', apiKeyPrefixOf($revokedKey))->sole()
        ->forceFill(['revoked_at' => now()])->save();
    ApiKey::query()->where('key_prefix', apiKeyPrefixOf($scopedKey))->sole()
        ->forceFill(['scopes' => []])->save();
    ApiKey::query()->where('key_prefix', apiKeyPrefixOf($ipKey))->sole()
        ->forceFill(['ip_allowlist' => ['203.0.113.10']])->save();

    callWithApiKey(null)
        ->assertUnauthorized()
        ->assertJsonPath('error', 'invalid_token')
        ->assertHeader('WWW-Authenticate', 'Bearer error="invalid_token"');
    callWithApiKey($expiredKey)
        ->assertUnauthorized()
        ->assertJsonPath('error', 'invalid_token')
        ->assertHeader('WWW-Authenticate', 'Bearer error="invalid_token"');
    callWithApiKey($revokedKey)
        ->assertUnauthorized()
        ->assertJsonPath('error', 'invalid_token')
        ->assertHeader('WWW-Authenticate', 'Bearer error="invalid_token"');
    // A key with no usable scope at all: the package names no scope (qiick/laravel-api-keys 0.3).
    callWithApiKey($scopedKey)
        ->assertForbidden()
        ->assertJsonPath('error', 'insufficient_scope')
        ->assertJsonMissingPath('scope')
        ->assertHeader('WWW-Authenticate', 'Bearer error="insufficient_scope"');
    callWithApiKey($ipKey)
        ->assertForbidden()
        ->assertJsonPath('error', 'ip_not_allowed')
        ->assertHeaderMissing('WWW-Authenticate');
    callWithApiKey($readKey, '/__test/api-key-write')
        ->assertForbidden()
        ->assertJsonPath('error', 'insufficient_scope')
        ->assertJsonPath('scope', 'mcp:write')
        ->assertHeader('WWW-Authenticate', 'Bearer error="insufficient_scope", scope="mcp:write"');
});

it('does not let a user revoke someone else\'s key', function () {
    $otherKey = ApiKey::factory()->create();

    $this->actingAs(User::factory()->create())->delete(route('api-keys.destroy', $otherKey))->assertForbidden();

    expect($otherKey->fresh()->revoked_at)->toBeNull();
});

it('refuses key management over API-key auth', function () {
    $user = apiKeyOwner();
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
    $user = apiKeyOwner();
    $oldPlain = plainApiKey($user);
    $apiKey = ApiKey::query()->sole();

    $this->actingAs($user)->post(route('api-keys.rotate', $apiKey))->assertRedirect(route('api-keys.index'));

    $newPlain = pendingIssuedApiKeySecret();
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

it('keeps the exact expiry instant across offset and DST edges', function () {
    Date::setTestNow('2026-03-01 12:00:00');
    $user = apiKeyOwner();

    $this->actingAs($user)->post(route('api-keys.store'), [
        'name' => 'Rome morning',
        'scopes' => ['mcp:read'],
        // Europe/Rome is UTC+1 in winter; 09:30 local → 08:30 UTC.
        'expires_at' => '2026-03-20T09:30:00+01:00',
    ])->assertRedirect();

    expect(ApiKey::query()->sole()->expires_at?->toIso8601String())->toBe('2026-03-20T08:30:00+00:00');

    ApiKey::query()->delete();
    Date::setTestNow('2026-06-01 12:00:00');

    $this->actingAs($user)->post(route('api-keys.store'), [
        'name' => 'Rome after DST',
        'scopes' => ['mcp:read'],
        // Europe/Rome is UTC+2 in summer; 09:30+02:00 → 07:30 UTC.
        'expires_at' => '2026-06-15T09:30:00+02:00',
    ])->assertRedirect();

    expect(ApiKey::query()->sole()->expires_at?->toIso8601String())->toBe('2026-06-15T07:30:00+00:00');
});

it('validates each IP allowlist entry and refuses unrestricted CIDRs', function (array $allowlist, string $field) {
    $this->actingAs(apiKeyOwner())
        ->post(route('api-keys.store'), [
            'name' => 'Restricted',
            'scopes' => ['mcp:read'],
            'ip_allowlist' => $allowlist,
        ])
        ->assertSessionHasErrors($field);

    expect(ApiKey::query()->count())->toBe(0);
})->with([
    'garbage' => [['not-an-ip'], 'ip_allowlist.0'],
    'prefix too wide' => [['203.0.113.0/33'], 'ip_allowlist.0'],
    'ipv6 prefix too wide' => [['2001:db8::/129'], 'ip_allowlist.0'],
    'all ipv4' => [['0.0.0.0/0'], 'ip_allowlist.0'],
    'all ipv6' => [['::/0'], 'ip_allowlist.0'],
    'all ipv4 padded' => [['0.0.0.0/00'], 'ip_allowlist.0'],
    'non-zero host /0' => [['1.0.0.0/0'], 'ip_allowlist.0'],
    'ipv6 subnet /0' => [['2001:db8::/0'], 'ip_allowlist.0'],
    'second entry bad' => [['203.0.113.4', '999.0.0.1'], 'ip_allowlist.1'],
]);

it('accepts a mixed IPv4 and IPv6 allowlist', function () {
    $this->actingAs(apiKeyOwner())
        ->post(route('api-keys.store'), [
            'name' => 'Mixed',
            'scopes' => ['mcp:read'],
            'ip_allowlist' => ['203.0.113.4', '10.0.0.0/8', '2001:db8::1', '2001:db8::/32'],
        ])
        ->assertRedirect();

    expect(ApiKey::query()->sole()->ip_allowlist)->toBe([
        '203.0.113.4',
        '10.0.0.0/8',
        '2001:db8::1',
        '2001:db8::/32',
    ]);
});

it('redirects with a toast when rotating a revoked or expired key', function () {
    Date::setTestNow('2026-11-17 12:00:00');
    $user = apiKeyOwner();
    $revokedKey = ApiKey::factory()->for($user, 'owner')->create([
        'created_by' => $user->id,
        'revoked_at' => now(),
    ]);
    $expiredKey = ApiKey::factory()->for($user, 'owner')->create([
        'created_by' => $user->id,
        'expires_at' => now()->subHour(),
    ]);

    $this->actingAs($user)->post(route('api-keys.rotate', $revokedKey))
        ->assertRedirect(route('api-keys.index'))
        ->assertInertiaFlash('toast.type', 'error')
        ->assertInertiaFlash('toast.message', __('Only an active API key can be rotated.'));

    $this->actingAs($user)->post(route('api-keys.rotate', $expiredKey))
        ->assertRedirect(route('api-keys.index'))
        ->assertInertiaFlash('toast.type', 'error')
        ->assertInertiaFlash('toast.message', __('Only an active API key can be rotated.'));
});

it('redirects with a toast when rotating a key whose scopes the owner no longer holds', function () {
    $user = apiKeyOwner();
    $plainKey = plainApiKey($user, scopes: [ApiKeyScopeEnum::McpWrite]);
    $apiKey = ApiKey::query()->where('key_prefix', apiKeyPrefixOf($plainKey))->sole();

    $user->syncRoles([RoleEnum::Reader->value]);

    $this->actingAs($user)->post(route('api-keys.rotate', $apiKey))
        ->assertRedirect(route('api-keys.index'))
        ->assertInertiaFlash('toast.type', 'error')
        ->assertInertiaFlash('toast.message', __('This API key cannot be rotated: you no longer hold its scopes.'));
});

it('never writes the plaintext secret into the sessions table on create or rotate', function () {
    config(['session.driver' => 'database']);
    $user = apiKeyOwner();

    $this->actingAs($user)
        ->post(route('api-keys.store'), ['name' => 'Secret', 'scopes' => ['mcp:read']])
        ->assertRedirect();

    $plainKey = pendingIssuedApiKeySecret();
    $encrypted = session()->get(ApiKeySecretOnceService::SESSION_KEY);
    session()->save();

    $decodedCreatePayloads = DB::table('sessions')->pluck('payload')->map(
        fn (string $payload): mixed => json_decode(base64_decode($payload, true) ?: '', true),
    );

    expect(json_encode($decodedCreatePayloads->all()))->not->toContain($plainKey)
        ->and(json_encode(session()->all()))->not->toContain($plainKey)
        ->and(is_string($encrypted))->toBeTrue()
        ->and($encrypted)->not->toBe($plainKey);

    $apiKey = ApiKey::query()->sole();
    $this->actingAs($user)->post(route('api-keys.rotate', $apiKey))->assertRedirect();

    $rotatedPlain = pendingIssuedApiKeySecret();
    session()->save();

    $decodedRotatePayloads = DB::table('sessions')->pluck('payload')->map(
        fn (string $payload): mixed => json_decode(base64_decode($payload, true) ?: '', true),
    );

    expect(json_encode($decodedRotatePayloads->all()))->not->toContain($rotatedPlain)
        ->and(json_encode($decodedRotatePayloads->all()))->not->toContain($plainKey);
});

it('does not consume the pending secret on a partial reload from another tab', function () {
    $user = apiKeyOwner();

    $this->actingAs($user)
        ->post(route('api-keys.store'), ['name' => 'Tab', 'scopes' => ['mcp:read']])
        ->assertRedirect();

    $plainKey = pendingIssuedApiKeySecret();

    $partial = $this->actingAs($user)
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) Inertia::getVersion(),
            'X-Inertia-Partial-Component' => 'settings/api-keys',
            'X-Inertia-Partial-Data' => 'apiKeys',
            'X-Requested-With' => 'XMLHttpRequest',
            'Accept' => 'text/html, application/xhtml+xml',
        ])
        ->get(route('api-keys.index'));

    $partial->assertOk()
        ->assertJsonPath('component', 'settings/api-keys')
        ->assertJsonPath('props.apiKeys.data.0.name', 'Tab');

    expect($partial->json('flash') ?? [])->toBe([])
        ->and(session()->has(ApiKeySecretOnceService::SESSION_KEY))->toBeTrue();

    $this->flushHeaders();

    $this->actingAs($user)->get(route('api-keys.index'))
        ->assertInertia(fn (Assert $page) => $page->hasFlash('issuedApiKey.secret', $plainKey));
});

it('drops a pending secret after ten minutes', function () {
    Date::setTestNow('2026-11-22 12:00:00');
    $user = apiKeyOwner();

    $this->actingAs($user)
        ->post(route('api-keys.store'), ['name' => 'Stale', 'scopes' => ['mcp:read']])
        ->assertRedirect();

    expect(pendingIssuedApiKeySecret())->toBeString();

    Date::setTestNow('2026-11-22 12:10:01');

    $this->actingAs($user)->get(route('api-keys.index'))
        ->assertInertia(fn (Assert $page) => $page->missingFlash('issuedApiKey'));

    expect(session()->has(ApiKeySecretOnceService::SESSION_KEY))->toBeFalse();
});

it('revokes immediately so the previous secret stops working too', function () {
    $user = apiKeyOwner();
    $oldPlain = plainApiKey($user);
    $apiKey = ApiKey::query()->sole();
    $newPlain = app(RotateApiKeyAction::class)->execute($apiKey, $user);

    $this->actingAs($user)->delete(route('api-keys.destroy', $apiKey))->assertRedirect();

    callWithApiKey($oldPlain)->assertUnauthorized();
    callWithApiKey($newPlain)->assertUnauthorized();
});

it('enforces the per-key rate_limit_per_minute column', function () {
    $user = apiKeyOwner();
    $plainKey = plainApiKey($user);
    ApiKey::query()->sole()->forceFill(['rate_limit_per_minute' => 2])->save();

    callWithApiKey($plainKey)->assertOk();
    callWithApiKey($plainKey)->assertOk();
    callWithApiKey($plainKey)->assertTooManyRequests()->assertHeader('Retry-After');
});

it('limits failed attempts per IP and requests per key via the default config', function () {
    expect(config('api-keys.failed_attempts_per_minute'))->toBe(20);

    for ($attempt = 0; $attempt < 20; $attempt++) {
        callWithApiKey('exc_test_'.str_repeat('x', 40))->assertUnauthorized();
    }

    callWithApiKey('exc_test_'.str_repeat('x', 40))->assertTooManyRequests();

    callWithApiKey(plainApiKey(apiKeyOwner()))->assertTooManyRequests();
});

it('limits the requests of one key per minute from the app default', function () {
    config(['api-keys.default_rate_limit_per_minute' => 2]);
    $plainKey = plainApiKey(apiKeyOwner());

    callWithApiKey($plainKey)->assertOk();
    callWithApiKey($plainKey)->assertOk();
    callWithApiKey($plainKey)->assertTooManyRequests()->assertHeader('Retry-After');
});

it('notifies the owner and the issuer once for every key within the seven-day notice window', function () {
    Notification::fake();
    Date::setTestNow('2026-11-17 10:00:00');
    $owner = User::factory()->create();
    $issuer = User::factory()->create();

    $inWindow = ApiKey::factory()->for($owner, 'owner')->create([
        'created_by' => $issuer->id,
        'expires_at' => now()->addDays(6)->addHours(12),
    ]);
    $soon = ApiKey::factory()->for($owner, 'owner')->create([
        'created_by' => $owner->id,
        'expires_at' => now()->addDays(3),
    ]);
    ApiKey::factory()->for($owner, 'owner')->create([
        'created_by' => $owner->id,
        'expires_at' => now()->addDays(10),
    ]);

    $this->artisan('api-keys:notify-expiring')->assertSuccessful();

    Notification::assertSentTo($owner, ApiKeyExpiringNotification::class, fn (ApiKeyExpiringNotification $notification): bool => $notification->apiKey->is($inWindow));
    Notification::assertSentTo($owner, ApiKeyExpiringNotification::class, fn (ApiKeyExpiringNotification $notification): bool => $notification->apiKey->is($soon));
    Notification::assertSentTo($issuer, ApiKeyExpiringNotification::class, fn (ApiKeyExpiringNotification $notification): bool => $notification->apiKey->is($inWindow));
    Notification::assertCount(3);

    // expiry_notified_at makes the next daily run skip both keys.
    $this->artisan('api-keys:notify-expiring')->assertSuccessful();

    Notification::assertCount(3);
});
