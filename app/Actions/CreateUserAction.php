<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Qiick\Access\ExclusiveSuperAdmin;
use Qiick\Access\ExclusiveSuperAdminException;

/**
 * Creates a dashboard user with the given roles. The email counts as verified because an
 * administrator (UI or CLI) created the account.
 * The creation and the roles are logged with the acting user as causer. The roles go through
 * qiick/laravel-access ExclusiveSuperAdmin::write(), so super-admin stands alone and only an actor
 * who may manage super-admins creates one.
 */
class CreateUserAction
{
    public function __construct(
        private SyncUserRolesAction $syncRoles,
        private ExclusiveSuperAdmin $exclusiveSuperAdmin,
    ) {}

    /**
     * @param  ?User  $actor  the acting user; null only for trusted system writes (CLI, first admin)
     * @param  list<string>  $roles
     *
     * @throws ExclusiveSuperAdminException after rolling the role write back
     */
    public function execute(?User $actor, string $firstName, ?string $lastName, string $email, ?string $username, string $password, array $roles, bool $isActive = true, string $timezone = 'UTC'): User
    {
        // One transaction, so a refused role write leaves no user without roles behind. Its first
        // write inserts (and so locks) the new row before ExclusiveSuperAdmin::write() locks it.
        return DB::transaction(function () use ($actor, $firstName, $lastName, $email, $username, $password, $roles, $isActive, $timezone): User {
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
            $user->save();
            $this->exclusiveSuperAdmin->write($user, fn () => $this->syncRoles->execute($user, $roles), $actor);

            return $user;
        });
    }
}
