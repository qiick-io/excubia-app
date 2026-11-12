<?php

declare(strict_types=1);

use App\Actions\SaveProjectLogoAction;
use App\Enums\ProjectVisibilityEnum;
use App\Enums\RoleEnum;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The optional project logo (#287): uploaded with the project form, stored on the public disk
 * as a square, replaced and removed there, served only to people who may see the project, and
 * shown wherever the project's avatar is (list, sidebar, search).
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    Storage::fake(SaveProjectLogoAction::DISK);
});

function logoActor(RoleEnum $role = RoleEnum::Admin): User
{
    return User::factory()->create()->assignRole($role->value);
}

function svgLogo(string $markup = '<circle r="4"/>'): UploadedFile
{
    return UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 8 8">'.$markup.'</svg>');
}

/** Updates a project through the form's request: a POST with `_method`, as for a file. */
function updateProjectLogo(User $actor, Project $project, array $payload): TestResponse
{
    return test()->actingAs($actor)->post(route('projects.update', $project), [
        '_method' => 'put',
        'name' => $project->name,
        'is_enabled' => true,
        ...$payload,
    ]);
}

it('creates a project with a logo stored as a transparent square PNG', function () {
    $this->actingAs(logoActor())
        ->post(route('projects.store'), ['name' => 'Shop', 'logo' => UploadedFile::fake()->image('logo.jpg', 300, 150)])
        ->assertRedirect(route('projects.index'));

    $project = Project::query()->where('slug', 'shop')->sole();
    $stored = Storage::disk(SaveProjectLogoAction::DISK)->get((string) $project->logo_path);
    $size = getimagesizefromstring((string) $stored);

    expect($project->logo_path)->toStartWith(SaveProjectLogoAction::DIRECTORY.'/')->toEndWith('.png')
        ->and($size[0] ?? null)->toBe(SaveProjectLogoAction::SIZE)
        ->and($size[1] ?? null)->toBe(SaveProjectLogoAction::SIZE)
        ->and($size['mime'] ?? null)->toBe('image/png');
});

it('replaces a logo and deletes the old file', function () {
    $admin = logoActor();
    $project = Project::factory()->create();

    updateProjectLogo($admin, $project, ['logo' => UploadedFile::fake()->image('first.png', 64, 64)])->assertRedirect();
    $firstPath = (string) $project->refresh()->logo_path;
    updateProjectLogo($admin, $project, ['logo' => svgLogo()])->assertRedirect();

    expect($project->refresh()->logo_path)->not->toBe($firstPath)->toEndWith('.svg');
    Storage::disk(SaveProjectLogoAction::DISK)->assertMissing($firstPath);
    Storage::disk(SaveProjectLogoAction::DISK)->assertExists((string) $project->logo_path);
});

it('removes a logo, back to the initials avatar', function () {
    $admin = logoActor();
    $project = Project::factory()->create();
    updateProjectLogo($admin, $project, ['logo' => UploadedFile::fake()->image('logo.png', 64, 64)]);
    $path = (string) $project->refresh()->logo_path;

    updateProjectLogo($admin, $project, ['remove_logo' => true])->assertRedirect();

    expect($project->refresh()->logo_path)->toBeNull();
    Storage::disk(SaveProjectLogoAction::DISK)->assertMissing($path);
});

it('keeps the logo when the form changes something else', function () {
    $admin = logoActor();
    $project = Project::factory()->create();
    updateProjectLogo($admin, $project, ['logo' => UploadedFile::fake()->image('logo.png', 64, 64)]);
    $path = $project->refresh()->logo_path;

    $this->actingAs($admin)->put(route('projects.update', $project), ['name' => 'Renamed', 'is_enabled' => true])->assertRedirect();

    expect($project->refresh()->logo_path)->toBe($path);
});

it('stores an uploaded SVG without its script', function () {
    $project = Project::factory()->create();

    updateProjectLogo(logoActor(), $project, [
        'logo' => svgLogo('<script>alert(1)</script><rect onclick="alert(2)" width="8" height="8"/>'),
    ])->assertRedirect()->assertSessionHasNoErrors();

    $stored = (string) Storage::disk(SaveProjectLogoAction::DISK)->get((string) $project->refresh()->logo_path);

    expect($stored)->toContain('<rect width="8" height="8"/>')
        ->and($stored)->not->toContain('alert');
});

