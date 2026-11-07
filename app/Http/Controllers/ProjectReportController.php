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
use App\Models\Project;
use App\Services\FixSuggestionService;
use App\Services\ProjectAlertsService;
use App\Services\ProjectRuntimeService;
use App\Services\ProjectTelemetryService;
use App\Services\ReportFindingsService;
use App\Services\TimeSeriesService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Project page (`/projects/{slug}`): the findings and upgrade plan of the latest report, and
 * the summaries of the reports kept in the history. The system project (#70) can be refreshed
 * from here. The runtime, exceptions and schedule tabs cover the time range of `?range=` (#113);
 * the overview keeps the default range and every exception group.
 */
class ProjectReportController extends Controller
{
    /**
     * The project's tabs after the overview (`/projects/{slug}/{view}`).
     *
     * @var list<string>
     */
    public const VIEWS = ['findings', 'runtime', 'exceptions', 'schedule', 'alerts', 'plan', 'history'];

    /** The tabs with a time range picker (#113). */
    public const RANGE_VIEWS = ['runtime', 'exceptions', 'schedule'];

    public function show(Request $request, Project $project, ReportFindingsService $findings, FixSuggestionService $fixes, ProjectRuntimeService $runtime, ProjectTelemetryService $telemetry, TimeSeriesService $timeSeries, ?string $view = null): Response|RedirectResponse
    {
        Gate::authorize('view', $project);

        // Links from before the tabs had their own URLs used `?view=`.
        if ($view === null && in_array($request->query('view'), self::VIEWS, true)) {
            return to_route('projects.view', ['project' => $project->slug, 'view' => $request->query('view')]);
        }

        $project->load('latestReport');
        $hasRange = in_array($view, self::RANGE_VIEWS, true);
        $range = $hasRange ? ($request->enum('range', TimeRangeEnum::class) ?? TimeRangeEnum::DEFAULT) : TimeRangeEnum::DEFAULT;
        $now = CarbonImmutable::instance(Date::now());
        $schedule = $view === 'schedule' ? $telemetry->schedule($project, $range, $now) : null;

        // A ready command and prompt per finding that warns or fails (#78), keyed like
        // ReportFindingsService::identity() so the page can match its rows.
        $findingFixes = [];

        if ($project->latestReport !== null) {
            foreach ($findings->issues($project->latestReport) as $finding) {
                $findingFixes[$findings->identity($finding)] = $fixes->forFinding($finding);
            }
        }

        return Inertia::render('projects/show', [
            'project' => (new ProjectResource($project))->resolve(),
            'view' => $view ?? 'overview',
            'canRefreshReport' => (bool) $request->user()?->can('refreshReport', $project),
            'report' => $project->latestReport?->report,
            'findingFixes' => (object) $findingFixes,
            'range' => $range->value,
            'runtime' => $runtime->summary($project, $range),
            'exceptions' => $telemetry->exceptions($project, $view === 'exceptions' ? $range : null, $now),
            // Tells "none in this range" from "none yet" on the Exceptions tab.
            'hasExceptions' => $view === 'exceptions' && $project->exceptionGroups()->exists(),
            // Schedule inventory, status and runs (#110), only on its tab.
            'schedule' => $schedule['tasks'] ?? [],
            'scheduleTimeline' => $schedule['timeline'] ?? [],
            // Chart markers of the exceptions and schedule tabs; the runtime has its own.
            'deploys' => in_array($view, ['exceptions', 'schedule'], true) ? $timeSeries->deploys($project, $range, $now) : [],
            // Alert history, mutes and maintenance windows (#114), only on its tab.
            'alerts' => $view === 'alerts' ? app(ProjectAlertsService::class)->forPage($project) : null,
            'canManageAlerts' => (bool) $request->user()?->can('update', $project),
            'canManageExceptions' => (bool) $request->user()?->can(PermissionEnum::CanManageExceptions->value),
            'reports' => HealthReportResource::collection(
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
