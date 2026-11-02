<?php

namespace App\Providers;

use App\Enums\PermissionEnum;
use App\Models\User;
use App\Policies\ActivityPolicy;
use App\Policies\JobPolicy;
use App\Policies\PermissionPolicy;
use App\Policies\RolePolicy;
use App\Services\EffectivePermissionResolverService;
use App\Services\JobMonitorQueryService;
use App\Services\SettingsRepositoryService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Spatie\Activitylog\Facades\Activity;
use Spatie\Activitylog\Models\Activity as ActivityModel;
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
    }

    /**
     * Permission abilities resolve from direct and group roles; super-admin passes all of them.
     * Policies still apply their own rules (for example, built-in roles stay read-only for everyone).
     */
    protected function configureAuthorization(): void
    {
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(ActivityModel::class, ActivityPolicy::class);
        Gate::policy(Permission::class, PermissionPolicy::class);
        // Queue rows have no model: the queue monitor's policy is keyed on its read service.
        Gate::policy(JobMonitorQueryService::class, JobPolicy::class);

        Gate::before(fn (User $user, string $ability): ?bool => PermissionEnum::tryFrom($ability) !== null
            && app(EffectivePermissionResolverService::class)->hasPermission($user, $ability) ? true : null);
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

        Password::defaults(fn (): Password => app(SettingsRepositoryService::class)->passwordRule());
    }
}
