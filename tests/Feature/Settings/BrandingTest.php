<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Models\User;
use App\Services\SettingsRepositoryService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Logo and favicon uploads (#96): stored on the local disk, served publicly, removable.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    Storage::fake('local');
});

function brandingPayload(array $overrides = []): array
{
    return ['theme_preset' => 'blue', 'default_appearance' => 'system', ...$overrides];
}

function brandingManager(): User
{
    return User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value);
}

it('uploads a logo and a favicon, shares their URLs and serves them publicly', function () {
    $manager = brandingManager();

    $this->actingAs($manager)
        ->put(route('appearance.update'), brandingPayload([
            'project_logo' => UploadedFile::fake()->image('logo.png', 64, 64),
            'public_favicon' => UploadedFile::fake()->image('favicon.png', 32, 32),
        ]))
        ->assertRedirect(route('appearance.edit'));

    $settings = app(SettingsRepositoryService::class);
    $logo = $settings->all()['project_logo'];

    Storage::disk('local')->assertExists($logo);
    expect($settings->brandingUrls()['logoUrl'])->toStartWith(route('branding.show', 'logo'))
        ->and($settings->brandingUrls()['logoDarkUrl'])->toBeNull();

    $this->actingAs($manager)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('projectAppearance.logoUrl', $settings->brandingUrls()['logoUrl'])
        ->where('projectAppearance.faviconUrl', $settings->brandingUrls()['faviconUrl']));

    auth()->logout();

    $this->get(route('branding.show', 'logo'))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy');
    $this->get(route('branding.show', 'logo-dark'))->assertNotFound();
    $this->get('/login')->assertSee($settings->brandingUrls()['faviconUrl'], false);
});

it('replaces and removes branding files, deleting the old ones', function () {
    $manager = brandingManager();

    $this->actingAs($manager)->put(route('appearance.update'), brandingPayload([
        'project_logo' => UploadedFile::fake()->image('first.png'),
    ]));
    $first = app(SettingsRepositoryService::class)->all()['project_logo'];

    $this->actingAs($manager)->put(route('appearance.update'), brandingPayload([
        'project_logo' => UploadedFile::fake()->image('second.png'),
    ]));
    $second = app(SettingsRepositoryService::class)->all()['project_logo'];

    Storage::disk('local')->assertMissing($first);
    Storage::disk('local')->assertExists($second);

    $this->actingAs($manager)->put(route('appearance.update'), brandingPayload(['remove_files' => ['project_logo']]));

    Storage::disk('local')->assertMissing($second);
    expect(app(SettingsRepositoryService::class)->brandingUrls()['logoUrl'])->toBeNull();
});

it('keeps the saved files when the form is saved without uploads', function () {
    $manager = brandingManager();

    $this->actingAs($manager)->put(route('appearance.update'), brandingPayload([
        'public_favicon' => UploadedFile::fake()->image('favicon.png'),
    ]));
    $favicon = app(SettingsRepositoryService::class)->all()['public_favicon'];

    $this->actingAs($manager)->put(route('appearance.update'), brandingPayload());

    expect(app(SettingsRepositoryService::class)->all()['public_favicon'])->toBe($favicon);
});

it('accepts only small images', function () {
    $this->actingAs(brandingManager())
        ->put(route('appearance.update'), brandingPayload([
            'project_logo' => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf'),
            'public_favicon' => UploadedFile::fake()->image('favicon.png')->size(2048),
            'remove_files' => ['users'],
        ]))
        ->assertSessionHasErrors(['project_logo', 'public_favicon', 'remove_files.0']);
});
