<?php

declare(strict_types=1);

use App\Actions\DeleteUserAction;
use App\Enums\RoleEnum;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsRepositoryService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Passkeys\Passkeys;
use Qiick\Oidc\Models\UserIdentity;
use Qiick\Oidc\Testing\FakeIdentityProvider;
use Tests\TestCase;

/*
 * SSO through qiick/laravel-oidc (#356): Excubia's settings, linking, provisioning, second
 * factor, back-channel logout and SSO-only mode. The IdP is the package's in-memory fake: no
 * network.
 */

const FAKE_ISSUER = 'https://idp.example.com/realms/test';

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
});

/**
 * Saves Settings → Application SSO values pointing at the fake IdP.
 *
 * @param  array<string, mixed>  $overrides
 */
function enableOidc(array $overrides = []): void
{
    app(SettingsRepositoryService::class)->save([
        'oidc_enabled' => true,
        'oidc_issuer' => FAKE_ISSUER,
        'oidc_client_id' => 'laravel-app',
        'oidc_client_secret' => 'fake-client-secret',
        'oidc_button_label' => 'Company SSO',
        'oidc_jit_provisioning' => false,
        'oidc_auto_link' => false,
        'oidc_only' => false,
        ...$overrides,
    ]);
}

/**
 * Plays a whole SSO login: the button, the IdP's login with these id_token claims, the callback.
 *
 * @param  array<string, mixed>  $claims
 */
function signInThroughSso(TestCase $testCase, FakeIdentityProvider $identityProvider, array $claims = []): TestResponse
{
    $authorizationUrl = (string) $testCase->get(route('oidc.redirect'))->assertRedirect()->headers->get('Location');

    return $testCase->get($identityProvider->authorize($authorizationUrl, $claims));
}

function linkIdentity(User $user, string $subject, string $issuer = FAKE_ISSUER): UserIdentity
{
    return UserIdentity::query()->create(['user_id' => $user->id, 'issuer' => $issuer, 'subject' => $subject]);
}

it('answers 404 on the redirect, callback and back-channel logout routes while SSO is off', function (Closure $arrange) {
    // The package's OIDC_* config points at a working IdP: only the settings may decide.
    $identityProvider = FakeIdentityProvider::fake();
    $arrange();

    expect(Config::boolean('oidc.routes.enabled'))->toBeTrue();

    $this->get('/auth/oidc/redirect')->assertNotFound();
    $this->get('/auth/oidc/callback?state=any&code=any')->assertNotFound();
    $this->post('/auth/oidc/backchannel-logout', [
        'logout_token' => $identityProvider->sign($identityProvider->logoutTokenClaims(), ['typ' => 'logout+jwt']),
    ])->assertNotFound();
    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page
        ->where('canUseOidc', false)
        ->where('oidcRedirectUrl', null));
})->with([
    'never configured' => [fn () => null],
    'configured but switched off' => [fn () => enableOidc(['oidc_enabled' => false])],
    'switched on without a client id' => [fn () => enableOidc(['oidc_client_id' => null])],
    'client secret no longer decrypts' => [function () {
        enableOidc();
        Setting::query()->where('key', 'oidc_client_secret')->update(['value' => json_encode('not-encrypted')]);
    }],
]);

it('shows the SSO button only when SSO is enabled and fully configured', function () {
    enableOidc();

    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page
        ->where('canUseOidc', true)
        ->where('oidcButtonLabel', 'Company SSO')
        ->where('oidcRedirectUrl', route('oidc.redirect'))
        ->where('isPasswordLoginVisible', true));
});

it('stores the client secret encrypted and never shares it', function () {
    enableOidc();

    expect(Setting::query()->where('key', 'oidc_client_secret')->value('value'))->not->toBe('fake-client-secret')
        ->and(app(SettingsRepositoryService::class)->oidcClientSecret())->toBe('fake-client-secret');

    $this->actingAs(User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value))
        ->get(route('project.security.edit'))
        ->assertDontSee('fake-client-secret')
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/project/security')
            ->missing('project.oidc_client_secret')
            ->where('project.has_oidc_client_secret', true)
            ->where('oidcCallbackUrl', url('/auth/oidc/callback'))
            ->where('oidcBackchannelLogoutUrl', url('/auth/oidc/backchannel-logout')));
});

it('sends the browser to the IdP with PKCE S256 and the settings client id', function () {
    Http::preventStrayRequests();
    FakeIdentityProvider::fake();
    enableOidc();

    $location = (string) $this->get(route('oidc.redirect'))->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith(FAKE_ISSUER.'/protocol/openid-connect/auth?')
        ->and($query['client_id'] ?? null)->toBe('laravel-app')
        ->and($query['redirect_uri'] ?? null)->toBe(url('/auth/oidc/callback'))
        ->and($query['scope'] ?? null)->toBe('openid profile email')
        ->and($query['code_challenge_method'] ?? null)->toBe('S256');
});

