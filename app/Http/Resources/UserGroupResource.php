<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * A user group with its roles and members for the admin list and the group drawer.
 *
 * @mixin UserGroup
 */
class UserGroupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var UserGroup $group */
        $group = $this->resource;

        return [
            'id' => $group->id,
            'name' => $group->name,
            'slug' => Str::slug($group->name),
            'description' => $group->description,
            'users_count' => $group->users_count,
            'roles' => $this->whenLoaded('roles', fn (): array => $group->roles
                ->map(fn (Role $role): array => ['id' => $role->id, 'name' => $role->name])
                ->values()
                ->all(), []),
            'users' => $this->whenLoaded('users', fn (): array => $group->users
                ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email])
                ->values()
                ->all(), []),
            'can_update' => Gate::allows('update', $group),
            'created_at' => $group->created_at?->toIso8601String(),
            'updated_at' => $group->updated_at?->toIso8601String(),
        ];
    }
}
