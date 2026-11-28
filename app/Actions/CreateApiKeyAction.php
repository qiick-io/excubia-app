<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\ApiKeyData;
use App\Enums\ActivityEventEnum;
use App\Enums\ApiKeyScopeEnum;
use App\Models\ApiKey;
use App\Models\User;
use App\Services\ErasureOrchestratorService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Qiick\ApiKeys\Contracts\Clock;
use Qiick\ApiKeys\Contracts\LifetimePolicy;
use Qiick\ApiKeys\LifetimeAssertion;
use Qiick\ApiKeys\TokenCodec;

/**
 * Creates a personal API key (#315, #351) for an owner and returns the plain token once. The hash
 * of the full token is stored; the plain value is never logged or written to the activity log.
 */
final class CreateApiKeyAction
{
    public function __construct(
        private readonly TokenCodec $tokenCodec,
        private readonly LifetimePolicy $lifetimePolicy,
        private readonly Clock $clock,
        private readonly ErasureOrchestratorService $erasure,
    ) {}

    /**
     * @return string the plain token, shown once to the caller
     *
     * @throws \InvalidArgumentException when the expiry breaks `api-keys.max_lifetime_days`
     * @throws AuthorizationException when the owner's erasure began after the request was authorised
     */
    public function execute(User $owner, ApiKeyData $apiKeyData, ?User $creator = null): string
    {
        $creator ??= $owner;
        LifetimeAssertion::assertAllows($this->lifetimePolicy, $apiKeyData->expiresAt, $this->clock->now());
        $token = $this->tokenCodec->issue();
        $scopes = array_map(
            fn (ApiKeyScopeEnum $scope): string => $scope->value,
            ApiKeyScopeEnum::normalize($apiKeyData->scopes),
        );

        DB::transaction(function () use ($owner, $creator, $apiKeyData, $token, $scopes): void {
            // A key is never issued after the owner's erasure began (it would outlive the case).
            $this->erasure->lockLiveUser($owner);

            $apiKey = new ApiKey([
                'name' => $apiKeyData->name,
                'scopes' => $scopes,
                'ip_allowlist' => $apiKeyData->ipAllowlist,
                'rate_limit_per_minute' => $apiKeyData->rateLimitPerMinute,
                'expires_at' => $apiKeyData->expiresAt,
            ]);
            $ownerPrincipal = $owner->principal
                ?? $owner->principal()->firstOrFail();

            $apiKey->forceFill([
                'owner_id' => $owner->id,
                'owner_principal_id' => $ownerPrincipal->getKey(),
                'created_by' => $creator->id,
                'key_prefix' => $token->keyPrefix,
                'key_hash' => $token->keyHash,
                'token_version' => $token->tokenVersion,
            ])->saveQuietly();

            activity('api-keys')
                ->causedBy($creator)
                ->performedOn($apiKey)
                ->event(ActivityEventEnum::Created->value)
                ->withProperties(['name' => $apiKey->name, 'scopes' => $scopes, 'key_prefix' => $apiKey->key_prefix])
                ->log('API key created');
        });

        return $token->plainText;
    }
}
