<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\TimeRangeData;
use App\Enums\ExceptionStatusEnum;
use App\Enums\SeverityEnum;
use App\Enums\TimeRangeEnum;
use App\Http\Resources\HealthReportResource;
use App\Models\HealthReport;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Date;

/**
 * The read-only data behind the assistant and MCP tools (#17, #234). Every method returns plain
 * arrays built from stored reports and telemetry: project names and slugs, report summaries,
 * findings, exception groups, runtime tables and scheduled tasks, each narrowed to an allowlist
 * of fields. Push tokens, user data and file paths are never part of them. Projects restricted to admins (#70) are
 * left out unless the asking user is an admin.
 */
class AssistantQueryService
{
    /** Most findings one search returns, so a tool result stays small. */
    public const SEARCH_LIMIT = 100;

    /** Most exception groups one call returns (ProjectTelemetryService reads at most 100). */
    public const EXCEPTION_GROUP_LIMIT = 100;

    /**
     * The fields of an exception group a tool returns. Frames and previous exceptions (file
     * paths), the fix prompt (built from the frames) and the assignee (user data) stay out.
     */
    private const EXCEPTION_GROUP_FIELDS = ['id', 'class', 'message', 'environment', 'context', 'count', 'range_count', 'first_seen_at', 'last_seen_at', 'first_release', 'last_release', 'regressed_release', 'status', 'resolved_at'];

    /**
     * The tables of the Runtime tab a tool returns. Charts stay out (too large), and so do the
     * deprecations (source file paths), servers (host names) and deploys.
     */
    private const RUNTIME_FIELDS = ['range', 'isSampled', 'requests', 'slowQueries', 'jobs', 'queueWait', 'scheduledTasks', 'outgoingHttp', 'commands', 'cache', 'mail', 'notifications'];

    /** The fields of a scheduled task a tool returns, as the Schedule tab shows them. */
    private const SCHEDULED_TASK_FIELDS = ['task', 'expression', 'timezone', 'environments', 'is_heartbeat', 'status', 'is_low_confidence', 'last_due_at', 'next_due_at', 'last_run_at', 'last_outcome', 'status_changed_at', 'evaluated_at', 'runs'];

    private ?User $user = null;

    public function __construct(
        private ProjectStalenessService $staleness,
        private ReportFindingsService $findings,
        private ProjectTelemetryService $telemetry,
        private ProjectRuntimeService $runtime,
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
     * @return array<string, mixed>|null null when the project does not exist, an `error` when a report does not
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

        // Say why there is nothing to compare (#436): a wrong id, or no earlier report yet.
        $missingId = ($toReportId !== null && ! $to instanceof HealthReport) || ($fromReportId !== null && ! $from instanceof HealthReport);

        if ($missingId) {
            return ['error' => 'This project has no report with this id. Call GetReportHistory for the report ids.'];
        }

        if (! $to instanceof HealthReport || ! $from instanceof HealthReport) {
            return ['error' => 'This project has no earlier report to compare with yet.'];
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
     * report received in a date range. Filters are optional; `package` matches the subject. Each
     * has its `fingerprint`, which the finding write tools (#320) take.
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

                    $matches[] = ['project' => $project->slug, 'reportId' => $report->id, 'receivedAt' => $report->received_at->toIso8601String(), ...$finding, 'fingerprint' => $this->findings->fingerprint($finding)];
                }
            }
        }

        return ['findings' => $matches, 'isTruncated' => false];
    }

    /**
     * The exception groups of a project seen in the range (#234), open first, then the latest,
     * optionally of one status, with only EXCEPTION_GROUP_FIELDS.
     *
     * @return array<string, mixed>|null null when the project does not exist
     */
    public function exceptionGroups(string $slug, TimeRangeEnum $range, ?ExceptionStatusEnum $status): ?array
    {
        $project = $this->project($slug);

        if ($project === null) {
            return null;
        }

        $groups = collect($this->telemetry->exceptions($project, TimeRangeData::fromPreset($range, Date::now()->toImmutable())))
            ->when($status !== null, fn ($collection) => $collection->where('status', $status?->value))
            ->map(fn (array $group): array => Arr::only($group, self::EXCEPTION_GROUP_FIELDS))
            ->values()
            ->all();

        return [
            'project' => $this->projectSummary($project),
            'range' => $range->value,
            'exceptionGroups' => $groups,
        ];
    }

    /**
     * The runtime tables of a project over the range (#234), with only RUNTIME_FIELDS and the
     * request totals without their chart. `runtime` is null when the project never sent any.
     *
     * @return array<string, mixed>|null null when the project does not exist
     */
    public function runtimeSummary(string $slug, TimeRangeEnum $range): ?array
    {
        $project = $this->project($slug);

        if ($project === null) {
            return null;
        }

        $summary = $this->runtime->summary($project, TimeRangeData::fromPreset($range, Date::now()->toImmutable()));

        return [
            'project' => $this->projectSummary($project),
            'runtime' => $summary === null ? null : [
                ...Arr::only($summary, self::RUNTIME_FIELDS),
                'requests' => Arr::except((array) $summary['requests'], ['timeline']),
            ],
        ];
    }

    /**
     * The scheduled tasks of a project with their status and runs per outcome in the range
     * (#234), with only SCHEDULED_TASK_FIELDS.
     *
     * @return array<string, mixed>|null null when the project does not exist
     */
    public function scheduleStatus(string $slug, TimeRangeEnum $range): ?array
    {
        $project = $this->project($slug);

        if ($project === null) {
            return null;
        }

        $schedule = $this->telemetry->schedule($project, TimeRangeData::fromPreset($range, Date::now()->toImmutable()));

        return [
            'project' => $this->projectSummary($project),
            'range' => $range->value,
            'scheduledTasks' => array_map(fn (array $task): array => Arr::only($task, self::SCHEDULED_TASK_FIELDS), $schedule['tasks']),
        ];
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
