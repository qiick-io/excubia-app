<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * A permission for the permissions list, with the names of the roles that hold it.
 *
 * @mixin Permission
 */
class PermissionResource extends JsonResource
{
    /**
     * @return array<string, mixed> id, name, guard_name, roles (sorted role names), created_at, updated_at
     */
    public function toArray(Request $request): array
    {
        /** @var Permission $permission */
        $permission = $this->resource;

        return [
            'id' => $permission->id,
            'name' => $permission->name,
            'guard_name' => $permission->guard_name,
            'roles' => $permission->roles
                ->map(fn (Role $role): string => $role->name)
                ->sort()
                ->values()
                ->all(),
            'created_at' => $permission->created_at?->toIso8601String(),
            'updated_at' => $permission->updated_at?->toIso8601String(),
        ];
    }
}
