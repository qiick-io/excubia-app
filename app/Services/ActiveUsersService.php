<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Qiick\Access\Contracts\ActiveUsers;

/**
 * Which users count as active for qiick/laravel-access super-admin governance: `is_active`, and
 * never a soft-deleted account (the model scope). The package's SuperAdminGuard locks and counts
 * these when it decides whether a change would leave no active super-admin.
 */
class ActiveUsersService implements ActiveUsers
{
    /**
     * @return Builder<Model>
     */
    public function query(): Builder
    {
        // The contract is typed on the base model, and Builder's template is invariant.
        $userModel = $this->userModel();

        // Id order: the package guard locks these rows, and every guarded write locks users in
        // the same order (SuperAdminGuardService::lockUsers()), so two writes never deadlock on them.
        return $userModel->newQuery()->where('is_active', true)->orderBy($userModel->getKeyName());
    }

    private function userModel(): Model
    {
        return new User;
    }
}
