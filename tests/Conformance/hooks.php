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
use App\Actions\SaveUserGroupAction;
use App\Actions\SyncUserRolesAction;
use App\Data\ApiKeyData;
use App\Enums\ApiKeyScopeEnum;
use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Http\Resources\ApiKeyResource;
use App\Jobs\SendAlertWebhooksJob;
use App\Models\Activity;
use App\Models\ApiKey;
use App\Models\HealthReport;
use App\Models\Project;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\AlertWebhookService;
use App\Services\EffectivePermissionResolverService;
use App\Services\SettingsRepositoryService;
use Database\Seeders\CreateSuperAdminSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\Schema\Builder;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Fortify;
use Qiick\Access\Contracts\ActorContext;
use Qiick\Access\Contracts\ActorKind;
use Qiick\Access\EffectivePermissionResolver;
use Qiick\Access\Laravel\AccessContext;
use Qiick\Access\Principals\PrincipalDirectory;
use Qiick\Outbound\Contracts\HostResolver;
use Qiick\Outbound\Models\WebhookDeliveryAttempt;
use Qiick\Outbound\Ssrf\RemoteUrlGuard;
use Qiick\Outbound\Ssrf\UnsafeUrl;
use Qiick\Standards\Conformance\Support\ConformanceBehaviourProbe;
use Spatie\Permission\Models\Role as SpatieRole;

/** Seeds the built-in roles the behaviour hooks assert against. */
$seedPermissions = static function (): void {
    Artisan::call('db:seed', ['--class' => PermissionSeeder::class, '--force' => true]);
};

