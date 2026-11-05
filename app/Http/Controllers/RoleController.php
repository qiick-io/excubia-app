<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\SaveRoleAction;
use App\Enums\PermissionEnum;
use App\Http\Requests\ListQueryRequest;
use App\Http\Requests\RoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings pages for roles (`/settings/roles`). Built-in roles are shown read-only; custom
 * roles can be edited and deleted.
 */
class RoleController extends Controller
{
    public function index(ListQueryRequest $request): Response
    {
        Gate::authorize('viewAny', Role::class);

        return Inertia::render('admin/roles/index', [
            'roles' => RoleResource::collection(Role::query()
                ->withCount('permissions')
                ->orderBy('name')
                ->paginate($request->perPage())
                ->withQueryString()),
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

    /**
     * The role form with permissions grouped by resource (`can-create-users` → `users`).
     */
    private function form(?Role $role): Response
    {
        return Inertia::render('admin/roles/form', [
            // Resolved so Inertia gets a flat object (not { data: ... }).
            'role' => $role === null ? null : (new RoleResource($role))->resolve(),
            'permissionGroups' => collect(PermissionEnum::cases())
                ->groupBy(fn (PermissionEnum $permission): string => $permission->resource())
                ->map(fn ($permissions, string $section): array => [
                    'section' => $section,
                    'label' => Str::headline($section),
                    'permissions' => $permissions->map(fn (PermissionEnum $permission): string => $permission->value)->values()->all(),
                ])
                ->values()
                ->all(),
        ]);
    }
}
