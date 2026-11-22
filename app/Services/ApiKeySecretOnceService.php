<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Crypt;

/**
 * Hands a newly issued API key secret to the next Settings → API keys GET (#365 review).
 *
 * The plaintext never enters the session (SESSION_ENCRYPT is off by default): only an
 * APP_KEY-encrypted JSON payload is stored, and it is pulled and decrypted once.
 */
final class ApiKeySecretOnceService
{
    public const SESSION_KEY = 'api_key_secret_encrypted';

    /**
     * @param  'created'|'rotated'  $reason
     *
     * @side-effect Puts an encrypted payload in the session until the next Settings → API keys GET.
     * Uses put (not flash): Inertia's redirect follow can age flash bags before the page reads them.
     */
    public function store(
        Session $session,
        string $plainSecret,
        string $keyName,
        string $reason = 'created',
        ?string $previousKeyValidUntil = null,
    ): void {
        $session->put(self::SESSION_KEY, Crypt::encryptString(json_encode([
            'secret' => $plainSecret,
            'name' => $keyName,
            'reason' => $reason,
            'previous_key_valid_until' => $previousKeyValidUntil,
        ], JSON_THROW_ON_ERROR)));
    }

    /**
     * Decrypt and consume the one-time payload, or null when nothing was stored.
     *
     * @return array{secret: string, name: string, reason: string, previous_key_valid_until: string|null}|null
     *
     * @side-effect Removes the session key.
     */
    public function pull(Session $session): ?array
    {
        $encrypted = $session->pull(self::SESSION_KEY);

        if (! is_string($encrypted) || $encrypted === '') {
            return null;
        }

        /** @var array{secret?: mixed, name?: mixed, reason?: mixed, previous_key_valid_until?: mixed} $payload */
        $payload = json_decode(Crypt::decryptString($encrypted), true, 512, JSON_THROW_ON_ERROR);

        if (! is_string($payload['secret'] ?? null) || $payload['secret'] === '') {
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
