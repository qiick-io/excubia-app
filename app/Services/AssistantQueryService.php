<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SeverityEnum;
use App\Http\Resources\HealthReportResource;
use App\Models\HealthReport;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;

/**
 * The read-only data behind the assistant tools (#17). Every method returns plain arrays built
 * from stored reports: project names and slugs, report summaries and findings. Push tokens,
 * user data and file paths are never part of them. Projects restricted to admins (#70) are
 * left out unless the asking user is an admin.
 */
class AssistantQueryService
{
    /** Most findings one search returns, so a tool result stays small. */
    public const SEARCH_LIMIT = 100;

    private ?User $user = null;

    public function __construct(
        private ProjectStalenessService $staleness,
        private ReportFindingsService $findings,
    ) {}

    /**
     * The same queries, limited to the projects this user may see.
     */
    public function forUser(User $user): static
    {
        $scoped = clone $this;
        $scoped->user = $user;

        return $scoped;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function projects(): array
    {
        return array_values($this->visibleProjects()->with('latestReport')->orderBy('name')->get()
            ->map(fn (Project $project): array => [
                ...$this->projectSummary($project),
                'latestReport' => $project->latestReport === null ? null : $this->reportSummary($project->latestReport),
            ])
            ->all());
    }

    /**
     * @return array<string, mixed>|null null when the project does not exist
     */
    public function latestReport(string $slug): ?array
    {
        $project = $this->project($slug);

        if ($project === null) {
            return null;
        }

        $report = $project->latestReport;

        return [
            'project' => $this->projectSummary($project),
            'report' => $report === null ? null : [
                ...$this->reportSummary($report),
                'findings' => $this->findings->issues($report),
                'skippedChecks' => collect((array) ($report->report['checks'] ?? []))
                    ->where('status', 'skipped')
                    ->pluck('name')
                    ->values()
                    ->all(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null null when the project does not exist
     */
    public function upgradePlan(string $slug): ?array
    {
        $project = $this->project($slug);

        if ($project === null) {
            return null;
        }

        return [
            'project' => $this->projectSummary($project),
            'upgradePlan' => $project->latestReport?->report['upgradePlan'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>|null null when the project does not exist
     */
    public function reportHistory(string $slug, int $limit): ?array
    {
        $project = $this->project($slug);

        if ($project === null) {
            return null;
        }

        return [
            'project' => $this->projectSummary($project),
            'reports' => $project->healthReports()->orderByDesc('received_at')->orderByDesc('id')->limit($limit)->get()
                ->map(fn (HealthReport $report): array => $this->reportSummary($report))
                ->all(),
        ];
    }

    /**
     * New and resolved findings between two reports of a project, by default the latest two.
     *
     * @return array<string, mixed>|null null when the project or a report does not exist
     */
    public function compareReports(string $slug, ?int $fromReportId, ?int $toReportId): ?array
    {
        $project = $this->project($slug);

        if ($project === null) {
            return null;
        }

        $reports = $project->healthReports()->orderByDesc('received_at')->orderByDesc('id');
        $to = $toReportId === null ? (clone $reports)->first() : (clone $reports)->find($toReportId);
        // Without a "from", the report before "to" (none when there is no "to" either).
        $from = match (true) {
            $fromReportId !== null => (clone $reports)->find($fromReportId),
            $to instanceof HealthReport => (clone $reports)->where('id', '!=', $to->id)->where('received_at', '<=', $to->received_at)->first(),
            default => null,
        };

        if (! $to instanceof HealthReport || ! $from instanceof HealthReport) {
            return null;
        }

        return [
            'project' => $this->projectSummary($project),
            'from' => $this->reportSummary($from),
            'to' => $this->reportSummary($to),
            ...$this->findings->compare($from, $to),
        ];
    }

    /**
     * Findings that warn or fail across projects, latest report of each project only, or every
     * report received in a date range. Filters are optional; `package` matches the subject.
     *
     * @param  array{project?: string|null, minimumSeverity?: string|null, package?: string|null, advisoryId?: string|null, checkId?: string|null, from?: string|null, to?: string|null}  $filters
     * @return array{findings: list<array<string, mixed>>, isTruncated: bool}
     */
    public function searchFindings(array $filters): array
    {
        $minimum = SeverityEnum::tryFrom((string) ($filters['minimumSeverity'] ?? ''));
        $package = mb_strtolower(trim((string) ($filters['package'] ?? '')));
        $isRange = ($filters['from'] ?? null) !== null || ($filters['to'] ?? null) !== null;

        $projectSlug = $filters['project'] ?? null;
        $projects = $this->visibleProjects()
            ->when($projectSlug !== null, fn ($query) => $query->where('slug', $projectSlug))
            ->with('latestReport')
            ->orderBy('name')
            ->get();

        $matches = [];

        foreach ($projects as $project) {
            $reports = $isRange
                ? $project->healthReports()
                    ->when($filters['from'] ?? null, fn ($query, $from) => $query->where('received_at', '>=', Date::parse($from)->startOfDay()))
                    ->when($filters['to'] ?? null, fn ($query, $to) => $query->where('received_at', '<=', Date::parse($to)->endOfDay()))
                    ->orderByDesc('received_at')
                    ->get()
                : collect([$project->latestReport])->filter();

            foreach ($reports as $report) {
                foreach ($this->findings->issues($report) as $finding) {
                    $severity = SeverityEnum::tryFrom((string) ($finding['severity'] ?? ''));

                    if (
                        ($minimum !== null && ($severity === null || $severity->rank() > $minimum->rank()))
                        || ($package !== '' && ! str_contains(mb_strtolower((string) ($finding['subject'] ?? '')), $package))
                        || (($filters['advisoryId'] ?? null) !== null && ($finding['advisoryId'] ?? null) !== $filters['advisoryId'])
                        || (($filters['checkId'] ?? null) !== null && ($finding['checkId'] ?? null) !== $filters['checkId'])
                    ) {
                        continue;
                    }

                    if (count($matches) === self::SEARCH_LIMIT) {
                        return ['findings' => $matches, 'isTruncated' => true];
                    }

                    $matches[] = ['project' => $project->slug, 'reportId' => $report->id, 'receivedAt' => $report->received_at->toIso8601String(), ...$finding];
                }
            }
        }

        return ['findings' => $matches, 'isTruncated' => false];
    }

    /**
     * @return Builder<Project>
     */
    private function visibleProjects(): Builder
    {
        return Project::query()->visibleTo($this->user);
    }

    private function project(string $slug): ?Project
    {
        return $this->visibleProjects()->with('latestReport')->where('slug', $slug)->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function projectSummary(Project $project): array
    {
        return [
            'name' => $project->name,
            'slug' => $project->slug,
            'isEnabled' => $project->is_enabled,
            'isStale' => $this->staleness->isStale($project),
            'lastReportAt' => $project->last_report_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reportSummary(HealthReport $report): array
    {
        return (new HealthReportResource($report))->resolve();
    }
}
