<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

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
     * An expired session (419), and a 500 or 503 of a form post or action, go back to the page the
     * user was on with a toast, so a reload never re-sends to an action URL. Guests get the page in
     * the auth layout: signed-in routes send them to the login before any 404, so the page reveals
     * nothing about what exists. If the page itself cannot be rendered (the database is down), the
     * framework's response stands.
     */
    public static function from(Response $response, Request $request): Response
    {
        $status = $response->getStatusCode();

        if ($request->is('api/*') || $request->is('mcp') || $request->expectsJson()) {
            return $response;
        }

        if (! in_array($status, [419, ...self::STATUSES], true) || ($status === 500 && app()->hasDebugModeEnabled())) {
            return $response;
        }

        try {
            if ($status === 419 || ($status >= 500 && ! $request->isMethod('GET') && $request->hasSession())) {
                return self::backWithToast($request, $status === 419
                    ? __('Your session expired. Please try again.')
                    : __('Something went wrong. Please try again.'));
            }

            return self::errorPage($response, $request, $status);
        } catch (Throwable) {
            return $response;
        }
    }

    private static function errorPage(Response $response, Request $request, int $status): Response
    {
        // Route model binding fails before HandleInertiaRequests runs, so share its props here: the
        // app shell needs `auth`. A 5xx may come from the database those props read, so it renders
        // the guest layout, as does a request without a session (maintenance mode, an unmatched URL).
        if ($status < 500 && $request->hasSession()) {
            Inertia::share(app(HandleInertiaRequests::class)->share($request));
        }

        $page = Inertia::render('errors/error-page', ['status' => $status])
            ->toResponse($request)
            ->setStatusCode($status);
        $page->headers->set('Vary', 'X-Inertia');

        // Maintenance mode's Retry-After and Refresh headers keep working.
        foreach (['Retry-After', 'Refresh'] as $header) {
            if ($response->headers->has($header)) {
                $page->headers->set($header, $response->headers->get($header));
            }
        }

        return $page;
    }

    /**
     * Back to the previous page of this app (never a foreign Referer), with an error toast.
     */
    private static function backWithToast(Request $request, string $message): RedirectResponse
    {
        $previousUrl = url()->previous();
        $isLocal = parse_url($previousUrl, PHP_URL_HOST) === $request->getHost();

        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return redirect()->to($isLocal ? $previousUrl : route('dashboard'));
    }
}
