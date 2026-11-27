<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\UserGroup;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Qiick\Access\EffectivePermissionResolver;
use Qiick\Access\ExclusiveSuperAdmin;
use Qiick\Access\ExclusiveSuperAdminException;
use Qiick\Access\SuperAdminGuard;
use Spatie\Permission\Models\Role;

/**
 * Super-admin governance on top of qiick/laravel-access: the instance keeps at least one active,
 * effective super-admin (a mixed holder whose bypass is withheld does not count), and every role
 * or group write runs through ExclusiveSuperAdmin::write(), so super-admin stands alone and only
 * an actor who may manage super-admins makes someone one.
 */
class SuperAdminGuardService
{
    public function __construct(
        private EffectivePermissionResolver $resolver,
        private SuperAdminGuard $superAdminGuard,
        private ExclusiveSuperAdmin $exclusiveSuperAdmin,
        private ActiveUsersService $activeUsers,
    ) {}

    /**
     * Whether the user is the only active, effective super-admin left.
     */
    public function isLastSuperAdmin(User $user): bool
    {
        if (! $user->is_active || ! $this->resolver->isSuperAdmin($user)) {
            return false;
        }

        return $this->activeUsers->query()
            ->whereKeyNot($user->getKey())
            ->get()
            ->doesntContain(fn ($otherUser): bool => $otherUser instanceof User && $this->resolver->isSuperAdmin($otherUser));
    }

    /**
     * Runs a change (role, group, membership, activation or deletion) and rolls it back when
     * no active super-admin would be left. The package guard locks every active user before it
     * reads, so two concurrent changes cannot each count the other as the remaining one.
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
        $results = [];
        $wouldLeaveNone = $this->superAdminGuard->wouldLeaveNoActiveSuperAdmin(function () use ($change, &$results): void {
            $results[] = $change();
        });

        if ($wouldLeaveNone) {
            throw ValidationException::withMessages([
                $errorKey => __('The last active super-admin must stay an active super-admin.'),
            ]);
        }

        if ($results === []) {
            throw new LogicException('The guarded change did not run.');
        }

        return $results[0];
    }

    /**
     * Runs a role or group write for every user it affects. `$lockAffectedUsers` runs first in the
     * transaction: it locks what the write hangs on (the group or role row) and returns the ids of
     * the users the write touches, read with locking reads only, so a concurrent write cannot add
     * a user after the list was taken and no plain read fixes an InnoDB snapshot before the users
     * are locked. The last-super-admin guard then locks every active user, and
     * ExclusiveSuperAdmin::write() runs inside it over the affected users.
     *
     * @template TResult
     *
     * @param  ?User  $actor  the acting user; null only for trusted system writes (CLI, OIDC JIT, seeders)
     * @param  Closure(): list<int>  $lockAffectedUsers
     * @param  Closure(): TResult  $write
     * @return TResult
     *
     * @throws ValidationException when the change would leave no active super-admin
     * @throws ExclusiveSuperAdminException after rolling back a mixed or unauthorised super-admin
     */
    public function guardRoleWrite(?User $actor, Closure $lockAffectedUsers, Closure $write, string $errorKey = 'roles'): mixed
    {
        return DB::transaction(function () use ($actor, $lockAffectedUsers, $write, $errorKey): mixed {
            $affectedUsers = array_values(User::query()
                ->whereKey($lockAffectedUsers())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->all());

            if ($affectedUsers === []) {
                return $this->preventLosingLastSuperAdmin($write, $errorKey);
            }

            // The guard outside write(): its first statement locks every active user, so the
            // first plain read (and InnoDB's snapshot) comes after every lock.
            return $this->preventLosingLastSuperAdmin(
                fn (): mixed => $this->exclusiveSuperAdmin->write($affectedUsers, $write, $actor),
                $errorKey,
            );
        });
    }

    /**
     * Locks a saved group and returns its member ids, read with locking reads, for
     * guardRoleWrite(): a concurrent save of the same group waits, so it cannot add a member
     * after this list was taken. A new group has no members yet.
     *
     * @return list<int>
     */
    public function lockGroupMemberIds(UserGroup $group): array
    {
        if (! $group->exists) {
            return [];
        }

        UserGroup::query()->whereKey($group->getKey())->lockForUpdate()->first();

        return $this->intIds(DB::table('user_group_user')->where('user_group_id', $group->getKey())->lockForUpdate()->pluck('user_id')->all());
    }

    /**
     * Locks a role and returns every user holding it, directly or through a group, read with
     * locking reads, for guardRoleWrite().
     *
     * @return list<int>
     */
    public function lockRoleHolderIds(Role $role): array
    {
        Role::query()->whereKey($role->getKey())->lockForUpdate()->first();

        $directHolderIds = DB::table('model_has_roles')
            ->where('role_id', $role->getKey())
            ->where('model_type', (new User)->getMorphClass())
            ->lockForUpdate()
            ->pluck('model_id')
            ->all();
        $groupHolderIds = DB::table('user_group_user')
            ->join('user_group_role', 'user_group_role.user_group_id', '=', 'user_group_user.user_group_id')
            ->where('user_group_role.role_id', $role->getKey())
            ->lockForUpdate()
            ->pluck('user_group_user.user_id')
            ->all();

        return $this->intIds([...$directHolderIds, ...$groupHolderIds]);
    }

    /**
     * @param  array<array-key, mixed>  $ids
     * @return list<int>
     */
    private function intIds(array $ids): array
    {
        return array_values(array_unique(array_map(intval(...), $ids)));
    }
}
