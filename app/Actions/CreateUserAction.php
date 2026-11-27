<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Services\SuperAdminGuardService;
use Illuminate\Validation\ValidationException;
use Qiick\Access\ExclusiveSuperAdminException;

/**
 * Creates a dashboard user with the given roles. The email counts as verified because an
 * administrator (UI or CLI) created the account.
 * The creation and the roles are logged with the acting user as causer. The roles go through
 * SuperAdminGuardService::guardRoleWrite() (qiick/laravel-access ExclusiveSuperAdmin), so
 * super-admin stands alone and only an actor who may manage super-admins creates one; a refused
 * write leaves no user behind.
 */
class CreateUserAction
{
    public function __construct(
        private SyncUserRolesAction $syncRoles,
        private SuperAdminGuardService $superAdminGuard,
    ) {}

    /**
     * @param  ?User  $actor  the acting user; null only for trusted system writes (CLI, first admin)
     * @param  list<string>  $roles
     *
     * @throws ExclusiveSuperAdminException after rolling the creation back
     * @throws ValidationException when the change would leave no active super-admin
     */
    public function execute(?User $actor, string $firstName, ?string $lastName, string $email, ?string $username, string $password, array $roles, bool $isActive = true, string $timezone = 'UTC'): User
    {
        $user = (new User)->forceFill([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'username' => $username,
            'email' => $email,
            'password' => $password,
            'timezone' => $timezone,
            'is_active' => $isActive,
            'email_verified_at' => now(),
        ]);

        return $this->superAdminGuard->guardRoleWrite(
            $actor,
            // The insert is the transaction's first statement, and it locks the new row.
            function () use ($user): array {
                $user->save();

                return [$user->id];
            },
            function () use ($user, $roles): User {
                $this->syncRoles->execute($user, $roles);

                return $user;
            },
        );
    }
}
