<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\ApiKeyScopeEnum;
use Carbon\CarbonImmutable;

/**
 * A personal API key to create (#315, #351): name, scopes, optional expiry, optional IP allowlist
 * and optional per-key rate limit (null falls back to the app default limiter).
 */
final readonly class ApiKeyData
{
    /**
     * @param  list<ApiKeyScopeEnum>  $scopes
     * @param  list<string>|null  $ipAllowlist
     */
    public function __construct(
        public string $name,
        public array $scopes,
        public ?CarbonImmutable $expiresAt,
        public ?array $ipAllowlist = null,
        public ?int $rateLimitPerMinute = null,
    ) {}
}
