<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Date;
use Symfony\Component\HttpFoundation\Response;

/**
 * Limits web sessions from the original authentication, including sessions
 * restored from Laravel's encrypted remember cookie.
 */
class EnforceAbsoluteSessionLifetimeMiddleware
{
    private const string AUTHENTICATED_AT = 'browser_authenticated_at';

    /**
     * Runs after session and cookie setup and before the web guard authenticates.
     *
     * @param  Closure(Request): Response  $next
     *
     * @throws AuthenticationException when the current session has reached its absolute limit
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var SessionGuard $guard */
        $guard = Auth::guard('web');
        $session = $request->session();
        $now = Date::now()->getTimestamp();
        $lifetime = Config::integer('session.absolute_lifetime') * 60;
        $hasAuthenticatedSession = $session->has($guard->getName());

        $recalledAt = $this->validatedRememberedAt($request, $guard, $now, $lifetime);

        if ($hasAuthenticatedSession && $this->hasExpired($session->get(self::AUTHENTICATED_AT), $now, $lifetime)) {
            $guard->logoutCurrentDevice();
            $session->invalidate();
            $session->regenerateToken();

            throw new AuthenticationException(guards: ['web']);
        }

        $response = $next($request);

        if (! $hasAuthenticatedSession && $session->has($guard->getName())) {
            $authenticatedAt = $guard->viaRemember() ? $recalledAt : $now;
            $session->put(self::AUTHENTICATED_AT, $authenticatedAt);

            $rememberCookie = Cookie::queued($guard->getRecallerName());

            if ($authenticatedAt !== null && $rememberCookie !== null && $rememberCookie->getExpiresTime() > $now) {
                // Laravel reads the ID, token and password hash from the first three segments.
                // Add the original sign-in time before EncryptCookies authenticates the value.
                Cookie::queue($rememberCookie->withValue($rememberCookie->getValue().'|'.$authenticatedAt)
                    ->withExpires(min($rememberCookie->getExpiresTime(), $authenticatedAt + $lifetime)));
            }
        }

        return $response;
    }

    /** Drop expired or legacy remember cookies before the guard can restore a session. */
    private function validatedRememberedAt(Request $request, SessionGuard $guard, int $now, int $lifetime): ?int
    {
        $name = $guard->getRecallerName();
        $cookie = $request->cookie($name);

        if ($cookie === null) {
            return null;
        }

        $segments = is_string($cookie) ? explode('|', $cookie) : [];
        $authenticatedAt = filter_var($segments[3] ?? null, FILTER_VALIDATE_INT);

        if (count($segments) !== 4 || $authenticatedAt === false || $this->hasExpired($authenticatedAt, $now, $lifetime)) {
            $request->cookies->remove($name);
            Cookie::queue(Cookie::forget($name));

            return null;
        }

        return $authenticatedAt;
    }

    private function hasExpired(mixed $authenticatedAt, int $now, int $lifetime): bool
    {
        return ! is_int($authenticatedAt) || $authenticatedAt <= 0 || $authenticatedAt > $now || $now - $authenticatedAt >= $lifetime;
    }
}
