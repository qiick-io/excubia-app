<?php

declare(strict_types=1);

use App\Actions\CreateApiKeyAction;
use App\Actions\UpdateUserAction;
use App\Data\ApiKeyData;
use App\Enums\ApiKeyScopeEnum;
use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Http\Middleware\AuthenticateApiKeyMiddleware;
use App\Models\Project;
use App\Models\User;
use App\Models\UserGroup;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Qiick\Access\Principals\DeactivationReason;

/**
 * What adopting qiick/laravel-access adds to Excubia's authorization (docs/adoption-plan.md §2):
 * an API key never carries the super-admin or permission pass of Gate::before, its owner
 * ceiling counts only assigned permissions, principals follow the package lifecycle, and
 * Excubia's groups are instance groups.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    Route::middleware(AuthenticateApiKeyMiddleware::class.':mcp:read')
        ->get('/__test/access-gate', fn () => response()->json([
            'can_show_system' => Gate::allows(PermissionEnum::CanShowSystem->value),
            'can_view_projects' => Gate::allows('viewAny', Project::class),
        ]));
});

function accessKeyFor(User $owner): string
{
    return app(CreateApiKeyAction::class)->execute($owner, new ApiKeyData('Access', [ApiKeyScopeEnum::McpRead], null));
}

it('gives a super-admin web session every permission ability', function () {
    $superAdmin = User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value);

    expect($superAdmin->can(PermissionEnum::CanShowSystem->value))->toBeTrue();
});

it('never gives a key the super-admin pass, only what its owner is assigned', function () {
    $owner = User::factory()->create()->assignRole([RoleEnum::SuperAdmin->value, RoleEnum::Admin->value]);
    $plainKey = accessKeyFor($owner);

    // Admin is assigned can-show-projects but not can-show-system, which only super-admin grants.
    $this->withToken($plainKey)->getJson('/__test/access-gate')
        ->assertOk()
        ->assertJsonPath('can_show_system', false)
        ->assertJsonPath('can_view_projects', true);
});

it('refuses the key of a super-admin whose can-use-ai comes only from the super-admin role', function () {
    $owner = User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value);
    $plainKey = accessKeyFor($owner);

    $this->withToken($plainKey)->getJson('/__test/access-gate')
        ->assertForbidden()
        ->assertJsonPath('error', 'insufficient_scope');
});

it('deactivates the principal as offboarded with the user and brings it back on reactivation', function () {
    $owner = User::factory()->create()->assignRole(RoleEnum::Admin->value);
    User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value);
    $plainKey = accessKeyFor($owner);
    $update = fn (bool $isActive) => app(UpdateUserAction::class)->execute(
        $owner, $owner->first_name, $owner->last_name, $owner->email, $owner->username, null, [RoleEnum::Admin->value], $isActive,
    );

    $update(false);

    expect($owner->principal()->sole()->deactivation_reason)->toBe(DeactivationReason::Offboarded);
    $this->withToken($plainKey)->getJson('/__test/access-gate')->assertUnauthorized();

    $update(true);

    expect($owner->principal()->sole()->deactivated_at)->toBeNull();
    $this->withToken($plainKey)->getJson('/__test/access-gate')->assertOk();
});

it('creates every group as an instance group', function () {
    $group = UserGroup::query()->create(['name' => 'operators']);

    expect($group->fresh()->is_instance)->toBeTruthy()
        ->and($group->fresh()->public_id)->toBeString();
});
