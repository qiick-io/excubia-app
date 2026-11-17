<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Data\ApiKeyData;
use App\Enums\ApiKeyScopeEnum;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Settings → API keys: a new personal key (#315, #351) with a name, at least one scope and an
 * optional expiry, IP allowlist and per-key rate limit. Session (`web`) only; scopes are enum
 * values the creator may grant (`scopesCreatorMayGrant`), normalised so write implies read.
 */
class StoreApiKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web') !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $creator = $this->user('web');
        $allowed = $creator instanceof User
            ? array_map(
                fn (ApiKeyScopeEnum $scope): string => $scope->value,
                ApiKeyScopeEnum::scopesCreatorMayGrant($creator),
            )
            : [];

        return [
            'name' => ['required', 'string', 'max:255'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', 'distinct', Rule::in($allowed)],
            'expires_at' => ['nullable', 'date', 'after:today'],
            'ip_allowlist' => ['nullable', 'array'],
            'ip_allowlist.*' => ['string', 'max:64'],
            'rate_limit_per_minute' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ];
    }

    public function apiKeyData(): ApiKeyData
    {
        $expiresAt = $this->input('expires_at');
        $ipAllowlist = $this->input('ip_allowlist');

        return new ApiKeyData(
            name: $this->string('name')->trim()->toString(),
            scopes: ApiKeyScopeEnum::normalize(array_values(array_map(
                fn (mixed $scope): ApiKeyScopeEnum => ApiKeyScopeEnum::from((string) $scope),
                (array) $this->input('scopes', []),
            ))),
            // A date is valid through its last second.
            expiresAt: is_string($expiresAt) && $expiresAt !== '' ? CarbonImmutable::parse($expiresAt)->endOfDay() : null,
            ipAllowlist: is_array($ipAllowlist) ? array_values(array_filter(array_map('strval', $ipAllowlist))) : null,
            rateLimitPerMinute: $this->filled('rate_limit_per_minute') ? $this->integer('rate_limit_per_minute') : null,
        );
    }
}
