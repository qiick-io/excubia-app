<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;

/**
 * Creates a dashboard user with the given roles. The email counts as verified because an
 * administrator (UI or CLI) created the account.
 * The creation and the roles are logged with the acting user as causer.
 */
class CreateUserAction
{
    public function __construct(private SyncUserRolesAction $syncRoles) {}

    /**
     * @param  list<string>  $roles
     */
    public function execute(string $name, string $email, string $password, array $roles, bool $isActive = true): User
    {
        $user = (new User)->forceFill([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'is_active' => $isActive,
            'email_verified_at' => now(),
        ]);
        $user->save();
        $this->syncRoles->execute($user, $roles);

        return $user;
    }
}
