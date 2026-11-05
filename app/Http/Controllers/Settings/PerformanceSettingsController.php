<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\PermissionEnum;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\PermissionRegistrar;

/**
 * Runtime and bootstrap cache status, the queue monitor link, and the permission cache flush.
 * The routes require `can-manage-project-settings`.
 */
class PerformanceSettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/performance', [
            'status' => [
                'cacheStore' => (string) config('cache.default'),
                'queueConnection' => (string) config('queue.default'),
                'appEnv' => app()->environment(),
                'appDebug' => (bool) config('app.debug'),
            ],
            'bootstrap' => $this->bootstrapCacheStatus(),
            'links' => [
                'jobs' => $request->user()?->can(PermissionEnum::CanShowJobs->value) ? route('jobs.index') : null,
            ],
        ]);
    }

    /**
     * Clears Spatie's role and permission cache.
     */
    public function flushSpatiePermissions(PermissionRegistrar $permissionRegistrar): RedirectResponse
    {
        $permissionRegistrar->forgetCachedPermissions();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Spatie permission cache flushed.')]);

        return to_route('performance.edit');
    }

    /**
     * Which Laravel bootstrap cache files exist in bootstrap/cache.
     *
     * @return array{configCached: bool, routesCached: bool, eventsCached: bool, packagesCached: bool}
     */
    private function bootstrapCacheStatus(): array
    {
        return [
            'configCached' => app()->configurationIsCached(),
            'routesCached' => app()->routesAreCached(),
            'eventsCached' => app()->eventsAreCached(),
            'packagesCached' => is_file(app()->getCachedPackagesPath()),
        ];
    }
}
