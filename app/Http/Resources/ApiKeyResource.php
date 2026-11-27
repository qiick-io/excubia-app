<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ApiKey;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A personal API key for Settings → API keys (#315, #351): the public prefix to tell it apart,
 * never the hash.
 *
 * @mixin ApiKey
 */
class ApiKeyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ApiKey $apiKey */
        $apiKey = $this->resource;

        return [
            'id' => $apiKey->id,
            'uuid' => $apiKey->uuid,
            'name' => $apiKey->name,
            'key_prefix' => $apiKey->key_prefix,
            'scopes' => $apiKey->scopes,
            'is_active' => $apiKey->isActive(),
            'expires_at' => $apiKey->expires_at?->toIso8601String(),
            'last_used_at' => $apiKey->last_used_at?->toIso8601String(),
            'last_used_ip' => $apiKey->last_used_ip,
            'revoked_at' => $apiKey->revoked_at?->toIso8601String(),
            'previous_key_valid_until' => $apiKey->previous_key_valid_until?->toIso8601String(),
            'created_at' => $apiKey->created_at?->toIso8601String(),
        ];
    }
}
