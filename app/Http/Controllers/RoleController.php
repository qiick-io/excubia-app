<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\SaveRoleAction;
use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Http\Requests\RoleRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Admin pages for roles. Built-in roles are shown read-only; custom roles can be edited and deleted.
 */
class RoleController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', Role::class);

        return Inertia::render('roles/index', [
            'roles' => Role::query()
                ->with('permissions:id,name')
                ->withCount('users')
                ->orderBy('name')
                ->get()
                ->map(fn (Role $role): array => $this->present($role)),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Role::class);

        return $this->form(null);
    }

    public function store(RoleRequest $request, SaveRoleAction $saveRole): RedirectResponse
    {
        $saveRole->execute(new Role, $request->string('name')->toString(), $request->permissions());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role created.')]);

        return to_route('roles.index');
    }

    public function edit(Role $role): Response
    {
        Gate::authorize('viewAny', Role::class);

        return $this->form($role->load('permissions:id,name'));
    }

    public function update(RoleRequest $request, Role $role, SaveRoleAction $saveRole): RedirectResponse
    {
        $saveRole->execute($role, $request->string('name')->toString(), $request->permissions());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role updated.')]);

        return to_route('roles.index');
    }

    public function destroy(Role $role): RedirectResponse
    {
        Gate::authorize('delete', $role);

        $role->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role deleted.')]);

        return to_route('roles.index');
    }

    private function form(?Role $role): Response
    {
        return Inertia::render('roles/edit', [
            'role' => $role === null ? null : $this->present($role),
            'permissions' => PermissionEnum::values(),
        ]);
    }

    /**
     * @return array{id: int|string, name: string, permissions: list<string>, users_count: int|null, is_built_in: bool, can_edit: bool}
     */
    private function present(Role $role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'permissions' => array_values($role->permissions->map(fn (Permission $permission): string => $permission->name)->all()),
            'users_count' => $role->users_count ?? null,
            'is_built_in' => RoleEnum::tryFrom($role->name) !== null,
            'can_edit' => Gate::allows('update', $role),
        ];
    }
}