/** Stores one alert webhook delivery and runs the worker once, as a queued alert does. */
$sendAlertWebhook = static function (): void {
    app(AlertWebhookService::class)->send(['id' => (string) Str::uuid(), 'event' => 'ping']);
    app()->call([new SendAlertWebhooksJob, 'handle']);
};

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

            // Case variants are the same known default, whatever the policy would accept.
            config(['super_admin.password' => 'PASSWORD']);
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
            // A user with a principal is hard-deleted only through the access erasure.
            app(PrincipalDirectory::class)->eraseUser($weakAdmin, fn (User $user) => $user->forceDelete());

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
        $firstName = 'Erase';
        $lastName = 'Conformance';
        $ip = '203.0.113.77';
        $owner = User::factory()->create([
            'email' => $email,
            'username' => $username,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'last_login_ip' => $ip,
        ]);
        // A real update makes Spatie write an `updated` row whose `attribute_changes` also holds
        // the old PII. Both this and the `created` row must be clean for erasure to be complete.
        $owner->update(['first_name' => 'EraseRenamed']);
        $publicId = $owner->public_id;
        $databaseKey = (string) $owner->getKey();
        $principal = $owner->principal()->firstOrFail();
        app(CreateApiKeyAction::class)->execute(
            $owner,
            new ApiKeyData('Erasure key', [ApiKeyScopeEnum::McpRead], null),
        );
        $apiKey = ApiKey::query()->sole();

        // These are the app's own activity rows, not a hand-built probe: Spatie writes model
        // changes to `attribute_changes`, while `properties` only holds explicit withProperties().
        $activityIds = Activity::query()
            ->where('subject_type', $owner->getMorphClass())
            ->where('subject_id', $databaseKey)
            ->whereIn('event', ['created', 'updated'])
            ->pluck('id')
            ->all();

        expect($activityIds)->not->toBeEmpty();

        app(DeleteUserAction::class)->execute($owner);

        $erased = $owner->fresh();

        expect($erased)->not->toBeNull()
            ->and($erased->trashed())->toBeTrue()
            ->and($erased->email)->toBe('deleted+'.$publicId.'@example.invalid')
            ->and($erased->username)->toBe('deleted_'.str_replace('-', '', $publicId))
            ->and($erased->first_name)->toBe('Deleted')
            ->and($erased->last_name)->toBeNull()
            ->and($erased->last_login_ip)->toBeNull()
            ->and($erased->is_active)->toBeFalse()
            ->and($apiKey->fresh())->not->toBeNull()
            ->and($apiKey->fresh()->isRevoked())->toBeTrue()
            ->and($principal->fresh()->user_id)->toBeNull()
            ->and($principal->fresh()->deactivated_at)->not->toBeNull();

        $needles = [$email, $username, $firstName, $lastName, $ip];

        foreach ($activityIds as $activityId) {
            $activity = Activity::query()->findOrFail($activityId);
            $payload = json_encode([
                'properties' => $activity->properties?->toArray() ?? [],
                'attribute_changes' => $activity->attribute_changes?->toArray() ?? [],
            ], JSON_THROW_ON_ERROR);

            foreach ($needles as $needle) {
                if (str_contains($payload, $needle)) {
                    throw new RuntimeException("C17 activity {$activityId} still contains \"{$needle}\" after erasure.");
                }
            }

            expect($activity->subject_id)->toBe($databaseKey);
        }
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
        $apiKey = ApiKey::query()->where('key_prefix', apiKeyPrefixOf($plainKey))->sole();

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

    'assertWebhooks' => static function () use ($sendAlertWebhook): void {
        configureAlertWebhook('https://hooks.example.com/hook');

        $request = null;
        $body = null;
        $guard = new RemoteUrlGuard(
            new class implements HostResolver
            {
                public function resolve(string $host): array
                {
                    return ['93.184.216.34'];
                }
            },
            httpsOnly: true,
        );
        bindPinnedAlertClient($guard, static function (ClientRequest $clientRequest) use (&$request, &$body) {
            $request = $clientRequest;
            $body = json_decode($clientRequest->body(), true, 512, JSON_THROW_ON_ERROR);

            return Http::response('', 204);
        });

        $sendAlertWebhook();

        expect($request)->not->toBeNull()
            ->and($request->hasHeader('Webhook-Id'))->toBeTrue()
            ->and($request->hasHeader('Webhook-Timestamp'))->toBeTrue()
            ->and($request->hasHeader('Webhook-Signature'))->toBeTrue()
            ->and($body)->toHaveKeys(['type', 'timestamp', 'data']);
    },

    'assertSsrfGuard' => static function () use ($sendAlertWebhook): void {
        $guardReason = static function (RemoteUrlGuard $guard, string $url): ?string {
            try {
                $guard->authorize($url);

                return null;
            } catch (UnsafeUrl $exception) {
                return $exception->reason();
            }
        };

        $guard = new RemoteUrlGuard(
            new class implements HostResolver
            {
                public function resolve(string $host): array
                {
                    return ['93.184.216.34'];
                }
            },
            httpsOnly: true,
        );

        expect($guardReason($guard, 'https://169.254.169.254/latest/meta-data/'))->toBe('Host is not allowed.')
            ->and($guardReason($guard, 'https://2852039166/latest/meta-data/'))->toBe('Host is not allowed.');

        $destination = $guard->authorize('https://hooks.example.com/hook');
        expect([$destination->curlConnectToEntry])->toBe(['::93.184.216.34:443']);

        foreach (['https://10.0.0.8/hook', 'https://127.0.0.1/hook'] as $blockedLiteral) {
            expect($guardReason($guard, $blockedLiteral))->toBe('Host is not allowed.');
        }

        $resolver = new class implements HostResolver
        {
            public int $calls = 0;

            public function resolve(string $host): array
            {
                $this->calls++;

                return ['93.184.216.34'];
            }
        };
        $pinnedGuard = new RemoteUrlGuard($resolver, httpsOnly: true);

        $sentOptions = null;
        bindPinnedAlertClient($pinnedGuard, static function (ClientRequest $_request, array $options) use (&$sentOptions) {
            $sentOptions = $options;

            return Http::response('', 204);
        });

        configureAlertWebhook('https://rebind.example.com/hook');
        $sendAlertWebhook();

        expect($resolver->calls)->toBe(1)
            ->and($sentOptions['curl'] ?? null)->toBe([CURLOPT_CONNECT_TO => ['::93.184.216.34:443']]);

        foreach (['10.0.0.8' => 'Host resolves to a disallowed address.', '169.254.169.254' => 'Host resolves to a disallowed address.'] as $address => $reason) {
            $blockedGuard = new RemoteUrlGuard(
                new class($address) implements HostResolver
                {
                    public function __construct(private readonly string $address) {}

                    public function resolve(string $host): array
                    {
                        return [$this->address];
                    }
                },
                httpsOnly: true,
            );
            bindPinnedAlertClient($blockedGuard, static fn () => Http::response('', 204));
            $sendAlertWebhook();

            $attempt = WebhookDeliveryAttempt::query()->latest('id')->firstOrFail();

            expect($attempt->error_code)->toBe('ssrf_refused')
                ->and((string) $attempt->error_message)->toContain($reason);
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

        // The alert webhook's signing secret lives in qiick/laravel-outbound's table, encrypted.
        $webhookSecret = configureAlertWebhook('https://hooks.example.com/hook');
        expect($webhookSecret)->toStartWith('whsec_')
            ->and(json_encode(DB::table('webhook_endpoint_secrets')->get()))->not->toContain($webhookSecret);

        foreach (['oidc_client_secret' => 'oidc-conformance-secret', 'notifier_telegram_bot_token' => '123456:conformance-token'] as $key => $plainSecret) {
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

        // Rotate the app's own encrypter through config/app.php: APP_PREVIOUS_KEYS must survive
        // the rotation without setting app.previous_keys by hand. No custom Encrypter in this probe.
        $originalAppConfig = config('app');
        $originalEnvironment = [
            'APP_KEY' => [getenv('APP_KEY'), $_SERVER['APP_KEY'] ?? null, $_ENV['APP_KEY'] ?? null],
            'APP_PREVIOUS_KEYS' => [getenv('APP_PREVIOUS_KEYS'), $_SERVER['APP_PREVIOUS_KEYS'] ?? null, $_ENV['APP_PREVIOUS_KEYS'] ?? null],
        ];
        $oldKey = 'base64:'.base64_encode(random_bytes(32));
        $newKey = 'base64:'.base64_encode(random_bytes(32));

        $setEnvironment = static function (string $name, string $value): void {
            putenv("{$name}={$value}");
            $_SERVER[$name] = $value;
            $_ENV[$name] = $value;
        };

        try {
            $setEnvironment('APP_KEY', $oldKey);
            $setEnvironment('APP_PREVIOUS_KEYS', '');
            config()->set('app', require config_path('app.php'));
            app()->forgetInstance('encrypter');
            $storedBeforeRotation = app('encrypter')->encryptString('rotate-me');

            $setEnvironment('APP_KEY', $newKey);
            $setEnvironment('APP_PREVIOUS_KEYS', $oldKey);
            $rotatedConfig = require config_path('app.php');

            expect($rotatedConfig['previous_keys'])->toContain($oldKey);

            config()->set('app', $rotatedConfig);
            app()->forgetInstance('encrypter');

            expect(app('encrypter')->decryptString($storedBeforeRotation))->toBe('rotate-me');
        } finally {
            foreach ($originalEnvironment as $name => [$putenvValue, $serverValue, $envValue]) {
                if ($putenvValue === false) {
                    putenv($name);
                } else {
                    putenv("{$name}={$putenvValue}");
                }

                if ($serverValue === null) {
                    unset($_SERVER[$name]);
                } else {
                    $_SERVER[$name] = $serverValue;
                }

                if ($envValue === null) {
                    unset($_ENV[$name]);
                } else {
                    $_ENV[$name] = $envValue;
                }
            }

            config()->set('app', $originalAppConfig);
            app()->forgetInstance('encrypter');
        }
    },

    // C71: a role detach reaches the resolver at once (Spatie's events clear its cache).
    'assertRoleDetachClearsResolver' => static function (ConformanceBehaviourProbe $probe) use ($seedPermissions): void {
        $seedPermissions();

        $user = User::factory()->create()->assignRole(RoleEnum::Reader->value);
        $resolver = app(EffectivePermissionResolverService::class);

        expect($resolver->hasPermission($user, PermissionEnum::CanShowProjects->value))->toBeTrue();

        $user->removeRole(RoleEnum::Reader->value);

        expect($resolver->hasPermission($user, PermissionEnum::CanShowProjects->value))->toBeFalse();
        $probe->pass();
    },

    // C71: behind an API key the owner's super-admin grant is gone, in the Gate and in the policies.
    'assertApiActorDeniedSuperAdmin' => static function (ConformanceBehaviourProbe $probe) use ($seedPermissions): void {
        $seedPermissions();

        $owner = User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value);
        $resolver = app(EffectivePermissionResolverService::class);

        AccessContext::enter(new ActorContext(ActorKind::ApiKey, $owner, (string) Str::uuid()));

        try {
            expect(Gate::forUser($owner)->allows(PermissionEnum::CanShowSystem->value))->toBeFalse()
                ->and(Gate::forUser($owner)->allows('viewAny', Project::class))->toBeFalse()
                ->and($resolver->isSuperAdmin($owner))->toBeFalse();
        } finally {
            AccessContext::clear();
        }

        expect($resolver->isSuperAdmin($owner))->toBeTrue();
        $probe->pass();
    },

    // C73: an expired membership grants nothing, super-admin through the group included.
    'assertExpiredMembershipGrantsNothing' => static function (ConformanceBehaviourProbe $probe) use ($seedPermissions): void {
        $seedPermissions();

        $member = User::factory()->create();
        $group = UserGroup::query()->create(['name' => 'conformance-owners']);
        $group->roles()->sync([SpatieRole::query()->where('name', RoleEnum::SuperAdmin->value)->sole()->id]);
        $group->users()->sync([$member->id => ['expires_at' => now()->subMinute()]]);
        app(EffectivePermissionResolver::class)->flush();

        $resolver = app(EffectivePermissionResolverService::class);

        expect($resolver->isSuperAdmin($member))->toBeFalse()
            ->and($resolver->hasPermission($member, PermissionEnum::CanShowProjects->value))->toBeFalse();
        $probe->pass();
    },

    // C74: group, membership and role activity carries public ids and names, never row ids.
    'assertAccessActivityUuidOnly' => static function (ConformanceBehaviourProbe $probe) use ($seedPermissions): void {
        $seedPermissions();

        User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value);
        $member = User::factory()->create();
        $group = UserGroup::query()->create(['name' => 'conformance-activity']);
        app(SaveUserGroupAction::class)->execute($group, 'conformance-activity', null, [RoleEnum::Reader->value], [$member->id]);
        app(SyncUserRolesAction::class)->execute($member, [RoleEnum::Reader->value]);

        $properties = Activity::query()
            ->whereIn('event', ['members_synced', 'roles_synced'])
            ->pluck('properties')
            ->map(fn (mixed $value): array => is_array($value) ? $value : (array) json_decode((string) json_encode($value), true));

        expect($properties)->not->toBeEmpty();

        foreach ($properties as $propertySet) {
            array_walk_recursive($propertySet, fn (mixed $value) => expect(is_int($value))->toBeFalse());
        }

        expect($properties->pluck('user_public_ids')->filter()->flatten()->all())->toBe([$member->public_id]);
        $probe->pass();
    },
];
