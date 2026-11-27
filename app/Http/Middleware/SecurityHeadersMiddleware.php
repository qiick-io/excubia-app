<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers on every web response (#153): no framing (clickjacking on actions
 * such as rotating a push token), no MIME sniffing, no full URLs in the Referer to other
 * origins, and no camera, microphone, geolocation or payment APIs. HSTS is sent only on https
 * requests and only when `excubia.hsts_max_age` is above 0, since it is usually the TLS proxy's job.
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

        if ($hstsMaxAge > 0 && $request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age='.$hstsMaxAge);
        }

        return $response;
    }
}
