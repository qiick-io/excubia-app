<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\UpdateExceptionStatusAction;
use App\Enums\ExceptionStatusEnum;
use App\Enums\PermissionEnum;
use App\Enums\TimeRangeEnum;
use App\Http\Requests\UpdateExceptionStatusRequest;
use App\Http\Resources\HealthReportResource;
use App\Http\Resources\ProjectResource;
use App\Jobs\RunSystemReportJob;
use App\Models\ExceptionGroup;
use App\Models\HealthReport;
use App\Models\Project;
use App\Services\FixSuggestionService;
use App\Services\HealthStatusService;
use App\Services\ProjectAlertsService;
use App\Services\ProjectRuntimeService;
use App\Services\ProjectTelemetryService;
use App\Services\ReportFindingsService;
use App\Services\ReportHealthService;
use App\Services\TimeSeriesService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Project page (`/projects/{slug}`): the findings, health summary, licenses and upgrade plan of
 * the latest report, and the summaries of the reports kept in the history. The system project
 * (#70) can be refreshed from here. The runtime, exceptions and schedule tabs cover the time
 * range of `?range=` (#113); the overview keeps the default range and every exception group.
 */
class ProjectReportController extends Controller
{
    /**
     * The project's tabs after the overview (`/projects/{slug}/{view}`).
     *
     * @var list<string>
     */
    public const VIEWS = ['findings', 'licenses', 'runtime', 'exceptions', 'schedule', 'alerts', 'plan', 'history'];

    /** The tabs with a time range picker (#113). */
    public const RANGE_VIEWS = ['runtime', 'exceptions', 'schedule'];

    public function show(Request $request, Project $project, ReportFindingsService $findings, FixSuggestionService $fixes, ProjectRuntimeService $runtime, ProjectTelemetryService $telemetry, TimeSeriesService $timeSeries, ReportHealthService $health, ?string $view = null): Response|RedirectResponse
    {
        Gate::authorize('view', $project);

        // Links from before the tabs had their own URLs used `?view=`.
        if ($view === null && in_array($request->query('view'), self::VIEWS, true)) {
            return to_route('projects.view', ['project' => $project->slug, 'view' => $request->query('view')]);
        }

        // Loaded by the first prop that needs it, so the poll does not read the report.
        $report = fn (): ?HealthReport => $project->loadMissing('latestReport')->latestReport;
        $hasRange = in_array($view, self::RANGE_VIEWS, true);
        $range = $hasRange ? ($request->enum('range', TimeRangeEnum::class) ?? TimeRangeEnum::DEFAULT) : TimeRangeEnum::DEFAULT;
        $now = CarbonImmutable::instance(Date::now());
        $scheduleData = null;
        // Shared by `schedule` and `scheduleTimeline`, computed once.
        $schedule = function () use (&$scheduleData, $view, $telemetry, $project, $range, $now): array {
            return $scheduleData ??= $view === 'schedule' ? $telemetry->schedule($project, $range, $now) : ['tasks' => [], 'timeline' => []];
        };
        // A ready command and prompt per finding that warns or fails (#78), keyed like
        // ReportFindingsService::identity() so the page can match its rows.
        $findingFixes = function () use ($report, $findings, $fixes): object {
            $byIdentity = [];

            foreach (($latest = $report()) === null ? [] : $findings->issues($latest) as $finding) {
                $byIdentity[$findings->identity($finding)] = $fixes->forFinding($finding);
            }

            return (object) $byIdentity;
        };

        // Every prop is a closure, so the overview's minute poll of `liveHealth` (#143), a
        // partial reload, computes that prop only.
        return Inertia::render('projects/show', [
            'project' => fn (): array => (new ProjectResource($project->loadMissing('latestReport')))->resolve(),
            'view' => $view ?? 'overview',
            'canRefreshReport' => fn (): bool => (bool) $request->user()?->can('refreshReport', $project),
            'report' => fn (): ?array => $report()?->report,
            'findingFixes' => $findingFixes,
            // The issues in page order with their check group, and the health summary (#108, #109).
            'issues' => fn (): array => ($latest = $report()) === null ? [] : $findings->prioritized($latest),
            'health' => fn (): ?array => ($latest = $report()) === null ? null : $health->summary($latest->report),
            // Minute-level status per check and server (#115), on the overview, which polls it.
            'liveHealth' => fn (): array => $view === null ? app(HealthStatusService::class)->forPage($project, $now) : [],
            'range' => $range->value,
            'runtime' => fn (): ?array => $runtime->summary($project, $range),
            'exceptions' => fn (): array => $telemetry->exceptions($project, $view === 'exceptions' ? $range : null, $now),
            // Tells "none in this range" from "none yet" on the Exceptions tab.
            'hasExceptions' => fn (): bool => $view === 'exceptions' && $project->exceptionGroups()->exists(),
            // Schedule inventory, status and runs (#110), only on its tab.
            'schedule' => fn (): array => $schedule()['tasks'],
            'scheduleTimeline' => fn (): array => $schedule()['timeline'],
            // Chart markers of the exceptions and schedule tabs; the runtime has its own.
            'deploys' => fn (): array => in_array($view, ['exceptions', 'schedule'], true) ? $timeSeries->deploys($project, $range, $now) : [],
            // Alert history, mutes and maintenance windows (#114), only on its tab.
            'alerts' => fn (): ?array => $view === 'alerts' ? app(ProjectAlertsService::class)->forPage($project) : null,
            'canManageAlerts' => fn (): bool => (bool) $request->user()?->can('update', $project),
            'canManageExceptions' => fn (): bool => (bool) $request->user()?->can(PermissionEnum::CanManageExceptions->value),
            'reports' => fn (): array => HealthReportResource::collection(
                $project->healthReports()->orderByDesc('received_at')->orderByDesc('id')->get(),
            )->resolve(),
        ]);
    }

    /**
     * Runs the system report now instead of waiting for the daily schedule.
     */
    public function refresh(Project $project): RedirectResponse
    {
        Gate::authorize('refreshReport', $project);

        RunSystemReportJob::dispatch();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('System report started.')]);

        return to_route('projects.show', $project->slug);
    }

    /**
     * Resolves, ignores or reopens an exception group (#26).
     */
    public function updateException(UpdateExceptionStatusRequest $request, Project $project, ExceptionGroup $exceptionGroup, UpdateExceptionStatusAction $updateStatus): RedirectResponse
    {
        $updateStatus->execute($exceptionGroup, $request->enum('status', ExceptionStatusEnum::class) ?? ExceptionStatusEnum::Open);

        return back();
    }
}
