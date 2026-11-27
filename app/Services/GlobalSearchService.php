<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\GlobalSearchResultData;
use App\Models\ExceptionGroup;
use App\Models\Project;
use App\Models\ProjectGroup;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;

/**
 * Finds records by name for the command palette (#274, app-ui optional pattern
 * `command-palette`). Every group goes through the same access rules as its index page, so
 * the palette never reveals a record the user could not open: projects through
 * their policy's `viewAny` and `Project::visibleTo()`, users and groups through their policies' `viewAny`.
 */
final class GlobalSearchService
{
    /** Results per group: enough to pick from, small enough to read at a glance. */
    public const int LIMIT_PER_GROUP = 6;

    /**
     * @return array<string, list<GlobalSearchResultData>> Group type (`projects`, `users`, …) => results; empty groups left out
     */
    public function search(User $user, string $query): array
    {
        // ponytail: `%` and `_` typed in the query act as wildcards (SQLite has no default LIKE
        // escape). They only widen the match inside what the user may see; escape them with a
        // per-driver ESCAPE clause if exact matching of those characters is ever needed.
        $pattern = "%{$query}%";
        $groups = [
            'projects' => $user->can('viewAny', Project::class) ? $this->projects($user, $pattern) : [],
            'exceptions' => $user->can('viewAny', Project::class) ? $this->exceptionGroups($user, $pattern) : [],
            'projectGroups' => $user->can('viewAny', ProjectGroup::class) ? $this->projectGroups($pattern) : [],
            'users' => $user->can('viewAny', User::class) ? $this->users($pattern) : [],
            'groups' => $user->can('viewAny', UserGroup::class) ? $this->userGroups($pattern) : [],
        ];

        return array_filter($groups, fn (array $results): bool => $results !== []);
    }

    /**
     * @return list<GlobalSearchResultData>
     */
    private function projects(User $user, string $pattern): array
    {
        return array_values(Project::query()
            ->visibleTo($user)
            ->where(fn (Builder $query) => $query
                ->whereLike('name', $pattern)
                ->orWhereLike('slug', $pattern))
            ->orderBy('name')
            ->limit(self::LIMIT_PER_GROUP)
            ->get(['id', 'name', 'slug', 'environment', 'logo_path'])
            ->map(fn (Project $project): GlobalSearchResultData => new GlobalSearchResultData(
                id: "project:{$project->id}",
                label: $project->name,
                description: $project->environment ?? $project->slug,
                url: route('projects.show', $project->slug, false),
                avatar: ['slug' => $project->slug, 'logoUrl' => $project->logoUrl()],
            ))
            ->all());
    }

    /**
     * Exception groups seen in the last 14 days (the longest range of the Exceptions tab), by
     * class or message, in the projects the user may see; each opens its drawer (#276).
     *
     * @return list<GlobalSearchResultData>
     */
    private function exceptionGroups(User $user, string $pattern): array
    {
        $groups = ExceptionGroup::query()
            ->whereIn('project_id', Project::query()->visibleTo($user)->select('id'))
            ->where('last_seen_at', '>=', Date::now()->subDays(14))
            ->where(fn (Builder $query) => $query
                ->whereLike('class', $pattern)
                ->orWhereLike('message', $pattern))
            ->with('project:id,name,slug')
            ->orderByDesc('last_seen_at')
            ->limit(self::LIMIT_PER_GROUP)
            ->get(['id', 'project_id', 'class', 'message']);
        $results = [];

        foreach ($groups as $group) {
            $project = $group->project;

            if ($project === null) {
                continue;
            }

            $results[] = new GlobalSearchResultData(
                id: "exception:{$group->id}",
                label: $group->class,
                description: "{$project->name} · {$group->message}",
                url: route('projects.view', ['project' => $project->slug, 'view' => 'exceptions', 'range' => '14d', 'status' => 'all', 'exception' => $group->id], false),
            );
        }

        return $results;
    }

    /**
     * @return list<GlobalSearchResultData>
     */
    private function projectGroups(string $pattern): array
    {
        return array_values(ProjectGroup::query()
            ->whereLike('name', $pattern)
            ->orderBy('name')
            ->limit(self::LIMIT_PER_GROUP)
            ->get(['id', 'name', 'description'])
            ->map(fn (ProjectGroup $projectGroup): GlobalSearchResultData => new GlobalSearchResultData(
                id: "project-group:{$projectGroup->id}",
                label: $projectGroup->name,
                description: $projectGroup->description,
                url: route('project-groups.index', ['edit' => $projectGroup->id], false),
            ))
            ->all());
    }

    /**
     * @return list<GlobalSearchResultData>
     */
    private function users(string $pattern): array
    {
        return array_values(User::query()
            ->where(fn (Builder $query) => $query
                ->whereLike('first_name', $pattern)
                ->orWhereLike('last_name', $pattern)
                ->orWhereLike('email', $pattern))
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->limit(self::LIMIT_PER_GROUP)
            ->get(['id', 'first_name', 'last_name', 'email'])
            ->map(fn (User $foundUser): GlobalSearchResultData => new GlobalSearchResultData(
                id: "user:{$foundUser->id}",
                label: trim("{$foundUser->first_name} {$foundUser->last_name}"),
                description: $foundUser->email,
                url: route('users.index', ['search' => $foundUser->email], false),
            ))
            ->all());
    }

    /**
     * @return list<GlobalSearchResultData>
     */
    private function userGroups(string $pattern): array
    {
        return array_values(UserGroup::query()
            ->whereLike('name', $pattern)
            ->orderBy('name')
            ->limit(self::LIMIT_PER_GROUP)
            ->get(['id', 'name', 'description'])
            ->map(fn (UserGroup $userGroup): GlobalSearchResultData => new GlobalSearchResultData(
                id: "group:{$userGroup->id}",
                label: $userGroup->name,
                description: $userGroup->description,
                url: route('groups.index', ['edit' => $userGroup->id], false),
            ))
            ->all());
    }
}
