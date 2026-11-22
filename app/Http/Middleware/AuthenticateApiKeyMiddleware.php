<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\RecordApiKeyUseAction;
use App\Enums\ApiKeyScopeEnum;
use App\Exceptions\ApiKeyRejectedException;
use App\Models\ApiKey;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use ValueError;

/**
 * Authenticates a request by a personal API key (#315, #351) as `Authorization: Bearer <token>`,
 * bound to the dedicated `api_key` guard (never the `web` session guard). Use with the scope the
 * route needs: `AuthenticateApiKeyMiddleware::class.':mcp:read'`.
 *
 * Lookup is by the public `key_prefix`, then `hash_equals` on SHA-256 of the full presented token
 * (and the previous hash inside the rotation grace window). Failures are RFC 6750-shaped through
 * {@see ApiKeyRejectedException} (fleet contract with externa-core 1.5.0).
 */
class AuthenticateApiKeyMiddleware
{
    /** Failed attempts one IP may make per minute before every attempt answers 429. */
    public const FAILED_ATTEMPTS_PER_MINUTE = 20;

    public function __construct(private readonly RecordApiKeyUseAction $recordApiKeyUse) {}

    /**
     * @param  Closure(Request): (Response)  $next
     * @param  string  $scope  an ApiKeyScopeEnum value the key must carry
     */
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $failuresKey = 'api-key-failures:'.$request->ip();

        if (RateLimiter::tooManyAttempts($failuresKey, self::FAILED_ATTEMPTS_PER_MINUTE)) {
            return $this->tooManyRequests(RateLimiter::availableIn($failuresKey));
        }

        $plainToken = $request->bearerToken();
        $publicPrefix = is_string($plainToken) && $plainToken !== ''
            ? ApiKey::publicPrefixFromToken($plainToken)
            : null;

        $apiKey = $publicPrefix === null
            ? null
            : ApiKey::query()->with('owner')->where('key_prefix', $publicPrefix)->first();

        if (
            $plainToken === null
            || $plainToken === ''
            || $publicPrefix === null
            || $apiKey === null
            || ! $apiKey->matchesPresentedToken($plainToken)
        ) {
            RateLimiter::hit($failuresKey);

            return ApiKeyRejectedException::unknownKey()->render();
        }

        if ($apiKey->isRevoked()) {
            RateLimiter::hit($failuresKey);

            return ApiKeyRejectedException::revokedKey()->render();
        }

        if ($apiKey->isExpired()) {
            RateLimiter::hit($failuresKey);

            return ApiKeyRejectedException::expiredKey()->render();
        }

        // A soft-deleted owner is hidden by the relation's default scope: treat the missing
        // owner like an inactive one, never as a reason to dereference null.
        $owner = $apiKey->owner;

        if ($owner === null || ! $owner->is_active) {
            RateLimiter::hit($failuresKey);

            return ApiKeyRejectedException::unusableKey(__('The API key owner is no longer active.'))->render();
        }

        try {
            $requiredScope = ApiKeyScopeEnum::from($scope);
        } catch (ValueError) {
            RateLimiter::hit($failuresKey);

            return ApiKeyRejectedException::noScopes()->render();
        }

        // Stored scopes ∩ owner's current permissions: a key whose owner loses a permission
        // loses that scope on the next request (fleet ceiling).
        if (! $apiKey->hasScope($requiredScope) || ! $requiredScope->isHeldBy($owner)) {
            RateLimiter::hit($failuresKey);

            return ApiKeyRejectedException::insufficientScope($requiredScope)->render();
        }

        if (! $apiKey->allowsIp((string) $request->ip())) {
            RateLimiter::hit($failuresKey);

            return ApiKeyRejectedException::ipNotAllowed()->render();
        }

        $limit = $apiKey->rate_limit_per_minute
            ?? (int) config('excubia.api_key_requests_per_minute');
        $requestsKey = 'api-key:'.$apiKey->id;

        if ($limit > 0 && RateLimiter::tooManyAttempts($requestsKey, $limit)) {
            return $this->tooManyRequests(RateLimiter::availableIn($requestsKey));
        }

        if ($limit > 0) {
            RateLimiter::hit($requestsKey);
        }

        // Dedicated `api_key` guard only: the web session guard stays untouched so key auth cannot
        // mint a session. shouldUse makes Auth::user() (and laravel/mcp) resolve the owner; User::
        // getDefaultGuardName() keeps Spatie on `web`. Restore the previous default afterwards.
        $request->attributes->set(ApiKey::class, $apiKey);
        Auth::guard('api_key')->setUser($owner);
        $previousGuard = Auth::getDefaultDriver();
        Auth::shouldUse('api_key');
        $this->recordApiKeyUse->execute($apiKey, $request->ip());

        try {
            return $next($request);
        } finally {
            Auth::shouldUse($previousGuard);
        }
    }

    private function tooManyRequests(int $retryAfterSeconds): JsonResponse
    {
        return response()->json(
            ['message' => 'Too many requests.', 'error' => 'rate_limit_exceeded'],
            Response::HTTP_TOO_MANY_REQUESTS,
            ['Retry-After' => (string) $retryAfterSeconds],
        );
    }
}
