<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Services\SuperAdminGuardService;
use Illuminate\Validation\ValidationException;
use Qiick\Access\ExclusiveSuperAdminException;
use Qiick\Access\Principals\DeactivationReason;
use Qiick\Access\Principals\PrincipalDirectory;

/**
 * Updates a dashboard user. A null password keeps the current one.
 *
 * Side effects: deactivating the user deactivates their principal as `offboarded` (their API keys
 * stop working); reactivating brings it back (qiick/laravel-access PrincipalDirectory).
 */
class UpdateUserAction
{
    public function __construct(
        private SuperAdminGuardService $superAdminGuard,
        private SyncUserRolesAction $syncRoles,
        private PrincipalDirectory $principals,
    ) {}

    /**
     * The whole update runs through SuperAdminGuardService::guardRoleWrite(): the user is locked,
     * the instance keeps an active super-admin, and super-admin stands alone.
     *
     * @param  ?User  $actor  the acting user; null only for trusted system writes
     * @param  list<string>  $roles
     *
     * @throws ValidationException when the change would leave no active super-admin
     * @throws ExclusiveSuperAdminException after rolling back a mixed or unauthorised super-admin
     */
    public function execute(?User $actor, User $user, string $firstName, ?string $lastName, string $email, ?string $username, ?string $password, array $roles, bool $isActive, string $timezone = 'UTC'): User
    {
        return $this->superAdminGuard->guardRoleWrite($actor, fn (): array => [$user->id], function () use ($user, $firstName, $lastName, $email, $username, $password, $roles, $isActive, $timezone): User {
            $user->forceFill([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'username' => $username,
                'email' => $email,
                'timezone' => $timezone,
                'is_active' => $isActive,
            ]);

            if ($password !== null) {
                $user->password = $password;
            }

            $user->save();
            $this->syncRoles->execute($user, $roles);

            // Offboarded, not admin: reactivating the user brings their principal (and keys) back.
            $principal = $this->principals->forMember($user, null);

            if (! $isActive) {
                $this->principals->deactivate($principal, DeactivationReason::Offboarded);
            }

            return $user;
        });
    }
}
