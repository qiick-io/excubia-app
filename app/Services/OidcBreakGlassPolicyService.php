<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Qiick\Access\EffectivePermissionResolver;
use Qiick\Oidc\Contracts\BreakGlassPolicyContract;

/**
 * Who still signs in locally (password or passkey) in SSO-only mode, so an unreachable or
 * misconfigured IdP never locks the owner out: an active, effective `super-admin` (not a mixed
 * holder whose bypass qiick/laravel-access withholds) with a second factor
 * (a confirmed TOTP or a passkey, the same factors TwoFactorRequirementService accepts).
 * A password alone never gets past SSO-only mode: the password path also needs a confirmed TOTP
 * (FortifyServiceProvider), so Fortify challenges it; a passkey counts for the passkey login.
 *
 * The password form of a break-glass sign-in is at `/login?local=1`. Outside SSO-only mode the
 * policy is not consulted: every active user keeps local login next to SSO.
 */
class OidcBreakGlassPolicyService implements BreakGlassPolicyContract
{
    public function __construct(private EffectivePermissionResolver $resolver) {}

    public function isBreakGlassAccount(Authenticatable $user): bool
    {
        if (! $user instanceof User || ! $user->is_active || ! $this->resolver->isSuperAdmin($user)) {
            return false;
        }

        return $user->hasEnabledTwoFactorAuthentication() || $user->hasPasskeysEnabled();
    }
}
