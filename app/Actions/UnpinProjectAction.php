<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Project;
use App\Models\User;
use App\Services\SidebarProjectsService;

/**
 * Removes a project from the user's sidebar pins (#273).
 */
final class UnpinProjectAction
{
    public function execute(User $user, Project $project): void
    {
        $user->pinnedProjects()->detach($project->id);
        SidebarProjectsService::forget($user);
    }
}