it('signs in the user linked to the identity, whatever the email', function () {
    $identityProvider = FakeIdentityProvider::fake();
    enableOidc();
    $user = User::factory()->create(['email' => 'other@example.com']);
    linkIdentity($user, 'sub-abc');

    signInThroughSso($this, $identityProvider, ['sub' => 'sub-abc'])->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($user);
    expect(UserIdentity::query()->sole()->last_login_at)->not->toBeNull();
});

it('links an existing user by verified email only with auto-link on', function () {
    $identityProvider = FakeIdentityProvider::fake();
    $user = User::factory()->create(['email' => 'sso.user@example.com']);

    enableOidc();
    signInThroughSso($this, $identityProvider, ['sub' => 'sub-abc'])
        ->assertRedirect('/login')
        ->assertSessionHasErrors(['email' => 'No local account matches this SSO login. Ask an admin to create your user first.']);
    $this->assertGuest();

    // With JIT on, an existing account is still never linked or duplicated without auto-link.
    enableOidc(['oidc_jit_provisioning' => true]);
    signInThroughSso($this, $identityProvider, ['sub' => 'sub-abc'])
        ->assertRedirect('/login')
        ->assertSessionHasErrors(['email' => 'An account with this email exists but is not linked to SSO. Ask an admin to link it.']);
    $this->assertGuest();
    expect(User::query()->count())->toBe(1);

    enableOidc(['oidc_auto_link' => true]);
    signInThroughSso($this, $identityProvider, ['sub' => 'sub-abc'])->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($user);
    expect(UserIdentity::query()->sole()->only(['user_id', 'issuer', 'subject']))
        ->toBe(['user_id' => $user->id, 'issuer' => FAKE_ISSUER, 'subject' => 'sub-abc']);
});

it('refuses the login', function (Closure $arrange, array $claims) {
    $identityProvider = FakeIdentityProvider::fake();
    enableOidc(['oidc_auto_link' => true]);
    $arrange();
    $userCount = User::query()->count();
    $identityCount = UserIdentity::query()->count();

    signInThroughSso($this, $identityProvider, ['sub' => 'sub-abc', ...$claims])
        ->assertRedirect('/login')
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(User::query()->count())->toBe($userCount)
        ->and(UserIdentity::query()->count())->toBe($identityCount);
})->with([
    'unverified email' => [fn () => User::factory()->create(['email' => 'sso.user@example.com']), ['email_verified' => false]],
    'email linked to another identity' => [fn () => linkIdentity(User::factory()->create(['email' => 'sso.user@example.com']), 'sub-other'), []],
    'inactive user by email' => [fn () => User::factory()->inactive()->create(['email' => 'sso.user@example.com']), []],
    'inactive user by identity' => [fn () => linkIdentity(User::factory()->inactive()->create(), 'sub-abc'), []],
    'unknown user with JIT off' => [fn () => null, []],
]);

it('creates an active reader without a password for an unknown user when JIT is on', function () {
    $identityProvider = FakeIdentityProvider::fake();
    enableOidc(['oidc_jit_provisioning' => true]);

    signInThroughSso($this, $identityProvider, [
        'email' => 'New.Person@Example.com',
        'given_name' => 'Maria Luisa',
        'family_name' => 'Rossi',
    ])->assertRedirect('/dashboard');

    $user = User::query()->where('email', 'new.person@example.com')->sole();

    $this->assertAuthenticatedAs($user);
    expect($user->getRoleNames()->all())->toBe([RoleEnum::Reader->value])
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->password)->toBeNull()
        ->and($user->is_active)->toBeTrue()
        ->and($user->first_name)->toBe('Maria Luisa')
        ->and($user->last_name)->toBe('Rossi')
        ->and($user->identities()->sole()->subject)->toBe('fake-subject-1');
});

it('re-provisions a deleted account by email when JIT is on', function () {
    $identityProvider = FakeIdentityProvider::fake();
    enableOidc(['oidc_jit_provisioning' => true]);
    $deleted = User::factory()->create(['email' => 'sso.user@example.com']);
    app(DeleteUserAction::class)->execute($deleted);

    signInThroughSso($this, $identityProvider)->assertRedirect('/dashboard');

    $user = User::query()->where('email', 'sso.user@example.com')->sole();

    $this->assertAuthenticatedAs($user);
    expect($user->id)->not->toBe($deleted->id)
        ->and($user->trashed())->toBeFalse();
});

