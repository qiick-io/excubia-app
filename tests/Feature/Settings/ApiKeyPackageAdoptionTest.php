<?php

declare(strict_types=1);

use App\Actions\CreateApiKeyAction;
use App\Actions\RevokeApiKeyAction;
use App\Actions\RotateApiKeyAction;
use App\Data\ApiKeyData;
use App\Enums\ApiKeyScopeEnum;
use App\Enums\RoleEnum;
use App\Http\Middleware\AuthenticateApiKeyMiddleware;
use App\Models\ApiKey;
use App\Models\User;
use App\Notifications\ApiKeyExpiringNotification;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Qiick\ApiKeys\Contracts\ApiKeyRepository;
use Qiick\ApiKeys\Contracts\LifetimePolicy;
use Qiick\ApiKeys\Contracts\ScopeVocabulary;
use Qiick\ApiKeys\CredentialKind;
use Qiick\ApiKeys\RevocationReason;
use Qiick\ApiKeys\RotationService;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * What adopting qiick/laravel-api-keys 0.3 adds to personal API keys (docs/adoption-plan.md §1):
 * the reference schema's guarantees, the v2 checksum, the owner principal rule, fail-closed
 * resource restrictions, day quotas, and the attribution columns of use, rotation and revocation.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    Route::middleware(AuthenticateApiKeyMiddleware::class.':mcp:read')
        ->get('/__test/adopted-api-key', fn () => response()->json(['ok' => true]));
});

function adoptedKeyOwner(): User
{
    return User::factory()->create()->assignRole(RoleEnum::Admin->value);
}

function adoptedPlainKey(User $owner): string
{
    return app(CreateApiKeyAction::class)->execute($owner, new ApiKeyData('Adopted', [ApiKeyScopeEnum::McpRead], null));
}

function callAdoptedKey(string $plainKey, array $headers = []): TestResponse
{
    return test()->withHeaders(['Authorization' => 'Bearer '.$plainKey, ...$headers])->getJson('/__test/adopted-api-key');
}

it('names exactly one row per public prefix', function () {
    $apiKey = ApiKey::factory()->create();

    expect(fn () => ApiKey::factory()->create(['key_prefix' => $apiKey->key_prefix]))
        ->toThrow(QueryException::class);
});

it('requires an owner principal and refuses to delete a principal that still owns a key', function () {
    $apiKey = ApiKey::factory()->create();

    // Each refused write runs in its own savepoint: PostgreSQL aborts the test transaction otherwise.
    expect(fn () => DB::transaction(fn () => ApiKey::factory()->create(['owner_principal_id' => null])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('principals')->where('id', $apiKey->owner_principal_id)->delete()))->toThrow(QueryException::class)
        ->and(ApiKey::query()->whereKey($apiKey->id)->exists())->toBeTrue();
});

it('refuses a token whose checksum does not match, without trying the secret', function () {
    $plainKey = adoptedPlainKey(adoptedKeyOwner());
    $lastCharacter = substr($plainKey, -1);
    $tamperedKey = substr($plainKey, 0, -1).($lastCharacter === 'a' ? 'b' : 'a');

    callAdoptedKey($plainKey)->assertOk();
    callAdoptedKey($tamperedKey)->assertUnauthorized()->assertJsonPath('error', 'invalid_token');
});

it('refuses the key of a deactivated owner principal even while the user is active', function () {
    $owner = adoptedKeyOwner();
    $plainKey = adoptedPlainKey($owner);
    callAdoptedKey($plainKey)->assertOk();

    DB::table('principals')->where('user_id', $owner->id)->update(['deactivated_at' => now()]);

    callAdoptedKey($plainKey)
        ->assertUnauthorized()
        ->assertJsonPath('error', 'invalid_token')
        ->assertJsonPath('message', 'The API key owner is no longer active.');
});

it('refuses a key that carries resource restrictions Excubia does not evaluate', function () {
    $plainKey = adoptedPlainKey(adoptedKeyOwner());
    ApiKey::query()->sole()->forceFill(['restrictions' => [
        'v' => 1,
        'resources' => [['type' => 'project', 'values' => ['billing'], 'mode' => 'allow']],
    ]])->save();

    callAdoptedKey($plainKey)
        ->assertForbidden()
        ->assertJsonPath('error', 'request_not_allowed')
        ->assertHeaderMissing('WWW-Authenticate');
});

