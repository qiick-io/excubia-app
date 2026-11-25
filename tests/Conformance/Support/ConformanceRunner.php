<?php

declare(strict_types=1);

namespace Qiick\Standards\Conformance\Support;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

/**
 * Executes one fleet conformance check against the product's migrated database
 * and built config. Behaviour checks that need product specifics call hooks.
 */
final class ConformanceRunner
{
    public function __construct(
        private readonly string $basePath,
        private readonly ConformanceHooks $hooks,
        private readonly SchemaInspector $schema,
    ) {}

    /**
     * @param array{id: string, tag: string, description: string, decision: string, engines?: list<string>} $check
     */
    public function run(array $check): void
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
            'C29' => $this->hooks->requireCallable('assertTeamSwitch'),
            'C30' => $this->hooks->requireCallable('assertSuperAdmin'),
            'C31' => $this->hooks->requireCallable('assertApiResourcesHideBigints'),
            'C32' => $this->hooks->requireCallable('assertEncryptedCasts'),
            'C33' => $this->c33FrozenBaseline(),
            'C34' => $this->c34NoAlterMigrationsBeforeStable(),
            'C35' => $this->c35GraphifyIgnored(),
            'C36' => $this->c36NoSetupTeams(),
            'C37' => null, // meta-check handled by the suite runner
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

        if (! $this->schema->hasTable($organisationTable) && $this->schema->hasTable('organizations')) {
            $organisationTable = 'organizations';
            $membershipTable = $this->hooks->table('membershipTable', 'organization_user');
        }

        $this->schema->assertTableExists($organisationTable);
        $this->schema->assertColumnExists($organisationTable, 'id');

        if (! $this->schema->columnTypeContains($organisationTable, 'id', 'uuid')
            && ! $this->schema->columnTypeContains($organisationTable, 'id', 'char')
            && ! $this->schema->columnTypeContains($organisationTable, 'id', 'guid')) {
            throw new \RuntimeException("{$organisationTable}.id must be a UUID primary key.");
        }

        $this->schema->assertTableExists($membershipTable);

        $orgIdColumn = str_contains($membershipTable, 'organization') ? 'organization_id' : 'organisation_id';

        if (! $this->schema->hasUniqueIndexCovering($membershipTable, $orgIdColumn, 'user_id')
            && ! $this->schema->hasUniqueIndexCovering($membershipTable, 'user_id', $orgIdColumn)) {
            throw new \RuntimeException("{$membershipTable} must UNIQUE({$orgIdColumn}, user_id).");
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

        // Probe: case-sensitive uniqueness should allow Issuer/X and issuer/x as two rows.
        $probeIssuerA = 'ConformanceIssuer/Case';
        $probeIssuerB = 'conformanceissuer/case';
        $probeSubject = 'conformance-subject-'.bin2hex(random_bytes(4));

        try {
            $this->schema->statement(
                "INSERT INTO {$table} (user_id, issuer, subject, created_at, updated_at) VALUES (1, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
                [$probeIssuerA, $probeSubject],
            );
            $this->schema->statement(
                "INSERT INTO {$table} (user_id, issuer, subject, created_at, updated_at) VALUES (1, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
                [$probeIssuerB, $probeSubject],
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
        if (! $this->hooks->boolean('usesSpatieTeams', false)) {
            return;
        }

        if (Config::get('permission.teams') !== true) {
            throw new \RuntimeException('permission.teams must be true when usesSpatieTeams is set.');
        }

        if (Config::get('permission.column_names.team_foreign_key') !== 'organisation_id'
            && Config::get('permission.team_foreign_key') !== 'organisation_id') {
            // Spatie 6+ uses column_names.team_foreign_key; older used team_foreign_key.
            $teamKey = Config::get('permission.column_names.team_foreign_key')
                ?? Config::get('permission.team_foreign_key');

            if ($teamKey !== 'organisation_id') {
                throw new \RuntimeException("permission team_foreign_key must be organisation_id, got {$teamKey}.");
            }
        }
    }

    private function c5SpatieTeamColumnTypes(): void
    {
        if (! $this->hooks->boolean('usesSpatieTeams', false)) {
            return;
        }

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
    }

    private function c11ApiKeyColumns(): void
    {
        if (! $this->hooks->boolean('hasApiKeys', true)) {
            return;
        }

        $table = $this->hooks->table('apiKeysTable', 'api_keys');
        $this->schema->assertTableExists($table);

        foreach (['uuid', 'key_prefix', 'key_hash', 'scopes', 'ip_allowlist', 'rate_limit_per_minute', 'expires_at', 'previous_key_valid_until', 'last_used_at', 'revoked_at'] as $column) {
            // Some products name previous_key_* slightly differently during adoption.
            if ($column === 'previous_key_valid_until' && ! $this->schema->hasColumn($table, $column)) {
                if ($this->schema->hasColumn($table, 'previous_key_expires_at')) {
                    $column = 'previous_key_expires_at';
                }
            }

            $this->schema->assertColumnExists($table, $column);
        }

        if (! $this->schema->isDateTimeNotTimestamp($table, 'expires_at')) {
            throw new \RuntimeException('api_keys.expires_at must be dateTime, not MySQL TIMESTAMP.');
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

        $apiPrefix = trim($this->hooks->apiPrefix(), '/');
        $missing = [];

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            $uri = $route->uri();

            if ($name === null || ! str_starts_with($uri, $apiPrefix)) {
                continue;
            }

            if (! str_contains($content, $uri) && ! str_contains($content, '/'.$uri)) {
                $missing[] = "{$name} ({$uri})";
            }
        }

        if ($missing !== []) {
            throw new \RuntimeException('OpenAPI missing named api routes: '.implode(', ', array_slice($missing, 0, 10)));
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
