<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ExceptionStatusEnum;
use App\Enums\SeverityEnum;
use App\Models\ExceptionGroup;
use App\Models\Finding;
use App\Models\Project;

/**
 * What is open on each tab of a project page (#275), for the counts and severity markers of
 * the grouped view tabs (app-ui optional pattern `grouped-tabs`). Two small queries, whichever
 * tab is open, so the tabs say where the problems are before anyone opens them.
 */
final class ProjectViewCountsService
{
    /**
     * Severities that mark a tab as critical; any other open finding marks it as a warning.
     */
    private const array CRITICAL_SEVERITIES = [SeverityEnum::Critical, SeverityEnum::High];

    /**
     * @return array{exceptions: array{count: int, severity: 'critical'|null}, findings: array{count: int, severity: 'critical'|'warning'|null}}
     */
    public function forProject(Project $project): array
    {
        $openExceptionGroups = ExceptionGroup::query()
            ->where('project_id', $project->id)
            ->where('status', ExceptionStatusEnum::Open->value)
            ->count();
        $activeFindingsBySeverity = Finding::query()
            ->where('project_id', $project->id)
            ->active()
            ->selectRaw('severity, count(*) as aggregate')
            ->groupBy('severity')
            ->pluck('aggregate', 'severity');
        $activeFindings = (int) $activeFindingsBySeverity->sum();
        $hasCriticalFinding = collect(self::CRITICAL_SEVERITIES)
            ->contains(fn (SeverityEnum $severity): bool => (int) ($activeFindingsBySeverity[$severity->value] ?? 0) > 0);

        return [
            // An open exception is an error happening in production: always critical.
            'exceptions' => ['count' => $openExceptionGroups, 'severity' => $openExceptionGroups > 0 ? 'critical' : null],
            'findings' => [
                'count' => $activeFindings,
                'severity' => match (true) {
                    $hasCriticalFinding => 'critical',
                    $activeFindings > 0 => 'warning',
                    default => null,
                },
            ],
        ];
    }
}