it('enforces a request restriction on the origin', function () {
    $plainKey = adoptedPlainKey(adoptedKeyOwner());
    ApiKey::query()->sole()->forceFill(['restrictions' => [
        'v' => 1,
        'request' => ['origins' => ['https://app.example.com']],
    ]])->save();

    callAdoptedKey($plainKey, ['Origin' => 'https://app.example.com'])->assertOk();
    callAdoptedKey($plainKey, ['Origin' => 'https://app.example.com.evil.test'])
        ->assertForbidden()
        ->assertJsonPath('error', 'request_not_allowed');
});

it('enforces the per-key day quota', function () {
    $plainKey = adoptedPlainKey(adoptedKeyOwner());
    ApiKey::query()->sole()->forceFill(['rate_limit_per_day' => 2])->save();

    callAdoptedKey($plainKey)->assertOk();
    callAdoptedKey($plainKey)->assertOk();
    callAdoptedKey($plainKey)
        ->assertTooManyRequests()
        ->assertJsonPath('error', 'rate_limit_exceeded')
        ->assertHeader('X-RateLimit-Limit', '2');
});

it('records the user agent of the last use', function () {
    $plainKey = adoptedPlainKey(adoptedKeyOwner());

    callAdoptedKey($plainKey, ['User-Agent' => 'claude-code/2.0'])->assertOk();

    expect(ApiKey::query()->sole()->last_used_user_agent)->toBe('claude-code/2.0');
});

it('keeps the prefix on rotation and stamps the rotation', function () {
    Date::setTestNow('2026-11-25 12:00:00');
    $owner = adoptedKeyOwner();
    adoptedPlainKey($owner);
    $apiKey = ApiKey::query()->sole();
    $prefixBefore = $apiKey->key_prefix;

    $this->actingAs($owner)->post(route('api-keys.rotate', $apiKey))->assertRedirect(route('api-keys.index'));
    $apiKey->refresh();

    expect($apiKey->key_prefix)->toBe($prefixBefore)
        ->and($apiKey->previous_key_prefix)->toBeNull()
        ->and($apiKey->last_rotated_at?->toDateTimeString())->toBe('2026-11-25 12:00:00')
        ->and($apiKey->token_version)->toBe(2);
});

it('records who revoked a key and why', function () {
    $owner = adoptedKeyOwner();
    adoptedPlainKey($owner);
    $apiKey = ApiKey::query()->sole();

    $this->actingAs($owner)->delete(route('api-keys.destroy', $apiKey))->assertRedirect(route('api-keys.index'));
    $apiKey->refresh();

    expect($apiKey->revoked_by)->toBe($owner->id)
        ->and($apiKey->revoked_reason)->toBe('user');
});

it('records a revocation by someone other than the owner, and by the system', function () {
    $owner = adoptedKeyOwner();
    $admin = adoptedKeyOwner();
    $byAdmin = ApiKey::factory()->for($owner, 'owner')->create();
    $bySystem = ApiKey::factory()->for($owner, 'owner')->create();

    app(RevokeApiKeyAction::class)->execute($byAdmin, $admin);
    app(RevokeApiKeyAction::class)->execute($bySystem);
    app(RevokeApiKeyAction::class)->execute($bySystem->fresh(), $admin);

    expect($byAdmin->fresh()->revoked_reason)->toBe('admin')
        ->and($byAdmin->fresh()->revoked_by)->toBe($admin->id)
        ->and($bySystem->fresh()->revoked_reason)->toBe('system')
        ->and($bySystem->fresh()->revoked_by)->toBeNull();
});

it('refuses with 409 a rotation the lifetime policy forbids', function () {
    config(['api-keys.max_lifetime_days' => 30]);
    app()->forgetInstance(LifetimePolicy::class);
    app()->forgetInstance(RotationService::class);
    $owner = adoptedKeyOwner();
    $apiKey = ApiKey::factory()->for($owner, 'owner')->create(['expires_at' => now()->addDays(90)]);

    expect(fn () => app(RotateApiKeyAction::class)->execute($apiKey, $owner))
        ->toThrow(HttpException::class);
});

