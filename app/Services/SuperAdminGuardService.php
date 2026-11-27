<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RoleEnum;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Qiick\Access\Contracts\ActiveUsers;

/**
 * Keeps at least one active super-admin (direct role or through a group), so the instance
 * never locks itself out. It is also qiick/laravel-access's ActiveUsers: the package's
 * SuperAdminGuard (used by ExclusiveSuperAdmin::write()) counts the same users as active.
 */
class SuperAdminGuardService implements ActiveUsers
{
    public function __construct(private EffectivePermissionResolverService $permissionResolver) {}

    /**
     * Whether the user is the only active super-admin left.
     */
    public function isLastSuperAdmin(User $user): bool
    {
        if (! $user->is_active || ! $this->permissionResolver->isSuperAdmin($user)) {
            return false;
        }

        return $this->activeSuperAdmins()->whereKeyNot($user->getKey())->doesntExist();
    }

    /**
     * Runs a change (role, group, membership, activation or deletion) and rolls it back when
     * no active super-admin would be left.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $change
     * @return TResult
     *
     * @throws ValidationException under `$errorKey` when the change is refused
     */
    public function preventLosingLastSuperAdmin(Closure $change, string $errorKey = 'roles'): mixed
    {
        return DB::transaction(function () use ($change, $errorKey): mixed {
            $hadSuperAdmin = $this->activeSuperAdmins()->exists();
            $result = $change();

            if ($hadSuperAdmin && $this->activeSuperAdmins()->doesntExist()) {
                throw ValidationException::withMessages([
                    $errorKey => __('The last active super-admin must stay an active super-admin.'),
                ]);
            }

            return $result;
        });
    }

    /**
     * Active users: an inactive (or soft-deleted) account never counts as a super-admin.
     *
     * @return Builder<Model>
     */
    public function query(): Builder
    {
        // The contract is typed on the base model, and Builder's template is invariant.
        $userModel = $this->userModel();

        return $userModel->newQuery()->where('is_active', true);
    }

    private function userModel(): Model
    {
        return new User;
    }

    /**
     * @return Builder<User>
     */
    private function activeUsers(): Builder
    {
        return User::query()->where('is_active', true);
    }

    /**
     * @return Builder<User>
     */
    private function activeSuperAdmins(): Builder
    {
        $isSuperAdmin = fn (Builder $roles) => $roles->where('name', RoleEnum::SuperAdmin->value);

        // The same rule as the resolver: a group counts only while it is a live instance group and
        // the membership has not expired (soft-deleted groups drop out through the model scope).
        return $this->activeUsers()
            ->where(fn (Builder $users) => $users
                ->whereHas('roles', $isSuperAdmin)
                ->orWhereHas('groups', fn (Builder $groups) => $groups
                    ->where('user_groups.is_instance', true)
                    ->where(fn (Builder $membership) => $membership
                        ->whereNull('user_group_user.expires_at')
                        ->orWhere('user_group_user.expires_at', '>', now()))
                    ->whereHas('roles', $isSuperAdmin)));
    }
}
