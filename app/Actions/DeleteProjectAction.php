<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Project;

/**
 * Deletes a project; its push token stops working with it.
 */
class DeleteProjectAction
{
    public function execute(Project $project): void
    {
        $project->delete();
    }
}
