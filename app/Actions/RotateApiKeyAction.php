<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ActivityEventEnum;
use App\Enums\ApiKeyScopeEnum;
use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Qiick\ApiKeys\Contracts\Clock;
use Qiick\ApiKeys\RotationService;
use Qiick\ApiKeys\TokenCodec;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Rotates a personal API key (#351): same row, uuid, key_prefix, scopes and audit trail; a new
 * secret from qiick/laravel-api-keys' RotationService. The previous full-token hash stays valid
 * until the grace window (`api-keys.rotation_grace_hours`) ends.
 *
 * Refuses revoked or expired keys with 409, and refuses when the actor no longer holds every
 * scope stored on the key (same ceiling as create). The row is locked for the rotation and every
 * field the service returns is written, as the package requires.
 */
final class RotateApiKeyAction
{
    public function __construct(
        private readonly RotationService $rotationService,
        private readonly TokenCodec $tokenCodec,
        private readonly Clock $clock,
    ) {}

    /**
     * @return string the new plain token, shown once
     *
     * @throws HttpException 409 when the key is inactive or the actor lost a stored scope
     */
    public function execute(ApiKey $apiKey, User $actor): string
    {
        if (! $apiKey->isActive()) {
            throw new HttpException(409, __('Only an active API key can be rotated.'));
        }

        foreach ($apiKey->scopes as $scopeValue) {
            $scope = ApiKeyScopeEnum::tryFrom($scopeValue);

            if ($scope === null || ! $scope->isHeldBy($actor)) {
                throw new HttpException(409, __('This API key cannot be rotated: you no longer hold its scopes.'));
            }
        }

        $plainText = DB::transaction(function () use ($apiKey): string {
            $lockedApiKey = ApiKey::query()->whereKey($apiKey->id)->lockForUpdate()->firstOrFail();

            try {
                $rotation = $this->rotationService->rotate(
                    $lockedApiKey,
                    $this->tokenCodec,
                    $this->clock,
                    config()->integer('api-keys.rotation_grace_hours', 24),
                );
            } catch (InvalidArgumentException) {
                // Revoked or expired since the check above, or outside the lifetime policy.
                throw new HttpException(409, __('Only an active API key can be rotated.'));
            }

            $lockedApiKey->forceFill([
                'key_prefix' => $rotation->newKeyPrefix,
                'key_hash' => $rotation->newKeyHash,
                'previous_key_hash' => $rotation->previousKeyHash,
                'previous_key_valid_until' => $rotation->previousKeyValidUntil,
                'previous_key_prefix' => $rotation->previousKeyPrefix,
                'last_rotated_at' => $rotation->lastRotatedAt,
                'token_version' => $rotation->tokenVersion,
            ])->saveQuietly();

            return $rotation->newPlainText;
        });

        $apiKey->refresh();

        activity('api-keys')
            ->causedBy($actor)
            ->performedOn($apiKey)
            ->event(ActivityEventEnum::ApiKeyRotated->value)
            ->withProperties(['name' => $apiKey->name, 'scopes' => $apiKey->scopes, 'key_prefix' => $apiKey->key_prefix])
            ->log('API key rotated');

        return $plainText;
    }
}
