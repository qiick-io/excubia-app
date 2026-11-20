<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Principal;
use App\Models\User;
use App\Services\SuperAdminGuardService;
use Illuminate\Validation\ValidationException;

/**
 * Updates a dashboard user. A null password keeps the current one.
 *
 * Side effects: deactivating the user stamps `principals.deactivated_at`; reactivating clears it.
 */
class UpdateUserAction
{
    public function __construct(
        private SuperAdminGuardService $superAdminGuard,
        private SyncUserRolesAction $syncRoles,
    ) {}

    /**
     * @param  list<string>  $roles
     *
     * @throws ValidationException when the change would leave no active super-admin
     */
    public function execute(User $user, string $firstName, ?string $lastName, string $email, ?string $username, ?string $password, array $roles, bool $isActive, string $timezone = 'UTC'): User
    {
        return $this->superAdminGuard->preventLosingLastSuperAdmin(function () use ($user, $firstName, $lastName, $email, $username, $password, $roles, $isActive, $timezone): User {
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

            if ($isActive) {
                Principal::query()
                    ->where('user_id', $user->getKey())
                    ->whereNotNull('deactivated_at')
                    ->update(['deactivated_at' => null]);
            } else {
                Principal::query()
                    ->where('user_id', $user->getKey())
                    ->whereNull('deactivated_at')
                    ->update(['deactivated_at' => now()]);
            }

            return $user;
        });
    }
}
