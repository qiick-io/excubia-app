<?php

declare(strict_types=1);

/**
 * Product-owned behaviour hooks for the fleet conformance suite.
 *
 * The sync writes this file once and never overwrites or checks it: it is the
 * repo's own. Scalar product settings (user model, table names, feature flags)
 * live in `qiick-standards.json` → `conformance`. Callables that exercise the
 * real app (soft-deleted groups, erasure, SSRF, …) go here.
 *
 * Each key matches a check that calls `ConformanceHooks::requireCallable()`.
 * Omit a key until the product is ready; the check fails until you implement
 * it or declare a divergence in `qiick-standards.json`. Diverged checks keep a
 * hook too, so C37 can tell a real gap from a missing probe.
 *
 * @return array<string, callable(): void>
 */

use App\Actions\CreateApiKeyAction;
use App\Actions\DeleteUserAction;
use App\Actions\RotateProjectTokenAction;
use App\Data\ApiKeyData;
use App\Enums\ApiKeyScopeEnum;
use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Http\Resources\ApiKeyResource;
use App\Jobs\DeliverAlertJob;
use App\Models\Activity;
use App\Models\ApiKey;
use App\Models\HealthReport;
use App\Models\Project;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\EffectivePermissionResolverService;
use App\Services\SettingsRepositoryService;
use App\Services\WebhookUrlGuardService;
use Database\Seeders\CreateSuperAdminSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\Schema\Builder;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Fortify;
use Spatie\Permission\Models\Role as SpatieRole;

/** Seeds the built-in roles the behaviour hooks assert against. */
$seedPermissions = static function (): void {
    Artisan::call('db:seed', ['--class' => PermissionSeeder::class, '--force' => true]);
};

/** A webhook job with the minimum payload its delivery recorder needs. */
$alertJob = static fn (): DeliverAlertJob => new DeliverAlertJob(DeliverAlertJob::WEBHOOK, [
    'id' => (string) Str::uuid(),
    'event' => 'ping',
]);

