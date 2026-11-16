<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\RecordApiKeyUseAction;
use App\Enums\ApiKeyScopeEnum;
use App\Models\ApiKey;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a request by a personal API key (#315), sent as `Authorization: Bearer <key>`,
 * as externa-core's AuthenticateMcpRequestMiddleware. Use it with the scope the route needs:
 * `AuthenticateApiKeyMiddleware::class.':mcp:read'`.
 *
 * The key acts as its owner: the owner becomes the authenticated user for this request only (no
 * session), so every policy and `Project::visibleTo()` apply exactly as in the UI. A revoked or
 * expired key, or a deactivated owner, is rejected on the very next request; deleting the owner
 * deletes the key.
 *
 * Rate limited twice: failed attempts per IP (a guessed key never reaches the per-key bucket),
 * then `excubia.api_key_requests_per_minute` per key.
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

        $plainKey = $request->bearerToken();
        $apiKey = $plainKey === null || $plainKey === ''
            ? null
            : ApiKey::query()->with('user')->where('key_hash', ApiKey::hashKey($plainKey))->first();

        if ($apiKey === null || ! $apiKey->isActive() || ! $apiKey->user->is_active) {
            RateLimiter::hit($failuresKey);

            return response()->json(['message' => 'Missing, unknown, revoked or expired API key.'], Response::HTTP_UNAUTHORIZED);
        }

        if (! $apiKey->hasScope(ApiKeyScopeEnum::from($scope))) {
            RateLimiter::hit($failuresKey);

            return response()->json(['message' => "This API key lacks the {$scope} scope."], Response::HTTP_FORBIDDEN);
        }

        $requestsKey = 'api-key:'.$apiKey->id;

        if (RateLimiter::tooManyAttempts($requestsKey, (int) config('excubia.api_key_requests_per_minute'))) {
            return $this->tooManyRequests(RateLimiter::availableIn($requestsKey));
        }

        RateLimiter::hit($requestsKey);

        // The default guard, without touching a session: the owner is signed in for this request only.
        Auth::setUser($apiKey->user);
        $this->recordApiKeyUse->execute($apiKey);

        return $next($request);
    }

    private function tooManyRequests(int $retryAfterSeconds): JsonResponse
    {
        return response()->json(['message' => 'Too many requests.'], Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => (string) $retryAfterSeconds]);
    }
}
