<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\UserGroup;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
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

    /** Attempts of a guarded transaction: a deadlock between two writes is retried, not shown. */
    public const TRANSACTION_ATTEMPTS = 3;

    /**
     * Runs a role or group write for every user it affects, all locks first, in one canonical
     * order, then the checks against the locked state, then the write:
     *
     * 1. `$lockAnchors` locks what the write hangs on (the group or role row) and returns the ids
     *    of the users it touches, read with locking reads only;
     * 2. lockUsers() locks every active user and those users in one id-ordered query, the same
     *    order every guarded write and the package guard use;
     * 3. `$authorize` re-checks the actor's policy on the target as it now stands, so a target
     *    that became a protected super-admin after the request's check is refused;
     * 4. the last-super-admin guard and ExclusiveSuperAdmin::write() run over the locked users.
     *
     * No plain read comes before the locks, so InnoDB's snapshot is taken after them. The whole
     * transaction is retried on a deadlock; the closures must not keep state across attempts.
     *
     * @template TResult
     *
     * @param  ?User  $actor  the acting user; null only for trusted system writes (CLI, OIDC JIT, seeders)
     * @param  Closure(): list<int>  $lockAnchors
     * @param  Closure(): TResult  $write
     * @param  (Closure(): mixed)|null  $authorize  throws to refuse; its return value is ignored
     * @return TResult
     *
     * @throws ValidationException when the change would leave no active super-admin
     * @throws ExclusiveSuperAdminException after rolling back a mixed or unauthorised super-admin
     * @throws AuthorizationException when `$authorize` refuses the locked state
     */
    public function guardRoleWrite(?User $actor, Closure $lockAnchors, Closure $write, ?Closure $authorize = null, string $errorKey = 'roles'): mixed
    {
        return DB::transaction(function () use ($actor, $lockAnchors, $write, $authorize, $errorKey): mixed {
            $affectedUsers = $this->lockUsers($lockAnchors());

            if ($authorize !== null) {
                $authorize();
            }

            if ($affectedUsers === []) {
                return $this->preventLosingLastSuperAdmin($write, $errorKey);
            }

            return $this->preventLosingLastSuperAdmin(
                fn (): mixed => $this->exclusiveSuperAdmin->write($affectedUsers, $write, $actor),
                $errorKey,
            );
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * Locks every active user and the given users in one id-ordered query (the canonical order),
     * then drops the resolver's verdicts read before the locks. Call it first in a transaction.
     *
     * @param  list<int>  $userIds
     * @return list<User> the given users that exist, in id order
     */
    public function lockUsers(array $userIds): array
    {
        $lockedUsers = User::query()
            ->where(fn ($users) => $users->where('is_active', true)->orWhereKey($userIds))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $this->resolver->flush();

        return array_values($lockedUsers->whereIn('id', $userIds)->all());
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
