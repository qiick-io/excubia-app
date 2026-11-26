<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Inertia\Response as InertiaResponse;
use Inertia\Support\Header;
use Inertia\Support\SessionKey;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hands a newly issued secret to the next page GET that shows it once: an API key on Settings → API
 * keys (#365 review), the alert webhook's signing secret on Settings → Integrations (its own
 * session key, {@see self::WEBHOOK_SESSION_KEY}).
 *
 * Neither the plaintext nor its ciphertext enters the session (SESSION_ENCRYPT is off by
 * default): the session holds a random token, and the APP_KEY-encrypted payload waits in the
 * cache under it. The first page that claims the token (an atomic `Cache::add`) shows the
 * secret, so two requests that loaded the same session cannot both show it. Payloads older
 * than {@see self::TTL_SECONDS} are dropped unread.
 */
final class ApiKeySecretOnceService
{
    public const SESSION_KEY = 'api_key_secret_token';

    public const WEBHOOK_SESSION_KEY = 'alert_webhook_secret_token';

    /** Cache key prefix of a pending payload; `{prefix}{token}:claimed` marks it taken. */
    public const CACHE_PREFIX = 'secret-once:';

    /** How long a pending secret may wait before the page refuses it. */
    public const TTL_SECONDS = 600;

    /**
     * @param  'created'|'rotated'  $reason
     *
     * @side-effect Puts a token in the session and the encrypted payload in the cache until the next
     * full page GET. Uses put (not Laravel flash): Inertia's redirect follow can age flash bags
     * before the page reads them.
     */
    public function store(
        Session $session,
        string $plainSecret,
        string $keyName,
        string $reason = 'created',
        ?string $previousKeyValidUntil = null,
        string $sessionKey = self::SESSION_KEY,
    ): void {
        $token = Str::random(40);

        Cache::put(self::CACHE_PREFIX.$token, Crypt::encryptString(json_encode([
            'secret' => $plainSecret,
            'name' => $keyName,
            'reason' => $reason,
            'previous_key_valid_until' => $previousKeyValidUntil,
            'issued_at' => now()->getTimestamp(),
        ], JSON_THROW_ON_ERROR)), self::TTL_SECONDS);
        $session->put($sessionKey, $token);
    }

    /**
     * Claim and decrypt the one-time payload, or null when nothing was stored, it expired, or
     * another request claimed it first.
     *
     * @return array{secret: string, name: string, reason: string, previous_key_valid_until: string|null}|null
     *
     * @side-effect Removes the session token and the cached payload.
     */
    public function pull(Session $session, string $sessionKey = self::SESSION_KEY): ?array
    {
        $token = $session->pull($sessionKey);

        if (! is_string($token) || $token === '') {
            return null;
        }

        if (! Cache::add(self::CACHE_PREFIX.$token.':claimed', true, self::TTL_SECONDS)) {
            return null;
        }

        $encrypted = Cache::pull(self::CACHE_PREFIX.$token);

        if (! is_string($encrypted) || $encrypted === '') {
            return null;
        }

        /** @var array{secret?: mixed, name?: mixed, reason?: mixed, previous_key_valid_until?: mixed, issued_at?: mixed} $payload */
        $payload = json_decode(Crypt::decryptString($encrypted), true, 512, JSON_THROW_ON_ERROR);

        if (! is_string($payload['secret'] ?? null) || $payload['secret'] === '') {
            return null;
        }

        $issuedAt = is_int($payload['issued_at'] ?? null)
            ? $payload['issued_at']
            : (is_numeric($payload['issued_at'] ?? null) ? (int) $payload['issued_at'] : null);

        if ($issuedAt === null || now()->getTimestamp() - $issuedAt > self::TTL_SECONDS) {
            return null;
        }

        return [
            'secret' => $payload['secret'],
            'name' => is_string($payload['name'] ?? null) ? $payload['name'] : '',
            'reason' => ($payload['reason'] ?? 'created') === 'rotated' ? 'rotated' : 'created',
            'previous_key_valid_until' => is_string($payload['previous_key_valid_until'] ?? null)
                ? $payload['previous_key_valid_until']
                : null,
        ];
    }

    /**
     * Whether this request may consume a pending secret: a full page visit only. A partial reload
     * (another tab refreshing a prop) and a prefetch would take it from the page that shows it.
     */
    public function isShowingRequest(Request $request, string $component): bool
    {
        return $request->header(Header::PARTIAL_COMPONENT) !== $component
            && $request->header('Purpose') !== 'prefetch';
    }

    /**
     * Render the page with the secret in Inertia's one-time flash, without the plaintext ever
     * reaching the stored session: Inertia keeps flash in the session until the page resolves,
     * so a shared prop that throws first would let Laravel save it there (and a later response
     * would carry it). The flash key is dropped from the session whatever the render does.
     *
     * @param  array<string, mixed>|null  $flashValue  null renders the page without a secret
     */
    public function render(Request $request, InertiaResponse $page, string $flashKey, ?array $flashValue): Response
    {
        if ($flashValue !== null) {
            $page->flash($flashKey, $flashValue);
        }

        try {
            $response = $page->toResponse($request);
        } finally {
            $request->session()->forget(SessionKey::FLASH_DATA.'.'.$flashKey);
        }

        // The page can carry a secret: never cache it.
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
