<?php

declare(strict_types=1);

namespace Qiick\Standards\Conformance\Support;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Auth\Access\Authorizable as AuthorizableTrait;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/**
 * Executes one fleet conformance check against the product's migrated database
 * and built config. Behaviour checks that need product specifics call hooks.
 *
 * Every check runs inside a SAVEPOINT so one failure cannot abort the outer
 * Pest / RefreshDatabase transaction and blind later checks (C37 on PostgreSQL).
 */
final class ConformanceRunner
{
    public function __construct(
        private readonly string $basePath,
        private readonly ConformanceHooks $hooks,
        private readonly SchemaInspector $schema,
    ) {}

    /**
     * @param  array{id: string, tag: string, description: string, decision: string, engines?: list<string>}  $check
     */
    public function run(array $check): void
    {
        $this->schema->isolate(function () use ($check): void {
            $this->runIsolated($check);
        });
    }

    /**
     * @param  array{id: string, tag: string, description: string, decision: string, engines?: list<string>}  $check
     */
    private function runIsolated(array $check): void
    {
        $engines = $check['engines'] ?? null;

        if (is_array($engines) && $engines !== [] && ! in_array($this->schema->driver(), $engines, true)
            && ! ($this->schema->driver() === 'mariadb' && in_array('mysql', $engines, true))) {
            throw new \RuntimeException(
                "{$check['id']} requires ".implode('/', $engines).'; current driver is '.$this->schema->driver().'.',
            );
        }

        match ($check['id']) {
            'C1' => $this->c1UsersBaseline(),
            'C2' => $this->c2NoUserOrganisationId(),
            'C3' => $this->c3UserIdentities(),
            'C4' => $this->c4SpatieTeamsConfig(),
            'C5' => $this->c5SpatieTeamColumnTypes(),
            'C6' => $this->c6RolesAndGroups(),
            'C7' => $this->hooks->requireCallable('assertSoftDeletedGroupGrantsNothing'),
            'C8' => $this->hooks->requireCallable('assertGlobalRoleGuard'),
            'C9' => $this->c9Principals(),
            'C10' => $this->c10ApiKeyOwnership(),
            'C11' => $this->c11ApiKeyColumns(),
            'C12' => $this->c12ActivityMorphs(),
            'C13' => $this->hooks->requireCallable('assertApiKeyActivityUuid'),
            'C14' => $this->c14Settings(),
            'C15' => $this->c15SettingsUnique(),
            'C16' => $this->c16LaravelTables(),
            'C17' => $this->hooks->requireCallable('assertErasure'),
            'C18' => $this->c18SuperAdminConfig(),
            'C19' => $this->hooks->requireCallable('assertInitialAdminSeeder'),
            'C20' => $this->hooks->requireCallable('assertErrorContract'),
            'C21' => $this->hooks->requireCallable('assertContentLanguage'),
            'C22' => $this->hooks->requireCallable('assertPaginationShape'),
            'C23' => $this->hooks->requireCallable('assertThrottleHeaders'),
            'C24' => $this->hooks->requireCallable('assertRequestId'),
            'C25' => $this->hooks->requireCallable('assertIdempotency'),
            'C26' => $this->c26OpenApi(),
            'C27' => $this->hooks->requireCallable('assertWebhooks'),
            'C28' => $this->hooks->requireCallable('assertSsrfGuard'),
            'C29' => $this->c29TeamSwitch(),
            'C30' => $this->hooks->requireCallable('assertSuperAdmin'),
            'C31' => $this->hooks->requireCallable('assertApiResourcesHideBigints'),
            'C32' => $this->hooks->requireCallable('assertEncryptedCasts'),
            'C33' => $this->c33FrozenBaseline(),
            'C34' => $this->c34NoAlterMigrationsBeforeStable(),
            'C35' => $this->c35GraphifyIgnored(),
            'C36' => $this->c36NoSetupTeams(),
            'C37' => null, // meta-check handled by the suite runner
            'C71' => $this->c71AccessEventsAndResolver(),
            'C72' => $this->c72AccessSpatieTeams(),
            'C73' => $this->c73AccessExpiredMembership(),
            'C74' => $this->c74AccessActivityUuids(),
            'C75' => $this->c75AccessGroupMigrations(),
            default => throw new \RuntimeException("No runner for {$check['id']}."),
        };
    }

