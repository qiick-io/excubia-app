<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Defaults application responses to no-store so browsers cannot retain sensitive data. */
final class PreventSensitiveResponseCachingMiddleware
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Branding is public before sign-in. Its controller sets the asset cache lifetime.
        if (! $request->routeIs('branding.show') || ! $response->isSuccessful()) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