return [
    'assertSoftDeletedGroupGrantsNothing' => static function () use ($seedPermissions): void {
        $seedPermissions();

        $member = User::factory()->create();
        $reader = SpatieRole::query()->where('name', RoleEnum::Reader->value)->sole();
        $group = UserGroup::query()->create(['name' => 'conformance-viewers']);
        $group->roles()->sync([$reader->id]);
        $group->users()->sync([$member->id]);

        $resolver = app(EffectivePermissionResolverService::class);
        $permission = PermissionEnum::CanShowProjects->value;

        expect($resolver->hasPermission($member, $permission))->toBeTrue();

        $group->delete();

        expect($resolver->hasPermission($member, $permission))->toBeFalse();
    },

    'assertGlobalRoleGuard' => static function () use ($seedPermissions): void {
        $seedPermissions();

        $wasRejected = false;

        try {
            SpatieRole::create(['name' => RoleEnum::Reader->value]);
        } catch (Throwable) {
            $wasRejected = true;
        }

        expect($wasRejected)->toBeTrue();
    },

    'assertInitialAdminSeeder' => static function () use ($seedPermissions): void {
        $seedPermissions();

        $originalEnvironment = app()['env'];
        $settings = app(SettingsRepositoryService::class);
        $originalPolicy = $settings->passwordPolicy();

        try {
            app()['env'] = 'production';
            $settings->save(['password_policy' => 'weak']);

            // The known default is refused even when the active policy would accept it.
            config(['super_admin.password' => 'password']);
            Artisan::call('db:seed', ['--class' => CreateSuperAdminSeeder::class, '--force' => true]);
            expect(User::query()->exists())->toBeFalse();

            // Another weak password is refused by the policy itself, not by a string comparison.
            config(['super_admin.password' => 'abc12']);
            Artisan::call('db:seed', ['--class' => CreateSuperAdminSeeder::class, '--force' => true]);
            expect(User::query()->exists())->toBeFalse();

            // A six-character password does satisfy the weak policy, so the seeder creates it.
            config(['super_admin.password' => 's3cret']);
            Artisan::call('db:seed', ['--class' => CreateSuperAdminSeeder::class, '--force' => true]);
            $weakAdmin = User::query()->where('email', config('super_admin.email'))->sole();
            expect(Hash::check('s3cret', $weakAdmin->password))->toBeTrue();
            $weakAdmin->forceDelete();

            $settings->save(['password_policy' => 'strong']);
            $strongPassword = 'V3ry-Str0ng-Passphrase-2026';
            config(['super_admin.password' => $strongPassword]);
            Artisan::call('db:seed', ['--class' => CreateSuperAdminSeeder::class, '--force' => true]);

            $admin = User::query()->where('email', config('super_admin.email'))->sole();

            expect($admin->hasRole(RoleEnum::SuperAdmin->value))->toBeTrue()
                ->and(Hash::check($strongPassword, $admin->password))->toBeTrue();

            $admin->forceFill(['first_name' => 'Existing', 'is_active' => false])->save();
            Artisan::call('db:seed', ['--class' => CreateSuperAdminSeeder::class, '--force' => true]);

            expect(User::query()->count())->toBe(1)
                ->and($admin->refresh()->first_name)->toBe('Existing')
                ->and($admin->is_active)->toBeFalse()
                ->and($admin->hasRole(RoleEnum::SuperAdmin->value))->toBeTrue();
        } finally {
            app()['env'] = $originalEnvironment;
            $settings->save(['password_policy' => $originalPolicy]);
        }
    },

    'assertErasure' => static function (): void {
        $email = 'erase-me-conformance@example.test';
        $username = 'erase-me-conformance';
        $owner = User::factory()->create(['email' => $email, 'username' => $username]);
        $principal = $owner->principal()->firstOrFail();
        app(CreateApiKeyAction::class)->execute(
            $owner,
            new ApiKeyData('Erasure key', [ApiKeyScopeEnum::McpRead], null),
        );
        $apiKey = ApiKey::query()->sole();

        app(DeleteUserAction::class)->execute($owner);

        $erased = $owner->fresh();

        expect($erased)->not->toBeNull()
            ->and($erased->trashed())->toBeTrue()
            ->and($erased->email)->not->toBe($email)
            ->and($erased->username)->not->toBe($username)
            ->and($apiKey->fresh())->not->toBeNull()
            ->and($apiKey->fresh()->isRevoked())->toBeTrue()
            ->and($principal->fresh()->user_id)->toBeNull()
            ->and($principal->fresh()->deactivated_at)->not->toBeNull();
    },

    'assertApiKeyActivityUuid' => static function () use ($seedPermissions): void {
        $seedPermissions();
        config(['excubia.mcp_enabled' => true]);

        $user = User::factory()->create()->assignRole(RoleEnum::Reader->value);

        if (! $user->hasPermissionTo(PermissionEnum::CanUseAi->value)) {
            $user->givePermissionTo(PermissionEnum::CanUseAi->value);
        }

        $plainKey = app(CreateApiKeyAction::class)->execute(
            $user,
            new ApiKeyData('MCP key', [ApiKeyScopeEnum::McpRead], null),
        );
        $apiKey = ApiKey::query()->where('key_prefix', ApiKey::publicPrefixFromToken($plainKey))->sole();

        test()->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'list-projects',
                'arguments' => [],
                '_meta' => [
                    'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                    'io.modelcontextprotocol/clientCapabilities' => new stdClass,
                    'io.modelcontextprotocol/clientInfo' => ['name' => 'conformance', 'version' => '1.0.0'],
                ],
            ],
        ], [
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => '2026-07-28',
            'Mcp-Method' => 'tools/call',
            'Mcp-Name' => 'list-projects',
            'Authorization' => 'Bearer '.$plainKey,
        ])->assertOk();

        $activity = Activity::query()->where('log_name', 'ai')->where('causer_id', $user->id)->sole();

        expect($activity->properties['surface'])->toBe('mcp')
            ->and($activity->properties['api_key_id'])->toBe($apiKey->uuid)
            ->and($activity->properties['api_key_id'])->not->toBe($apiKey->id);
    },

    'assertErrorContract' => static function (): void {
        config(['app.debug' => false]);

        $unauthorized = test()->call('POST', '/api/reports', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], '{}');

        expect($unauthorized->getStatusCode())->toBe(401)
            ->and($unauthorized->json('error'))->toBe('invalid_token')
            ->and($unauthorized->json())->not->toHaveKey('exception')
            ->not->toHaveKey('trace')
            ->not->toHaveKey('file')
            ->not->toHaveKey('line');

        $withoutAccept = test()->call('GET', '/api/does-not-exist');

        expect($withoutAccept->headers->get('Content-Type'))->toContain('application/json')
            ->and($withoutAccept->json())->toBeArray();

        // The backed error-code enum is still pending (#390): a 422 must carry a stable code too.
        $project = Project::factory()->create();
        $token = app(RotateProjectTokenAction::class)->execute($project);
        $invalid = test()->postJson('/api/reports', ['protocolVersion' => 1], ['Authorization' => 'Bearer '.$token]);

        expect($invalid->getStatusCode())->toBe(422)
            ->and($invalid->json('error'))->toBe('validation_failed');
    },

    'assertContentLanguage' => static function (): void {
        expect(test()->getJson('/up')->headers->get('Content-Language'))->toBe('en')
            ->and(test()->call('GET', '/api/does-not-exist')->headers->get('Content-Language'))->toBe('en');
    },

    'assertPaginationShape' => static function () use ($seedPermissions): void {
        $seedPermissions();

        $admin = User::factory()->create()->assignRole(RoleEnum::Admin->value);
        $payload = test()->actingAs($admin)->getJson(route('users.index'))->json();
        $rolesPayload = test()->actingAs($admin)->getJson(route('users.assignable-roles'))->json();

        expect(array_keys($payload))->toBe(['data', 'links', 'meta'])
            ->and(array_keys($rolesPayload))->toBe(['data', 'links', 'meta'])
            ->and($rolesPayload['links'])->not->toBeEmpty()
            ->and(array_keys($rolesPayload['meta']))->toContain('current_page', 'last_page', 'per_page', 'total');
    },

    'assertThrottleHeaders' => static function (): void {
        config(['excubia.push_requests_per_minute' => 1]);

        $project = Project::factory()->create();
        $token = app(RotateProjectTokenAction::class)->execute($project);

        $post = static function () use ($token): TestResponse {
            return test()->call('POST', '/api/reports', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            ], '{"protocolVersion":1}');
        };

        $post();
        $response = $post();

        expect($response->getStatusCode())->toBe(429)
            ->and($response->headers->get('X-RateLimit-Limit'))->toBe('1')
            ->and($response->headers->has('X-RateLimit-Remaining'))->toBeTrue()
            ->and($response->headers->has('Retry-After'))->toBeTrue();
    },

    'assertRequestId' => static function (): void {
        $inbound = test()->withHeaders(['X-Request-Id' => 'conformance-request-123'])->getJson('/up');
        $generated = test()->getJson('/up');

        expect($inbound->headers->get('X-Request-Id'))->toBe('conformance-request-123')
            ->and($generated->headers->get('X-Request-Id'))->not->toBeEmpty();
    },

    'assertIdempotency' => static function (): void {
        $project = Project::factory()->create();
        $token = app(RotateProjectTokenAction::class)->execute($project);
        $report = json_decode((string) file_get_contents(base_path('tests/Fixtures/core/report-every-check.json')), true, 512, JSON_THROW_ON_ERROR);
        $envelope = [
            'protocolVersion' => 1,
            'batchId' => (string) Str::uuid(),
            'sentAt' => '2026-11-22T08:00:00Z',
            'report' => $report,
        ];
        $headers = ['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => 'conformance-key-123'];

        $first = test()->postJson('/api/reports', $envelope, $headers)->assertCreated();
        $replay = test()->postJson('/api/reports', $envelope, $headers);

        expect($replay->getStatusCode())->toBe(201)
            ->and($replay->json('reportId'))->toBe($first->json('reportId'))
            ->and(HealthReport::query()->count())->toBe(1);
    },

    'assertWebhooks' => static function () use ($alertJob): void {
        $settings = app(SettingsRepositoryService::class);
        $settings->save([
            'webhook_url' => 'https://hooks.example.com/hook',
            'webhook_secret' => 'whsec_'.base64_encode(str_repeat('s', 32)),
        ]);

        $headers = [];
        $body = null;
        Http::fake(static function (ClientRequest $request) use (&$headers, &$body) {
            $headers = $request->headers();
            $body = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);

            return Http::response('', 204);
        });

        $alertJob()->handle($settings, new WebhookUrlGuardService(static fn (): array => ['93.184.216.34']));

        expect(array_keys($headers))->toContain('Webhook-Id', 'Webhook-Timestamp', 'Webhook-Signature')
            ->and($body)->toHaveKeys(['type', 'timestamp', 'data']);
    },

    'assertSsrfGuard' => static function () use ($alertJob): void {
        $guard = new WebhookUrlGuardService(static fn (): array => ['93.184.216.34']);

        expect($guard->validate('https://169.254.169.254/latest/meta-data/'))->toBe('Host is not allowed.')
            ->and($guard->validate('https://2852039166/latest/meta-data/'))->toBe('Host is not allowed.')
            ->and($guard->authorizeOutbound('https://hooks.example.com/hook'))->toBe([
                'ok' => true,
                'curl_connect_to' => ['::93.184.216.34:443'],
            ]);

        foreach (['https://10.0.0.8/hook', 'https://127.0.0.1/hook'] as $blockedLiteral) {
            expect($guard->validate($blockedLiteral))->toBe('Host is not allowed.');
        }

        $resolverCalls = 0;
        $pinnedGuard = new WebhookUrlGuardService(static function (string $_host) use (&$resolverCalls): array {
            $resolverCalls++;

            return ['93.184.216.34'];
        });
        app()->instance(WebhookUrlGuardService::class, $pinnedGuard);

        $sentOptions = null;
        Http::fake(static function (ClientRequest $_request, array $options) use (&$sentOptions) {
            $sentOptions = $options;

            return Http::response('', 204);
        });

        $settings = app(SettingsRepositoryService::class);
        $settings->save([
            'webhook_url' => 'https://rebind.example.com/hook',
            'webhook_secret' => str_repeat('s', 32),
        ]);
        $alertJob()->handle($settings, $pinnedGuard);

        expect($resolverCalls)->toBe(1)
            ->and($sentOptions['curl'] ?? null)->toBe([CURLOPT_CONNECT_TO => ['::93.184.216.34:443']]);

        foreach (['10.0.0.8' => 'Host resolves to a disallowed address.', '169.254.169.254' => 'Host resolves to a disallowed address.'] as $address => $reason) {
            Http::fake();
            $job = $alertJob();
            $job->handle($settings, new WebhookUrlGuardService(static fn (): array => [$address]));

            Http::assertNothingSent();
            expect($settings->all()['webhook_last_error']['message'])->toContain($reason);
        }
    },

    'assertTeamSwitch' => static function () use ($seedPermissions): void {
        $seedPermissions();

        expect(config('permission.teams'))->toBeTrue();

        $user = User::factory()->create();
        $teamA = (string) Str::uuid();
        $teamB = (string) Str::uuid();

        setPermissionsTeamId($teamA);
        $user->assignRole(RoleEnum::Reader->value);

        expect($user->fresh()->hasRole(RoleEnum::Reader->value))->toBeTrue();

        setPermissionsTeamId($teamB);
        $user->unsetRelation('roles')->unsetRelation('permissions');

        expect($user->hasRole(RoleEnum::Reader->value))->toBeFalse();

        setPermissionsTeamId($teamA);
        $user->unsetRelation('roles')->unsetRelation('permissions');

        expect($user->hasRole(RoleEnum::Reader->value))->toBeTrue();
    },

    'assertSuperAdmin' => static function () use ($seedPermissions): void {
        $seedPermissions();

        $user = User::factory()->create();
        $user->assignRole(RoleEnum::SuperAdmin->value);

        /** @var Builder $schema */
        $schema = Schema::connection(config('database.default'));

        expect(config('permission.teams'))->toBeFalse()
            ->and($schema->hasColumn('roles', 'organisation_id'))->toBeFalse()
            ->and($user->fresh()->hasRole(RoleEnum::SuperAdmin->value))->toBeTrue()
            ->and(app(EffectivePermissionResolverService::class)->isSuperAdmin($user))->toBeTrue()
            ->and($user->can(PermissionEnum::CanShowProjects->value))->toBeTrue();
    },

    'assertApiResourcesHideBigints' => static function () use ($seedPermissions): void {
        $seedPermissions();

        $admin = User::factory()->create()->assignRole(RoleEnum::Admin->value);
        $users = test()->actingAs($admin)->getJson(route('users.index'))->json('data');

        expect($users)->not->toBeEmpty()
            ->and($users[0]['id'] ?? null)->not->toBeInt();

        $apiKey = ApiKey::factory()->create();
        $resource = (new ApiKeyResource($apiKey))->resolve(request());

        expect($resource)->not->toHaveKey('id')
            ->and($resource['uuid'])->toBe($apiKey->uuid);
    },

    'assertEncryptedCasts' => static function (): void {
        $user = User::factory()->create();
        app(EnableTwoFactorAuthentication::class)($user);

        $secret = (string) DB::table('users')->where('id', $user->id)->value('two_factor_secret');
        $codes = (string) DB::table('users')->where('id', $user->id)->value('two_factor_recovery_codes');

        expect($secret)->not->toBeEmpty()
            ->and($secret)->not->toContain(Fortify::currentEncrypter()->decrypt($secret))
            ->and(Fortify::currentEncrypter()->decrypt($codes))->toContain('"');

        $settings = app(SettingsRepositoryService::class);

        foreach (['webhook_secret' => 'whsec_conformance-secret', 'oidc_client_secret' => 'oidc-conformance-secret', 'notifier_telegram_bot_token' => '123456:conformance-token'] as $key => $plainSecret) {
            $settings->save([$key => $plainSecret]);

            $stored = (string) DB::table('settings')
                ->where('scope', SettingsRepositoryService::SCOPE_INSTANCE)
                ->where('key', $key)
                ->value('value');
            $storedSecret = json_decode($stored, true, 512, JSON_THROW_ON_ERROR);

            expect($storedSecret)->not->toBe($plainSecret)
                ->and(Crypt::decryptString((string) $storedSecret))->toBe($plainSecret);
        }

        $apiKey = ApiKey::factory()->create(['owner_id' => $user->id]);

        expect($apiKey->key_hash)->toMatch('/^[a-f0-9]{64}$/');

        // Rotate the app's own encrypter: a new APP_KEY plus APP_PREVIOUS_KEYS must still read
        // a value written before the rotation. No hand-built Encrypter in this probe.
        $originalKey = (string) config('app.key');
        $originalPreviousKeys = config('app.previous_keys');
        $oldKey = 'base64:'.base64_encode(random_bytes(32));
        $newKey = 'base64:'.base64_encode(random_bytes(32));

        try {
            config(['app.key' => $oldKey, 'app.previous_keys' => []]);
            app()->forgetInstance('encrypter');
            $storedBeforeRotation = app('encrypter')->encryptString('rotate-me');

            config(['app.key' => $newKey, 'app.previous_keys' => [$oldKey]]);
            app()->forgetInstance('encrypter');

            expect(app('encrypter')->decryptString($storedBeforeRotation))->toBe('rotate-me');
        } finally {
            config(['app.key' => $originalKey, 'app.previous_keys' => $originalPreviousKeys]);
            app()->forgetInstance('encrypter');
        }
    },
];
