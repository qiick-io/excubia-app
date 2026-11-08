<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Services\DashboardOverviewService;
use App\Services\FindingsTrendService;
use App\Services\HealthStatusService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Home page (#77): the issue-first overview of the projects the user may see, scoped to one
 * project and a period from the URL (`?project=slug&period=14`). An unknown project or period
 * falls back to all projects and 14 days. Users without `can-show-projects` see the page without
 * projects. The project list shows each project's live health (#143), which the page polls on
 * its own: every prop is a closure, so that partial reload computes `liveHealth` only.
 */
class DashboardController extends Controller
{
    /** @var list<int> */
    public const PERIODS = [7, 14, 30, 90];

    public const DEFAULT_PERIOD = 14;

    public function index(Request $request, DashboardOverviewService $overview, FindingsTrendService $trend, HealthStatusService $health): Response
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
                'liveHealth' => (object) [],
            ]);
        }

        $projects = Project::query()->visibleTo($request->user())->orderBy('name')->get();
        $selected = $projects->firstWhere('slug', $request->string('project')->toString());
        $scoped = fn (): Collection => ($selected === null ? $projects : $projects->where('id', $selected->id)->values())->loadMissing('latestReport');

        return Inertia::render('dashboard', [
            'canViewProjects' => true,
            'projects' => fn (): array => ProjectResource::collection($projects->loadMissing('latestReport'))->resolve(),
            'scope' => ['project' => $selected?->slug, 'period' => $period],
            'overview' => fn (): array => $overview->overview($scoped(), $period),
            'trend' => fn (): array => $trend->daily($scoped(), $period),
            'liveHealth' => fn (): object => (object) $health->summaries($projects),
        ]);
    }
}
