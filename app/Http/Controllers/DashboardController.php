<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Services\DashboardOverviewService;
use App\Services\FindingsTrendService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Home page (#77): the issue-first overview of the projects the user may see, scoped to one
 * project and a period from the URL (`?project=slug&period=14`). An unknown project or period
 * falls back to all projects and 14 days. Users without `can-show-projects` see the page without
 * projects.
 */
class DashboardController extends Controller
{
    /** @var list<int> */
    public const PERIODS = [7, 14, 30, 90];

    public const DEFAULT_PERIOD = 14;

    public function index(Request $request, DashboardOverviewService $overview, FindingsTrendService $trend): Response
    {
        $canViewProjects = (bool) $request->user()?->can('viewAny', Project::class);
        $period = in_array($request->integer('period'), self::PERIODS, true) ? $request->integer('period') : self::DEFAULT_PERIOD;

        if (! $canViewProjects) {
            return Inertia::render('dashboard', [
                'canViewProjects' => false,
                'projects' => [],
                'scope' => ['project' => null, 'period' => $period],
                'overview' => null,
                'trend' => [],
            ]);
        }

        $projects = Project::query()->visibleTo($request->user())->with('latestReport')->orderBy('name')->get();
        $selected = $projects->firstWhere('slug', $request->string('project')->toString());
        $scoped = $selected === null ? $projects : $projects->where('id', $selected->id)->values();

        return Inertia::render('dashboard', [
            'canViewProjects' => true,
            'projects' => ProjectResource::collection($projects)->resolve(),
            'scope' => ['project' => $selected?->slug, 'period' => $period],
            'overview' => $overview->overview($scoped, $period),
            'trend' => $trend->daily($scoped, $period),
        ]);
    }
}
