<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\RoleEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * A role for the roles list and the role form. Built-in roles (RoleEnum) come back with
 * `can_edit` false.
 *
 * @mixin Role
 */
class RoleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Role $role */
        $role = $this->resource;

        return [
            'id' => $role->id,
            'name' => $role->name,
            'is_built_in' => RoleEnum::tryFrom($role->name) !== null,
            'can_edit' => Gate::allows('update', $role),
            'permissions_count' => $this->whenCounted('permissions'),
            'permissions' => $this->whenLoaded('permissions', fn (): array => $role->permissions
                ->map(fn (Permission $permission): string => $permission->name)
                ->values()
                ->all()),
            'created_at' => $role->created_at?->toIso8601String(),
            'updated_at' => $role->updated_at?->toIso8601String(),
        ];
    }
}
