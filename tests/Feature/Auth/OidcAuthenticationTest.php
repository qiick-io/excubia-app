<?php

declare(strict_types=1);

use App\Auth\Oidc\GenericOidcProvider;
use App\Enums\RoleEnum;
use App\Models\Setting;
use App\Models\User;
use App\Services\OidcSocialiteFactoryService;
use App\Services\SettingsRepositoryService;
use Database\Seeders\PermissionSeeder;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
});

function enableOidc(array $overrides = []): void
{
    app(SettingsRepositoryService::class)->save([
        'oidc_enabled' => true,
        'oidc_issuer' => 'https://idp.example.com/realms/excubia',
        'oidc_client_id' => 'excubia-app',
        'oidc_client_secret' => 'super-secret',
        'oidc_button_label' => 'Company SSO',
        'oidc_jit_provisioning' => false,
        ...$overrides,
    ]);
}

function fakeOidcIdentity(string $sub = 'sub-abc', string $email = 'sso@example.com', mixed $emailVerified = true): SocialiteUser
{
    $raw = ['sub' => $sub, 'email' => $email, 'name' => 'Sso User'];

    if ($emailVerified !== null) {
        $raw['email_verified'] = $emailVerified;
    }

    return (new SocialiteUser)->setRaw($raw)->map(['id' => $sub, 'name' => 'Sso User', 'email' => $email]);
}

/**
 * Replaces the IdP round trip: the factory returns a provider that yields the given identity.
 */
function fakeOidcProvider(SocialiteUser $identity): void
{
    $provider = Mockery::mock(AbstractProvider::class);
    $provider->shouldReceive('user')->andReturn($identity);

    $factory = Mockery::mock(OidcSocialiteFactoryService::class, [app(SettingsRepositoryService::class)])->makePartial();
    $factory->shouldReceive('driver')->andReturn($provider);

    app()->instance(OidcSocialiteFactoryService::class, $factory);
}

it('is disabled by default', function () {
    Http::preventStrayRequests();

    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('canUseOidc', false));
    $this->get(route('auth.oidc.redirect'))->assertNotFound();
    $this->get(route('auth.oidc.callback'))->assertNotFound();
});

it('shows the button only when SSO is enabled and fully configured', function () {
    enableOidc(['oidc_client_id' => null]);

    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('canUseOidc', false));

    enableOidc();

    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page
        ->where('canUseOidc', true)
        ->where('oidcButtonLabel', 'Company SSO'));
});

it('stores the client secret encrypted and never shares it', function () {
    enableOidc();

    expect(Setting::query()->where('key', 'oidc_client_secret')->value('value'))->not->toBe('super-secret')
        ->and(app(SettingsRepositoryService::class)->oidcClientSecret())->toBe('super-secret');

    $this->actingAs(User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value))
        ->get(route('project.security.edit'))
        ->assertDontSee('super-secret')
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/project/security')
            ->missing('project.oidc_client_secret')
            ->where('project.has_oidc_client_secret', true));
});

it('redirects to the authorize endpoint from discovery, without real network', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://idp.example.com/realms/excubia/.well-known/openid-configuration' => Http::response([
            'authorization_endpoint' => 'https://idp.example.com/realms/excubia/auth',
            'token_endpoint' => 'https://idp.example.com/realms/excubia/token',
            'userinfo_endpoint' => 'https://idp.example.com/realms/excubia/userinfo',
        ]),
    ]);
    enableOidc();

    $location = $this->get(route('auth.oidc.redirect'))->assertRedirect()->headers->get('Location');

    expect($location)->toStartWith('https://idp.example.com/realms/excubia/auth?')
        ->toContain('client_id=excubia-app')
        ->toContain('scope=openid+profile+email');
});

it('logs in a user who matches the sub', function () {
    enableOidc();
    $user = User::factory()->create(['email' => 'other@example.com', 'oidc_sub' => 'sub-abc']);
    fakeOidcProvider(fakeOidcIdentity());

    $this->get(route('auth.oidc.callback'))->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
});

it('links a user by verified email', function () {
    enableOidc();
    $user = User::factory()->create(['email' => 'sso@example.com']);
    fakeOidcProvider(fakeOidcIdentity());

    $this->get(route('auth.oidc.callback'))->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->oidc_sub)->toBe('sub-abc');
});

