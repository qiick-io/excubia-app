<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionEnum;
use App\Enums\ProjectVisibilityEnum;
use App\Models\Project;
use App\Models\User;
use App\Services\EffectivePermissionResolverService;

/**
 * Projects are listed with `projects.view` and created, edited, deleted and given push tokens
 * with `projects.manage`. A project visible to admins only is hidden from everyone else. The
 * system project (#70) is never deleted and has no push token; only admins change visibility.
 */
class ProjectPolicy
{
    public function __construct(private EffectivePermissionResolverService $permissions) {}

    public function viewAny(User $actor): bool
    {
        return $actor->can(PermissionEnum::ProjectsView->value);
    }

    public function view(User $actor, Project $project): bool
    {
        return $this->viewAny($actor)
            && ($project->visibility === ProjectVisibilityEnum::Everyone || $this->permissions->isAdmin($actor));
    }

    public function create(User $actor): bool
    {
        return $actor->can(PermissionEnum::ProjectsManage->value);
    }

    public function update(User $actor, Project $project): bool
    {
        return $this->create($actor) && $this->view($actor, $project);
    }

    public function delete(User $actor, Project $project): bool
    {
        return ! $project->is_system && $this->update($actor, $project);
    }

    public function manageToken(User $actor, Project $project): bool
    {
        return ! $project->is_system && $this->update($actor, $project);
    }

    public function changeVisibility(User $actor, Project $project): bool
    {
        return $this->update($actor, $project) && $this->permissions->isAdmin($actor);
    }

    public function refreshReport(User $actor, Project $project): bool
    {
        return $project->is_system && $this->update($actor, $project);
    }
}
