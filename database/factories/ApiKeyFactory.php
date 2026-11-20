<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ApiKeyScopeEnum;
use App\Models\ApiKey;
use App\Models\Principal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A personal API key with the `mcp:read` scope. The plain key is not kept: tests that send a key
 * create it through CreateApiKeyAction.
 *
 * @extends Factory<ApiKey>
 */
class ApiKeyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $token = ApiKey::generateToken();

        return [
            'uuid' => (string) Str::uuid(),
            'name' => fake()->words(2, true),
            'key_prefix' => $token['prefix'],
            'key_hash' => $token['hash'],
            'owner_id' => User::factory(),
            'owner_principal_id' => fn (array $attributes): string => (string) Principal::query()
                ->where('user_id', $attributes['owner_id'])
                ->value('id'),
            'created_by' => fn (array $attributes): mixed => $attributes['owner_id'],
            'scopes' => [ApiKeyScopeEnum::McpRead->value],
        ];
    }
}
