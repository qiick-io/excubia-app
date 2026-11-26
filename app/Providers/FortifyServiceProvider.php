<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use App\Services\SettingsRepositoryService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\Passkeys;
use Qiick\Oidc\Services\SsoOnlyModeService;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureAuthentication();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
    }

    /**
     * Refuse inactive users on every local login path (password, two-factor and passkey), and
     * in SSO-only mode everyone but the break-glass accounts (OidcBreakGlassPolicyService).
     *
     * Both callbacks bypass Auth::attempt(), so qiick/laravel-oidc's own SSO-only listener never
     * sees these logins: canUseLocalLogin() is checked here instead. The password is checked
     * first, so the SSO-only message never tells a stranger that an email exists.
     *
     * @throws ValidationException in SSO-only mode for a valid password of a non-break-glass user
     */
    private function configureAuthentication(): void
    {
        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::query()->where('email', $request->input(Fortify::username()))->first();

            if ($user === null || ! $user->is_active || $user->password === null || ! Hash::check((string) $request->input('password'), $user->password)) {
                return null;
            }

            $ssoOnlyMode = app(SsoOnlyModeService::class);

            // A break-glass password sign-in needs a confirmed TOTP, which Fortify then challenges:
            // a passkey qualifies the account for the passkey login, never for a password alone.
            if (! $ssoOnlyMode->canUseLocalLogin($user) || ($ssoOnlyMode->isActive() && ! $user->hasEnabledTwoFactorAuthentication())) {
                throw ValidationException::withMessages([Fortify::username() => __('Password sign-in is disabled. Use single sign-on.')]);
            }

            return $user;
        });

        // A passkey may outlive its user (a soft-deleted account, or an orphaned row): the
        // relation resolves to null, so this must be an ordinary refusal, not a TypeError → 500.
        Passkeys::authorizeLoginUsing(
            fn (Request $request, ?PasskeyUser $user): bool => $user instanceof User
                && $user->is_active
                && app(SsoOnlyModeService::class)->canUseLocalLogin($user),
        );
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            ...$this->ssoLoginProps($request),
            'status' => $request->session()->get('status'),
            // A fresh install has no user yet: the login page links to the first-run wizard (#116).
            'needsSetup' => ! User::query()->exists(),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/reset-password', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/forgot-password', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/verify-email', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/two-factor-challenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/confirm-password'));
    }

    /**
     * The SSO part of the login page, from qiick/laravel-oidc's login options. The button label
     * stays the admin's own (null lets the page translate the default).
     *
     * @return array{canUseOidc: bool, oidcButtonLabel: string|null, oidcRedirectUrl: string|null, isPasswordLoginVisible: bool}
     */
    private function ssoLoginProps(Request $request): array
    {
        $loginOptions = app(SsoOnlyModeService::class)->loginOptions($request);

        return [
            'canUseOidc' => $loginOptions->isSsoAvailable,
            'oidcButtonLabel' => app(SettingsRepositoryService::class)->oidcButtonLabel(),
            'oidcRedirectUrl' => $loginOptions->redirectUrl,
            'isPasswordLoginVisible' => $loginOptions->isPasswordLoginVisible,
        ];
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(app(SettingsRepositoryService::class)->loginMaxAttempts())->by($throttleKey);
        });

        RateLimiter::for('passkeys', function (Request $request) {
            return Limit::perMinute(10)->by(
                ($request->input('credential.id') ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });
    }
}
