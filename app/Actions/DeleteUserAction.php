<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Services\SuperAdminGuardService;

/**
 * Soft-deletes a dashboard user. Roles and passkeys stay attached for the audit trail; the keys
 * are revoked and the principal deactivated first, so neither can act for a deleted person.
 */
class DeleteUserAction
{
    public function __construct(private SuperAdminGuardService $superAdminGuard) {}

    public function execute(User $user): void
    {
        $this->superAdminGuard->preventLosingLastSuperAdmin(function () use ($user): void {
            $user->apiKeys()->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $user->principal()->update([
                'deactivated_at' => now(),
                'user_id' => null,
            ]);

            $user->delete();
        }, 'user');
    }
}