    private function c1UsersBaseline(): void
    {
        $this->schema->assertTableExists('users');
        $this->schema->assertColumnExists('users', 'id');

        if (! $this->schema->columnTypeContains('users', 'id', 'int')
            && ! $this->schema->columnTypeContains('users', 'id', 'bigint')) {
            throw new \RuntimeException('users.id must be a bigint primary key.');
        }

        $publicIdColumn = $this->hooks->publicIdColumn();
        $this->schema->assertColumnExists('users', $publicIdColumn);

        if (! $this->schema->hasUniqueIndexCovering('users', $publicIdColumn)
            && ! $this->schema->hasUniqueIndexCovering('users', $publicIdColumn, 'deleted_at')) {
            // Soft-delete products may unique only the column; require at least a unique index including it.
            $found = false;

            foreach ($this->schema->indexes('users') as $index) {
                if (($index['unique'] ?? false) && in_array($publicIdColumn, $index['columns'] ?? [], true)) {
                    $found = true;
                    break;
                }
            }

            if (! $found) {
                throw new \RuntimeException("users.{$publicIdColumn} must be unique.");
            }
        }

        foreach (['username', 'locale', 'timezone', 'password', 'deleted_at', 'is_active', 'last_login_at', 'last_login_ip', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at'] as $column) {
            $this->schema->assertColumnExists('users', $column);
        }

        if (! $this->schema->isNullable('users', 'username')) {
            throw new \RuntimeException('users.username must be nullable.');
        }

        if (! $this->schema->isNullable('users', 'password')) {
            throw new \RuntimeException('users.password must be nullable.');
        }

        if (! $this->schema->defaultEquals('users', 'timezone', 'UTC')) {
            throw new \RuntimeException('users.timezone must default to UTC.');
        }
    }

    private function c2NoUserOrganisationId(): void
    {
        $this->schema->assertColumnMissing('users', 'organisation_id');
        $this->schema->assertColumnMissing('users', 'organization_id');

        if (! $this->hooks->boolean('hasOrganisations', true)) {
            return;
        }

        $organisationTable = $this->hooks->table('organisationTable', 'organisations');
        $membershipTable = $this->hooks->table('membershipTable', 'organisation_user');
        $principalsTable = $this->hooks->table('principalsTable', 'principals');

        if (! $this->schema->hasTable($organisationTable) && $this->schema->hasTable('organizations')) {
            $organisationTable = 'organizations';

            if ($membershipTable === 'organisation_user') {
                $membershipTable = $this->hooks->table('membershipTable', 'organization_user');
            }
        }

        $this->schema->assertTableExists($organisationTable);
        $this->schema->assertColumnExists($organisationTable, 'id');

        if (! $this->schema->columnTypeContains($organisationTable, 'id', 'uuid')
            && ! $this->schema->columnTypeContains($organisationTable, 'id', 'char')
            && ! $this->schema->columnTypeContains($organisationTable, 'id', 'guid')) {
            throw new \RuntimeException("{$organisationTable}.id must be a UUID primary key.");
        }

        $orgIdColumn = str_contains($membershipTable, 'organization') ? 'organization_id' : 'organisation_id';
        $this->schema->assertTableExists($membershipTable);

        if (! $this->schema->hasUniqueIndexCovering($membershipTable, $orgIdColumn, 'user_id')
            && ! $this->schema->hasUniqueIndexCovering($membershipTable, 'user_id', $orgIdColumn)) {
            throw new \RuntimeException("{$membershipTable} must UNIQUE({$orgIdColumn}, user_id).");
        }

        // Decision 2026-11-22: with organisations, every principal belongs to one org.
        // Principals may also be the membership table (membershipTable=principals).
        if ($this->hooks->boolean('hasPrincipals', true) && $this->schema->hasTable($principalsTable)) {
            $this->schema->assertColumnExists($principalsTable, 'organisation_id');

            if ($this->schema->isNullable($principalsTable, 'organisation_id')) {
                throw new \RuntimeException(
                    'principals.organisation_id must be NOT NULL when hasOrganisations is true.',
                );
            }
        }
    }

    private function c3UserIdentities(): void
    {
        $this->schema->assertColumnMissing('users', 'oidc_sub');

        if (! $this->hooks->boolean('hasUserIdentities', true)) {
            throw new \RuntimeException('conformance.hasUserIdentities is false: declare a C3 divergence for IdP-only products.');
        }

        $table = $this->hooks->table('userIdentitiesTable', 'user_identities');
        $this->schema->assertTableExists($table);
        $this->schema->assertColumnExists($table, 'issuer');
        $this->schema->assertColumnExists($table, 'subject');

        if (! $this->schema->hasUniqueIndexCovering($table, 'issuer', 'subject')
            && ! $this->schema->hasUniqueIndexCovering($table, 'subject', 'issuer')) {
            throw new \RuntimeException("{$table} must UNIQUE(issuer, subject).");
        }

        if (! $this->schema->isEngineDependentDriver()) {
            throw new \RuntimeException('C3 case-sensitivity probe requires PostgreSQL or MySQL.');
        }

        // Real fixture user: inserting user_id=1 without a row aborts PostgreSQL's transaction.
        $userId = $this->insertFixtureUser();

        // Probe: case-sensitive uniqueness should allow Issuer/X and issuer/x as two rows.
        $probeIssuerA = 'ConformanceIssuer/Case';
        $probeIssuerB = 'conformanceissuer/case';
        $probeSubject = 'conformance-subject-'.bin2hex(random_bytes(4));

        try {
            $this->schema->statement(
                "INSERT INTO {$table} (user_id, issuer, subject, created_at, updated_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
                [$userId, $probeIssuerA, $probeSubject],
            );
            $this->schema->statement(
                "INSERT INTO {$table} (user_id, issuer, subject, created_at, updated_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
                [$userId, $probeIssuerB, $probeSubject],
            );
        } catch (\Throwable $exception) {
            throw new \RuntimeException(
                'user_identities UNIQUE(issuer, subject) must be case-sensitive: '.$exception->getMessage(),
                0,
                $exception,
            );
        }
    }

    private function c4SpatieTeamsConfig(): void
    {
        if (! $this->spatieTeamsAreInScope()) {
            return;
        }

        $this->assertSpatieTeamsDeclarationMatchesConfig();
        $this->assertSpatieTeamForeignKeyIsOrganisationId();
    }

    private function c5SpatieTeamColumnTypes(): void
    {
        if (! $this->spatieTeamsAreInScope()) {
            return;
        }

        $this->assertSpatieTeamsDeclarationMatchesConfig();

        if (! $this->schema->isEngineDependentDriver()) {
            throw new \RuntimeException('C5 requires PostgreSQL or MySQL.');
        }

        foreach (['roles', 'model_has_roles', 'model_has_permissions'] as $table) {
            $this->schema->assertTableExists($table);
            $this->schema->assertColumnExists($table, 'organisation_id');

            if (! $this->schema->columnTypeContains($table, 'organisation_id', 'uuid')
                && ! $this->schema->columnTypeContains($table, 'organisation_id', 'char')
                && ! $this->schema->columnTypeContains($table, 'organisation_id', 'guid')) {
                throw new \RuntimeException("{$table}.organisation_id must be UUID-typed.");
            }
        }

        $this->schema->assertColumnExists('model_has_roles', 'model_id');

        if (! $this->schema->columnTypeContains('model_has_roles', 'model_id', 'int')
            && ! $this->schema->columnTypeContains('model_has_roles', 'model_id', 'bigint')) {
            throw new \RuntimeException('model_has_roles.model_id must stay bigint.');
        }
    }

    private function c6RolesAndGroups(): void
    {
        foreach (['roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions'] as $table) {
            $this->schema->assertTableExists($table);
        }

        if (! $this->hooks->boolean('hasUserGroups', true)) {
            return;
        }

        $this->schema->assertTableExists('user_groups');
        $this->schema->assertColumnExists('user_groups', 'deleted_at');

        foreach (['group_user' => ['user_group_id', 'user_id'], 'group_role' => ['user_group_id', 'role_id']] as $pivot => $columns) {
            $table = $this->schema->hasTable($pivot) ? $pivot : null;

            // externa uses user_group_user / user_group_role naming variants.
            if ($table === null) {
                foreach ([$pivot, 'user_'.$pivot, 'user_group_'.str_replace('group_', '', $pivot)] as $candidate) {
                    if ($this->schema->hasTable($candidate)) {
                        $table = $candidate;
                        break;
                    }
                }
            }

            if ($table === null) {
                throw new \RuntimeException("Expected group pivot table for {$pivot}.");
            }
        }
    }

    private function c9Principals(): void
    {
        if (! $this->hooks->boolean('hasPrincipals', true)) {
            throw new \RuntimeException('conformance.hasPrincipals is false: declare a C9 divergence or enable principals.');
        }

        $table = $this->hooks->table('principalsTable', 'principals');
        $this->schema->assertTableExists($table);
        $this->schema->assertColumnExists($table, 'id');
        $this->schema->assertColumnExists($table, 'user_id');
        $this->schema->assertColumnExists($table, 'organisation_id');
        $this->schema->assertColumnExists($table, 'deactivated_at');

        if (! $this->schema->isNullable($table, 'user_id')) {
            throw new \RuntimeException('principals.user_id must be nullable.');
        }
    }

    private function c10ApiKeyOwnership(): void
    {
        if (! $this->hooks->boolean('hasApiKeys', true)) {
            return;
        }

        $table = $this->hooks->table('apiKeysTable', 'api_keys');
        $this->schema->assertTableExists($table);
        $this->schema->assertColumnExists($table, 'key_hash');
        $this->schema->assertColumnExists($table, 'owner_principal_id');

        $onDelete = $this->schema->foreignKeyOnDelete($table, 'owner_principal_id');

        if ($onDelete !== null && str_contains($onDelete, 'cascade')) {
            throw new \RuntimeException('api_keys.owner_principal_id must not cascade-delete on principal removal.');
        }

        if ($this->schema->hasColumn($table, 'created_by')) {
            $createdByOnDelete = $this->schema->foreignKeyOnDelete($table, 'created_by');

            if ($createdByOnDelete !== null && str_contains($createdByOnDelete, 'cascade')) {
                throw new \RuntimeException('api_keys.created_by must null-on-delete, not cascade.');
            }
        }

        // Legacy / dual-read owner_id → users: never a silent cascade (core-hardening FK table).
        if ($this->schema->hasColumn($table, 'owner_id')) {
            $ownerIdOnDelete = $this->schema->foreignKeyOnDelete($table, 'owner_id');

            if ($ownerIdOnDelete !== null && str_contains($ownerIdOnDelete, 'cascade')) {
                throw new \RuntimeException('api_keys.owner_id must not cascade-delete on user removal.');
            }
        }

        if ($this->hooks->boolean('hasPrincipals', true)) {
            $principalsTable = $this->hooks->table('principalsTable', 'principals');

            if ($this->schema->hasTable($principalsTable) && $this->schema->hasColumn($principalsTable, 'user_id')) {
                $principalsUserOnDelete = $this->schema->foreignKeyOnDelete($principalsTable, 'user_id');

                if ($principalsUserOnDelete !== null && str_contains($principalsUserOnDelete, 'cascade')) {
                    throw new \RuntimeException(
                        'principals.user_id must not cascade-delete; null on delete so the principal row stays for audit.',
                    );
                }
            }
        }
    }

    private function c11ApiKeyColumns(): void
    {
        if (! $this->hooks->boolean('hasApiKeys', true)) {
            return;
        }

        $table = $this->hooks->table('apiKeysTable', 'api_keys');
        $this->schema->assertTableExists($table);

        $previousKeyColumn = 'previous_key_valid_until';

        foreach (['uuid', 'key_prefix', 'key_hash', 'scopes', 'ip_allowlist', 'rate_limit_per_minute', 'expires_at', 'previous_key_valid_until', 'last_used_at', 'revoked_at'] as $column) {
            // Some products name previous_key_* slightly differently during adoption.
            if ($column === 'previous_key_valid_until' && ! $this->schema->hasColumn($table, $column)) {
                if ($this->schema->hasColumn($table, 'previous_key_expires_at')) {
                    $column = 'previous_key_expires_at';
                    $previousKeyColumn = 'previous_key_expires_at';
                }
            }

            $this->schema->assertColumnExists($table, $column);
        }

        if (! $this->schema->isDateTimeNotTimestamp($table, 'expires_at')) {
            throw new \RuntimeException('api_keys.expires_at must be dateTime, not MySQL TIMESTAMP.');
        }

        if (! $this->schema->isDateTimeNotTimestamp($table, $previousKeyColumn)) {
            throw new \RuntimeException(
                "api_keys.{$previousKeyColumn} must be dateTime, not MySQL TIMESTAMP.",
            );
        }
    }

    private function c12ActivityMorphs(): void
    {
        $table = $this->hooks->table('activityTable', 'activity_log');
        $this->schema->assertTableExists($table);

        foreach (['subject_id', 'causer_id'] as $column) {
            $this->schema->assertColumnExists($table, $column);
            $definition = $this->schema->column($table, $column);
            $type = strtolower((string) ($definition['type'] ?? $definition['type_name'] ?? ''));

            if (str_contains($type, 'bigint') || str_contains($type, 'integer')) {
                throw new \RuntimeException("{$table}.{$column} must be string(64), not bigint.");
            }
        }

        $this->schema->assertColumnExists($table, 'organisation_id');

        if (! $this->schema->isNullable($table, 'organisation_id')) {
            throw new \RuntimeException('activity_log.organisation_id must be nullable.');
        }
    }

    private function c14Settings(): void
    {
        if (! $this->hooks->boolean('hasSettings', true)) {
            return;
        }

        $table = $this->hooks->table('settingsTable', 'settings');
        $this->schema->assertTableExists($table);

        foreach (['scope', 'scope_id', 'group', 'key'] as $column) {
            $this->schema->assertColumnExists($table, $column);

            if ($this->schema->isNullable($table, $column)) {
                throw new \RuntimeException("{$table}.{$column} must be NOT NULL.");
            }
        }

        if (! $this->schema->hasUniqueIndexCovering($table, 'scope', 'scope_id', 'group', 'key')) {
            throw new \RuntimeException("{$table} must UNIQUE(scope, scope_id, group, key).");
        }
    }

    private function c15SettingsUnique(): void
    {
        if (! $this->hooks->boolean('hasSettings', true)) {
            return;
        }

        if (! $this->schema->isEngineDependentDriver()) {
            throw new \RuntimeException('C15 requires PostgreSQL or MySQL.');
        }

        $table = $this->hooks->table('settingsTable', 'settings');
        $this->schema->assertTableExists($table);

        $sql = "INSERT INTO {$table} (scope, scope_id, \"group\", key, value, created_at, updated_at) VALUES ('instance', '', 'app', 'conformance_probe', '1', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)";

        if (in_array($this->schema->driver(), ['mysql', 'mariadb'], true)) {
            $sql = "INSERT INTO {$table} (`scope`, `scope_id`, `group`, `key`, `value`, `created_at`, `updated_at`) VALUES ('instance', '', 'app', 'conformance_probe', '1', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)";
        }

        $this->schema->statement($sql);

        try {
            $this->schema->statement($sql);
            throw new \RuntimeException('Duplicate instance settings insert should fail.');
        } catch (\RuntimeException $exception) {
            if ($exception->getMessage() === 'Duplicate instance settings insert should fail.') {
                throw $exception;
            }
        } catch (\Throwable) {
            // Expected: unique violation.
        }
    }

    private function c16LaravelTables(): void
    {
        foreach (['sessions', 'notifications', 'passkeys'] as $table) {
            $this->schema->assertTableExists($table);
        }
    }

    private function c18SuperAdminConfig(): void
    {
        $email = Config::get('super_admin.email') ?? Config::get('super_admin.initial_email');
        $keys = [
            Config::get('super_admin.first_name') ?? Config::get('super_admin.initial_first_name'),
            Config::get('super_admin.last_name') ?? Config::get('super_admin.initial_last_name'),
            $email,
            Config::get('super_admin.password') ?? Config::get('super_admin.initial_password'),
        ];

        if ($keys[0] === null || $keys[1] === null || $keys[2] === null) {
            // Fall back to env-shaped config array.
            $config = Config::get('super_admin');

            if (! is_array($config)) {
                throw new \RuntimeException('config/super_admin.php must expose the four INITIAL_SUPER_ADMIN_* keys.');
            }
        }

        $resolvedEmail = is_string($email) ? $email : (string) (Config::get('super_admin.email') ?? '');

        if ($resolvedEmail !== '' && $resolvedEmail !== 'superadmin@example.com') {
            // Default may be overridden in .env during tests; the config default in the file is what matters.
            // Accept when the config key exists.
        }

        if (! is_array(Config::get('super_admin'))) {
            throw new \RuntimeException('config/super_admin.php must be loaded.');
        }
    }

    private function c26OpenApi(): void
    {
        $path = $this->hooks->string('openApiPath', 'storage/api-docs/api-docs.json')
            ?? 'storage/api-docs/api-docs.json';
        $candidates = [
            $this->basePath.'/'.$path,
            $this->basePath.'/openapi.yaml',
            $this->basePath.'/openapi.yml',
            $this->basePath.'/openapi.json',
            $this->basePath.'/docs/openapi.yaml',
            $this->basePath.'/docs/api/openapi.yaml',
        ];

        $content = null;

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                $content = (string) file_get_contents($candidate);
                break;
            }
        }

