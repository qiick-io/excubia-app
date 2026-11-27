<?php

declare(strict_types=1);

use App\Actions\SaveBrandingFilesAction;
use App\Actions\SaveProjectLogoAction;
use App\Enums\RoleEnum;
use App\Models\Project;
use App\Models\User;
use App\Services\SettingsRepositoryService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/**
 * The files disk (#312): with `excubia.files_disk` set, project logos and branding files are
 * written to, streamed from and deleted on that disk (a bucket on hosts whose filesystem is
 * rebuilt on every deploy) under the same relative paths. Unset, logos stay on `public` and
 * branding on `local`, so existing installs keep their files.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    foreach (['s3', SaveProjectLogoAction::DEFAULT_DISK, SaveBrandingFilesAction::DEFAULT_DISK] as $diskName) {
        Storage::fake($diskName);
    }
});

function filesDiskAdmin(): User
{
    return User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value);
}

function saveProjectLogoThroughForm(User $actor, Project $project, array $payload): TestResponse
{
    return test()->actingAs($actor)->post(route('projects.update', $project), [
        '_method' => 'put',
        'name' => $project->name,
        'is_enabled' => true,
        ...$payload,
    ]);
}

function saveBrandingThroughForm(User $actor, array $payload): TestResponse
{
    return test()->actingAs($actor)->put(route('appearance.update'), [
        'theme_preset' => 'genziana',
        'default_appearance' => 'system',
        ...$payload,
    ]);
}

/** @return list<string> every file on the local disks the defaults use */
function filesOnDefaultDisks(): array
{
    return [
        ...Storage::disk(SaveProjectLogoAction::DEFAULT_DISK)->allFiles(),
        ...Storage::disk(SaveBrandingFilesAction::DEFAULT_DISK)->allFiles(),
    ];
}

it('uploads, serves, replaces and removes a project logo on the configured disk', function () {
    config(['excubia.files_disk' => 's3']);
    $admin = filesDiskAdmin();
    $project = Project::factory()->create();

    saveProjectLogoThroughForm($admin, $project, ['logo' => UploadedFile::fake()->image('first.png', 64, 64)])->assertRedirect();
    $firstPath = (string) $project->refresh()->logo_path;

    expect($firstPath)->toStartWith(SaveProjectLogoAction::DIRECTORY.'/');
    Storage::disk('s3')->assertExists($firstPath);

    $this->actingAs($admin)->get((string) $project->logoUrl())
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('Cache-Control', 'immutable, max-age=31536000, private');

    saveProjectLogoThroughForm($admin, $project, ['logo' => UploadedFile::fake()->image('second.png', 64, 64)])->assertRedirect();
    $secondPath = (string) $project->refresh()->logo_path;

    Storage::disk('s3')->assertMissing($firstPath);
    Storage::disk('s3')->assertExists($secondPath);

    saveProjectLogoThroughForm($admin, $project, ['remove_logo' => true])->assertRedirect();

    Storage::disk('s3')->assertMissing($secondPath);
    expect($project->refresh()->logo_path)->toBeNull()
        ->and(filesOnDefaultDisks())->toBe([]);
});

it('uploads, serves, replaces and removes the branding files on the configured disk', function () {
    config(['excubia.files_disk' => 's3']);
    $admin = filesDiskAdmin();

    saveBrandingThroughForm($admin, [
        'project_logo' => UploadedFile::fake()->image('logo.png', 64, 64),
        'project_logo_dark' => UploadedFile::fake()->createWithContent('logo-dark.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 8 8"><circle r="4"/></svg>'),
        'public_favicon' => UploadedFile::fake()->image('favicon.png', 32, 32),
    ])->assertRedirect(route('appearance.edit'));

    $settings = app(SettingsRepositoryService::class);
    $firstLogoPath = $settings->all()['project_logo'];

    foreach (SettingsRepositoryService::BRANDING_ASSETS as $settingKey) {
        expect($settings->all()[$settingKey])->toStartWith(SaveBrandingFilesAction::DIRECTORY.'/');
        Storage::disk('s3')->assertExists($settings->all()[$settingKey]);
    }

    auth()->logout();

    $this->get(route('branding.show', 'logo'))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');
    $this->get(route('branding.show', 'logo-dark'))->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
    $this->get(route('branding.show', 'favicon'))->assertOk()->assertHeader('Content-Type', 'image/png');

    saveBrandingThroughForm($admin, ['project_logo' => UploadedFile::fake()->image('second.png', 64, 64)])->assertRedirect();
    $secondLogoPath = app(SettingsRepositoryService::class)->all()['project_logo'];

    Storage::disk('s3')->assertMissing($firstLogoPath);
    Storage::disk('s3')->assertExists($secondLogoPath);

    saveBrandingThroughForm($admin, ['remove_files' => ['project_logo']])->assertRedirect();

    Storage::disk('s3')->assertMissing($secondLogoPath);
    expect(app(SettingsRepositoryService::class)->brandingUrls()['logoUrl'])->toBeNull()
        ->and(filesOnDefaultDisks())->toBe([]);
    $this->get(route('branding.show', 'logo'))->assertNotFound();
});

it('keeps logos on the public disk and branding on the local disk when no files disk is set', function (?string $unsetValue) {
    config(['excubia.files_disk' => $unsetValue]);
    $admin = filesDiskAdmin();
    $project = Project::factory()->create();

    saveProjectLogoThroughForm($admin, $project, ['logo' => UploadedFile::fake()->image('logo.png', 64, 64)])->assertRedirect();
    saveBrandingThroughForm($admin, ['project_logo' => UploadedFile::fake()->image('logo.png', 64, 64)])->assertRedirect();
    $brandingPath = app(SettingsRepositoryService::class)->all()['project_logo'];

    Storage::disk(SaveProjectLogoAction::DEFAULT_DISK)->assertExists((string) $project->refresh()->logo_path);
    Storage::disk(SaveBrandingFilesAction::DEFAULT_DISK)->assertExists($brandingPath);
    expect(Storage::disk('s3')->allFiles())->toBe([]);

    $this->actingAs($admin)->get((string) $project->logoUrl())->assertOk()->assertHeader('Content-Type', 'image/png');
    $this->get(route('branding.show', 'logo'))->assertOk()->assertHeader('Content-Type', 'image/png');
})->with(['null' => [null], 'empty' => ['']]);
