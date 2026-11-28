<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Services\ErasureOrchestratorService;
use App\Services\SuperAdminGuardService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Deletes a dashboard user through the resumable erasure (#381, ErasureOrchestratorService): the
 * erasure request and the lock-out commit together, then every eraser runs. The user row ends as
 * an anonymised, soft-deleted tombstone; roles, keys and audit rows stay attached to it for the
 * trail, scrubbed of the person's data.
 */
class DeleteUserAction
{
    public function __construct(
        private SuperAdminGuardService $superAdminGuard,
        private ErasureOrchestratorService $erasure,
    ) {}

    /**
     * Every lock first (SuperAdminGuardService::lockUsers()), then the actor's `delete` policy on
     * the locked state, then the last-super-admin guard around step 0: a target that became a
     * protected super-admin after the request's check is refused. Not a role write, so a mixed
     * holder can still be deleted. A step that fails after the commit leaves the case `failed`
     * for `excubia:resume-erasures`; the person is locked out already.
     *
     * @param  ?User  $actor  the admin deleting the account; null when people delete their own
     *
     * @throws ValidationException when the user is the last active super-admin
     * @throws AuthorizationException when the actor may no longer delete the locked user
     */
    public function execute(User $user, ?User $actor = null): void
    {
        $erasureRequest = DB::transaction(function () use ($user, $actor) {
            $this->superAdminGuard->lockUsers([$user->id]);

            if ($actor !== null) {
                Gate::forUser($actor)->authorize('delete', $user);
            }

            return $this->superAdminGuard->preventLosingLastSuperAdmin(fn () => $this->erasure->begin($user), 'user');
        }, SuperAdminGuardService::TRANSACTION_ATTEMPTS);

        $this->erasure->run($erasureRequest);
    }
}
