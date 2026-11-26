<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The one-time link that opens the first-run admin step (/setup). Without it a fresh install,
 * reachable before anyone has signed up, would hand super-admin to the first visitor. The
 * operator gets the link from `php artisan excubia:setup-link` (or the installer) on the server.
 *
 * Only a SHA-256 hash of the token is kept, in the cache, for {@see self::TTL_SECONDS}; a new
 * link replaces the previous one, and creating the first admin consumes it.
 */
class SetupTokenService
{
    private const CACHE_KEY = 'excubia:setup-token';

    public const TTL_SECONDS = 3600;

    /**
     * @side-effect Replaces the pending setup token in the cache.
     */
    public function issue(): string
    {
        $token = Str::random(48);
        Cache::put(self::CACHE_KEY, hash('sha256', $token), self::TTL_SECONDS);

        return $token;
    }

    public function isValid(mixed $token): bool
    {
        $expectedHash = Cache::get(self::CACHE_KEY);

        return is_string($token) && $token !== '' && is_string($expectedHash)
            && hash_equals($expectedHash, hash('sha256', $token));
    }

    /**
     * @side-effect Drops the pending token, so the link works once.
     */
    public function consume(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
