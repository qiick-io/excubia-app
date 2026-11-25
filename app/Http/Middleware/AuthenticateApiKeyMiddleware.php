<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Services\ApiKeyAuthorizerService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Qiick\Access\Contracts\ActorContext;
use Qiick\Access\Contracts\ActorKind;
use Qiick\Access\Laravel\AccessContext;
use Qiick\ApiKeys\ApiKeyAuthenticator;
use Qiick\ApiKeys\Exceptions\ApiKeyRejection;
use Qiick\ApiKeys\Http\BearerTokenExtractor;
use Qiick\ApiKeys\Http\KeyContextFactory;
use Qiick\ApiKeys\Scope;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a request by a personal API key (#315, #351) as `Authorization: Bearer <token>`,
 * bound to the dedicated `api_key` guard (never the `web` session guard). Use with the scope the
 * route needs: `AuthenticateApiKeyMiddleware::class.':mcp:read'`.
 *
 * qiick/laravel-api-keys does the work: the failed-attempt brake per client IP, lookup by the
 * public prefix and `hash_equals` on SHA-256 of the full token (and the previous hash inside the
 * rotation grace window), revocation and expiry, the owner's state and permissions
 * ({@see ApiKeyAuthorizerService}), IP allowlist, scopes, rate limits and the usage
 * stamp. Every refusal is its RFC 6750-shaped fleet JSON.
 */
class AuthenticateApiKeyMiddleware
{
    public function __construct(
        private readonly ApiKeyAuthenticator $authenticator,
        private readonly BearerTokenExtractor $bearerTokenExtractor,
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     * @param  string  $scope  an ApiKeyScopeEnum value the key must carry
     */
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $requiredScope = Scope::tryFromString($scope);

        if ($requiredScope === null) {
            return ApiKeyRejection::noUsableScopes()->render();
        }

        try {
            // A missing header goes through the authenticator too, so the brake counts it.
            $authentication = $this->authenticator->authenticate(
                $this->bearerTokenExtractor->fromRequest($request) ?? '',
                KeyContextFactory::fromRequest($request),
                $requiredScope,
            );
        } catch (ApiKeyRejection $rejection) {
            return $rejection->render();
        }

        /** @var ApiKey $apiKey the repository only returns Excubia's own rows */
        $apiKey = $authentication->record;
        $owner = $apiKey->owner;

        // The authorizer already refused a key without an active owner; never bind a null user.
        if ($owner === null) {
            return ApiKeyRejection::invalidToken()->render();
        }

        // Dedicated `api_key` guard only: the web session guard stays untouched so key auth cannot
        // mint a session. shouldUse makes Auth::user() (and laravel/mcp) resolve the owner; User::
        // getDefaultGuardName() keeps Spatie on `web`. Restore the previous default afterwards.
        $request->attributes->set(ApiKey::class, $apiKey);
        Auth::guard('api_key')->setUser($owner);
        $previousGuard = Auth::getDefaultDriver();
        Auth::shouldUse('api_key');
        // The bound API actor: qiick/laravel-access never gives it the super-admin or permission
        // pass of Gate::before, and queued jobs dispatched now carry it.
        AccessContext::enter(new ActorContext(ActorKind::ApiKey, $owner, $apiKey->uuid));

        try {
            return $next($request);
        } finally {
            AccessContext::clear();
            Auth::shouldUse($previousGuard);
        }
    }
}
