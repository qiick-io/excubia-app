<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AlertEventEnum;
use App\Enums\ProjectVisibilityEnum;
use App\Models\Project;
use App\Services\AlertDispatcherService;

/**
 * Renames a project, enables / disables it and sets who sees it. The slug stays as created, so
 * links keep working; the system project (#70) keeps its name too. Disabling a project raises
 * `project.disabled` (#22).
 */
class UpdateProjectAction
{
    public function __construct(private AlertDispatcherService $alerts) {}

    public function execute(Project $project, string $name, bool $isEnabled, ?ProjectVisibilityEnum $visibility = null): Project
    {
        $project->fill([
            'name' => $project->is_system ? $project->name : $name,
            'is_enabled' => $isEnabled,
            'visibility' => $visibility ?? $project->visibility,
        ])->save();

        if ($project->wasChanged('is_enabled') && ! $project->is_enabled) {
            $this->alerts->dispatch(AlertEventEnum::ProjectDisabled, $project, 'project disabled');
        }

        return $project;
    }
}
