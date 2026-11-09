<?php

use App\Http\Middleware\EnsureTwoFactorIsEnabledMiddleware;
use App\Http\Middleware\EnsureUserIsActiveMiddleware;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RecordIngestRejectionMiddleware;
use App\Http\Middleware\SecurityHeadersMiddleware;
use App\Http\Middleware\SetLocaleMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Auth-free liveness and readiness probes, as externa-core.
        then: function (): void {
            require __DIR__.'/../routes/health.php';
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Pushed reports are stored as sent, so their strings are neither trimmed nor nulled.
        $middleware->trimStrings(except: [fn (Request $request) => $request->is('api/*')]);
        $middleware->convertEmptyStringsToNull(except: [fn (Request $request) => $request->is('api/*')]);

        // The throttle is in Laravel's priority list and would be sorted ahead of the rejection
        // recorder, so its 429s were never counted (#244). Rate-limited pushes still cost one
        // counter upsert per endpoint, status, reason and hour, never a row per request.
        $middleware->prependToPriorityList(ThrottleRequests::class, RecordIngestRejectionMiddleware::class);

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state', 'locale', 'accessibility_high_contrast', 'accessibility_reduce_motion']);

        $middleware->web(prepend: [SecurityHeadersMiddleware::class], append: [
            EnsureUserIsActiveMiddleware::class,
            SetLocaleMiddleware::class,
            EnsureTwoFactorIsEnabledMiddleware::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
