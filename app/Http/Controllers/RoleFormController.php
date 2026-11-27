<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PermissionEnum;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The role form page (`/settings/roles/create`, `/settings/roles/{role}/edit`), with the
 * permissions grouped by resource. RoleController saves what it posts.
 */
class RoleFormController extends Controller
{
    public function create(): Response
    {
        Gate::authorize('create', Role::class);

        return $this->form(null);
    }

    public function edit(Role $role): Response
    {
        Gate::authorize('viewAny', Role::class);

        return $this->form($role->load('permissions:id,name'));
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
