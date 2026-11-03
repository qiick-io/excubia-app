<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Project;

/**
 * Renames a project or enables / disables it. The slug stays as created, so links keep working.
 */
class UpdateProjectAction
{
    public function execute(Project $project, string $name, bool $isEnabled): Project
    {
        $project->fill(['name' => $name, 'is_enabled' => $isEnabled])->save();

        return $project;
    }
}