it('re-provisions a deleted SSO user and links the same subject to the new account', function () {
    $identityProvider = FakeIdentityProvider::fake();
    enableOidc(['oidc_jit_provisioning' => true]);
    $deleted = User::factory()->create(['email' => 'sso.user@example.com']);
    linkIdentity($deleted, 'fake-subject-1');
    app(DeleteUserAction::class)->execute($deleted);

    expect(UserIdentity::query()->count())->toBe(0);

    signInThroughSso($this, $identityProvider)->assertRedirect('/dashboard');

    $user = User::query()->where('email', 'sso.user@example.com')->sole();

    $this->assertAuthenticatedAs($user);
    expect($user->id)->not->toBe($deleted->id)
        ->and($user->identities()->sole()->subject)->toBe('fake-subject-1')
        ->and($user->is_active)->toBeTrue();
});

it('auto-links a re-created account to the SSO subject after the old account was deleted', function () {
    $identityProvider = FakeIdentityProvider::fake();
    enableOidc(['oidc_auto_link' => true]);
    $deleted = User::factory()->create(['email' => 'sso.user@example.com']);
    linkIdentity($deleted, 'fake-subject-1');
    app(DeleteUserAction::class)->execute($deleted);

    $recreated = User::factory()->create(['email' => 'sso.user@example.com']);

    signInThroughSso($this, $identityProvider)->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($recreated);
    expect(UserIdentity::query()->sole()->only(['user_id', 'subject']))
        ->toBe(['user_id' => $recreated->id, 'subject' => 'fake-subject-1']);
});

it('takes the last word of the name claim as the last name', function () {
    $identityProvider = FakeIdentityProvider::fake();
    enableOidc(['oidc_jit_provisioning' => true]);

    signInThroughSso($this, $identityProvider, ['given_name' => null, 'family_name' => null, 'name' => 'Ada King Lovelace'])
        ->assertRedirect('/dashboard');

    expect(User::query()->where('email', 'sso.user@example.com')->sole()->only(['first_name', 'last_name']))
        ->toBe(['first_name' => 'Ada King', 'last_name' => 'Lovelace']);
});

it('runs the TOTP challenge unless the id_token proves MFA at the IdP', function (bool $isRequiredBySettings, array $claims, bool $isChallenged) {
    $identityProvider = FakeIdentityProvider::fake();
    enableOidc(['two_factor_required' => $isRequiredBySettings]);
    $user = User::factory()->withTwoFactor()->create();
    linkIdentity($user, 'sub-abc');

    $response = signInThroughSso($this, $identityProvider, ['sub' => 'sub-abc', ...$claims]);

    if ($isChallenged) {
        $response->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
        expect(session('login.id'))->toBe($user->id);
    } else {
        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }
})->with([
    'required by settings, no amr' => [true, [], true],
    'turned on by the user, no amr' => [false, [], true],
    'required by settings, amr mfa' => [true, ['amr' => ['pwd', 'mfa']], false],
    'required by settings, amr pwd only' => [true, ['amr' => ['pwd']], true],
]);

it('signs in a user without a second factor even when 2FA is required, who then has to set one up', function () {
    $identityProvider = FakeIdentityProvider::fake();
    enableOidc(['two_factor_required' => true]);
    $user = User::factory()->create();
    linkIdentity($user, 'sub-abc');

    signInThroughSso($this, $identityProvider, ['sub' => 'sub-abc'])->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($user);
    $this->get(route('dashboard'))->assertRedirect(route('security.edit'));
});

it('ends the Excubia session when the IdP logs the user out through the back channel', function () {
    $identityProvider = FakeIdentityProvider::fake();
    enableOidc();
    $user = User::factory()->create();
    linkIdentity($user, 'sub-abc');
    signInThroughSso($this, $identityProvider, ['sub' => 'sub-abc', 'sid' => 'idp-session-1'])->assertRedirect('/dashboard');
    $this->get(route('dashboard'))->assertOk();

    $this->post('/auth/oidc/backchannel-logout', [
        'logout_token' => $identityProvider->sign($identityProvider->logoutTokenClaims(['sub' => 'sub-abc', 'sid' => 'idp-session-1']), ['typ' => 'logout+jwt']),
    ])->assertOk();

    // First request after back-channel logout must already be a guest: the package places
    // EnsureOidcSessionIsActiveMiddleware before auth (beta.2+), so no app workaround.
    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
});

it('keeps the session when the IdP logs out another of its sessions', function () {
    $identityProvider = FakeIdentityProvider::fake();
    enableOidc();
    linkIdentity(User::factory()->create(), 'sub-abc');
    signInThroughSso($this, $identityProvider, ['sub' => 'sub-abc', 'sid' => 'idp-session-1'])->assertRedirect('/dashboard');

    $this->post('/auth/oidc/backchannel-logout', [
        'logout_token' => $identityProvider->sign($identityProvider->logoutTokenClaims(['sub' => 'sub-abc', 'sid' => 'idp-session-2']), ['typ' => 'logout+jwt']),
    ])->assertOk();

    $this->get(route('dashboard'))->assertOk();
});

