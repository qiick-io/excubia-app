<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ApiKeyScopeEnum;
use App\Enums\PermissionEnum;
use App\Models\ApiKey;
use App\Models\User;
use Qiick\Access\Principals\PrincipalDirectory;
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
 * while the owner is still assigned the permissions that scope needs (checked on every request;
 * the super-admin grant never counts behind a key).
 *
 * Also the scope vocabulary: the values of {@see ApiKeyScopeEnum}. Excubia stores no resource
 * restrictions, so a key that carries some is refused rather than trusted (fail closed).
 */
class ApiKeyAuthorizerService implements KeyAuthorizer, ScopeVocabulary
{
    public function __construct(
        private readonly EffectivePermissionResolverService $permissions,
        private readonly PrincipalDirectory $principals,
    ) {}

    public function authorizeActor(ApiKeyRecord $record, KeyContext $context): AuthorizationDecision
    {
        if (! $record instanceof ApiKey) {
            return AuthorizationDecision::deny('The API key is not valid.');
        }

        // A soft-deleted owner is hidden by the relation's default scope: it reads as inactive.
        $owner = $record->owner;

        // The principal must be the owner's own `user` principal: two independent foreign keys
        // could otherwise pair an active user with someone else's (or a stale) principal. Only the
        // exact `user` type passes, so an unknown stored type denies (401). The directory then
        // checks the principal's whole owner chain (deactivated, expired, unlinked).
        $principal = $record->ownerPrincipal;
        $isOwnersPrincipal = $principal->type === 'user' && $principal->user_id === $record->owner_id;

        if ($owner === null || ! $owner->is_active || ! $isOwnersPrincipal || ! $this->principals->isActive($record->owner_principal_id)) {
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

        // The owner's assigned permissions only, read fresh: a key never inherits the super-admin
        // grant (qiick/laravel-access design §6).
        return array_values(array_filter($knownScopes, function (string $scope) use ($owner): bool {
            $requiredPermissions = ApiKeyScopeEnum::tryFrom($scope)?->requiredCreatorPermissions() ?? [];

            return $requiredPermissions !== [] && array_all(
                $requiredPermissions,
                fn (PermissionEnum $permission): bool => $this->permissions->hasAssignedPermission($owner, $permission->value),
            );
        }));
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