it('refuses the login', function (Closure $arrange, SocialiteUser $identity) {
    enableOidc();
    $arrange();
    $userCount = User::query()->count();
    fakeOidcProvider($identity);

    $this->get(route('auth.oidc.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(User::query()->count())->toBe($userCount)
        ->and(User::query()->where('oidc_sub', 'sub-abc')->where('is_active', true)->exists())->toBeFalse();
})->with([
    'unverified email' => [fn () => User::factory()->create(['email' => 'sso@example.com']), fakeOidcIdentity(emailVerified: false)],
    'missing email_verified flag' => [fn () => User::factory()->create(['email' => 'sso@example.com']), fakeOidcIdentity(emailVerified: null)],
    'missing sub' => [fn () => User::factory()->create(['email' => 'sso@example.com']), fakeOidcIdentity(sub: '')],
    'different sub' => [fn () => User::factory()->create(['email' => 'sso@example.com', 'oidc_sub' => 'sub-other']), fakeOidcIdentity()],
    'inactive user by email' => [fn () => User::factory()->inactive()->create(['email' => 'sso@example.com']), fakeOidcIdentity()],
    'inactive user by sub' => [fn () => User::factory()->inactive()->create(['oidc_sub' => 'sub-abc']), fakeOidcIdentity()],
    'unknown user with JIT off' => [fn () => null, fakeOidcIdentity()],
]);

it('creates a reader for an unknown user when JIT is on', function () {
    enableOidc(['oidc_jit_provisioning' => true]);
    fakeOidcProvider(fakeOidcIdentity(email: 'New.Person@Example.com'));

    $this->get(route('auth.oidc.callback'))->assertRedirect(route('dashboard', absolute: false));

    $user = User::query()->where('email', 'new.person@example.com')->sole();

    $this->assertAuthenticatedAs($user);
    expect($user->oidc_sub)->toBe('sub-abc')
        ->and($user->getRoleNames()->all())->toBe([RoleEnum::Reader->value])
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->password)->toBeNull()
        ->and($user->first_name)->toBe('Sso')
        ->and($user->last_name)->toBe('User');
});

it('takes the first and last name from the given_name and family_name claims', function () {
    enableOidc(['oidc_jit_provisioning' => true]);
    $identity = fakeOidcIdentity(email: 'new.person@example.com');
    $identity->setRaw([...$identity->getRaw(), 'given_name' => 'Maria Luisa', 'family_name' => 'Rossi']);
    fakeOidcProvider($identity);

    $this->get(route('auth.oidc.callback'))->assertRedirect(route('dashboard', absolute: false));

    $user = User::query()->where('email', 'new.person@example.com')->sole();

    expect($user->first_name)->toBe('Maria Luisa')
        ->and($user->last_name)->toBe('Rossi')
        ->and($user->name)->toBe('Maria Luisa Rossi');
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
            'oidc_jit_provisioning' => false,
        ])
        ->assertSessionHasErrors(['oidc_issuer', 'oidc_client_id', 'oidc_client_secret']);
});

it('shows SSO-linked users in the user list', function () {
    User::factory()->create(['first_name' => 'A', 'last_name' => 'linked', 'oidc_sub' => 'sub-abc']);

    $this->actingAs(User::factory()->create(['first_name' => 'B', 'last_name' => 'local'])->assignRole(RoleEnum::Admin->value))
        ->get(route('users.index', ['sort' => 'name', 'direction' => 'asc']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('users.data.0.is_sso_linked', true)
            ->where('users.data.1.is_sso_linked', false));
});

it('sends a PKCE S256 challenge with the authorization request', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://idp.example.com/realms/excubia/.well-known/openid-configuration' => Http::response([
            'authorization_endpoint' => 'https://idp.example.com/realms/excubia/auth',
            'token_endpoint' => 'https://idp.example.com/realms/excubia/token',
            'userinfo_endpoint' => 'https://idp.example.com/realms/excubia/userinfo',
        ]),
    ]);
    enableOidc();

    $location = (string) $this->get(route('auth.oidc.redirect'))->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    $verifier = (string) session('code_verifier');

    expect($verifier)->toHaveLength(96)
        ->and($query['code_challenge_method'] ?? null)->toBe('S256')
        ->and($query['code_challenge'] ?? null)->toBe(rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='));
});

it('sends the PKCE verifier from the session with the token exchange', function () {
    $session = app('session')->driver();
    $session->put('state', 'the-state');
    $session->put('code_verifier', 'the-verifier');
    $request = Request::create('/auth/oidc/callback', 'GET', ['state' => 'the-state', 'code' => 'the-code']);
    $request->setLaravelSession($session);
    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new GuzzleResponse(200, [], (string) json_encode(['access_token' => 'access-token'])),
        new GuzzleResponse(200, [], (string) json_encode(['sub' => 'sub-abc', 'email' => 'sso@example.com'])),
    ]));
    $stack->push(Middleware::history($history));

    $provider = new GenericOidcProvider($request, 'excubia-app', 'super-secret', 'https://excubia.test/auth/oidc/callback', [],
        'https://idp.example.com/auth', 'https://idp.example.com/token', 'https://idp.example.com/userinfo');
    $provider->setHttpClient(new Client(['handler' => $stack]));

    expect($provider->user()->getId())->toBe('sub-abc');

    parse_str((string) $history[0]['request']->getBody(), $fields);

    expect((string) $history[0]['request']->getUri())->toBe('https://idp.example.com/token')
        ->and($fields['code'] ?? null)->toBe('the-code')
        ->and($fields['code_verifier'] ?? null)->toBe('the-verifier')
        ->and($session->has('code_verifier'))->toBeFalse();
});

it('throttles the redirect and callback routes', function (string $routeName) {
    foreach (range(1, 10) as $attempt) {
        $this->get(route($routeName))->assertNotFound();
    }

    $this->get(route($routeName))->assertTooManyRequests();
})->with(['auth.oidc.redirect', 'auth.oidc.callback']);
