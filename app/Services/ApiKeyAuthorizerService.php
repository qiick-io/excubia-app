<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ApiKeyScopeEnum;
use App\Enums\PrincipalTypeEnum;
use App\Models\ApiKey;
use App\Models\User;
use Qiick\ApiKeys\AuthorizationDecision;
use Qiick\ApiKeys\Contracts\ApiKeyRecord;
use Qiick\ApiKeys\Contracts\KeyAuthorizer;
use Qiick\ApiKeys\Contracts\ScopeVocabulary;
use Qiick\ApiKeys\KeyContext;
use Qiick\ApiKeys\ResourceRestrictions;
use Qiick\ApiKeys\Scope;
use Qiick\ApiKeys\ScopeSet;

/**
 * Excubia's rules on top of qiick/laravel-api-keys (fleet ceiling): a key acts as its owner, so it
 * works only while the owner is an active user with an active principal, and each scope only
 * while the owner still holds the permissions that scope needs (checked on every request).
 *
 * Also the scope vocabulary: the values of {@see ApiKeyScopeEnum}. Excubia stores no resource
 * restrictions, so a key that carries some is refused rather than trusted (fail closed).
 */
class ApiKeyAuthorizerService implements KeyAuthorizer, ScopeVocabulary
{
    public function authorizeActor(ApiKeyRecord $record, KeyContext $context): AuthorizationDecision
    {
        if (! $record instanceof ApiKey) {
            return AuthorizationDecision::deny('The API key is not valid.');
        }

        // A soft-deleted owner is hidden by the relation's default scope: it reads as inactive.
        $owner = $record->owner;

        // The principal must be the owner's own `user` principal: two independent foreign keys
        // could otherwise pair an active user with someone else's (or a stale) principal.
        $principal = $record->ownerPrincipal;
        $isOwnersPrincipal = $principal->type === PrincipalTypeEnum::User && $principal->user_id === $record->owner_id;

        if ($owner === null || ! $owner->is_active || ! $isOwnersPrincipal || $principal->deactivated_at !== null) {
            return AuthorizationDecision::deny('The API key owner is no longer active.');
        }

        return AuthorizationDecision::allow();
    }

    /**
     * The required scope was already checked against the narrowed set: nothing more to decide.
     */
    public function authorizeScopes(ApiKeyRecord $record, ScopeSet $granted, ?Scope $required, KeyContext $context): AuthorizationDecision
    {
        return AuthorizationDecision::allow();
    }

    /**
     * @param  list<string>  $knownScopes
     * @return list<string>
     */
    public function scopesCreatorMayGrant(ApiKeyRecord $record, array $knownScopes, KeyContext $context): array
    {
        $owner = $record instanceof ApiKey ? $record->owner : null;

        if (! $owner instanceof User) {
            return [];
        }

        return array_values(array_filter(
            $knownScopes,
            fn (string $scope): bool => ApiKeyScopeEnum::tryFrom($scope)?->isHeldBy($owner) === true,
        ));
    }

    /**
     * Empty: the package applies the row's own limits, with Excubia's default per minute.
     *
     * @return list<never>
     */
    public function rateLimit(ApiKeyRecord $record, KeyContext $context): array
    {
        return [];
    }

    public function authorizeResources(ApiKeyRecord $record, ResourceRestrictions $resources, KeyContext $context): AuthorizationDecision
    {
        return AuthorizationDecision::deny('Excubia keys carry no resource restrictions.');
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return ApiKeyScopeEnum::values();
    }

    public function contains(string $value): bool
    {
        return ApiKeyScopeEnum::tryFrom($value) !== null;
    }
}
