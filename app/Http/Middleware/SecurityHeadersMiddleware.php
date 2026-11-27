<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers on every web response (#153): no framing (clickjacking on actions
 * such as rotating a push token), no MIME sniffing, no full URLs in the Referer to other
 * origins, and no camera, microphone, geolocation or payment APIs. HSTS is sent when `excubia.hsts_max_age`
 * is above 0 (by default one year in production with an https APP_URL, #478) and the request or
 * APP_URL is https.
 *
 * Headers a response already sets (the branding route's own CSP and `nosniff`) are kept.
 *
 * ponytail: no full Content-Security-Policy; the Vite dev server and the inline appearance
 * script in app.blade.php would need nonces. Add one with Vite's nonce support if needed.
 */
class SecurityHeadersMiddleware
{
    /** @var array<string, string> */
    private const HEADERS = [
        'X-Frame-Options' => 'DENY',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
    ];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach (self::HEADERS as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        $hstsMaxAge = (int) config('excubia.hsts_max_age');

        // Behind a TLS proxy without TRUSTED_PROXIES the request looks like http; an https APP_URL
        // still says the site is https-only (#478). Browsers ignore the header over plain http.
        $isHttpsSite = $request->isSecure() || str_starts_with((string) config('app.url'), 'https://');

        if ($hstsMaxAge > 0 && $isHttpsSite) {
            $response->headers->set('Strict-Transport-Security', 'max-age='.$hstsMaxAge);
        }

        return $response;
    }
}