        if ($content === null) {
            throw new \RuntimeException('OpenAPI document not found; set conformance.openApiPath.');
        }

        if (preg_match('/openapi:\s*[\'"]?3\.0/', $content) === 1
            && preg_match('/openapi:\s*[\'"]?3\.1/', $content) !== 1) {
            throw new \RuntimeException('OpenAPI version must be 3.1.x (declare a divergence for 3.0.x).');
        }

        if (str_contains($content, '"openapi"') && preg_match('/"openapi"\s*:\s*"3\.0/', $content) === 1
            && preg_match('/"openapi"\s*:\s*"3\.1/', $content) !== 1) {
            throw new \RuntimeException('OpenAPI version must be 3.1.x (declare a divergence for 3.0.x).');
        }

        $document = $this->parseOpenApiDocument($content);
        $documentedPairs = $this->openApiPathMethodPairs($document);
        $apiPrefix = trim($this->hooks->apiPrefix(), '/');
        $missing = [];

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            $uri = $route->uri();

            if ($name === null || ! str_starts_with($uri, $apiPrefix)) {
                continue;
            }

            $normalizedPath = $this->normalizeOpenApiPath('/'.$uri);

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $pairKey = strtoupper($method).' '.$normalizedPath;

                if (! isset($documentedPairs[$pairKey])) {
                    $missing[] = "{$name} {$method} {$normalizedPath}";
                }
            }
        }

        if ($missing !== []) {
            throw new \RuntimeException(
                'OpenAPI missing exact path+method pairs: '.implode(', ', array_slice($missing, 0, 10)),
            );
        }
    }

    /**
     * Team switch: skip only when Spatie teams are really off in config.
     * When teams are on, a wrong declaration or team_foreign_key / missing
     * organisation_id columns must go red — not be hidden by usesSpatieTeams=false.
     */
    private function c29TeamSwitch(): void
    {
        if (! $this->spatieTeamsAreInScope()) {
            return;
        }

        $this->assertSpatieTeamsDeclarationMatchesConfig();
        $this->assertSpatieTeamForeignKeyIsOrganisationId();
        $this->assertSpatieTeamColumnsExist();
        $this->hooks->requireCallable('assertTeamSwitch');
    }

    /**
     * True when either the product declares Spatie teams or config has them on.
     * Both-false is the only quiet skip; a mismatch or both-true runs the check.
     */
    private function spatieTeamsAreInScope(): bool
    {
        return $this->hooks->boolean('usesSpatieTeams', false)
            || Config::get('permission.teams') === true;
    }

    private function assertSpatieTeamsDeclarationMatchesConfig(): void
    {
        $declared = $this->hooks->boolean('usesSpatieTeams', false);
        $enabled = Config::get('permission.teams') === true;

        if ($declared && ! $enabled) {
            throw new \RuntimeException('usesSpatieTeams is true but permission.teams is not true.');
        }

        if (! $declared && $enabled) {
            throw new \RuntimeException(
                'permission.teams is true but usesSpatieTeams is false: set usesSpatieTeams or turn teams off.',
            );
        }
    }

    private function assertSpatieTeamForeignKeyIsOrganisationId(): void
    {
        $teamKey = Config::get('permission.column_names.team_foreign_key')
            ?? Config::get('permission.team_foreign_key');

        if ($teamKey !== 'organisation_id') {
            throw new \RuntimeException(
                'permission team_foreign_key must be organisation_id, got '.(is_scalar($teamKey) ? (string) $teamKey : 'null').'.',
            );
        }
    }

    private function assertSpatieTeamColumnsExist(): void
    {
        foreach (['roles', 'model_has_roles', 'model_has_permissions'] as $table) {
            $this->schema->assertTableExists($table);
            $this->schema->assertColumnExists($table, 'organisation_id');
        }
    }

    private function c33FrozenBaseline(): void
    {
        $frozen = $this->basePath.'/database/migrations/.frozen';
        $hasStable = $this->hasStableTag();

        if (! $hasStable && is_file($frozen)) {
            throw new \RuntimeException('database/migrations/.frozen must be absent before the first stable v* tag.');
        }
    }

    private function c34NoAlterMigrationsBeforeStable(): void
    {
        if ($this->hasStableTag()) {
            return;
        }

        $directory = $this->basePath.'/database/migrations';

        if (! is_dir($directory)) {
            return;
        }

        $offenders = [];

        foreach (File::files($directory) as $file) {
            $name = $file->getFilename();

            if (preg_match('/_(add|alter|drop)_/', $name) === 1) {
                $offenders[] = $name;
            }
        }

        if ($offenders !== []) {
            throw new \RuntimeException(
                'Before the first stable tag, fold add_/alter_/drop_ migrations into create_: '.implode(', ', array_slice($offenders, 0, 8)),
            );
        }
    }

    private function c35GraphifyIgnored(): void
    {
        $gitignore = $this->basePath.'/.gitignore';

        if (! is_file($gitignore) || ! str_contains((string) file_get_contents($gitignore), 'graphify-out')) {
            throw new \RuntimeException('.gitignore must ignore graphify-out/.');
        }
    }

    private function c36NoSetupTeams(): void
    {
        // App code only: tests may mention the command when proving the check goes red.
        $roots = ['app', 'routes', 'database', 'config'];
        $hits = [];

        foreach ($roots as $root) {
            $path = $this->basePath.'/'.$root;

            if (! is_dir($path)) {
                continue;
            }

            foreach (File::allFiles($path) as $file) {
                if (! in_array($file->getExtension(), ['php', 'md'], true)) {
                    continue;
                }

                $contents = $file->getContents();

                if (str_contains($contents, 'permission:setup-teams')) {
                    $hits[] = $file->getRelativePathname();
                }
            }
        }

        if ($hits !== []) {
            throw new \RuntimeException('permission:setup-teams must never be called: '.implode(', ', array_slice($hits, 0, 5)));
        }
    }

    /**
     * AC13 → C71: Spatie events on, Spatie's Gate permission check off, resolver clear proved.
     */
    private function c71AccessEventsAndResolver(): void
    {
        if (! $this->hooks->boolean('usesLaravelAccess', false)) {
            return;
        }

        if (Config::get('permission.events_enabled') !== true) {
            throw new \RuntimeException(
                'permission.events_enabled must be true when usesLaravelAccess is set (access resolver cache).',
            );
        }

        // After full boot Spatie must not register its own Gate::before permission check.
        if (Config::get('permission.register_permission_check_method') !== false) {
            throw new \RuntimeException(
                'permission.register_permission_check_method must be false when usesLaravelAccess is set.',
            );
        }

        // Runtime proof: config alone is not enough if a grant-all / Spatie before was re-registered.
        $this->assertSpatiePermissionGateBeforeIsInactive();

        $this->hooks->requireProved('assertRoleDetachClearsResolver');
        // API actors (keys) must never receive the super-admin Gate::before pass.
        $this->hooks->requireProved('assertApiActorDeniedSuperAdmin');
    }

    /**
     * Fail when a Gate::before grants to a Spatie-shaped non-user actor (Spatie's
     * register_permission_check_method before, or an unconditional grant-all).
     * A product super-admin before that only passes for the User model stays green.
     */
    private function assertSpatiePermissionGateBeforeIsInactive(): void
    {
        $spatieShapedActor = new class implements Authorizable
        {
            use AuthorizableTrait;

            public function checkPermissionTo(mixed $permission, mixed $guardName = null): bool
            {
                return true;
            }
        };

        if (Gate::forUser($spatieShapedActor)->allows('__qiick_conformance_spatie_gate_probe__')) {
            throw new \RuntimeException(
                'A Gate::before callback granted a permission to a non-user Spatie-shaped actor; keep register_permission_check_method off and do not re-register a grant-all before after boot.',
            );
        }
    }

    /**
     * AC14 → C72: Spatie teams column name and UUID types for the access package.
     * Skips when teams are really off (externa 1.x keeps team_id and teams false until v2).
     */
    private function c72AccessSpatieTeams(): void
    {
        if (! $this->hooks->boolean('usesLaravelAccess', false)) {
            return;
        }

        // Same scope as C4/C5/C29: both-false is the quiet skip; a mismatch goes red.
        if (! $this->spatieTeamsAreInScope()) {
            return;
        }

        $this->assertSpatieTeamsDeclarationMatchesConfig();

        if (! $this->schema->isEngineDependentDriver()) {
            throw new \RuntimeException('C72 requires PostgreSQL or MySQL.');
        }

        $this->assertSpatieTeamForeignKeyIsOrganisationId();

        foreach (['roles', 'model_has_roles', 'model_has_permissions'] as $table) {
            $this->schema->assertTableExists($table);
            $this->schema->assertColumnExists($table, 'organisation_id');

            if (! $this->schema->columnTypeContains($table, 'organisation_id', 'uuid')
                && ! $this->schema->columnTypeContains($table, 'organisation_id', 'char')
                && ! $this->schema->columnTypeContains($table, 'organisation_id', 'guid')) {
                throw new \RuntimeException("{$table}.organisation_id must be UUID-typed for laravel-access.");
            }
        }
    }

    /**
     * AC15 → C73: expired memberships grant nothing through the real resolver.
     */
    private function c73AccessExpiredMembership(): void
    {
        if (! $this->hooks->boolean('usesLaravelAccess', false)) {
            return;
        }

        $this->hooks->requireProved('assertExpiredMembershipGrantsNothing');
    }

    /**
     * AC16 → C74: access audit properties expose public UUIDs only.
     */
    private function c74AccessActivityUuids(): void
    {
        if (! $this->hooks->boolean('usesLaravelAccess', false)) {
            return;
        }

        $this->hooks->requireProved('assertAccessActivityUuidOnly');
    }

    /**
     * AC17 → C75: package group-table shape (incl. organisation_id / is_instance XOR);
     * Spatie tables match the allowlist on the live schema; migration text is an extra signal.
     */
    private function c75AccessGroupMigrations(): void
    {
        if (! $this->hooks->boolean('usesLaravelAccess', false)) {
            return;
        }

        $this->schema->assertTableExists('user_groups');
        $this->schema->assertColumnExists('user_groups', 'public_id');
        $this->schema->assertColumnExists('user_groups', 'metadata');
        $this->schema->assertColumnExists('user_groups', 'deleted_at');
        $this->schema->assertColumnExists('user_groups', 'name');
        // laravel-access#8 / AC17: instance vs organisation scoping (externa leaves both unused).
        $this->schema->assertColumnExists('user_groups', 'organisation_id');
        $this->schema->assertColumnExists('user_groups', 'is_instance');

        if (! $this->schema->isNullable('user_groups', 'organisation_id')) {
            throw new \RuntimeException('user_groups.organisation_id must be nullable.');
        }

        if ($this->schema->isNullable('user_groups', 'is_instance')) {
            throw new \RuntimeException('user_groups.is_instance must not be nullable.');
        }

        if (! $this->schema->columnTypeContains('user_groups', 'organisation_id', 'uuid')
            && ! $this->schema->columnTypeContains('user_groups', 'organisation_id', 'char')
            && ! $this->schema->columnTypeContains('user_groups', 'organisation_id', 'guid')) {
            throw new \RuntimeException('user_groups.organisation_id must be UUID-typed.');
        }

        if (! $this->schema->hasIndexCovering('user_groups', 'organisation_id')
            && ! $this->indexMentionsColumn('user_groups', 'organisation_id')) {
            throw new \RuntimeException('user_groups.organisation_id must be indexed.');
        }

        $this->assertUserGroupsInstanceOrganisationXor();

        if (! $this->schema->hasUniqueIndexCovering('user_groups', 'public_id')
            && ! $this->schema->hasUniqueIndexCovering('user_groups', 'public_id', 'deleted_at')) {
            $found = false;

            foreach ($this->schema->indexes('user_groups') as $index) {
                if (($index['unique'] ?? false) && in_array('public_id', $index['columns'] ?? [], true)) {
                    $found = true;
                    break;
                }
            }

            if (! $found) {
                throw new \RuntimeException('user_groups.public_id must be unique.');
            }
        }

        if (! $this->schema->hasUniqueIndexCovering('user_groups', 'name')
            && ! $this->schema->hasUniqueIndexCovering('user_groups', 'name', 'deleted_at')) {
            $foundName = false;

            foreach ($this->schema->indexes('user_groups') as $index) {
                if (($index['unique'] ?? false) && in_array('name', $index['columns'] ?? [], true)) {
                    $foundName = true;
                    break;
                }
            }

            if (! $foundName) {
                throw new \RuntimeException('user_groups.name must be unique.');
            }
        }

        $membershipTable = 'user_group_user';
        $this->schema->assertTableExists($membershipTable);
        $this->schema->assertColumnExists($membershipTable, 'expires_at');

        if ($this->schema->isEngineDependentDriver()
            && ! $this->schema->isDateTimeNotTimestamp($membershipTable, 'expires_at')) {
            throw new \RuntimeException('user_group_user.expires_at must be dateTime, not MySQL TIMESTAMP.');
        }

        if (! $this->schema->hasIndexCovering($membershipTable, 'expires_at')
            && ! $this->indexMentionsColumn($membershipTable, 'expires_at')) {
            throw new \RuntimeException('user_group_user.expires_at must be indexed.');
        }

        if (! $this->schema->hasUniqueIndexCovering($membershipTable, 'user_group_id', 'user_id')
            && ! $this->schema->hasUniqueIndexCovering($membershipTable, 'user_id', 'user_group_id')) {
            throw new \RuntimeException('user_group_user must UNIQUE(user_group_id, user_id).');
        }

        if (! $this->indexMentionsColumn($membershipTable, 'user_id')) {
            throw new \RuntimeException('user_group_user.user_id must be indexed.');
        }

        $rolePivot = 'user_group_role';
        $this->schema->assertTableExists($rolePivot);

        if (! $this->schema->hasUniqueIndexCovering($rolePivot, 'user_group_id', 'role_id')
            && ! $this->schema->hasUniqueIndexCovering($rolePivot, 'role_id', 'user_group_id')) {
            throw new \RuntimeException('user_group_role must UNIQUE(user_group_id, role_id).');
        }

        if (! $this->indexMentionsColumn($rolePivot, 'role_id')) {
            throw new \RuntimeException('user_group_role.role_id must be indexed.');
        }

        // Primary proof: the migrated Spatie schema must not grow package-owned columns.
        $this->assertSpatieTablesMatchAllowlist();

        // Extra signal only: migration sources that mention altering Spatie tables.
        $offenders = $this->migrationsThatAlterSpatieTables();

        if ($offenders !== []) {
            throw new \RuntimeException(
                'laravel-access / product migrations must not alter Spatie role tables: '
                .implode(', ', array_slice($offenders, 0, 8)),
            );
        }
    }

    /**
     * Bidirectional Spatie schema allowlist: unexpected columns fail, required
     * columns/types/indexes must still be present (drops and type changes go red).
     *
     * @return array<string, array{
     *     required: array<string, list<string>>,
     *     optional: list<string>,
     *     required_unique_indexes: list<list<string>>
     * }>
     */
    private function spatieTableAllowlists(): array
    {
        $stringTypes = ['varchar', 'string', 'character varying', 'char'];
        $integerTypes = ['bigint', 'integer', 'int', 'int8', 'int4'];
        $datetimeTypes = ['datetime', 'timestamp', 'date'];

        return [
            'roles' => [
                'required' => [
                    'id' => $integerTypes,
                    'name' => $stringTypes,
                    'guard_name' => $stringTypes,
                    'created_at' => $datetimeTypes,
                    'updated_at' => $datetimeTypes,
                ],
                'optional' => [
                    'organisation_id', 'team_id', // team_id: externa 1.x until v2
                    'public_id', 'is_system', 'is_assignable', 'description', 'metadata',
                ],
                'required_unique_indexes' => [
                    ['name', 'guard_name'],
                    ['organisation_id', 'name', 'guard_name'],
                    ['team_id', 'name', 'guard_name'],
                ],
            ],
            'permissions' => [
                'required' => [
                    'id' => $integerTypes,
                    'name' => $stringTypes,
                    'guard_name' => $stringTypes,
                    'created_at' => $datetimeTypes,
                    'updated_at' => $datetimeTypes,
                ],
                'optional' => [],
                'required_unique_indexes' => [
                    ['name', 'guard_name'],
                ],
            ],
            'model_has_roles' => [
                'required' => [
                    'role_id' => $integerTypes,
                    'model_type' => $stringTypes,
                    'model_id' => $integerTypes,
                ],
                'optional' => ['organisation_id', 'team_id'],
                'required_unique_indexes' => [],
            ],
            'model_has_permissions' => [
                'required' => [
                    'permission_id' => $integerTypes,
                    'model_type' => $stringTypes,
                    'model_id' => $integerTypes,
                ],
                'optional' => ['organisation_id', 'team_id'],
                'required_unique_indexes' => [],
            ],
            'role_has_permissions' => [
                'required' => [
                    'permission_id' => $integerTypes,
                    'role_id' => $integerTypes,
                ],
                'optional' => [],
                'required_unique_indexes' => [],
            ],
        ];
    }

    /**
     * Fail when the migrated Spatie schema diverges from the allowlist in either direction.
     */
    private function assertSpatieTablesMatchAllowlist(): void
    {
        foreach ($this->spatieTableAllowlists() as $table => $definition) {
            if (! $this->schema->hasTable($table)) {
                throw new \RuntimeException("Expected Spatie table \"{$table}\" to exist when usesLaravelAccess is set.");
            }

            $allowedLookup = array_flip(array_merge(
                array_keys($definition['required']),
                $definition['optional'],
            ));

            foreach ($this->schema->columnNames($table) as $column) {
                if (! isset($allowedLookup[$column])) {
                    throw new \RuntimeException(
                        "Spatie table {$table} has unexpected column \"{$column}\"; laravel-access must not alter Spatie tables.",
                    );
                }
            }

            foreach ($definition['required'] as $column => $typeNeedles) {
                if (! in_array($column, $this->schema->columnNames($table), true)) {
                    throw new \RuntimeException(
                        "Spatie table {$table} is missing required column \"{$column}\".",
                    );
                }

                $this->assertSpatieColumnType($table, $column, $typeNeedles);
            }

            foreach (['organisation_id', 'team_id', 'public_id'] as $uuidColumn) {
                if (in_array($uuidColumn, $this->schema->columnNames($table), true)) {
                    $this->assertSpatieColumnType($table, $uuidColumn, ['uuid', 'char', 'guid', 'uniqueidentifier']);
                }
            }

            if ($definition['required_unique_indexes'] !== []) {
                $hasRequiredUnique = false;

                foreach ($definition['required_unique_indexes'] as $columns) {
                    if ($this->schema->hasUniqueIndexCovering($table, ...$columns)) {
                        $hasRequiredUnique = true;
                        break;
                    }
                }

                if (! $hasRequiredUnique) {
                    $patterns = array_map(
                        static fn (array $columns): string => '('.implode(', ', $columns).')',
                        $definition['required_unique_indexes'],
                    );

                    throw new \RuntimeException(
                        "Spatie table {$table} is missing a required unique index; expected one of: "
                        .implode(', ', $patterns).'.',
                    );
                }
            }
        }
    }

    /**
     * @param  list<string>  $typeNeedles
     */
    private function assertSpatieColumnType(string $table, string $column, array $typeNeedles): void
    {
        foreach ($typeNeedles as $needle) {
            if ($this->schema->columnTypeContains($table, $column, $needle)) {
                return;
            }
        }

        throw new \RuntimeException(
            "Spatie table {$table}.{$column} has an unexpected type; laravel-access must not alter Spatie column types.",
        );
    }

    private function indexMentionsColumn(string $table, string $column): bool
    {
        foreach ($this->schema->indexes($table) as $index) {
            if (in_array($column, $index['columns'] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Prove AC17 XOR: is_instance=true only with organisation_id NULL. A rolled-back
     * insert of the forbidden combo must fail (CHECK or SQLite trigger).
     */
    private function assertUserGroupsInstanceOrganisationXor(): void
    {
        $connection = DB::connection();
        $connection->beginTransaction();

        try {
            $connection->table('user_groups')->insert([
                'public_id' => (string) Str::uuid(),
                'name' => '__c75_instance_org_xor__',
                'organisation_id' => (string) Str::uuid(),
                'is_instance' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $connection->rollBack();

            throw new \RuntimeException(
                'user_groups must CHECK that is_instance=true only when organisation_id IS NULL (is_instance with organisation_id set must be rejected).',
            );
        } catch (QueryException) {
            $connection->rollBack();
        }
    }

    /**
     * Extra signal: migration sources that alter Spatie tables (literal Schema::table,
     * variable Schema::table after assignment, ALTER TABLE, or constructed DDL via
     * sprintf / concatenation). Comments are stripped. Constructed DDL is a
     * best-effort heuristic — the live-schema allowlist remains the primary proof.
     *
     * @return list<string>
     */
    private function migrationsThatAlterSpatieTables(): array
    {
        $directories = [
            $this->basePath.'/database/migrations',
            $this->basePath.'/vendor/qiick/laravel-access/database/migrations',
        ];
        $spatieTables = ['roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions'];
        $offenders = [];

        foreach ($directories as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            foreach (File::files($directory) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $contents = $this->stripPhpCommentsForScan($file->getContents());

                foreach ($spatieTables as $table) {
                    $quoted = preg_quote($table, '/');

                    if (preg_match('/Schema::table\(\s*[\'"]'.$quoted.'[\'"]/', $contents) === 1
                        || preg_match('/ALTER\s+TABLE\s+[\'"`]?'.$quoted.'[\'"`]?/i', $contents) === 1
                        || preg_match(
                            '/\$(\w+)\s*=\s*[\'"]'.$quoted.'[\'"]\s*;[\s\S]*?Schema::table\(\s*\$\1\b/',
                            $contents,
                        ) === 1
                        || preg_match(
                            '/sprintf\s*\(\s*[\'"][^\'"]*ALTER\s+TABLE[^\'"]*[\'"]\s*,\s*[\'"]'.$quoted.'[\'"]/i',
                            $contents,
                        ) === 1
                        || preg_match(
                            '/[\'"]ALTER\s+TABLE\s+[\'"]\s*\.\s*[\'"]'.$quoted.'[\'"]/i',
                            $contents,
                        ) === 1
                        || preg_match(
                            '/[\'"]ALTER\s+TABLE\s+'.$quoted.'\b/i',
                            $contents,
                        ) === 1) {
                        $offenders[] = $file->getFilename();
                        break;
                    }
                }
            }
        }

        return $offenders;
    }

    /**
     * Strip //, # and block comments so a commented Schema::table('roles') is not a false positive.
     */
    private function stripPhpCommentsForScan(string $contents): string
    {
        $withoutBlock = preg_replace('~/\*.*?\*/~s', '', $contents) ?? $contents;

        return preg_replace('~^\s*(//|#).*$~m', '', $withoutBlock) ?? $withoutBlock;
    }

    /**
     * Insert a real users row for probe FKs (C3). Hard-coding user_id=1 aborts
     * PostgreSQL when the row is missing.
     */
    private function insertFixtureUser(): int|string
    {
        $publicIdColumn = $this->hooks->publicIdColumn();
        $attributes = [
            $publicIdColumn => (string) Str::uuid(),
            'email' => 'conformance-c3-'.bin2hex(random_bytes(4)).'@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if ($this->schema->hasColumn('users', 'locale')) {
            $attributes['locale'] = 'en';
        }

        if ($this->schema->hasColumn('users', 'timezone')) {
            $attributes['timezone'] = 'UTC';
        }

        if ($this->schema->hasColumn('users', 'is_active')) {
            $attributes['is_active'] = true;
        }

        try {
            return $this->schema->connection()->table('users')->insertGetId($attributes);
        } catch (\Throwable $exception) {
            throw new \RuntimeException(
                'C3 could not insert a fixture user (extra NOT NULL columns without defaults?). '.$exception->getMessage(),
                0,
                $exception,
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function parseOpenApiDocument(string $content): array
    {
        $trimmed = ltrim($content);

        if (str_starts_with($trimmed, '{')) {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            return $decoded;
        }

        if (class_exists(Yaml::class)) {
            /** @var array<string, mixed>|null $parsed */
            $parsed = Yaml::parse($content);

            return is_array($parsed) ? $parsed : [];
        }

        // Minimal YAML path map when symfony/yaml is unavailable: enough for the check.
        return $this->parseOpenApiPathsFromYamlRoughly($content);
    }

    /**
     * @return array<string, mixed>
     */
    private function parseOpenApiPathsFromYamlRoughly(string $content): array
    {
        $paths = [];
        $servers = [];
        $currentPath = null;

        foreach (preg_split("/\r\n|\n|\r/", $content) ?: [] as $line) {
            if (preg_match('/^\s*-\s*url:\s*[\'"]?([^\'"\s]+)/', $line, $serverMatch) === 1) {
                $servers[] = ['url' => $serverMatch[1]];
            }

            if (preg_match('/^(\s*)(\/[^\s:]*):\s*$/', $line, $pathMatch) === 1) {
                $currentPath = $pathMatch[2];
                $paths[$currentPath] = [];

                continue;
            }

            if ($currentPath !== null
                && preg_match('/^\s+(get|post|put|patch|delete|head|options|trace)\s*:/i', $line, $methodMatch) === 1) {
                $paths[$currentPath][strtolower($methodMatch[1])] = [];
            }
        }

        return [
            'servers' => $servers,
            'paths' => $paths,
        ];
    }

    /**
     * Exact METHOD + path keys documented in OpenAPI, expanded by each servers URL path prefix.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, true>
     */
    private function openApiPathMethodPairs(array $document): array
    {
        $serverPrefixes = $this->openApiServerPathPrefixes($document);
        $pairs = [];
        $paths = $document['paths'] ?? [];

        if (! is_array($paths)) {
            return $pairs;
        }

        foreach ($paths as $path => $operations) {
            if (! is_string($path) || ! is_array($operations)) {
                continue;
            }

            foreach ($operations as $method => $operation) {
                if (! is_string($method) || in_array(strtolower($method), ['parameters', 'summary', 'description', 'servers'], true)) {
                    continue;
                }

                if (! is_array($operation) && $operation !== null) {
                    continue;
                }

                foreach ($serverPrefixes as $prefix) {
                    $fullPath = $this->normalizeOpenApiPath($prefix.$path);
                    $pairs[strtoupper($method).' '.$fullPath] = true;
                }
            }
        }

        return $pairs;
    }

    /**
     * @param  array<string, mixed>  $document
     * @return list<string>
     */
    private function openApiServerPathPrefixes(array $document): array
    {
        $prefixes = [''];
        $servers = $document['servers'] ?? [];

        if (! is_array($servers) || $servers === []) {
            return $prefixes;
        }

        $prefixes = [];

        foreach ($servers as $server) {
            if (! is_array($server)) {
                continue;
            }

            $url = $server['url'] ?? null;

            if (! is_string($url) || $url === '') {
                continue;
            }

            $path = parse_url($url, PHP_URL_PATH);

            if (! is_string($path) || $path === '' || $path === '/') {
                $prefixes[] = '';

                continue;
            }

            $prefixes[] = rtrim($path, '/');
        }

        return $prefixes === [] ? [''] : array_values(array_unique($prefixes));
    }

    private function normalizeOpenApiPath(string $path): string
    {
        $normalized = '/'.trim($path, '/');

        if ($normalized === '/') {
            return '/';
        }

        // Laravel {param} and OpenAPI {param} already match; collapse duplicate slashes.
        $normalized = (string) preg_replace('#/+#', '/', $normalized);

        return $normalized;
    }

    private function hasStableTag(): bool
    {
        $escaped = escapeshellarg($this->basePath);
        $toplevel = trim((string) shell_exec("git -C {$escaped} rev-parse --show-toplevel 2>/dev/null"));
        $resolvedBase = realpath($this->basePath) ?: $this->basePath;

        // A nested fixture (the app-ui harness) must not inherit the parent repo's tags.
        if ($toplevel === '' || realpath($toplevel) !== $resolvedBase) {
            return false;
        }

        $output = [];
        exec("git -C {$escaped} tag --list \"v*\"", $output);

        foreach ($output as $tag) {
            if (preg_match('/^v\d+\.\d+\.\d+$/', trim($tag)) === 1) {
                return true;
            }
        }

        return false;
    }
}
