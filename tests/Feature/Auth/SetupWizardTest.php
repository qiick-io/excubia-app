<?php

declare(strict_types=1);

use App\Actions\CreateFirstAdminAction;
use App\Enums\RoleEnum;
use App\Models\Project;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\SetupTokenService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SystemProjectSeeder;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * First-run wizard (#116): admin, first project, push token with the core snippet; locked once done.
 */
function setupAdminInput(array $overrides = []): array
{
    return [
        'setup_token' => $overrides['setup_token'] ?? app(SetupTokenService::class)->issue(),
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'ada@example.com',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
        ...$overrides,
    ];
}

it('links the login page to the wizard while no user exists', function () {
    $this->get(route('login'))
        ->assertInertia(fn (Assert $page) => $page->component('auth/login')->where('needsSetup', true));

    User::factory()->create();

    $this->get(route('login'))
        ->assertInertia(fn (Assert $page) => $page->where('needsSetup', false));
});

it('shows the admin step on a fresh install, the token staying in the link fragment', function () {
    // The page reads the token from `#token=` in the browser: the GET never carries it, so it
    // never lands in the session's previous URL.
    $this->get(route('setup.show'))
        ->assertInertia(fn (Assert $page) => $page->component('auth/setup')->where('step', 'admin')->missing('setupToken')->has('passwordRules'));

    expect((string) session('_previous.url'))->not->toContain('token');
});

it('refuses the admin step without a valid setup token, so nobody can claim a fresh install', function (?string $token) {
    app(SetupTokenService::class)->issue();

    $this->post(route('setup.admin.store'), [...setupAdminInput(), 'setup_token' => $token])->assertForbidden();

    expect(User::query()->exists())->toBeFalse();
})->with([
    'no token' => [null],
    'a wrong token' => ['not-the-issued-token'],
]);

it('never keeps the setup token in the session after a failed submit', function () {
    $token = app(SetupTokenService::class)->issue();

    $this->post(route('setup.admin.store'), setupAdminInput(['setup_token' => $token, 'email' => 'not-an-email']))
        ->assertSessionHasErrors('email');

    expect(json_encode(session()->all()))->not->toContain($token);
});

it('checks the setup token again under the creation lock and uses it once', function () {
    $token = app(SetupTokenService::class)->issue();
    $createFirstAdmin = app(CreateFirstAdminAction::class);

    // A token replaced (or expired) after the request was authorized creates nobody.
    expect($createFirstAdmin->execute('Ada', null, 'ada@example.com', 'correct-horse-battery', 'replaced-token'))->toBeNull()
        ->and(User::query()->exists())->toBeFalse();

    expect($createFirstAdmin->execute('Ada', null, 'ada@example.com', 'correct-horse-battery', $token))->not->toBeNull()
        ->and(app(SetupTokenService::class)->isValid($token))->toBeFalse();
});

it('issues a setup link only under the creation lock, so a new link cannot race the first admin', function () {
    $creation = Cache::lock('excubia:first-run-setup', 10);
    $creation->get();

    try {
        expect(fn () => $this->artisan('excubia:setup-link')->run())->toThrow(LockTimeoutException::class);
    } finally {
        $creation->release();
    }
});

it('prints a setup link with the command, only while no user exists', function () {
    $this->artisan('excubia:setup-link')
        ->expectsOutputToContain(url('/setup').'#token=')
        ->assertSuccessful();

    User::factory()->create();

    $this->artisan('excubia:setup-link')->assertFailed();
});

it('creates the first admin as super-admin, logs them in and moves to the project step', function () {
    $this->post(route('setup.admin.store'), setupAdminInput())
        ->assertRedirect(route('setup.show'));

    $user = User::query()->sole();

    expect($user->email)->toBe('ada@example.com')
        ->and($user->hasRole(RoleEnum::SuperAdmin->value))->toBeTrue()
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->is_active)->toBeTrue();
    $this->assertAuthenticatedAs($user);

    $this->get(route('setup.show'))
        ->assertInertia(fn (Assert $page) => $page->component('auth/setup')->where('step', 'project'));
});

it('validates the admin step', function () {
    $this->post(route('setup.admin.store'), setupAdminInput(['email' => 'not-an-email', 'password_confirmation' => 'other']))
        ->assertSessionHasErrors(['email', 'password']);

    expect(User::query()->exists())->toBeFalse();
});

it('rejects a context word in the first admin password', function () {
    $this->postJson(route('setup.admin.store'), setupAdminInput([
        'password' => 'Excubia2026!',
        'password_confirmation' => 'Excubia2026!',
    ]))->assertUnprocessable()
        ->assertJsonPath('errors.password.0', __('validation.password.context_specific'));

    expect(User::query()->exists())->toBeFalse();
});

it('locks the admin step once a user exists', function () {
    User::factory()->create();

    $this->get(route('setup.show'))->assertRedirect(route('dashboard'));
    $this->post(route('setup.admin.store'), setupAdminInput(['email' => 'mallory@example.com']))->assertForbidden();

    expect(User::query()->where('email', 'mallory@example.com')->exists())->toBeFalse();
});

it('creates the first project and shows its push token once with the install snippet', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(SystemProjectSeeder::class);
    $this->actingAs(User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value));

    $this->post(route('setup.project.store'), ['name' => 'Shop'])
        ->assertRedirect(route('setup.show'));

    $project = Project::query()->where('slug', 'shop')->sole();
    $token = null;

    $this->get(route('setup.show'))
        ->assertInertia(function (Assert $page) use (&$token) {
            $page->component('auth/setup')
                ->where('step', 'done')
                ->where('pushToken.project', 'Shop')
                ->where('pushToken.url', url('/'));
            $token = $page->toArray()['props']['pushToken']['token'];
        });

    expect($project->push_token_hash)->toBe(hash('sha256', (string) $token));

    // Shown once: the next visit is locked, since a project exists now.
    $this->get(route('setup.show'))->assertRedirect(route('dashboard'));
    $this->post(route('setup.project.store'), ['name' => 'Blog'])->assertForbidden();
});

it('keeps the project step from users who cannot create projects and from guests', function () {
    $this->seed(PermissionSeeder::class);
    $this->actingAs(User::factory()->create()->assignRole(RoleEnum::Reader->value));

    $this->get(route('setup.show'))->assertRedirect(route('dashboard'));
    $this->post(route('setup.project.store'), ['name' => 'Shop'])->assertForbidden();

    auth()->logout();

    $this->post(route('setup.project.store'), ['name' => 'Shop'])->assertRedirect(route('login'));
    expect(Project::query()->exists())->toBeFalse();
});

it('uses the forwarded scheme only behind a trusted proxy', function () {
    $headers = ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'excubia.example.com'];

    $this->get(route('setup.show'), $headers)
        ->assertInertia(fn (Assert $page) => $page->where('step', 'admin'));
    expect(url('/'))->toStartWith('http://');

    config(['excubia.trusted_proxies' => '*']);
    (fn () => $this->configureTrustedProxies())->call(new AppServiceProvider(app()));

    try {
        $this->get(route('setup.show'), $headers);
        expect(url('/'))->toBe('https://excubia.example.com');
    } finally {
        TrustProxies::flushState();
    }
});
