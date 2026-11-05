<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Services\SuperAdminGuardService;
use Illuminate\Validation\ValidationException;

/**
 * Updates a dashboard user. A null password keeps the current one.
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
    public function execute(User $user, string $firstName, ?string $lastName, string $email, ?string $password, array $roles, bool $isActive): User
    {
        return $this->superAdminGuard->preventLosingLastSuperAdmin(function () use ($user, $firstName, $lastName, $email, $password, $roles, $isActive): User {
            $user->forceFill([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'is_active' => $isActive,
            ]);

            if ($password !== null) {
                $user->password = $password;
            }

            $user->save();
            $this->syncRoles->execute($user, $roles);

            return $user;
        });
    }
}
