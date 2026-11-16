<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\ApiKeyScopeEnum;
use Carbon\CarbonImmutable;

/**
 * A personal API key to create (#315): its name, what it may be used for and when it stops
 * working (never, when null).
 */
final readonly class ApiKeyData
{
    /**
     * @param  list<ApiKeyScopeEnum>  $scopes
     */
    public function __construct(
        public string $name,
        public array $scopes,
        public ?CarbonImmutable $expiresAt,
    ) {}
}
