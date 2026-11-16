<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Data\ApiKeyData;
use App\Enums\ApiKeyScopeEnum;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Settings → API keys: a new personal key (#315) with a name, at least one scope and an optional
 * expiry date. Every signed-in, verified user may create keys for themselves.
 */
class StoreApiKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', 'distinct', Rule::in(ApiKeyScopeEnum::values())],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ];
    }

    public function apiKeyData(): ApiKeyData
    {
        $expiresAt = $this->input('expires_at');

        return new ApiKeyData(
            name: $this->string('name')->trim()->toString(),
            scopes: array_values(array_map(
                fn (mixed $scope): ApiKeyScopeEnum => ApiKeyScopeEnum::from((string) $scope),
                (array) $this->input('scopes', []),
            )),
            // A date is valid through its last second.
            expiresAt: is_string($expiresAt) && $expiresAt !== '' ? CarbonImmutable::parse($expiresAt)->endOfDay() : null,
        );
    }
}
