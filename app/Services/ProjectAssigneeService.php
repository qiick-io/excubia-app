<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ExceptionStatusEnum;
use App\Models\ExceptionGroup;
use App\Models\Finding;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Who may be assigned an exception group or a finding of a project (#254): active users who can
 * see the project (its visibility and the project access scope of #253, through the project
 * policy), so an assignment never leaks a project to someone outside it. Also counts what is
 * assigned to a user, for the dashboard.
 *
 * ponytail: one policy check per active user; fine for a self-hosted team. Resolve the visible
 * users in one query if installs reach hundreds of users.
 */
class ProjectAssigneeService
{
    /**
     * @return list<array{id: int, name: string}>
     */
    public function optionsFor(Project $project): array
    {
        return array_values(User::query()
            ->where('is_active', true)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->orderBy('id')
            ->get()
            ->filter(fn (User $user): bool => $this->canBeAssigned($user, $project))
            ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])
            ->all());
    }

    public function canBeAssigned(User $user, Project $project): bool
    {
        return $user->is_active && $user->can('view', $project);
    }

    /**
     * What is assigned to the user and still needs work, per project among `$projects` (the
     * ones they can see): open exception groups and open findings without an active dismissal.
     * Projects with nothing assigned are left out. Two grouped queries.
     *
     * @param  Collection<int, Project>  $projects
     * @return list<array{slug: string, name: string, exceptions: int, findings: int}>
     */
    public function assignedTo(User $user, Collection $projects): array
    {
        $projectIds = $projects->modelKeys();
        $exceptions = ExceptionGroup::query()
            ->whereIn('project_id', $projectIds)
            ->where('assigned_user_id', $user->id)
            ->where('status', ExceptionStatusEnum::Open->value)
            ->selectRaw('project_id, count(*) as aggregate')
            ->groupBy('project_id')
            ->pluck('aggregate', 'project_id');
        $findings = Finding::query()
            ->whereIn('project_id', $projectIds)
            ->where('assigned_user_id', $user->id)
            ->active()
            ->selectRaw('project_id, count(*) as aggregate')
            ->groupBy('project_id')
            ->pluck('aggregate', 'project_id');

        return array_values($projects
            ->map(fn (Project $project): array => [
                'slug' => $project->slug,
                'name' => $project->name,
                'exceptions' => (int) ($exceptions[$project->id] ?? 0),
                'findings' => (int) ($findings[$project->id] ?? 0),
            ])
            ->filter(fn (array $row): bool => $row['exceptions'] + $row['findings'] > 0)
            ->all());
    }
}
