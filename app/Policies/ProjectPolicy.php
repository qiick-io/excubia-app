<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionEnum;
use App\Models\Project;
use App\Models\User;

/**
 * Projects are listed with `projects.view` and created, edited, deleted and given push tokens
 * with `projects.manage`.
 */
class ProjectPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(PermissionEnum::ProjectsView->value);
    }

    public function view(User $actor, Project $project): bool
    {
        return $this->viewAny($actor);
    }

    public function create(User $actor): bool
    {
        return $actor->can(PermissionEnum::ProjectsManage->value);
    }

    public function update(User $actor, Project $project): bool
    {
        return $this->create($actor);
    }

    public function delete(User $actor, Project $project): bool
    {
        return $this->create($actor);
    }
}
