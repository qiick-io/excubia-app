<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class HandleAppearance
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        View::share('appearance', $request->cookie('appearance') ?? 'system');
        // ponytail: a11y cookies mirror appearance — Blade stamps classes pre-paint (FOUC)
        View::share('accessibilityHighContrast', $request->cookie('accessibility_high_contrast') === '1');
        View::share('accessibilityReduceMotion', $request->cookie('accessibility_reduce_motion') === '1');

        return $next($request);
    }
}
