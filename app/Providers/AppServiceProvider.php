<?php

namespace App\Providers;

use App\Enums\PermissionEnum;
use App\Models\User;
use App\Policies\RolePolicy;
use App\Services\EffectivePermissionResolverService;
use App\Services\SettingsRepositoryService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
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
    }

    /**
     * Permission abilities resolve from direct and group roles; super-admin passes all of them.
     * Policies still apply their own rules (for example, built-in roles stay read-only for everyone).
     */
    protected function configureAuthorization(): void
    {
        Gate::policy(Role::class, RolePolicy::class);

        Gate::before(fn (User $user, string $ability): ?bool => PermissionEnum::tryFrom($ability) !== null
            && app(EffectivePermissionResolverService::class)->hasPermission($user, $ability) ? true : null);
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
