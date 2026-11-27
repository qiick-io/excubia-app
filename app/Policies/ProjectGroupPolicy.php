<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionEnum;
use App\Models\ProjectGroup;
use App\Models\User;
use App\Services\EffectivePermissionResolverService;

/**
 * Project groups (#252) are managed with `can-*-project-groups`. A group grants no access by
 * itself: the projects it lists are still filtered by Project::visibleTo() wherever they show.
 */
class ProjectGroupPolicy
{
    public function __construct(private EffectivePermissionResolverService $permissions) {}

    public function viewAny(User $actor): bool
    {
        return $this->permissions->hasPermission($actor, PermissionEnum::CanShowProjectGroups->value);
    }

    public function create(User $actor): bool
    {
        return $this->permissions->hasPermission($actor, PermissionEnum::CanCreateProjectGroups->value);
    }

    public function update(User $actor, ProjectGroup $projectGroup): bool
    {
        return $this->permissions->hasPermission($actor, PermissionEnum::CanEditProjectGroups->value);
    }

    public function delete(User $actor, ProjectGroup $projectGroup): bool
    {
        return $this->permissions->hasPermission($actor, PermissionEnum::CanDeleteProjectGroups->value);
    }
}
