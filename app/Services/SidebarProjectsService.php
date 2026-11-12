<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ExceptionStatusEnum;
use App\Enums\SeverityEnum;
use App\Models\ExceptionGroup;
use App\Models\Finding;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The projects under "Projects" in the sidebar (#273, app-ui optional pattern
 * `sidebar-entities`): the user's pins in pin order, then up to five other projects with open
 * problems, worst first, each with its logo URL for the avatar (#287). Only projects the user may view (`Project::visibleTo()`, #253 scope
 * included), so a pin the user can no longer see simply drops out.
 *
 * It is a shared prop on every page, so it costs three queries and is cached per user for a
 * few seconds; pinning and unpinning forget the cache.
 */
final class SidebarProjectsService
{
    public const int PINNED_LIMIT = 8;

    public const int ATTENTION_LIMIT = 5;

    private const int CACHE_SECONDS = 30;

    /** Findings of these severities make a project critical; any other open one a warning. */
    private const array CRITICAL_SEVERITIES = [SeverityEnum::Critical, SeverityEnum::High];

    /**
     * @return array{pinned: list<array{slug: string, name: string, logo_url: string|null, status: 'good'|'warning'|'critical'}>, needsAttention: list<array{slug: string, name: string, logo_url: string|null, status: 'good'|'warning'|'critical'}>}
     */
    public function forUser(User $user): array
    {
        if (! $user->can('viewAny', Project::class)) {
            return ['pinned' => [], 'needsAttention' => []];
        }

        return Cache::remember(self::cacheKey($user), self::CACHE_SECONDS, fn (): array => $this->build($user));
    }

    /** Called after a pin changes, so the sidebar shows it on the next page. */
    public static function forget(User $user): void
    {
        Cache::forget(self::cacheKey($user));
    }

    private static function cacheKey(User $user): string
    {
        return "sidebar-projects:{$user->id}";
    }

    /**
     * @return array{pinned: list<array{slug: string, name: string, logo_url: string|null, status: 'good'|'warning'|'critical'}>, needsAttention: list<array{slug: string, name: string, logo_url: string|null, status: 'good'|'warning'|'critical'}>}
     */
    private function build(User $user): array
    {
        $visibleProjectIds = Project::query()->visibleTo($user)->select('id');
        $openExceptionsByProject = ExceptionGroup::query()
            ->whereIn('project_id', $visibleProjectIds)
            ->where('status', ExceptionStatusEnum::Open->value)
            ->selectRaw('project_id, count(*) as aggregate')
            ->groupBy('project_id')
            ->pluck('aggregate', 'project_id');
        /** @var Collection<int, Collection<int, Finding>> $activeFindingsByProject */
        $activeFindingsByProject = Finding::query()
            ->whereIn('project_id', $visibleProjectIds)
            ->active()
            ->selectRaw('project_id, severity, count(*) as aggregate')
            ->groupBy('project_id', 'severity')
            ->get()
            ->groupBy('project_id');

        // The scope's own `id` would be ambiguous next to the pivot table, so it filters by id.
        $pinned = $user->pinnedProjects()
            ->whereIn('projects.id', $visibleProjectIds)
            ->limit(self::PINNED_LIMIT)
            ->get(['projects.id', 'projects.slug', 'projects.name', 'projects.logo_path']);
        $pinnedIds = $pinned->modelKeys();
        $troubledIds = $openExceptionsByProject->keys()->merge($activeFindingsByProject->keys())
            ->map(fn (int|string $projectId): int => (int) $projectId)
            ->unique()
            ->reject(fn (int $projectId): bool => in_array($projectId, $pinnedIds, true));
        $troubledProjects = Project::query()
            ->whereIn('id', $troubledIds->all())
            ->orderBy('name')
            ->get(['id', 'slug', 'name', 'logo_path']);
        $critical = [];
        $warning = [];

        foreach ($troubledProjects as $project) {
            $status = $this->statusOf($project->id, $openExceptionsByProject, $activeFindingsByProject);
            $entry = ['slug' => $project->slug, 'name' => $project->name, 'logo_url' => $project->logoUrl(), 'status' => $status];

            if ($status === 'critical') {
                $critical[] = $entry;
            } else {
                $warning[] = $entry;
            }
        }

        $pinnedEntries = [];

        foreach ($pinned as $project) {
            $pinnedEntries[] = [
                'slug' => $project->slug,
                'name' => $project->name,
                'logo_url' => $project->logoUrl(),
                'status' => $this->statusOf($project->id, $openExceptionsByProject, $activeFindingsByProject),
            ];
        }

        return [
            'pinned' => $pinnedEntries,
            // Worst first, by name within a severity.
            'needsAttention' => array_slice([...$critical, ...$warning], 0, self::ATTENTION_LIMIT),
        ];
    }

    /**
     * Open exceptions or a high / critical finding make a project critical; any other active
     * finding a warning.
     *
     * @param  Collection<int|string, mixed>  $openExceptionsByProject
     * @param  Collection<int, Collection<int, Finding>>  $activeFindingsByProject
     * @return 'good'|'warning'|'critical'
     */
    private function statusOf(int $projectId, Collection $openExceptionsByProject, Collection $activeFindingsByProject): string
    {
        $findings = $activeFindingsByProject->get($projectId, collect());
        $hasCriticalFinding = $findings->contains(
            fn (Finding $row): bool => in_array(SeverityEnum::tryFrom((string) $row->getRawOriginal('severity')), self::CRITICAL_SEVERITIES, true),
        );

        return match (true) {
            (int) ($openExceptionsByProject[$projectId] ?? 0) > 0, $hasCriticalFinding => 'critical',
            $findings->isNotEmpty() => 'warning',
            default => 'good',
        };
    }
}
