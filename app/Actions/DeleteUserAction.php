<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Principal;
use App\Models\User;
use App\Services\SuperAdminGuardService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Qiick\Access\Principals\DeactivationReason;
use Qiick\Access\Principals\PrincipalDirectory;
use Qiick\ApiKeys\RevocationReason;

/**
 * Soft-deletes a dashboard user and releases their unique identifiers.
 *
 * Roles and audit activity stay attached for the trail, but login links and the user's own data
 * go with the account: SSO identities and passkeys are deleted (they are credentials, not audit),
 * assistant chats and their messages are removed, and the principal becomes an erased tombstone.
 * Personal columns become unique tombstones from the full public-id UUID so the email and username
 * can be reused on every database engine. The deleted row itself is inactive.
 */
class DeleteUserAction
{
    public function __construct(
        private SuperAdminGuardService $superAdminGuard,
        private PrincipalDirectory $principals,
    ) {}

    public function execute(User $user): void
    {
        $this->superAdminGuard->preventLosingLastSuperAdmin(function () use ($user): void {
            $publicId = $user->public_id;
            $originalEmail = $user->email;
            $publicIdHex = str_replace('-', '', $publicId);

            $user->apiKeys()->whereNull('revoked_at')->update([
                'revoked_at' => now(),
                'revoked_reason' => RevocationReason::Owner->value,
                'previous_key_hash' => null,
                'previous_key_valid_until' => null,
            ]);
            $user->apiKeys()->update(['name' => 'Deleted key '.$publicId]);
            // The principal becomes an erased tombstone, unlinked and anonymised as the package's
            // own eraseUser() leaves it (that one needs a hard delete; Excubia soft-deletes).
            $principal = $this->principals->forMember($user, null);
            $this->principals->deactivate($principal, DeactivationReason::Erased);
            Principal::query()->whereKey($principal->id)->update([
                'name' => 'Deleted principal '.$publicId,
                'user_id' => null,
            ]);

            // Login links are credentials, not audit: leaving an identity behind points SSO at a
            // soft-deleted user forever, and a passkey keeps its PII-bearing name and breaks auth.
            $user->identities()->delete();
            $user->passkeys()->delete();

            $conversationIds = DB::table('agent_conversations')
                ->where('participant_type', $user->getMorphClass())
                ->where('participant_id', $user->id)
                ->pluck('id');

            DB::table('agent_conversation_messages')->whereIn('conversation_id', $conversationIds)->delete();
            DB::table('agent_conversations')->whereIn('id', $conversationIds)->delete();

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
                'username' => 'deleted_'.$publicIdHex,
                'is_active' => false,
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
