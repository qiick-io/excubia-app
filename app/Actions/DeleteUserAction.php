<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Principal;
use App\Models\User;
use App\Services\SuperAdminGuardService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Qiick\Access\Principals\PrincipalDirectory;
use Qiick\ApiKeys\RevocationReason;
use Qiick\HttpBasics\Erasure\HttpBasicsEraser;
use Qiick\Oidc\Erasure\OidcEraser;
use Qiick\Outbound\Erasure\OutboundEraser;

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
        private OidcEraser $oidcEraser,
        private OutboundEraser $outboundEraser,
        private HttpBasicsEraser $httpBasicsEraser,
    ) {}

    /**
     * Every lock first (SuperAdminGuardService::lockUsers()), then the actor's `delete` policy on
     * the locked state, then the last-super-admin guard: a target that became a protected
     * super-admin after the request's check is refused. Not a role write, so a mixed holder can
     * still be deleted.
     *
     * @param  ?User  $actor  the admin deleting the account; null when people delete their own
     *
     * @throws ValidationException when the user is the last active super-admin
     * @throws AuthorizationException when the actor may no longer delete the locked user
     */
    public function execute(User $user, ?User $actor = null): void
    {
        DB::transaction(function () use ($user, $actor): void {
            $this->superAdminGuard->lockUsers([$user->id]);

            if ($actor !== null) {
                Gate::forUser($actor)->authorize('delete', $user);
            }

            $this->erase($user);
        }, SuperAdminGuardService::TRANSACTION_ATTEMPTS);
    }

    private function erase(User $user): void
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
            // The principal becomes an erased tombstone: markErased() is the package's soft-delete
            // path (it overrides an earlier reason and keeps the original deactivation time). The
            // tombstone keeps its user link, so a restored row can never mint a second principal
            // for the same user; only its name, which holds personal data, is anonymised.
            $principal = $this->principals->forMember($user, null);
            $this->principals->markErased($principal);
            Principal::query()->whereKey($principal->id)->update(['name' => 'Deleted principal '.$publicId]);

            // Login links are credentials, not audit: leaving an identity behind points SSO at a
            // soft-deleted user forever, and a passkey keeps its PII-bearing name and breaks auth.
            // qiick/laravel-oidc's eraser removes the identities and the logout state tied to them.
            $this->oidcEraser->erase((string) $user->getKey(), $publicId, OidcEraser::DELETE);
            $user->passkeys()->delete();
            // qiick/laravel-outbound: a pending alert webhook that names the person (an assigned
            // finding) is cancelled and every delivery about them loses its body; the person is
            // unlinked from the endpoints and redeliveries they triggered.
            $this->outboundEraser->erase((string) $user->getKey(), $publicId, OutboundEraser::DELETE, [$principal->id]);
            // qiick/laravel-http-basics: idempotency records of the person (none while Excubia keeps
            // them in the cache store, where they expire with their TTL).
            $this->httpBasicsEraser->erase((string) $user->getKey(), $publicId, HttpBasicsEraser::DELETE, [$principal->id]);

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
