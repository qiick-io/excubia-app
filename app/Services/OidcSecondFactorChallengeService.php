<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Qiick\Oidc\Contracts\SecondFactorChallengeContract;
use Qiick\Oidc\Services\FortifySecondFactorChallengeService;
use Symfony\Component\HttpFoundation\Response;

/**
 * When an SSO login must also pass Excubia's own second factor.
 *
 * An SSO login is never weaker than a password login: the TOTP challenge runs when Settings →
 * Application requires 2FA, and for every user who turned on TOTP themselves, exactly as Fortify
 * does after a password. The package skips it only when the signed id_token proves MFA at the
 * IdP (`amr` contains `mfa`, or Signaculum's MFA `acr`): the IdP is already the root of trust
 * for who the user is, so its signed statement of how they signed in does not lower the bar.
 *
 * The challenge itself is Fortify's (two-factor.login). A user whose only second factor is a
 * passkey has no challenge to run and signs in, as the 2FA requirement (TwoFactorRequirementService) accepts.
 */
class OidcSecondFactorChallengeService implements SecondFactorChallengeContract
{
    public function __construct(private SettingsRepositoryService $settings) {}

    public function isRequired(Model&Authenticatable $user): bool
    {
        return $this->settings->isTwoFactorRequired()
            || ($user instanceof User && $user->hasEnabledTwoFactorAuthentication());
    }

    public function challenge(Request $request, Model&Authenticatable $user, bool $shouldRemember): ?Response
    {
        return (new FortifySecondFactorChallengeService(isRequiredByDefault: true))->challenge($request, $user, $shouldRemember);
    }
}
