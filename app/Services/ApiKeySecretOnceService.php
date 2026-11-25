<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Crypt;

/**
 * Hands a newly issued secret to the next page GET that shows it once: an API key on Settings → API
 * keys (#365 review), the alert webhook's signing secret on Settings → Integrations (its own
 * session key, {@see self::WEBHOOK_SESSION_KEY}).
 *
 * The plaintext never enters the session (SESSION_ENCRYPT is off by default): only an
 * APP_KEY-encrypted JSON payload is stored. Index pulls it once and flashes it through
 * Inertia (stripped from history.state). Payloads older than {@see self::TTL_SECONDS}
 * are dropped unread.
 */
final class ApiKeySecretOnceService
{
    public const SESSION_KEY = 'api_key_secret_encrypted';

    public const WEBHOOK_SESSION_KEY = 'alert_webhook_secret_encrypted';

    /** How long a pending secret may sit in the session before index refuses it. */
    public const TTL_SECONDS = 600;

    /**
     * @param  'created'|'rotated'  $reason
     *
     * @side-effect Puts an encrypted payload in the session until the next full Settings → API keys GET.
     * Uses put (not Laravel flash): Inertia's redirect follow can age flash bags before index reads them.
     */
    public function store(
        Session $session,
        string $plainSecret,
        string $keyName,
        string $reason = 'created',
        ?string $previousKeyValidUntil = null,
        string $sessionKey = self::SESSION_KEY,
    ): void {
        $session->put($sessionKey, Crypt::encryptString(json_encode([
            'secret' => $plainSecret,
            'name' => $keyName,
            'reason' => $reason,
            'previous_key_valid_until' => $previousKeyValidUntil,
            'issued_at' => now()->getTimestamp(),
        ], JSON_THROW_ON_ERROR)));
    }

    /**
     * Decrypt and consume the one-time payload, or null when nothing was stored or it expired.
     *
     * @return array{secret: string, name: string, reason: string, previous_key_valid_until: string|null}|null
     *
     * @side-effect Removes the session key.
     */
    public function pull(Session $session, string $sessionKey = self::SESSION_KEY): ?array
    {
        $encrypted = $session->pull($sessionKey);

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
}
