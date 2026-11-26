<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Replaces Laravel's default error pages with the qiick error page (app-ui `docs/error-pages.md`,
 * #423): 403, 404, 500 and 503 of a browser request render `errors/error-page` in the app shell
 * (or the auth layout for guests). JSON, API and MCP requests keep Laravel's JSON errors; a 500
 * with debug mode on keeps the framework's debug page.
 */
final class ErrorPageResponse
{
    private const array STATUSES = [403, 404, 500, 503];

    /**
     * The response to send instead of `$response`, which the exception handler has already rendered.
     *
     * An expired session (419) goes back with a toast instead of an error page. Guests get the
     * page in the auth layout: signed-in routes send them to the login before any 404, so the
     * page reveals nothing about what exists.
     */
    public static function from(Response $response, Request $request): Response
    {
        $status = $response->getStatusCode();

        if ($request->is('api/*') || $request->is('mcp') || $request->expectsJson()) {
            return $response;
        }

        if ($status === 419) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Your session expired. Please try again.')]);

            return back();
        }

        if (! in_array($status, self::STATUSES, true) || ($status === 500 && app()->hasDebugModeEnabled())) {
            return $response;
        }

        // Route model binding and the maintenance check fail before HandleInertiaRequests runs, so
        // share its props here: the app shell needs `auth`. A request without a session (maintenance
        // mode, an unmatched URL) renders the guest layout.
        if ($request->hasSession()) {
            Inertia::share(app(HandleInertiaRequests::class)->share($request));
        }

        return Inertia::render('errors/error-page', ['status' => $status])
            ->toResponse($request)
            ->setStatusCode($status);
    }
}
