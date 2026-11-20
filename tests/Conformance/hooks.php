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
 * it or declare a divergence in `qiick-standards.json`.
 *
 * @return array<string, callable(): void>
 */

use App\Actions\RotateProjectTokenAction;
use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Models\ApiKey;
use App\Models\Project;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\EffectivePermissionResolverService;
use App\Services\SettingsRepositoryService;
use App\Services\WebhookUrlGuardService;
use Database\Seeders\CreateSuperAdminSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\Schema\Builder;
use Illuminate\Encryption\Encrypter as ConcreteEncrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Fortify;
use Spatie\Permission\Models\Role as SpatieRole;

/** Seeds the built-in roles the behaviour hooks assert against. */
$seedPermissions = static function (): void {
    Artisan::call('db:seed', ['--class' => PermissionSeeder::class, '--force' => true]);
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
            $settings->save(['password_policy' => 'strong']);
            config(['super_admin.password' => 'password']);

            Artisan::call('db:seed', ['--class' => CreateSuperAdminSeeder::class, '--force' => true]);

            expect(User::query()->exists())->toBeFalse();

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

    'assertPaginationShape' => static function () use ($seedPermissions): void {
        $seedPermissions();

        $admin = User::factory()->create()->assignRole(RoleEnum::Admin->value);
        $payload = test()->actingAs($admin)->getJson(route('users.index'))->json();

        expect(array_keys($payload))->toBe(['data', 'links', 'meta']);
    },

    'assertSsrfGuard' => static function (): void {
        $guard = new WebhookUrlGuardService(static fn (): array => ['93.184.216.34']);

        expect($guard->validate('https://169.254.169.254/latest/meta-data/'))->toBe('Host is not allowed.')
            ->and($guard->validate('https://2852039166/latest/meta-data/'))->toBe('Host is not allowed.')
            ->and($guard->authorizeOutbound('https://hooks.example.com/hook'))->toBe([
                'ok' => true,
                'curl_connect_to' => ['::93.184.216.34:443'],
            ]);

        $jobSource = (string) file_get_contents(base_path('app/Jobs/DeliverAlertJob.php'));

        expect($jobSource)->toContain('CURLOPT_CONNECT_TO');
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

    'assertEncryptedCasts' => static function (): void {
        expect(config('app.previous_keys'))->toBeArray();

        $user = User::factory()->create();
        app(EnableTwoFactorAuthentication::class)($user);

        $secret = (string) DB::table('users')->where('id', $user->id)->value('two_factor_secret');
        $codes = (string) DB::table('users')->where('id', $user->id)->value('two_factor_recovery_codes');

        expect($secret)->not->toBeEmpty()
            ->and($secret)->not->toContain(Fortify::currentEncrypter()->decrypt($secret))
            ->and(Fortify::currentEncrypter()->decrypt($codes))->toContain('"');

        $settings = app(SettingsRepositoryService::class);
        $settings->save(['webhook_secret' => 'whsec_conformance-secret']);

        $stored = (string) DB::table('settings')
            ->where('scope', 'instance')
            ->where('key', 'webhook_secret')
            ->value('value');
        $storedSecret = json_decode($stored, true, 512, JSON_THROW_ON_ERROR);

        expect($storedSecret)->not->toBe('whsec_conformance-secret')
            ->and(Crypt::decryptString((string) $storedSecret))->toBe('whsec_conformance-secret');

        $apiKey = ApiKey::factory()->create(['owner_id' => $user->id]);

        expect($apiKey->key_hash)->toMatch('/^[a-f0-9]{64}$/');

        $previousKey = random_bytes(32);
        $currentKey = random_bytes(32);
        $ciphertext = (new ConcreteEncrypter($previousKey, 'aes-256-cbc'))->encryptString('rotate-me');

        /** @var Encrypter $rotated */
        $rotated = new ConcreteEncrypter($currentKey, 'aes-256-cbc');
        $rotated->previousKeys([$previousKey]);

        expect($rotated->decryptString($ciphertext))->toBe('rotate-me');
    },
];
