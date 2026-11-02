<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;

/**
 * Updates a dashboard user. A null password keeps the current one.
 */
class UpdateUserAction
{
    /**
     * @param  list<string>  $roles
     */
    public function execute(User $user, string $name, string $email, ?string $password, array $roles, bool $isActive): User
    {
        $user->forceFill([
            'name' => $name,
            'email' => $email,
            'is_active' => $isActive,
        ]);

        if ($password !== null) {
            $user->password = $password;
        }

        $user->save();
        $user->syncRoles($roles);

        return $user;
    }
}
