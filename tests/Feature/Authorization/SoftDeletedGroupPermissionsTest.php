<?php

declare(strict_types=1);

use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\EffectivePermissionResolverService;
use Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
});

/**
 * Soft-deleted user groups must stop granting their roles immediately
 * (externa-core #823: a raw join without user_groups.deleted_at kept permissions).
 */
test('soft-deleting a group drops its members permissions immediately', function (): void {
    $member = User::factory()->create();
    $reader = Role::query()->where('name', RoleEnum::Reader->value)->sole();
    $group = UserGroup::query()->create(['name' => 'viewers']);
    $group->roles()->sync([$reader->id]);
    $group->users()->sync([$member->id]);

    $resolver = app(EffectivePermissionResolverService::class);

    expect($resolver->roleNamesFor($member))->toContain(RoleEnum::Reader->value)
        ->and($resolver->hasPermission($member, PermissionEnum::CanShowProjects->value))->toBeTrue();

    $group->delete();

    expect($group->trashed())->toBeTrue()
        ->and(UserGroup::query()->whereKey($group->getKey())->exists())->toBeFalse()
        ->and($resolver->roleNamesFor($member))->not->toContain(RoleEnum::Reader->value)
        ->and($resolver->hasPermission($member, PermissionEnum::CanShowProjects->value))->toBeFalse();

    $group->restore();

    expect($resolver->roleNamesFor($member))->toContain(RoleEnum::Reader->value)
        ->and($resolver->hasPermission($member, PermissionEnum::CanShowProjects->value))->toBeTrue();
});
