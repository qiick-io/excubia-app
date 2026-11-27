<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;

/**
 * The qiick error pages (#423): browser requests get `errors/error-page` with the app shell's
 * shared props; JSON keeps Laravel's errors.
 */
beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);

    Route::middleware('web')->get('/__error/{status}', fn (int $status) => abort($status, headers: $status === 503 ? ['Retry-After' => '60'] : []));
    Route::middleware('web')->post('/__error/{status}', fn (int $status) => abort($status));
});

it('renders the error page with the shared props for each status', function (int $status) {
    config(['app.debug' => false]);
    $this->actingAs(User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value));

    $this->get("/__error/{$status}")
        ->assertStatus($status)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('errors/error-page')
            ->where('status', $status)
            ->has('auth.user'));
})->with([403, 404, 500, 503]);

it('renders a missing route-bound project inside the app shell', function () {
    $this->actingAs(User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value));

    $this->get('/projects/does-not-exist')
        ->assertNotFound()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('errors/error-page')->has('auth.user'));
});

it('renders the page without the app shell for guests', function () {
    $this->get('/__error/404')
        ->assertNotFound()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('errors/error-page')->where('auth.user', null));
});

it('localizes an unmatched URL from the preference cookie in the guest layout', function (string $locale, bool $isSignedIn) {
    if ($isSignedIn) {
        $this->actingAs(User::factory()->create(['locale' => $locale]));
    }

    $this->withUnencryptedCookie('locale', $locale)
        ->get('/round-three-unmatched')
        ->assertNotFound()
        ->assertSee('lang="'.$locale.'"', false)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('errors/error-page')
            ->where('locale', $locale)
            ->missing('auth.user'));
})->with(['it', 'de'])->with([true, false]);

it('ignores an unsupported preference on an unmatched URL', function () {
    $this->withUnencryptedCookie('locale', 'unsupported')
        ->get('/round-three-unmatched')
        ->assertNotFound()
        ->assertSee('lang="en"', false)
        ->assertInertia(fn (AssertableInertia $page) => $page->where('locale', 'en'));
});

it('sends guests on a signed-in route to the login before any 404', function () {
    $this->get('/projects/does-not-exist')->assertRedirect(route('login'));
});

it('keeps JSON errors for JSON requests', function () {
    $this->actingAs(User::factory()->create());

    $this->getJson('/__error/404')->assertNotFound()->assertJsonStructure(['message']);
});

it('keeps the debug page for a 500 in debug mode', function () {
    config(['app.debug' => true]);
    $this->actingAs(User::factory()->create());

    expect($this->get('/__error/500')->assertStatus(500)->headers->get('X-Inertia'))->toBeNull()
        ->and($this->get('/__error/500')->getContent())->not->toContain('errors/error-page');
});

it('sends an expired session back with a toast, never to a foreign page', function () {
    $this->actingAs(User::factory()->create());

    $this->from('/projects')->post('/__error/419')->assertRedirect('/projects');
    $this->from('https://evil.example/phish')->post('/__error/419')->assertRedirect(route('dashboard'));
});

it('sends a failed action back to its page instead of rendering the action URL', function () {
    config(['app.debug' => false]);
    $this->actingAs(User::factory()->create());

    $this->from('/projects')->post('/__error/500')->assertRedirect('/projects');
});

it('keeps the Retry-After header of a 503 and varies on X-Inertia', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/__error/503')->assertStatus(503)->assertHeader('Retry-After', '60')->assertHeader('Vary', 'X-Inertia');
});
