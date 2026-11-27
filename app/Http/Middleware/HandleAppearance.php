<?php

namespace App\Http\Middleware;

use App\Services\SettingsRepositoryService;
use App\Services\ThemePresetService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class HandleAppearance
{
    /**
     * Prefer the personal appearance cookie; fall back to the project default. Also shares the
     * resolved theme preset CSS variables for the `<style>` block in app.blade.php.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $cookie = $request->cookie('appearance');

        View::share(
            'appearance',
            in_array($cookie, SettingsRepositoryService::APPEARANCES, true) ? $cookie : app(SettingsRepositoryService::class)->defaultAppearance(),
        );
        // ponytail: a11y cookies mirror appearance — Blade stamps classes pre-paint (FOUC)
        View::share('accessibilityHighContrast', $request->cookie('accessibility_high_contrast') === '1');
        View::share('accessibilityReduceMotion', $request->cookie('accessibility_reduce_motion') === '1');
        View::share('tableDensityCompact', $request->cookie('table_density_compact') === '1');
        View::share('projectThemeCss', app(ThemePresetService::class)->current());
        View::share('projectFaviconUrl', app(SettingsRepositoryService::class)->brandingUrls()['faviconUrl']);

        return $next($request);
    }
}
