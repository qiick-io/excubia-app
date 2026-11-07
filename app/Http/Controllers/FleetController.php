<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\FleetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Fleet pages (`/fleet/{view}`): upgrade readiness (#19), advisory exposure (#20) and the
 * end-of-life calendar (#23) across the projects the user may see, behind `can-show-projects`.
 * Only the open view is computed.
 */
class FleetController extends Controller
{
    /**
     * The Fleet tabs; the first is `/fleet`.
     *
     * @var list<string>
     */
    public const VIEWS = ['readiness', 'advisories', 'eol'];

    public function show(Request $request, FleetService $fleet, string $view = 'readiness'): Response
    {
        Gate::authorize('viewAny', Project::class);

        $projects = Project::query()->visibleTo($request->user())->with('latestReport')->orderBy('name')->get();

        return Inertia::render('fleet/index', [
            'view' => $view,
            'readiness' => $view === 'readiness' ? $fleet->upgradeReadiness($projects) : null,
            'advisories' => $view === 'advisories' ? $fleet->advisoryExposure($projects) : null,
            // Pre-fills the search, so a link can open the page on one package or advisory.
            'search' => $request->string('search')->limit(255, '')->toString(),
            'calendar' => $view === 'eol' ? $fleet->endOfLifeCalendar($projects) : null,
        ]);
    }
}
