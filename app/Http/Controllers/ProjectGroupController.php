<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\SaveProjectGroupAction;
use App\Http\Requests\ListQueryRequest;
use App\Http\Requests\ProjectGroupRequest;
use App\Http\Resources\ProjectGroupResource;
use App\Models\ProjectGroup;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin pages for project groups (#252), behind `can-*-project-groups` (ProjectGroupPolicy).
 * Create and edit happen in a drawer on the list page; each group lists only the projects the
 * actor can see.
 */
class ProjectGroupController extends Controller
{
    /**
     * @var list<string>
     */
    private const SORTABLE_COLUMNS = ['name', 'created_at', 'updated_at'];

    public function index(ListQueryRequest $request): Response
    {
        Gate::authorize('viewAny', ProjectGroup::class);

        $actor = $request->user();
        $sort = $request->sortColumn(self::SORTABLE_COLUMNS, 'name');
        $direction = $request->sortDirection('asc');

        $projectGroups = $request->applySearch(ProjectGroup::query(), ['name', 'description'])
            ->with(['projects' => fn ($projects) => $projects->visibleTo($actor instanceof User ? $actor : null)->orderBy('name')->select(['projects.id', 'projects.name'])])
            ->orderBy($sort, $direction)
            ->orderBy('id')
            ->paginate($request->perPage())
            ->withQueryString();

        return Inertia::render('admin/project-groups/index', [
            'projectGroups' => ProjectGroupResource::collection($projectGroups),
            'filters' => $request->filters($sort, $direction),
        ]);
    }

    public function store(ProjectGroupRequest $request, SaveProjectGroupAction $saveProjectGroup): RedirectResponse
    {
        $this->save($request, new ProjectGroup, $saveProjectGroup);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Project group created.')]);

        return to_route('project-groups.index');
    }

    public function update(ProjectGroupRequest $request, ProjectGroup $projectGroup, SaveProjectGroupAction $saveProjectGroup): RedirectResponse
    {
        $this->save($request, $projectGroup, $saveProjectGroup);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Project group updated.')]);

        return to_route('project-groups.index');
    }

    public function destroy(ProjectGroup $projectGroup): RedirectResponse
    {
        Gate::authorize('delete', $projectGroup);

        $projectGroup->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Project group deleted.')]);

        return to_route('project-groups.index');
    }

    private function save(ProjectGroupRequest $request, ProjectGroup $projectGroup, SaveProjectGroupAction $saveProjectGroup): void
    {
        $description = $request->string('description')->trim()->toString();
        $actor = $request->user();

        $saveProjectGroup->execute(
            $projectGroup,
            $actor instanceof User ? $actor : null,
            $request->string('name')->trim()->toString(),
            $description === '' ? null : $description,
            $request->projectIds(),
        );
    }
}