it('refuses a forged back-channel logout', function () {
    $identityProvider = FakeIdentityProvider::fake();
    enableOidc();
    $forger = new FakeIdentityProvider(FAKE_ISSUER);

    $this->post('/auth/oidc/backchannel-logout', [
        'logout_token' => $forger->sign($forger->logoutTokenClaims(), ['typ' => 'logout+jwt']),
    ])->assertBadRequest();
});

describe('SSO-only mode', function () {
    beforeEach(function () {
        FakeIdentityProvider::fake();
        enableOidc(['oidc_only' => true]);
    });

    it('shows only the SSO button, and the local sign-in at ?local=1', function () {
        $this->get(route('login'))->assertInertia(fn (Assert $page) => $page
            ->where('canUseOidc', true)
            ->where('isPasswordLoginVisible', false));

        $this->get(route('login', ['local' => 1]))->assertInertia(fn (Assert $page) => $page
            ->where('isPasswordLoginVisible', true));
    });

    it('refuses a correct password of everyone but a super-admin with a second factor', function (Closure $makeUser) {
        $user = $makeUser();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors(['email' => 'Password sign-in is disabled. Use single sign-on.']);

        $this->assertGuest();
        expect(session('login.id'))->toBeNull();
    })->with([
        'admin with TOTP' => [fn () => User::factory()->withTwoFactor()->create()->assignRole(RoleEnum::Admin->value)],
        'super-admin without a second factor' => [fn () => User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value)],
    ]);

    it('lets a super-admin with TOTP through to the two-factor challenge', function () {
        $user = User::factory()->withTwoFactor()->create()->assignRole(RoleEnum::SuperAdmin->value);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('two-factor.login'));

        expect(session('login.id'))->toBe($user->id);
    });

    it('allows passkey logins of break-glass accounts only', function () {
        $passkeyFor = fn (User $user) => $user->passkeys()->create([
            'name' => 'Laptop',
            'credential_id' => 'credential-'.$user->id,
            'credential' => [],
        ]);

        $superAdmin = User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value);
        $admin = User::factory()->create()->assignRole(RoleEnum::Admin->value);

        expect(Passkeys::allowsLogin(new Request, $passkeyFor($superAdmin)))->toBeTrue()
            ->and(Passkeys::allowsLogin(new Request, $passkeyFor($admin)))->toBeFalse();
    });

    it('refuses a passkey whose user was deleted instead of throwing a TypeError', function () {
        $user = User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value);
        $passkey = $user->passkeys()->create([
            'name' => 'Ada private laptop',
            'credential_id' => 'credential-orphan-'.$user->id,
            'credential' => [],
        ]);

        // A soft delete leaves the login link behind; the closure must refuse it, not throw.
        $user->delete();

        expect($passkey->fresh()->user)->toBeNull()
            ->and(Passkeys::allowsLogin(new Request, $passkey->fresh()))->toBeFalse();
    });

    it('never locks anyone out while SSO itself is off', function () {
        enableOidc(['oidc_only' => true, 'oidc_enabled' => false]);
        $user = User::factory()->create()->assignRole(RoleEnum::Reader->value);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($user);
    });
});

it('validates the SSO settings', function () {
    $this->actingAs(User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value))
        ->put(route('project.security.update'), [
            'password_policy' => 'weak',
            'login_max_attempts' => 5,
            'two_factor_required' => false,
            'oidc_enabled' => true,
            'oidc_issuer' => 'not a url',
            'oidc_client_id' => '',
            'oidc_client_secret' => '',
            'oidc_jit_provisioning' => 'maybe',
            'oidc_auto_link' => 'maybe',
            'oidc_only' => 'maybe',
        ])
        ->assertSessionHasErrors(['oidc_issuer', 'oidc_client_id', 'oidc_client_secret', 'oidc_jit_provisioning', 'oidc_auto_link', 'oidc_only']);
});

it('shows SSO-linked users in the user list', function () {
    linkIdentity(User::factory()->create(['first_name' => 'A', 'last_name' => 'linked']), 'sub-abc');

    $this->actingAs(User::factory()->create(['first_name' => 'B', 'last_name' => 'local'])->assignRole(RoleEnum::Admin->value))
        ->get(route('users.index', ['sort' => 'name', 'direction' => 'asc']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('users.data.0.is_sso_linked', true)
            ->where('users.data.1.is_sso_linked', false));
});

it('throttles the redirect and callback routes', function (string $path) {
    foreach (range(1, 10) as $attempt) {
        $this->get($path)->assertNotFound();
    }

    $this->get($path)->assertTooManyRequests();
})->with(['/auth/oidc/redirect', '/auth/oidc/callback']);

it('prunes expired SSO logout revocations daily on one server', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains((string) $event->command, 'oidc:prune-logout-revocations'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 0 * * *')
        ->and($event->onOneServer)->toBeTrue();
});
