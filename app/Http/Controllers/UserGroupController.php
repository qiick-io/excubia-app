<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\DeleteUserGroupAction;
use App\Actions\SaveUserGroupAction;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UserGroupRequest;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

/**
 * Admin pages for user groups and their members, behind `groups.manage` (UserGroupPolicy).
 */
class UserGroupController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', UserGroup::class);

        return Inertia::render('groups/index', [
            'groups' => UserGroup::query()
                ->with(['roles:id,name', 'users:id'])
                ->orderBy('name')
                ->get()
                ->map(fn (UserGroup $group): array => $this->present($group)),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', UserGroup::class);

        return $this->form(null);
    }

    public function store(UserGroupRequest $request, SaveUserGroupAction $saveGroup): RedirectResponse
    {
        $this->save($request, new UserGroup, $saveGroup);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Group created.')]);

        return to_route('groups.index');
    }

    public function edit(UserGroup $group): Response
    {
        Gate::authorize('update', $group);

        return $this->form($group->load(['roles:id,name', 'users:id']));
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

    private function form(?UserGroup $group): Response
    {
        $actor = request()->user();

        return Inertia::render('groups/edit', [
            'group' => $group === null ? null : $this->present($group),
            'roles' => StoreUserRequest::assignableRolesFor($actor instanceof User ? $actor : null),
            'users' => User::query()->orderBy('name')->get(['id', 'name', 'email']),
        ]);
    }

    /**
     * @return array{id: int, name: string, description: string|null, roles: list<string>, users: list<int>, can_update: bool}
     */
    private function present(UserGroup $group): array
    {
        return [
            'id' => $group->id,
            'name' => $group->name,
            'description' => $group->description,
            'roles' => array_values($group->roles->map(fn (Role $role): string => $role->name)->all()),
            'users' => array_values($group->users->map(fn (User $user): int => $user->id)->all()),
            'can_update' => Gate::allows('update', $group),
        ];
    }
}
