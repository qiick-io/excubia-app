<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureUserIsActiveMiddleware;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PreventSensitiveResponseCachingMiddleware;
use App\Http\Middleware\RecordIngestRejectionMiddleware;
use App\Http\Middleware\SecurityHeadersMiddleware;
use App\Http\Middleware\SetLocaleMiddleware;
use App\Http\Responses\ErrorPageResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Validation\ValidationException;
use Qiick\Access\ExclusiveSuperAdminException;
use Qiick\Access\Http\EnsureTwoFactorIsEnabled;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Auth-free liveness and readiness probes, as externa-core.
        then: function (): void {
            require __DIR__.'/../routes/health.php';
            // The read-only MCP server (#234), outside the web group: no session, no CSRF.
            require __DIR__.'/../routes/mcp.php';
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Apply the cache policy even to errors rendered outside the web group.
        $middleware->prepend(PreventSensitiveResponseCachingMiddleware::class);

        // Pushed reports are stored as sent, so their strings are neither trimmed nor nulled.
        $middleware->trimStrings(except: [fn (Request $request) => $request->is('api/*')]);
        $middleware->convertEmptyStringsToNull(except: [fn (Request $request) => $request->is('api/*')]);

        // The throttle is in Laravel's priority list and would be sorted ahead of the rejection
        // recorder, so its 429s were never counted (#244). Rate-limited pushes still cost one
        // counter upsert per endpoint, status, reason and hour, never a row per request.
        $middleware->prependToPriorityList(ThrottleRequests::class, RecordIngestRejectionMiddleware::class);

        // Health probes answer in maintenance too, so a platform (Laravel Cloud, Docker) does not
        // mark a deploy failed or restart a container that is down on purpose.
        $middleware->preventRequestsDuringMaintenance(except: ['health/live', 'health/ready']);

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state', 'locale', 'accessibility_high_contrast', 'accessibility_reduce_motion', 'table_density_compact']);
        $middleware->web(prepend: [SecurityHeadersMiddleware::class], append: [
            EnsureUserIsActiveMiddleware::class,
            SetLocaleMiddleware::class,
            EnsureTwoFactorIsEnabled::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Never flashed back as old input on a validation error (the session is not encrypted).
        $exceptions->dontFlash(['current_password', 'password', 'password_confirmation', 'setup_token']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('mcp') || $request->expectsJson(),
        );
        // Role writes route through ExclusiveSuperAdmin::write(); a refusal (a race past the form
        // checks, or a promotion by an actor who may not manage super-admins) is a form error.
        $exceptions->map(ExclusiveSuperAdminException::class, fn (): ValidationException => ValidationException::withMessages([
            'roles' => __('This role change is refused: super-admin stands alone, and only a super-admin may make someone one.'),
        ]));
        // The qiick 403 / 404 / 500 / 503 pages for browser requests (#423).
        $exceptions->respond(fn (Response $response, Throwable $exception, Request $request): Response => ErrorPageResponse::from($response, $request));
    })->create();
