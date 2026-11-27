<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionEnum;
use App\Models\Project;
use App\Models\User;
use App\Services\EffectivePermissionResolverService;

/**
 * Projects are listed with `can-show-projects` and created, edited, deleted and given push tokens
 * with `can-*-projects`. Permissions say what, scope says where (#253): a project visible to
 * admins only is hidden from everyone else, unless it sits in a project group linked to one of
 * their user groups (Project::visibleTo() holds the rule). The
 * system project (#70) is never deleted and has no push token; only admins change visibility.
 * Its alert mutes and maintenance windows need `can-manage-alerts`.
 */
class ProjectPolicy
{
    public function __construct(private EffectivePermissionResolverService $permissions) {}

    public function viewAny(User $actor): bool
    {
        return $this->permissions->hasPermission($actor, PermissionEnum::CanShowProjects->value);
    }

    public function view(User $actor, Project $project): bool
    {
        return $this->viewAny($actor)
            && $project->isVisibleTo($actor);
    }

    public function create(User $actor): bool
    {
        return $this->permissions->hasPermission($actor, PermissionEnum::CanCreateProjects->value);
    }

    public function update(User $actor, Project $project): bool
    {
        return $this->permissions->hasPermission($actor, PermissionEnum::CanEditProjects->value) && $this->view($actor, $project);
    }

    public function delete(User $actor, Project $project): bool
    {
        return ! $project->is_system
            && $this->permissions->hasPermission($actor, PermissionEnum::CanDeleteProjects->value)
            && $this->view($actor, $project);
    }

    public function manageToken(User $actor, Project $project): bool
    {
        return ! $project->is_system && $this->update($actor, $project);
    }

    public function changeVisibility(User $actor, Project $project): bool
    {
        return $this->update($actor, $project) && $this->permissions->isAdmin($actor);
    }

    /**
     * Status and assignee of the project's exception groups (#26, #254), from the UI or an AI tool (#320).
     */
    public function manageExceptions(User $actor, Project $project): bool
    {
        return $this->permissions->hasPermission($actor, PermissionEnum::CanManageExceptions->value) && $this->view($actor, $project);
    }

    /**
     * Dismissal and assignee of the project's findings (#18, #254), from the UI or an AI tool (#320).
     */
    public function manageFindings(User $actor, Project $project): bool
    {
        return $this->permissions->hasPermission($actor, PermissionEnum::CanDismissFindings->value) && $this->view($actor, $project);
    }

    /**
     * Mutes and maintenance windows of the project's Alerts tab (#22, #114).
     */
    public function manageAlerts(User $actor, Project $project): bool
    {
        return $this->permissions->hasPermission($actor, PermissionEnum::CanManageAlerts->value) && $this->view($actor, $project);
    }

    public function refreshReport(User $actor, Project $project): bool
    {
        return $project->is_system
            && $this->permissions->hasPermission($actor, PermissionEnum::CanRunChecks->value)
            && $this->view($actor, $project);
    }
}
