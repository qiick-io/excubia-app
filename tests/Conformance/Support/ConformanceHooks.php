<?php

declare(strict_types=1);

namespace Qiick\Standards\Conformance\Support;

/**
 * Product-supplied settings and behaviour hooks for conformance checks.
 *
 * Scalars come from `qiick-standards.json` → `conformance`. Callables come from
 * the repo-owned `tests/Conformance/hooks.php` (synced once, never overwritten).
 *
 * @phpstan-type ConformanceConfig array{
 *     userModel?: class-string,
 *     publicIdColumn?: string,
 *     apiPrefix?: string,
 *     openApiPath?: string,
 *     usesSpatieTeams?: bool,
 *     hasOrganisations?: bool,
 *     hasUserGroups?: bool,
 *     hasPrincipals?: bool,
 *     hasApiKeys?: bool,
 *     hasSettings?: bool,
 *     hasUserIdentities?: bool,
 *     organisationTable?: string,
 *     membershipTable?: string,
 *     settingsTable?: string,
 *     activityTable?: string,
 *     apiKeysTable?: string,
 *     principalsTable?: string,
 *     userIdentitiesTable?: string,
 * }
 */
final class ConformanceHooks
{
    /**
     * @param ConformanceConfig $config
     * @param array<string, callable(): void> $callables
     */
    public function __construct(
        private readonly array $config,
        private readonly array $callables = [],
    ) {}

    /**
     * Load scalars from qiick-standards.json and callables from hooks.php.
     */
    public static function fromBasePath(string $basePath, ?string $hooksPath = null): self
    {
        $path = $basePath.'/qiick-standards.json';
        $config = [];

        if (is_file($path)) {
            /** @var array{conformance?: ConformanceConfig} $decoded */
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $config = $decoded['conformance'] ?? [];
        }

        $hooksFile = $hooksPath ?? $basePath.'/tests/Conformance/hooks.php';
        $callables = [];

        if (is_file($hooksFile)) {
            /** @var array<string, callable(): void> $loaded */
            $loaded = require $hooksFile;
            $callables = is_array($loaded) ? $loaded : [];
        }

        return new self($config, $callables);
    }

    public function string(string $key, ?string $default = null): ?string
    {
        $value = $this->config[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }

    public function boolean(string $key, bool $default = false): bool
    {
        $value = $this->config[$key] ?? $default;

        return is_bool($value) ? $value : $default;
    }

    /**
     * @return class-string
     */
    public function userModel(): string
    {
        return $this->string('userModel', 'App\\Models\\User') ?? 'App\\Models\\User';
    }

    public function publicIdColumn(): string
    {
        return $this->string('publicIdColumn', 'public_id') ?? 'public_id';
    }

    public function apiPrefix(): string
    {
        return $this->string('apiPrefix', 'api/v1') ?? 'api/v1';
    }

    public function table(string $key, string $default): string
    {
        return $this->string($key, $default) ?? $default;
    }

    /**
     * @param non-empty-string $key
     */
    public function requireCallable(string $key): void
    {
        $callback = $this->callables[$key] ?? null;

        if (! is_callable($callback)) {
            throw new \RuntimeException(
                "Conformance hook \"{$key}\" is required. Implement it in tests/Conformance/hooks.php or declare a divergence for this check.",
            );
        }

        $callback();
    }

    public function hasCallable(string $key): bool
    {
        return is_callable($this->callables[$key] ?? null);
    }
}
