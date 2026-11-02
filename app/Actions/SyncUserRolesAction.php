<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;

/**
 * Replaces the direct roles of a user and logs a `roles_synced` activity (role changes are not
 * model attributes, so the model log misses them).
 */
class SyncUserRolesAction
{
    /**
     * @param  list<string>  $roles  role names
     */
    public function execute(User $user, array $roles): void
    {
        $user->syncRoles($roles);
        $synced = $user->roles()->orderBy('name')->get(['id', 'name']);

        activity()
            ->performedOn($user)
            ->event('roles_synced')
            ->withProperties([
                'role_ids' => $synced->pluck('id')->values()->all(),
                'role_names' => $synced->pluck('name')->values()->all(),
            ])
            ->log('User roles synced');
    }
}
