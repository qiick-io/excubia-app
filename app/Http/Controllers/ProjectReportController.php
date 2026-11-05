<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\HealthReportResource;
use App\Http\Resources\ProjectResource;
use App\Jobs\RunSystemReportJob;
use App\Models\Project;
use App\Services\FixSuggestionService;
use App\Services\ReportFindingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Project page (`/projects/{slug}`): the findings and upgrade plan of the latest report, and
 * the summaries of the reports kept in the history. The system project (#70) can be refreshed
 * from here.
 */
class ProjectReportController extends Controller
{
    public function show(Request $request, Project $project, ReportFindingsService $findings, FixSuggestionService $fixes): Response
    {
        Gate::authorize('view', $project);

        $project->load('latestReport');

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
            'canRefreshReport' => (bool) $request->user()?->can('refreshReport', $project),
            'report' => $project->latestReport?->report,
            'findingFixes' => (object) $findingFixes,
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
}
