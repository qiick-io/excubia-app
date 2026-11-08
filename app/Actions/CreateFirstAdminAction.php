<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\RoleEnum;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Creates the first super-admin from the first-run wizard (#116). It only works while the
 * database has no user: the check runs under a lock, so two concurrent wizard submits cannot
 * both create an admin. The built-in roles are synced first, as `excubia:create-user` does,
 * because a fresh install may never have run `db:seed`.
 */
class CreateFirstAdminAction
{
    public function __construct(
        private SyncPermissionsAction $syncPermissions,
        private CreateUserAction $createUser,
    ) {}

    /**
     * @return User|null The new super-admin, or null when a user already exists (setup is locked).
     */
    public function execute(string $firstName, ?string $lastName, string $email, string $password): ?User
    {
        return Cache::lock('excubia:first-run-setup', 10)->block(5, function () use ($firstName, $lastName, $email, $password): ?User {
            if (User::query()->exists()) {
                return null;
            }

            $this->syncPermissions->execute();

            return $this->createUser->execute($firstName, $lastName, $email, $password, [RoleEnum::SuperAdmin->value]);
        });
    }
}
