<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Services\SuperAdminGuardService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Soft-deletes a dashboard user and releases their unique identifiers.
 *
 * Roles and passkeys stay attached for the audit trail; the keys are revoked, the principal is
 * detached and deactivated, and personal data is replaced with unique tombstones so the email and
 * username can be reused on every database engine. Activity rows keep their audit ids.
 */
class DeleteUserAction
{
    public function __construct(private SuperAdminGuardService $superAdminGuard) {}

    public function execute(User $user): void
    {
        $this->superAdminGuard->preventLosingLastSuperAdmin(function () use ($user): void {
            $publicId = $user->public_id;
            $originalEmail = $user->email;

            $user->apiKeys()->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $user->apiKeys()->update(['name' => 'Deleted key '.$publicId]);
            $user->principal()->update([
                'deactivated_at' => now(),
                'name' => 'Deleted principal '.$publicId,
                'user_id' => null,
            ]);
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('password_reset_tokens')->where('email', $originalEmail)->delete();
            DB::table('notifications')
                ->where('notifiable_type', User::class)
                ->where('notifiable_id', $user->id)
                ->delete();

            // Quiet: erasing the row must not create a new activity entry with the old values.
            $user->forceFill([
                'first_name' => 'Deleted',
                'last_name' => null,
                'email' => 'deleted+'.$publicId.'@example.invalid',
                'username' => 'deleted_'.substr(str_replace('-', '', $publicId), 0, 24),
                'email_verified_at' => null,
                'last_login_at' => null,
                'last_login_ip' => null,
                'remember_token' => null,
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'password' => Str::random(64),
                'alert_email_filters' => null,
                'has_alert_emails' => false,
                'has_weekly_digest' => false,
            ])->saveQuietly();

            $user->delete();
        }, 'user');
    }
}
