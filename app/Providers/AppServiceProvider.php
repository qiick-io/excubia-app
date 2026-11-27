<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\RecordAlertDeliveryAction;
use App\Jobs\DeliverAlertJob;
use App\Models\Activity as ActivityModel;
use App\Models\ApiKey;
use App\Models\Project;
use App\Models\User;
use App\Policies\ActivityPolicy;
use App\Policies\JobPolicy;
use App\Policies\PermissionPolicy;
use App\Policies\RolePolicy;
use App\Policies\SystemHealthPolicy;
use App\Services\AccessResponderService;
use App\Services\ActiveUsersService;
use App\Services\ApiKeyAuthorizerService;
use App\Services\ApiKeyExpiryRecipientService;
use App\Services\ApiKeyRepositoryService;
use App\Services\EnvironmentMaintenanceModeService;
use App\Services\JobMonitorQueryService;
use App\Services\OidcBreakGlassPolicyService;
use App\Services\OidcSecondFactorChallengeService;
use App\Services\OidcSettingsRepositoryService;
use App\Services\OidcUserProvisioningService;
use App\Services\PwnedPasswordVerifierService;
use App\Services\SelfMonitoringService;
use App\Services\SettingsRepositoryService;
use App\Services\SystemHealthService;
use App\Services\TwoFactorRequirementService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\FileBasedMaintenanceMode;
use Illuminate\Foundation\MaintenanceModeManager;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Qiick\Access\Contracts\ActiveUsers;
use Qiick\Access\Contracts\AuthorizationResponder;
use Qiick\Access\Contracts\RoleCatalog;
use Qiick\Access\Contracts\TwoFactorPolicy;
use Qiick\Access\Contracts\TwoFactorResponder;
use Qiick\ApiKeys\ApiKeyAuthenticator;
use Qiick\ApiKeys\Contracts\ApiKeyRepository;
use Qiick\ApiKeys\Contracts\AttemptLimiter;
use Qiick\ApiKeys\Contracts\Clock;
use Qiick\ApiKeys\Contracts\ExpiryRecipientResolver;
use Qiick\ApiKeys\Contracts\KeyAuthorizer;
use Qiick\ApiKeys\Contracts\ScopeVocabulary;
use Qiick\ApiKeys\FailedAttemptLimiter;
use Qiick\ApiKeys\IpAllowlist;
use Qiick\ApiKeys\TokenCodec;
use Qiick\ApiKeys\UsageRecorder;
use Qiick\Excubia\Contracts\HttpClientContract;
use Qiick\Excubia\Services\SymfonyHttpClientService;
use Qiick\Oidc\Contracts\BreakGlassPolicyContract;
use Qiick\Oidc\Contracts\ProviderConfigurationRepositoryContract;
use Qiick\Oidc\Contracts\SecondFactorChallengeContract;
use Qiick\Oidc\Contracts\UserProvisioningContract;
use Qiick\Oidc\Services\SsoOnlyModeService;
use Qiick\Oidc\Services\UserLinkingService;
use Qiick\Outbound\Events\WebhookDeliveredEvent;
use Qiick\Outbound\Events\WebhookDeliveryFailedEvent;
use Qiick\Outbound\Models\WebhookDelivery;
use RuntimeException;
use Spatie\Activitylog\Facades\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SettingsRepositoryService::class);
        $this->app->extend(
            UncompromisedVerifier::class,
            fn (): PwnedPasswordVerifierService => $this->app->make(PwnedPasswordVerifierService::class),
        );
        // The `excubia` maintenance driver (config/app.php): the file one, plus EXCUBIA_MAINTENANCE_SECRET.
        $this->app->afterResolving(MaintenanceModeManager::class, fn (MaintenanceModeManager $manager) => $manager->extend(
            'excubia',
            fn (): EnvironmentMaintenanceModeService => new EnvironmentMaintenanceModeService(new FileBasedMaintenanceMode),
        ));
        // The HTTP client excubia-core uses for the system report (#70); tests bind a fake.
        $this->app->bind(HttpClientContract::class, fn (): HttpClientContract => new SymfonyHttpClientService);
        $this->registerOidcBindings();
        $this->registerApiKeyBindings();
        $this->registerAccessBindings();
    }

    /**
     * Plugs Excubia into qiick/laravel-access (Gate::before, resolver, 2FA middleware,
     * principals): the super-admin role name, the two-factor rule and the 403 response.
     */
    protected function registerAccessBindings(): void
    {
        $this->app->bind(RoleCatalog::class, AccessResponderService::class);
        $this->app->bind(ActiveUsers::class, ActiveUsersService::class);
        $this->app->bind(AuthorizationResponder::class, AccessResponderService::class);
        $this->app->bind(TwoFactorPolicy::class, TwoFactorRequirementService::class);
        $this->app->bind(TwoFactorResponder::class, TwoFactorRequirementService::class);
    }

    /**
     * Plugs Excubia into qiick/laravel-api-keys: its storage, its owner and scope rules, the
     * expiry recipients (the first-use log is written by the repository, with the stamp). The authenticator is built here because its
     * limits come from config; the failed-attempt brake is the package's, sized by
     * `api-keys.failed_attempts_per_minute`.
     */
    protected function registerApiKeyBindings(): void
    {
        $this->app->bind(ApiKeyRepository::class, ApiKeyRepositoryService::class);
        $this->app->bind(KeyAuthorizer::class, ApiKeyAuthorizerService::class);
        $this->app->bind(ScopeVocabulary::class, ApiKeyAuthorizerService::class);
        $this->app->bind(ExpiryRecipientResolver::class, ApiKeyExpiryRecipientService::class);

        $this->app->bind(ApiKeyAuthenticator::class, fn (Application $app): ApiKeyAuthenticator => new ApiKeyAuthenticator(
            codec: $app->make(TokenCodec::class),
            repository: $app->make(ApiKeyRepository::class),
            authorizer: $app->make(KeyAuthorizer::class),
            vocabulary: $app->make(ScopeVocabulary::class),
            ipAllowlist: new IpAllowlist,
            usageRecorder: new UsageRecorder,
            clock: $app->make(Clock::class),
            attemptLimiter: $app->make(AttemptLimiter::class),
            failedAttemptLimiter: $app->make(FailedAttemptLimiter::class),
            usageThrottleSeconds: config()->integer('api-keys.usage_throttle_seconds', 60),
            bypassThrottleOnIpChange: config()->boolean('api-keys.usage_bypass_throttle_on_ip_change', true),
            defaultRateLimitPerMinute: config()->integer('api-keys.default_rate_limit_per_minute', 60),
        ));
    }

    /**
     * Plugs Excubia into qiick/laravel-oidc (#356). Its provider registers first, so these
     * bindings replace its defaults: the provider settings, JIT, auto-link, SSO-only mode and the
     * button label come from Settings → Application instead of `config('oidc.*')`.
     *
     * The package builds UserLinkingService and SsoOnlyModeService from config values, so both
     * are rebuilt here with the settings; their other arguments keep the package's config.
     */
    protected function registerOidcBindings(): void
    {
        $this->app->bind(ProviderConfigurationRepositoryContract::class, OidcSettingsRepositoryService::class);
        $this->app->bind(UserProvisioningContract::class, OidcUserProvisioningService::class);
        $this->app->bind(SecondFactorChallengeContract::class, OidcSecondFactorChallengeService::class);
        $this->app->bind(BreakGlassPolicyContract::class, OidcBreakGlassPolicyService::class);

        $this->app->bind(UserLinkingService::class, fn (Application $app): UserLinkingService => new UserLinkingService(
            userProvisioning: $app->make(UserProvisioningContract::class),
            events: $app->make(Dispatcher::class),
            isAutoLinkEnabled: $app->make(SettingsRepositoryService::class)->isOidcAutoLinkEnabled(),
            isJitEnabled: $app->make(SettingsRepositoryService::class)->isOidcJitProvisioningEnabled(),
            isVerifiedEmailRequired: config()->boolean('oidc.require_verified_email', true),
            allowedDomains: [],
            emailColumn: 'email',
            activeColumn: 'is_active',
        ));

        $this->app->bind(SsoOnlyModeService::class, fn (Application $app): SsoOnlyModeService => new SsoOnlyModeService(
            configurationRepository: $app->make(ProviderConfigurationRepositoryContract::class),
            breakGlassPolicy: $app->make(BreakGlassPolicyContract::class),
            isOnlyModeConfigured: $app->make(SettingsRepositoryService::class)->isOidcOnlyEnabled(),
            buttonLabel: $app->make(SettingsRepositoryService::class)->oidcButtonLabel(),
            breakGlassQueryParameter: 'local',
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuthorization();
        $this->configureActivityLogging();
        $this->registerAuthActivityListeners();
        $this->registerAlertWebhookListeners();
        $this->configureApiKeyGuard();
        $this->configureRateLimiting();
        $this->configureTrustedProxies();
        // Excubia's own runtime and exceptions for the system project (#84).
        app(SelfMonitoringService::class)->register($this->app);
    }

    /**
     * Registers the `api_key` request guard (#351): resolves the owner from the ApiKey the
     * AuthenticateApiKeyMiddleware attached to the request. Never touches the web session.
     */
    protected function configureApiKeyGuard(): void
    {
        Auth::viaRequest('api_key', function (Request $request): ?User {
            $apiKey = $request->attributes->get(ApiKey::class);

            return $apiKey instanceof ApiKey ? $apiKey->owner : null;
        });
    }

    /**
     * Trusts the reverse proxies of `excubia.trusted_proxies` (`TRUSTED_PROXIES`): `*` or a
     * comma-separated list. Nothing is trusted by default.
     */
    protected function configureTrustedProxies(): void
    {
        $proxies = trim((string) config('excubia.trusted_proxies'));

        if ($proxies === '') {
            return;
        }

        TrustProxies::at($proxies === '*' ? '*' : array_map(trim(...), explode(',', $proxies)));
    }

    /**
     * `excubia-push`: pushes per minute for one project, or per IP for a missing or unknown token.
     * Keying on the token itself would give every made-up token its own bucket (#51), so a client
     * could send unlimited requests by changing the token on each one.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('excubia-push', function (Request $request): Limit {
            $token = $request->bearerToken();
            $projectId = $token === null ? null : Project::query()->where('push_token_hash', hash('sha256', $token))->value('id');

            return Limit::perMinute(config('excubia.push_requests_per_minute'))
                ->by($projectId === null ? 'ip:'.$request->ip() : 'project:'.$projectId);
        });
    }

    /**
     * Policies of the models without a conventional name. qiick/laravel-access registers the only
     * Gate::before: permission abilities resolve from direct and group roles, super-admin passes
     * every ability without a Policy, and API actors (personal keys) never get either pass.
     * Policies still apply their own rules (for example, built-in roles stay read-only for everyone).
     */
    protected function configureAuthorization(): void
    {
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(ActivityModel::class, ActivityPolicy::class);
        Gate::policy(Permission::class, PermissionPolicy::class);
        // Queue rows have no model: the queue monitor's policy is keyed on its read service.
        Gate::policy(JobMonitorQueryService::class, JobPolicy::class);
        Gate::policy(SystemHealthService::class, SystemHealthPolicy::class);

    }

    /**
     * Every activity also records the request IP and user agent.
     */
    protected function configureActivityLogging(): void
    {
        Activity::beforeLogging(function (ActivityModel $activity): void {
            $activity->properties = ($activity->properties ?? collect())->merge([
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);
        });
    }

    /**
     * Writes the alert webhook's outcome on the alert's history row (#114): qiick/laravel-outbound
     * announces a delivery the receiver accepted, or one that ran out of attempts (or was refused
     * for good). The delivery's `event_id` is the alert's id.
     */
    protected function registerAlertWebhookListeners(): void
    {
        $recordOutcome = function (string $deliveryPublicId, bool $isSent): void {
            $alertId = WebhookDelivery::query()->where('public_id', $deliveryPublicId)->value('event_id');

            if (is_string($alertId)) {
                app(RecordAlertDeliveryAction::class)->execute($alertId, DeliverAlertJob::WEBHOOK, $isSent);
            }
        };

        Event::listen(WebhookDeliveredEvent::class, fn (WebhookDeliveredEvent $event) => $recordOutcome($event->deliveryPublicId, true));
        Event::listen(WebhookDeliveryFailedEvent::class, fn (WebhookDeliveryFailedEvent $event) => $recordOutcome($event->deliveryPublicId, false));
    }

    /**
     * Logs logins, logouts and failed login attempts in the `auth` log.
     */
    protected function registerAuthActivityListeners(): void
    {
        Event::listen(Login::class, function (Login $event): void {
            if (! $event->user instanceof User) {
                return;
            }

            activity()
                ->causedBy($event->user)
                ->useLog('auth')
                ->event('login')
                ->log('User logged in');
        });

        Event::listen(Logout::class, function (Logout $event): void {
            if (! $event->user instanceof User) {
                return;
            }

            activity()
                ->causedBy($event->user)
                ->useLog('auth')
                ->event('logout')
                ->log('User logged out');
        });

        Event::listen(Failed::class, function (Failed $event): void {
            $identifier = $event->credentials['email'] ?? null;

            activity()
                ->causedByAnonymous()
                ->useLog('auth')
                ->event('failed')
                ->withProperties([
                    'identifier' => is_string($identifier) ? $identifier : null,
                ])
                ->log('Failed login attempt');
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        $this->assertOutboundHttpsOnly();

        // Lazy loading, silently discarded and missing attributes throw outside
        // production, so tests catch them (qiick standards, Automated checks).
        Model::shouldBeStrict(! app()->isProduction());

        Password::defaults(fn (): Password => app(SettingsRepositoryService::class)->passwordRule());

        // Values echoed into Markdown mails (the weekly digest) come from pushed reports: escape
        // their `[` and `<`, so a package or task name cannot plant a link in the email (#51).
        Markdown::withSecuredEncoding();
    }

    /**
     * Alert deliveries are HTTPS-only: a self-hoster must not weaken that in production via
     * OUTBOUND_HTTPS_ONLY (adversarial review of #399 / #400).
     *
     * @throws RuntimeException when production would allow plain HTTP outbound
     */
    protected function assertOutboundHttpsOnly(): void
    {
        if (! $this->app->isProduction()) {
            return;
        }

        // Read the published config (env() is forbidden outside config/). Config already
        // fail-closes via filter_var(…, FILTER_NULL_ON_FAILURE) === true.
        if (! self::outboundHttpsOnlyIsStrictlyEnabled(config('outbound.ssrf.https_only'))) {
            throw new RuntimeException(
                'OUTBOUND_HTTPS_ONLY must be true in production: alert deliveries must use HTTPS.',
            );
        }
    }

    /**
     * Fail-closed parse of OUTBOUND_HTTPS_ONLY: only filter_var(…, FILTER_VALIDATE_BOOLEAN,
     * FILTER_NULL_ON_FAILURE) === true counts as enabled. Values such as "0", "no", "off", ""
     * or null are rejected (a PHP (bool) cast or === false would let several of those through).
     */
    public static function outboundHttpsOnlyIsStrictlyEnabled(mixed $raw): bool
    {
        return filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
    }
}
