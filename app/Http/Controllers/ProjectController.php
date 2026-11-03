<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateProjectAction;
use App\Actions\DeleteProjectAction;
use App\Actions\RevokeProjectTokenAction;
use App\Actions\RotateProjectTokenAction;
use App\Actions\UpdateProjectAction;
use App\Http\Requests\ListQueryRequest;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Project registration (ProjectPolicy). Create and edit happen in a drawer on the list page.
 * A new push token is flashed once as `pushToken` and never stored in plain text.
 */
class ProjectController extends Controller
{
    /**
     * @var list<string>
     */
    private const SORTABLE_COLUMNS = ['name', 'last_report_at', 'created_at'];

    public function index(ListQueryRequest $request): Response
    {
        Gate::authorize('viewAny', Project::class);

        $sort = $request->sortColumn(self::SORTABLE_COLUMNS, 'name');
        $direction = $request->sortDirection('asc');

        $projects = $request->applySearch(Project::query(), ['name', 'slug'])
            ->orderBy($sort, $direction)
            ->orderBy('id')
            ->paginate($request->perPage())
            ->withQueryString();

        return Inertia::render('projects/index', [
            'projects' => ProjectResource::collection($projects),
            'filters' => $request->filters($sort, $direction),
        ]);
    }

    public function store(StoreProjectRequest $request, CreateProjectAction $createProject, RotateProjectTokenAction $rotateToken): RedirectResponse
    {
        $project = $createProject->execute(
            $request->string('name')->trim()->toString(),
            $request->string('slug')->toString(),
            $request->boolean('is_enabled', true),
        );

        $this->flashToken($project, $rotateToken->execute($project));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Project created.')]);

        return to_route('projects.index');
    }

    public function update(UpdateProjectRequest $request, Project $project, UpdateProjectAction $updateProject): RedirectResponse
    {
        $updateProject->execute($project, $request->string('name')->trim()->toString(), $request->boolean('is_enabled'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Project updated.')]);

        return to_route('projects.index');
    }

    public function destroy(Project $project, DeleteProjectAction $deleteProject): RedirectResponse
    {
        Gate::authorize('delete', $project);

        $deleteProject->execute($project);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Project deleted.')]);

        return to_route('projects.index');
    }

    public function rotateToken(Project $project, RotateProjectTokenAction $rotateToken): RedirectResponse
    {
        Gate::authorize('update', $project);

        $this->flashToken($project, $rotateToken->execute($project));

        return to_route('projects.index');
    }

    public function revokeToken(Project $project, RevokeProjectTokenAction $revokeToken): RedirectResponse
    {
        Gate::authorize('update', $project);

        $revokeToken->execute($project);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Push token revoked.')]);

        return to_route('projects.index');
    }

    /**
     * Flashes the plain token for one response only, with the URL the project pushes to.
     */
    private function flashToken(Project $project, string $token): void
    {
        Inertia::flash('pushToken', [
            'project' => $project->name,
            'token' => $token,
            'url' => url('/'),
        ]);
    }
}
