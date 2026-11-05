<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ProjectVisibilityEnum;
use App\Models\Project;

/**
 * Renames a project, enables / disables it and sets who sees it. The slug stays as created, so
 * links keep working; the system project (#70) keeps its name too.
 */
class UpdateProjectAction
{
    public function execute(Project $project, string $name, bool $isEnabled, ?ProjectVisibilityEnum $visibility = null): Project
    {
        $project->fill([
            'name' => $project->is_system ? $project->name : $name,
            'is_enabled' => $isEnabled,
            'visibility' => $visibility ?? $project->visibility,
        ])->save();

        return $project;
    }
}
