<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Project;
use App\Models\ProjectGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates a project group and replaces the projects the actor can see. Projects
 * hidden from the actor (admins only) stay in the group untouched, so a non-admin editing a
 * group never drops them. The group change is logged by the model, the projects as
 * `projects_synced`.
 */
class SaveProjectGroupAction
{
    /**
     * @param  list<int>  $projectIds  projects visible to the actor (ProjectGroupRequest)
     */
    public function execute(ProjectGroup $projectGroup, ?User $actor, string $name, ?string $description, array $projectIds): ProjectGroup
    {
        return DB::transaction(function () use ($projectGroup, $actor, $name, $description, $projectIds): ProjectGroup {
            $projectGroup->fill(['name' => $name, 'description' => $description])->save();

            $visibleProjectIds = Project::query()->visibleTo($actor)->pluck('id')->all();
            $hiddenProjectIds = $projectGroup->projects()->whereKeyNot($visibleProjectIds)->pluck('projects.id')->all();
            $syncedProjectIds = array_values(array_unique([...$projectIds, ...$hiddenProjectIds]));

            $projectGroup->projects()->sync($syncedProjectIds);

            activity()
                ->performedOn($projectGroup)
                ->event('projects_synced')
                ->withProperties(['project_ids' => $syncedProjectIds])
                ->log('Project group projects synced');

            return $projectGroup;
        });
    }
}
