<?php

declare(strict_types=1);

use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsRepositoryService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Qiick\Oidc\Models\UserIdentity;
use Qiick\Oidc\Testing\FakeIdentityProvider;

/*
 * The upgrade of SSO users onto qiick/laravel-oidc (#356): `users.oidc_sub` into `user_identities`
 * through the 2026_11_18 migration, which runs `oidc:import-legacy-subjects`. The rehearsal for
 * externa-core, so the legacy data is deliberately ugly. Runs on every CI database.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->issuer = 'https://idp.example.com/realms/test';
    $this->otherIssuer = 'https://other-idp.example.com';
});

function runLegacySubjectMigration(): void
{
    (require database_path('migrations/2026_11_18_000000_import_legacy_oidc_subjects.php'))->up();
}

/**
 * @return list<array{user_id: int, issuer: string, subject: string}>
 */
function identityRows(): array
{
    return UserIdentity::query()->orderBy('id')->get(['id', 'user_id', 'issuer', 'subject'])
        ->map(fn (UserIdentity $identity): array => [
            'id' => (int) $identity->id,
            'user_id' => (int) $identity->user_id,
            'issuer' => $identity->issuer,
            'subject' => $identity->subject,
        ])->all();
}

/**
 * A legacy install with SSO configured and every kind of `oidc_sub` the old client could leave.
 *
 * @return array<string, User|null>
 */
function seedLegacySsoInstall(string $issuer, string $otherIssuer): array
{
    app(SettingsRepositoryService::class)->save([
        'oidc_enabled' => true,
        'oidc_issuer' => $issuer,
        'oidc_client_id' => 'laravel-app',
        'oidc_client_secret' => 'fake-client-secret',
    ]);

    $users = [
        'plain' => User::factory()->create(['oidc_sub' => 'sub-plain']),
        'alreadyLinked' => User::factory()->create(['oidc_sub' => 'sub-already']),
        'conflicting' => User::factory()->create(['oidc_sub' => 'sub-taken']),
        'owner' => User::factory()->create(),
        'otherIssuerSub' => User::factory()->create(['oidc_sub' => 'sub-shared']),
        'otherIssuerOwner' => User::factory()->create(),
        'nullSub' => User::factory()->create(['oidc_sub' => null]),
        'emptySub' => User::factory()->create(['oidc_sub' => '']),
        'neverSso' => User::factory()->create(),
        'upperCase' => User::factory()->create(['oidc_sub' => 'Sub-Case']),
        'lowerCase' => null,
    ];

    // OIDC subjects are case sensitive. MySQL and MariaDB (utf8mb4_unicode_ci) refuse a second
    // `oidc_sub` that differs only by case, so on those engines this legacy row cannot exist.
    try {
        $users['lowerCase'] = User::factory()->create(['oidc_sub' => 'sub-case']);
    } catch (UniqueConstraintViolationException) {
        expect(DB::connection()->getDriverName())->toBeIn(['mysql', 'mariadb']);
    }

    UserIdentity::query()->create(['user_id' => $users['alreadyLinked']->id, 'issuer' => $issuer, 'subject' => 'sub-already']);
    UserIdentity::query()->create(['user_id' => $users['owner']->id, 'issuer' => $issuer, 'subject' => 'sub-taken']);
    UserIdentity::query()->create(['user_id' => $users['otherIssuerOwner']->id, 'issuer' => $otherIssuer, 'subject' => 'sub-shared']);

    return $users;
}

it('reports what it would import without writing', function () {
    $users = seedLegacySsoInstall($this->issuer, $this->otherIssuer);
    $before = identityRows();

    $exitCode = Artisan::call('oidc:import-legacy-subjects', ['--issuer' => $this->issuer, '--dry-run' => true]);
    $expectedImports = $users['lowerCase'] === null ? 3 : 4;

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())
        ->toContain("User {$users['conflicting']->id}: this sub is already linked to user {$users['owner']->id}; skipped.")
        ->toContain("Would import {$expectedImports} identities for issuer {$this->issuer}; 1 already linked, 1 conflicts.")
        ->and(identityRows())->toBe($before);
});