it('validates the logo type, size and SVG content', function (UploadedFile $upload) {
    $project = Project::factory()->create();

    updateProjectLogo(logoActor(), $project, ['logo' => $upload])->assertSessionHasErrors('logo');

    expect($project->refresh()->logo_path)->toBeNull();
})->with([
    'GIF' => fn () => UploadedFile::fake()->image('logo.gif', 32, 32),
    'PDF' => fn () => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf'),
    'over 256 KB' => fn () => UploadedFile::fake()->image('logo.png', 32, 32)->size(300),
    'SVG with a DOCTYPE' => fn () => UploadedFile::fake()->createWithContent('logo.svg', '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY a "a">]><svg xmlns="http://www.w3.org/2000/svg">&a;</svg>'),
    'HTML named .svg' => fn () => UploadedFile::fake()->createWithContent('logo.svg', '<html><body><script>alert(1)</script></body></html>'),
]);

it('lets only people who may edit the project change its logo', function () {
    $project = Project::factory()->create();

    updateProjectLogo(logoActor(RoleEnum::Reader), $project, ['logo' => UploadedFile::fake()->image('logo.png', 32, 32)])->assertForbidden();
    updateProjectLogo(logoActor(RoleEnum::Reader), $project, ['remove_logo' => true])->assertForbidden();
    $this->actingAs(logoActor(RoleEnum::Reader))
        ->post(route('projects.store'), ['name' => 'Shop', 'logo' => UploadedFile::fake()->image('logo.png', 32, 32)])
        ->assertForbidden();

    expect($project->refresh()->logo_path)->toBeNull();
    expect(Storage::disk(SaveProjectLogoAction::DISK)->allFiles())->toBe([]);
});

it('serves the logo to people who may see the project, with headers that keep an SVG inert', function () {
    $admin = logoActor();
    $project = Project::factory()->create();
    updateProjectLogo($admin, $project, ['logo' => svgLogo()]);
    $project->refresh();

    $response = $this->actingAs(logoActor(RoleEnum::Reader))->get((string) $project->logoUrl())->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('image/svg+xml')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Security-Policy'))->toContain("default-src 'none'")->toContain('sandbox');
});

it('hides the logo of a project the user may not see', function () {
    $admin = logoActor();
    $project = Project::factory()->create(['visibility' => ProjectVisibilityEnum::Admins]);
    updateProjectLogo($admin, $project, ['logo' => UploadedFile::fake()->image('logo.png', 32, 32)]);
    $url = (string) $project->refresh()->logoUrl();

    $this->actingAs(logoActor(RoleEnum::Reader))->get($url)->assertForbidden();
    $this->get($url)->assertForbidden();
    auth()->logout();
    $this->get($url)->assertRedirect(route('login'));
});

it('answers 404 for a project without a logo', function () {
    $project = Project::factory()->create();

    $this->actingAs(logoActor())->get(route('projects.logo', $project->slug))->assertNotFound();
});

it('deletes the logo file with its project', function () {
    $admin = logoActor();
    $project = Project::factory()->create();
    updateProjectLogo($admin, $project, ['logo' => UploadedFile::fake()->image('logo.png', 32, 32)]);
    $path = (string) $project->refresh()->logo_path;

    $this->actingAs($admin)->delete(route('projects.destroy', $project))->assertRedirect();

    Storage::disk(SaveProjectLogoAction::DISK)->assertMissing($path);
});

it('sends the logo URL to the projects list, the sidebar and the search', function () {
    $admin = logoActor();
    $project = Project::factory()->create(['name' => 'Billing API', 'slug' => 'billing-api']);
    $plain = Project::factory()->create(['name' => 'Billing worker', 'slug' => 'billing-worker']);
    updateProjectLogo($admin, $project, ['logo' => UploadedFile::fake()->image('logo.png', 32, 32)]);
    $logoUrl = $project->refresh()->logoUrl();
    $admin->pinnedProjects()->attach([$project->id, $plain->id]);

    $this->actingAs($admin)->get(route('projects.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('projects.data.0.logo_url', $logoUrl)
            ->where('projects.data.1.logo_url', null)
            ->where('sidebarProjects.pinned.0.logo_url', $logoUrl)
            ->where('sidebarProjects.pinned.1.logo_url', null));

    $projects = collect($this->actingAs($admin)->getJson(route('search', ['query' => 'billing']))->json('groups'))->firstWhere('type', 'projects');

    expect($projects['items'][0]['avatar'])->toBe(['slug' => 'billing-api', 'logoUrl' => $logoUrl])
        ->and($projects['items'][1]['avatar'])->toBe(['slug' => 'billing-worker', 'logoUrl' => null]);
});
