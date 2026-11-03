<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Log;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/**
 * Project registration (#5): `projects.view` lists, `projects.manage` creates, edits, deletes
 * and issues push tokens. The plain token is flashed once and stored only as a hash.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
});

function projectActor(RoleEnum $role = RoleEnum::Admin): User
{
    return User::factory()->create()->assignRole($role->value);
}

function flashedToken(): string
{
    return session(SessionKey::FLASH_DATA)['pushToken']['token'];
}

/**
 * Creates a project through the endpoint and returns it with the flashed plain token.
 *
 * @return array{0: Project, 1: string}
 */
function createProjectWithToken(User $actor, string $name = 'Shop'): array
{
    $response = test()->actingAs($actor)->post(route('projects.store'), ['name' => $name]);
    $response->assertRedirect(route('projects.index'))->assertInertiaFlash('pushToken.project', $name);

    return [Project::query()->where('name', $name)->sole(), flashedToken()];
}

it('lists projects for projects.view without leaking the token hash', function () {
    [$project] = createProjectWithToken(projectActor());
    $reader = projectActor(RoleEnum::Reader);

    $this->actingAs($reader)->get(route('projects.index'))
        ->assertOk()
        ->assertDontSee($project->push_token_hash)
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/index')
            ->where('projects.data.0.name', 'Shop')
            ->where('projects.data.0.slug', 'shop')
            ->where('projects.data.0.has_push_token', true)
            ->missing('projects.data.0.push_token_hash'));
});

it('keeps project changes behind projects.manage', function () {
    $reader = projectActor(RoleEnum::Reader);
    $project = Project::factory()->create();

    $this->actingAs(User::factory()->create())->get(route('projects.index'))->assertForbidden();
    $this->actingAs($reader)->post(route('projects.store'), ['name' => 'x'])->assertForbidden();
    $this->actingAs($reader)->put(route('projects.update', $project), ['name' => 'x'])->assertForbidden();
    $this->actingAs($reader)->delete(route('projects.destroy', $project))->assertForbidden();
    $this->actingAs($reader)->post(route('projects.token.rotate', $project))->assertForbidden();
    $this->actingAs($reader)->delete(route('projects.token.revoke', $project))->assertForbidden();
});

it('creates a project and shows its push token once', function () {
    $admin = projectActor();

    [$project, $token] = createProjectWithToken($admin, 'Shop Front');

    expect($project->slug)->toBe('shop-front')
        ->and($project->is_enabled)->toBeTrue()
        ->and($token)->toStartWith('excubia_')
        ->and($project->push_token_hash)->toBe(hash('sha256', $token));

    $this->actingAs($admin)->get(route('projects.index'))
        ->assertInertia(fn (Assert $page) => $page->hasFlash('pushToken.token', $token));

    $this->actingAs($admin)->get(route('projects.index'))
        ->assertInertia(fn (Assert $page) => $page->missingFlash('pushToken'));
});

it('never stores or logs the plain token', function () {
    Log::spy();

    [$project, $token] = createProjectWithToken(projectActor());
    $this->post(route('projects.token.rotate', $project));

    $storedProject = json_encode(Project::query()->get()->makeVisible('push_token_hash')->toArray());
    $storedActivity = json_encode(Activity::query()->get()->toArray());

    expect($storedProject)->not->toContain($token)
        ->and($storedActivity)->not->toContain($token)
        ->and($storedActivity)->not->toContain('push_token_hash')
        ->and(Activity::query()->where('event', 'token_rotated')->count())->toBe(2);

    Log::shouldNotHaveReceived('info');
    Log::shouldNotHaveReceived('debug');
});

it('validates the project name', function () {
    $admin = projectActor();
    Project::factory()->create(['name' => 'Shop', 'slug' => 'shop']);

    $this->actingAs($admin)->post(route('projects.store'), ['name' => ''])->assertSessionHasErrors('name');
    $this->actingAs($admin)->post(route('projects.store'), ['name' => str_repeat('a', 101)])->assertSessionHasErrors('name');
    $this->actingAs($admin)->post(route('projects.store'), ['name' => 'Shop'])->assertSessionHasErrors('name');
    $this->actingAs($admin)->post(route('projects.store'), ['name' => 'shop'])->assertSessionHasErrors('slug');
    $this->actingAs($admin)->post(route('projects.store'), ['name' => '!!!'])->assertSessionHasErrors('slug');

    expect(Project::query()->count())->toBe(1);
});

it('updates a project and keeps its slug', function () {
    $admin = projectActor();
    $project = Project::factory()->create(['name' => 'Shop', 'slug' => 'shop']);
    Project::factory()->create(['name' => 'Blog']);

    $this->actingAs($admin)->put(route('projects.update', $project), ['name' => 'Blog'])->assertSessionHasErrors('name');

    $this->actingAs($admin)->put(route('projects.update', $project), ['name' => 'Shop EU', 'is_enabled' => false])
        ->assertRedirect(route('projects.index'));

    expect($project->fresh())
        ->name->toBe('Shop EU')
        ->slug->toBe('shop')
        ->is_enabled->toBeFalse();
});

it('deletes a project', function () {
    $project = Project::factory()->create();

    $this->actingAs(projectActor())->delete(route('projects.destroy', $project))
        ->assertRedirect(route('projects.index'));

    expect(Project::query()->count())->toBe(0);
});

it('rotates the push token and invalidates the old one', function () {
    $admin = projectActor();
    [$project, $oldToken] = createProjectWithToken($admin);

    $response = $this->actingAs($admin)->post(route('projects.token.rotate', $project));
    $response->assertRedirect(route('projects.index'));
    $newToken = flashedToken();

    expect($newToken)->not->toBe($oldToken)
        ->and($project->fresh()->push_token_hash)->toBe(hash('sha256', $newToken));
});

it('revokes the push token', function () {
    $admin = projectActor();
    [$project] = createProjectWithToken($admin);

    $this->actingAs($admin)->delete(route('projects.token.revoke', $project))
        ->assertRedirect(route('projects.index'));

    expect($project->fresh()->push_token_hash)->toBeNull()
        ->and(Activity::query()->where('event', 'token_revoked')->exists())->toBeTrue();
});