it('imports every legacy SSO user once, relinks nothing and changes nothing on a second run', function () {
    $users = seedLegacySsoInstall($this->issuer, $this->otherIssuer);
    $before = identityRows();

    runLegacySubjectMigration();
    $afterFirstRun = identityRows();

    // Rows that existed before are untouched: nothing was moved to another user.
    expect(array_slice($afterFirstRun, 0, count($before)))->toBe($before);

    $imported = array_map(fn (array $row): array => [$row['user_id'], $row['issuer'], $row['subject']], array_slice($afterFirstRun, count($before)));
    $expected = array_values(array_filter([
        [$users['plain']->id, $this->issuer, 'sub-plain'],
        [$users['otherIssuerSub']->id, $this->issuer, 'sub-shared'],
        [$users['upperCase']->id, $this->issuer, 'Sub-Case'],
        $users['lowerCase'] === null ? null : [$users['lowerCase']->id, $this->issuer, 'sub-case'],
    ]));

    expect($imported)->toEqualCanonicalizing($expected)
        ->and(UserIdentity::query()->whereIn('user_id', [$users['nullSub']->id, $users['emptySub']->id, $users['neverSso']->id, $users['conflicting']->id])->exists())->toBeFalse()
        ->and(app(SettingsRepositoryService::class)->isOidcAutoLinkEnabled())->toBeTrue();

    runLegacySubjectMigration();
    expect(identityRows())->toBe($afterFirstRun);

    expect(Artisan::call('oidc:import-legacy-subjects', ['--issuer' => $this->issuer]))->toBe(1)
        ->and(Artisan::output())->toContain('Imported 0 identities')
        ->and(identityRows())->toBe($afterFirstRun);
});

it('signs every imported SSO user in to the same account through the package', function () {
    $users = seedLegacySsoInstall($this->issuer, $this->otherIssuer);
    runLegacySubjectMigration();
    // No email matches: only the imported identity can resolve the account.
    app(SettingsRepositoryService::class)->save(['oidc_auto_link' => false]);
    $identityProvider = FakeIdentityProvider::fake(issuer: $this->issuer);
    // Six logins in a row exceed the SSO routes' 10 requests a minute.
    $this->withoutMiddleware(ThrottleRequests::class);

    $expectedAccounts = array_filter([
        'sub-plain' => $users['plain'],
        'sub-already' => $users['alreadyLinked'],
        'sub-shared' => $users['otherIssuerSub'],
        'Sub-Case' => $users['upperCase'],
        'sub-case' => $users['lowerCase'],
        // The conflict was not moved: the sub keeps signing in its existing owner.
        'sub-taken' => $users['owner'],
    ]);

    foreach ($expectedAccounts as $subject => $user) {
        $authorizationUrl = (string) $this->get(route('oidc.redirect'))->headers->get('Location');
        $this->get($identityProvider->authorize($authorizationUrl, ['sub' => (string) $subject, 'email' => 'nobody@example.com']))
            ->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $this->post(route('logout'));
        $this->assertGuest();
    }
});

it('imports nothing and keeps the auto-link default on an install that never configured SSO', function () {
    User::factory()->create(['oidc_sub' => 'sub-orphan']);

    runLegacySubjectMigration();

    expect(UserIdentity::query()->exists())->toBeFalse()
        ->and(Setting::query()->where('key', 'oidc_auto_link')->exists())->toBeFalse();
});

it('keeps an auto-link choice that is already saved', function () {
    app(SettingsRepositoryService::class)->save(['oidc_issuer' => $this->issuer, 'oidc_auto_link' => false]);

    runLegacySubjectMigration();

    expect(app(SettingsRepositoryService::class)->isOidcAutoLinkEnabled())->toBeFalse();
});
