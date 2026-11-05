<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * A dashboard user for the admin list, the user drawer and the user picker (JSON).
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        return [
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'name' => $user->name,
            'email' => $user->email,
            'is_active' => $user->is_active,
            'is_sso_linked' => $user->oidc_sub !== null,
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
            'updated_at' => $user->updated_at?->toIso8601String(),
            'roles' => $this->whenLoaded('roles', fn (): array => $user->roles
                ->map(fn (Model $role): array => $role->only(['id', 'name']))
                ->values()
                ->all()),
            'groups' => $this->whenLoaded('groups', fn (): array => $user->groups
                ->map(fn (UserGroup $group): array => ['id' => $group->id, 'name' => $group->name])
                ->values()
                ->all()),
            'can_update' => Gate::allows('update', $user),
            'can_delete' => Gate::allows('delete', $user),
        ];
    }
}
