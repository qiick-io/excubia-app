<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AlertEventEnum;
use App\Models\Project;
use App\Services\AlertDispatcherService;

/**
 * Deletes a project; its push token stops working with it. `project.deleted` (#22) is raised
 * first, while the project row exists: its history row goes with the project, the deliveries
 * still go out (they carry the project's name and slug).
 */
class DeleteProjectAction
{
    public function __construct(private AlertDispatcherService $alerts) {}

    public function execute(Project $project): void
    {
        $this->alerts->dispatch(AlertEventEnum::ProjectDeleted, $project, 'project deleted');
        $project->delete();
    }
}
