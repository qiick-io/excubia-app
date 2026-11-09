<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionEnum;
use App\Models\ProjectGroup;
use App\Models\User;

/**
 * Project groups (#252) are managed with `can-*-project-groups`. A group grants no access by
 * itself: the projects it lists are still filtered by Project::visibleTo() wherever they show.
 */
class ProjectGroupPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(PermissionEnum::CanShowProjectGroups->value);
    }

    public function create(User $actor): bool
    {
        return $actor->can(PermissionEnum::CanCreateProjectGroups->value);
    }

    public function update(User $actor, ProjectGroup $projectGroup): bool
    {
        return $actor->can(PermissionEnum::CanEditProjectGroups->value);
    }

    public function delete(User $actor, ProjectGroup $projectGroup): bool
    {
        return $actor->can(PermissionEnum::CanDeleteProjectGroups->value);
    }
}
