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
    public function execute(User $user, string $name, string $email, ?string $password, array $roles, bool $isActive): User
    {
        return $this->superAdminGuard->preventLosingLastSuperAdmin(function () use ($user, $name, $email, $password, $roles, $isActive): User {
            $user->forceFill([
                'name' => $name,
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
