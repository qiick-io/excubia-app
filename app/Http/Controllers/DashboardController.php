<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\ProjectResource;
use App\Models\Project;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Home page: one entry per project with the summary of its latest report. Users without
 * `projects.view` see the page without projects.
 */
class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $canViewProjects = (bool) $request->user()?->can('viewAny', Project::class);

        $projects = $canViewProjects
            ? Project::query()->with('latestReport')->orderBy('name')->get()
            : collect();

        return Inertia::render('dashboard', [
            'projects' => ProjectResource::collection($projects)->resolve(),
            'canViewProjects' => $canViewProjects,
        ]);
    }
}
