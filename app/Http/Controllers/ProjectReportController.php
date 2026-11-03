<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\HealthReportResource;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Project page (`/projects/{slug}`): the findings and upgrade plan of the latest report, and
 * the summaries of the reports kept in the history.
 */
class ProjectReportController extends Controller
{
    public function show(Project $project): Response
    {
        Gate::authorize('view', $project);

        $project->load('latestReport');

        return Inertia::render('projects/show', [
            'project' => (new ProjectResource($project))->resolve(),
            'report' => $project->latestReport?->report,
            'reports' => HealthReportResource::collection(
                $project->healthReports()->orderByDesc('received_at')->orderByDesc('id')->get(),
            )->resolve(),
        ]);
    }
}
