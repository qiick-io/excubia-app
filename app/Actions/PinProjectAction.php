<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Project;
use App\Models\User;
use App\Services\SidebarProjectsService;

/**
 * Pins a project to the user's sidebar (#273), after their existing pins. Pinning twice keeps
 * the first position.
 */
final class PinProjectAction
{
    public function execute(User $user, Project $project): void
    {
        if (! $user->pinnedProjects()->whereKey($project->id)->exists()) {
            $nextPosition = (int) $user->pinnedProjects()->max('user_project_pins.position') + 1;
            $user->pinnedProjects()->attach($project->id, ['position' => $nextPosition]);
        }

        SidebarProjectsService::forget($user);
    }
}
