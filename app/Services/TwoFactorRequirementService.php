<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use Qiick\Access\Contracts\TwoFactorPolicy;
use Qiick\Access\Contracts\TwoFactorResponder;
use Symfony\Component\HttpFoundation\Response;

/**
 * Excubia's two-factor rule for qiick/laravel-access' EnsureTwoFactorIsEnabled: when Settings
 * require 2FA, a web user needs a confirmed TOTP or at least one passkey (as externa-core), and
 * is sent to their security page until they have one.
 */
class TwoFactorRequirementService implements TwoFactorPolicy, TwoFactorResponder
{
    public function __construct(private readonly SettingsRepositoryService $settings) {}

    /**
     * Only while TOTP can be managed: otherwise the requirement could never be met.
     */
    public function isRequired(Authenticatable $user): bool
    {
        return $this->settings->isTwoFactorRequired() && Features::canManageTwoFactorAuthentication();
    }

    public function hasUsableFactor(Authenticatable $user): bool
    {
        return $user instanceof User
            && (
                (Features::canManageTwoFactorAuthentication() && $user->hasEnabledTwoFactorAuthentication())
                || (Features::canManagePasskeys() && $user->hasPasskeysEnabled())
            );
    }

    public function isAnyMethodEnabled(): bool
    {
        return Features::canManageTwoFactorAuthentication();
    }

    public function enrol(Request $request): Response
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => __('Set up two-factor authentication or a passkey to continue.')]);

        return redirect()->route('security.edit');
    }

    /**
     * Unreachable: {@see isRequired()} is false whenever no method is available.
     */
    public function unavailable(): Response
    {
        abort(403, __('Two-factor authentication is required but unavailable.'));
    }
}
