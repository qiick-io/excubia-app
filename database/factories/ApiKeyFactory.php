<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ApiKeyScopeEnum;
use App\Models\ApiKey;
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
        $plainKey = ApiKey::KEY_PREFIX.Str::random(40);

        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'key_prefix' => substr($plainKey, 0, 12),
            'key_hash' => ApiKey::hashKey($plainKey),
            'scopes' => [ApiKeyScopeEnum::McpRead->value],
        ];
    }
}
