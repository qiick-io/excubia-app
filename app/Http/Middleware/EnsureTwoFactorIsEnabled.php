<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\SettingsRepositoryService;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use Symfony\Component\HttpFoundation\Response;

/**
 * When the settings require 2FA, sends users without it to their security page.
 * A confirmed TOTP or at least one passkey satisfies the requirement, as in externa-core.
 */
class EnsureTwoFactorIsEnabled
{
    /**
     * Routes needed to enroll (or leave) without a redirect loop.
     *
     * @var list<string>
     */
    private const EXEMPT_ROUTE_NAMES = [
        'logout',
        'security.edit',
        'user-password.update',
        'profile.edit',
        'profile.update',
        'password.confirm',
        'password.confirmation',
        'password.confirm.store',
        'two-factor.enable',
        'two-factor.confirm',
        'two-factor.disable',
        'two-factor.qr-code',
        'two-factor.secret-key',
        'two-factor.recovery-codes',
        'two-factor.regenerate-recovery-codes',
        'passkey.registration-options',
        'passkey.store',
        'passkey.destroy',
    ];

    public function __construct(private SettingsRepositoryService $settings) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null
            || in_array($request->route()?->getName(), self::EXEMPT_ROUTE_NAMES, true)
            || ! $this->settings->isTwoFactorRequired()
            || ! Features::canManageTwoFactorAuthentication()
            || $user->hasEnabledTwoFactorAuthentication()
            || (Features::canManagePasskeys() && $user->hasPasskeysEnabled())
        ) {
            return $next($request);
        }

        Inertia::flash('toast', ['type' => 'error', 'message' => __('Set up two-factor authentication or a passkey to continue.')]);

        return redirect()->route('security.edit');
    }
}
