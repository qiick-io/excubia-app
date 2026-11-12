<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateProjectAction;
use App\Actions\DeleteProjectAction;
use App\Actions\RotateProjectTokenAction;
use App\Actions\UpdateProjectAction;
use App\Enums\ProjectVisibilityEnum;
use App\Http\Requests\ListQueryRequest;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Services\EffectivePermissionResolverService;
use App\Services\ProjectListService;
use App\Traits\FlashesPushTokenTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Project registration (ProjectPolicy). Create and edit happen in a drawer on the list page.
 * A new push token is flashed once as `pushToken` and never stored in plain text; issuing a new
 * one later and revoking it live in ProjectTokenController.
 */
class ProjectController extends Controller
{
    use FlashesPushTokenTrait;

    /**
     * The project list, or as JSON (`expectsJson`) the paginator the registry picker of the
     * project group drawer reads (#252). Each row carries its health, failed checks and open
     * findings (#293), sortable on the server.
     */
    public function index(ListQueryRequest $request, EffectivePermissionResolverService $permissions, ProjectListService $projectList): Response|JsonResponse
    {
        Gate::authorize('viewAny', Project::class);

        $sort = $request->sortColumn(ProjectListService::SORTABLE_COLUMNS, 'name');
        $direction = $request->sortDirection('asc');

        $projects = $projectList->sort($request->applySearch($projectList->query($request->user()), ['name', 'slug']), $sort, $direction)
            ->paginate($request->perPage())
            ->withQueryString();

        if ($request->expectsJson()) {
            return ProjectResource::collection($projects)->response($request);
        }

        return Inertia::render('projects/index', [
            'projects' => ProjectResource::collection($projects),
            'filters' => $request->filters($sort, $direction),
            // Only admins restrict a project to admins (ProjectPolicy::changeVisibility).
            'canChangeVisibility' => $request->user()?->can('create', Project::class) === true
                && $permissions->isAdmin($request->user()),
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
        $updateProject->execute(
            $project,
            $request->string('name')->trim()->toString(),
            $request->boolean('is_enabled'),
            $request->enum('visibility', ProjectVisibilityEnum::class),
        );

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
}
