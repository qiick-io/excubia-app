<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\DeleteUserGroupAction;
use App\Actions\SaveUserGroupAction;
use App\Http\Requests\ListQueryRequest;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UserGroupRequest;
use App\Http\Resources\UserGroupResource;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin pages for user groups and their members, behind `can-*-groups` (UserGroupPolicy).
 * Create and edit happen in a drawer on the list page.
 */
class UserGroupController extends Controller
{
    /**
     * @var list<string>
     */
    private const SORTABLE_COLUMNS = ['name', 'created_at', 'updated_at'];

    /**
     * Lists groups with search, sort and pagination, plus the roles the actor may assign.
     */
    public function index(ListQueryRequest $request): Response
    {
        Gate::authorize('viewAny', UserGroup::class);

        $actor = $request->user();
        $sort = $request->sortColumn(self::SORTABLE_COLUMNS, 'name');
        $direction = $request->sortDirection('asc');

        $groups = $request->applySearch(UserGroup::query(), ['name', 'description'])
            ->with(['roles:id,name', 'users:id,first_name,last_name,email'])
            ->withCount('users')
            ->orderBy($sort, $direction)
            ->orderBy('id')
            ->paginate($request->perPage())
            ->withQueryString();

        return Inertia::render('admin/groups/index', [
            'groups' => UserGroupResource::collection($groups),
            'filters' => $request->filters($sort, $direction),
            'roles' => StoreUserRequest::assignableRoleOptionsFor($actor instanceof User ? $actor : null),
        ]);
    }

    public function store(UserGroupRequest $request, SaveUserGroupAction $saveGroup): RedirectResponse
    {
        $this->save($request, new UserGroup, $saveGroup);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Group created.')]);

        return to_route('groups.index');
    }

    public function update(UserGroupRequest $request, UserGroup $group, SaveUserGroupAction $saveGroup): RedirectResponse
    {
        $this->save($request, $group, $saveGroup);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Group updated.')]);

        return to_route('groups.index');
    }

    public function destroy(UserGroup $group, DeleteUserGroupAction $deleteGroup): RedirectResponse
    {
        Gate::authorize('delete', $group);

        $deleteGroup->execute($group);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Group deleted.')]);

        return to_route('groups.index');
    }

    private function save(UserGroupRequest $request, UserGroup $group, SaveUserGroupAction $saveGroup): void
    {
        $description = $request->string('description')->trim()->toString();

        $saveGroup->execute(
            $group,
            $request->string('name')->toString(),
            $description === '' ? null : $description,
            $request->roles(),
            $request->userIds(),
        );
    }
}