it('exposes the key row through the package record contract without its hashes', function () {
    $apiKey = ApiKey::factory()->create([
        'description' => 'CI pipeline',
        'metadata' => ['labels' => ['ci']],
        'revoked_reason' => 'exposure',
        'exposure_source' => 'manual',
    ]);
    $record = ApiKey::query()->findOrFail($apiKey->id);

    expect($record->id())->toBe($apiKey->id)
        ->and($record->publicId())->toBe($apiKey->uuid)
        ->and($record->description())->toBe('CI pipeline')
        ->and($record->metadata())->toBe(['labels' => ['ci']])
        ->and($record->oauthClientId())->toBeNull()
        ->and($record->credentialKind())->toBe(CredentialKind::ApiKey)
        ->and($record->revokedReason())->toBe(RevocationReason::Exposure)
        ->and($record->revokedByIdentifier())->toBeNull()
        ->and($record->revocationNote())->toBeNull()
        ->and($record->exposedAt())->toBeNull()
        ->and($record->exposureSource())->toBe('manual')
        ->and($record->lastRotatedAt())->toBeNull()
        ->and($record->ownerIdentifier())->toBe($apiKey->owner_id)
        ->and($record->ownerPrincipalIdentifier())->toBe($apiKey->owner_principal_id)
        ->and($record->ownerKind())->toBe('user')
        ->and($record->createdByIdentifier())->toBe($apiKey->created_by)
        ->and($record->expiryNotifiedAt())->toBeNull()
        ->and($record->expiryNoticeClaimedAt())->toBeNull()
        ->and($record->lastUsedUserAgent())->toBeNull()
        ->and($record->attributes())->not->toHaveKey('key_hash')
        ->and($record->attributes())->not->toHaveKey('previous_key_hash');

    $record->forceFill(['credential_kind' => 'unknown']);

    expect($record->credentialKind())->toBe(CredentialKind::OauthGrant);
});

it('finds a key by its public id and never reports legacy keys', function () {
    $apiKey = ApiKey::factory()->create();
    $repository = app(ApiKeyRepository::class);

    expect($repository->findByPublicId($apiKey->uuid)?->is($apiKey))->toBeTrue()
        ->and($repository->findByPublicId((string) Str::uuid()))->toBeNull()
        ->and($repository->findActiveLegacy())->toBe([]);
});

it('releases a claimed expiry notice so the next run can claim it again', function () {
    $apiKey = ApiKey::factory()->create(['expires_at' => now()->addDays(3)]);
    $repository = app(ApiKeyRepository::class);
    $now = now()->toDateTimeImmutable();

    expect($repository->claimExpiryNotice($apiKey, $now, 900))->toBeTrue();

    $repository->releaseExpiryNotice($apiKey);

    expect($apiKey->fresh()->expiry_notice_claimed_at)->toBeNull()
        ->and($repository->claimExpiryNotice($apiKey, $now, 900))->toBeTrue();
});

it('lists the scope vocabulary from the scope enum', function () {
    $vocabulary = app(ScopeVocabulary::class);

    expect($vocabulary->all())->toBe(['mcp:read', 'mcp:write'])
        ->and($vocabulary->contains('mcp:read'))->toBeTrue()
        ->and($vocabulary->contains('rest:read'))->toBeFalse();
});

it('renders the expiry notice by mail and in the bell without the secret', function () {
    $apiKey = ApiKey::factory()->create(['name' => 'Deploy bot']);
    $notification = new ApiKeyExpiringNotification($apiKey, new DateTimeImmutable('2026-12-01T09:00:00+00:00'));
    $owner = $apiKey->owner;

    $mail = $notification->toMail($owner);
    $bell = $notification->toArray($owner);

    expect($notification->via($owner))->toBe(['database', 'mail'])
        ->and($mail->subject)->toContain('Deploy bot')
        ->and(implode(' ', $mail->introLines))->toContain($apiKey->key_prefix)
        ->and($bell['type'])->toBe('api_key_expiring')
        ->and($bell['key_prefix'])->toBe($apiKey->key_prefix)
        ->and($bell['expires_at'])->toBe('2026-12-01T09:00:00+00:00')
        ->and(json_encode($bell))->not->toContain($apiKey->key_hash);
});

it('denies a key whose stored IP allowlist is corrupted instead of reading it as no restriction', function () {
    $plainKey = adoptedPlainKey(adoptedKeyOwner());
    DB::table('api_keys')->update(['ip_allowlist' => '["203.0.113.10"']);

    callAdoptedKey($plainKey)->assertForbidden()->assertJsonPath('error', 'ip_not_allowed');
});
