<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Models\Project;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SystemProjectSeeder;
use Illuminate\Http\Middleware\TrustProxies;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * First-run wizard (#116): admin, first project, push token with the core snippet; locked once done.
 */
function setupAdminInput(array $overrides = []): array
{
    return [
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

it('shows the admin step on a fresh install', function () {
    $this->get(route('setup.show'))
        ->assertInertia(fn (Assert $page) => $page->component('auth/setup')->where('step', 'admin')->has('passwordRules'));
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
