<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\EffectivePermissionResolverService;
use App\Services\SettingsRepositoryService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => app(SettingsRepositoryService::class)->appName(),
            'auth' => [
                'user' => $user,
                // Only hides UI: the server still checks every request.
                'permissions' => $user instanceof User ? app(EffectivePermissionResolverService::class)->permissionsFor($user) : [],
                'roleNames' => $user instanceof User ? app(EffectivePermissionResolverService::class)->roleNamesFor($user) : [],
                'isSuperAdmin' => $user instanceof User && app(EffectivePermissionResolverService::class)->isSuperAdmin($user),
            ],
            'locale' => app()->getLocale(),
            'availableLocales' => config('i18n.available_locales', ['en' => 'English']),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
