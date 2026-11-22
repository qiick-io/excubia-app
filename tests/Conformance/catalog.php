<?php

declare(strict_types=1);

/**
 * Fleet conformance check catalogue (qiick standards pack).
 *
 * Merged from the 2026-11-20 cross-reviews (OpenCode §5 + Cursor §5), deduplicated,
 * tagged schema|behaviour|config|file. Binding decision:
 * knowledge/decisions/2026-11-20-core-hardening.
 *
 * @return list<array{
 *     id: string,
 *     tag: 'schema'|'behaviour'|'config'|'file',
 *     description: string,
 *     decision: string,
 *     engines?: list<'pgsql'|'mysql'>,
 * }>
 */
return [
    [
        'id' => 'C1',
        'tag' => 'schema',
        'description' => 'users baseline: bigint id; UUID public_id UNIQUE (or subject divergence); nullable unique lowercase username; locale; timezone default UTC; nullable password; deleted_at; is_active; last_login_*; Fortify 2FA columns',
        'decision' => 'knowledge/decisions/2026-11-19-fleet-core-schema',
    ],
    [
        'id' => 'C2',
        'tag' => 'schema',
        'description' => 'No users.organisation_id; organisations use UUID PK and membership pivot UNIQUE(organisation_id,user_id)',
        'decision' => 'knowledge/decisions/2026-11-19-fleet-core-schema',
    ],
    [
        'id' => 'C3',
        'tag' => 'behaviour',
        'description' => 'user_identities(issuer,subject) exists with case-sensitive UNIQUE; no users.oidc_sub',
        'decision' => 'knowledge/decisions/2026-11-19-fleet-core-schema',
        'engines' => ['pgsql', 'mysql'],
    ],
    [
        'id' => 'C4',
        'tag' => 'config',
        'description' => 'permission.teams === true and team_foreign_key === organisation_id where org roles use Spatie teams',
        'decision' => 'knowledge/decisions/2026-11-19-fleet-core-schema',
    ],
    [
        'id' => 'C5',
        'tag' => 'schema',
        'description' => 'Three Spatie team columns are UUID; model_id stays bigint',
        'decision' => 'knowledge/decisions/2026-11-19-fleet-core-schema',
        'engines' => ['pgsql', 'mysql'],
    ],
    [
        'id' => 'C6',
        'tag' => 'schema',
        'description' => 'Spatie roles/permissions tables, user_groups.deleted_at, unique group/user and group/role pivots',
        'decision' => 'knowledge/decisions/2026-11-19-roles-groups-initial-admin-and-username',
    ],
    [
        'id' => 'C7',
        'tag' => 'behaviour',
        'description' => 'A soft-deleted group grants no permission through the real resolver',
        'decision' => 'knowledge/decisions/2026-11-19-roles-groups-initial-admin-and-username',
    ],
    [
        'id' => 'C8',
        'tag' => 'behaviour',
        'description' => 'Global role definitions cannot be duplicated via the Spatie NULL-unique hole',
        'decision' => 'knowledge/decisions/2026-11-20-core-hardening',
    ],
    [
        'id' => 'C9',
        'tag' => 'schema',
        'description' => 'principals.id UUID; user_id nullable UNIQUE; organisation_id UUID nullable; deactivated_at; every seeded user has one principal',
        'decision' => 'knowledge/decisions/2026-11-19-fleet-core-schema',
    ],
    [
        'id' => 'C10',
        'tag' => 'schema',
        'description' => 'API keys: key_hash UNIQUE; owner_principal_id UUID FK; FK actions never cascade-erase evidence; no required owner_id→users after backfill',
        'decision' => 'knowledge/decisions/2026-11-20-core-hardening',
    ],
    [
        'id' => 'C11',
        'tag' => 'schema',
        'description' => 'API key columns complete; expires_at and previous_key_valid_until are dateTime, never MySQL TIMESTAMP',
        'decision' => 'knowledge/decisions/2026-11-20-core-hardening',
        'engines' => ['pgsql', 'mysql'],
    ],
    [
        'id' => 'C12',
        'tag' => 'schema',
        'description' => 'Activity morph ids string(64); nullable indexed organisation_id; no 26-char org id',
        'decision' => 'knowledge/decisions/2026-11-19-fleet-core-schema',
    ],
    [
        'id' => 'C13',
        'tag' => 'behaviour',
        'description' => 'API-key-authenticated write records the public UUID in properties.api_key_id; morph values are public UUIDs',
        'decision' => 'knowledge/decisions/2026-11-20-core-hardening',
    ],
    [
        'id' => 'C14',
        'tag' => 'schema',
        'description' => 'Settings: scope, scope_id, group, key NOT NULL; scope_id string(36); 4-column UNIQUE plus 3-column index',
        'decision' => 'knowledge/decisions/2026-11-19-fleet-core-schema',
    ],
    [
        'id' => 'C15',
        'tag' => 'behaviour',
        'description' => 'Duplicate instance settings with scope_id="" fail on PostgreSQL and MySQL; empty string is the instance sentinel, never NULL',
        'decision' => 'knowledge/decisions/2026-11-20-core-hardening',
        'engines' => ['pgsql', 'mysql'],
    ],
    [
        'id' => 'C16',
        'tag' => 'schema',
        'description' => 'notifications, sessions and passkeys exist with Laravel-native columns',
        'decision' => 'knowledge/decisions/2026-11-19-fleet-core-schema',
    ],
    [
        'id' => 'C17',
        'tag' => 'behaviour',
        'description' => 'Erasure leaves anonymised activity, does not cascade-delete keys/audit links; ownerless keys are revoked; email/username become unique tombstones',
        'decision' => 'knowledge/decisions/2026-11-20-core-hardening',
    ],
    [
        'id' => 'C18',
        'tag' => 'config',
        'description' => 'Initial-admin env keys exist only via config/super_admin.php (four INITIAL_SUPER_ADMIN_* keys, default superadmin@example.com)',
        'decision' => 'knowledge/decisions/2026-11-19-roles-groups-initial-admin-and-username',
    ],
    [
        'id' => 'C19',
        'tag' => 'behaviour',
        'description' => 'Seeder creates nothing with the known default outside local/testing, creates with a strong explicit password, and never modifies an existing user',
        'decision' => 'knowledge/decisions/2026-11-19-roles-groups-initial-admin-and-username',
    ],
    [
        'id' => 'C20',
        'tag' => 'behaviour',
        'description' => 'Thrown exceptions map to documented error codes from one backed enum; JSON shape preserved; no stack with APP_DEBUG=false; /api/* and /mcp answer JSON without Accept',
        'decision' => 'knowledge/decisions/2026-11-20-core-hardening',
    ],
    [
        'id' => 'C21',
        'tag' => 'behaviour',
        'description' => 'Accept-Language negotiation sets Content-Language on success and error responses',
        'decision' => 'knowledge/decisions/2026-11-20-core-hardening',
    ],
    [
        'id' => 'C22',
        'tag' => 'behaviour',
        'description' => 'Pagination shape is exactly Laravel data, links, meta',
        'decision' => 'knowledge/decisions/2026-11-20-core-hardening',
    ],
    [
        'id' => 'C23',
        'tag' => 'behaviour',
        'description' => 'Throttle headers X-RateLimit-Limit, X-RateLimit-Remaining, Retry-After on 429',
        'decision' => 'knowledge/decisions/2026-11-20-core-hardening',
    ],
    [
        'id' => 'C24',
        'tag' => 'behaviour',
        'description' => 'X-Request-Id on every response; valid inbound preserved, invalid replaced; id reaches logs',
        'decision' => 'knowledge/decisions/2026-11-20-core-hardening',
    ],
    [
        'id' => 'C25',
        'tag' => 'behaviour',
        'description' => 'Idempotency-Key replay on retryable creates: same key+body returns original; different body fails; no duplicate rows',
        'decision' => 'knowledge/decisions/2026-11-20-core-hardening',
    ],
    [
        'id' => 'C26',
        'tag' => 'file',
        'description' => 'OpenAPI document exists, declares openapi 3.1.x, and every named api/v1 route appears in it',
        'decision' => 'knowledge/decisions/2026-11-20-core-hardening',
    ],
    [
        'id' => 'C27',
        'tag' => 'behaviour',
        'description' => 'Outbound webhooks use Standard Webhooks headers, whsec_ secret and {type,timestamp,data} envelope',
        'decision' => 'knowledge/decisions/2026-11-19-shared-packages-for-api-keys-and-outbound-http',
    ],
    [
        'id' => 'C28',
        'tag' => 'behaviour',
        'description' => 'SSRF guard accepts only curl-resolved IPv4 forms and pins with CURLOPT_CONNECT_TO; metadata blocked',
        'decision' => 'knowledge/decisions/2026-11-19-shared-packages-for-api-keys-and-outbound-http',
    ],
    [
        'id' => 'C29',
        'tag' => 'behaviour',
        'description' => 'Team switch calls setPermissionsTeamId and unsets roles/permissions relations; roles from org A are not visible in org B',
        'decision' => 'knowledge/decisions/2026-11-20-core-hardening',
    ],
    [
        'id' => 'C30',
        'tag' => 'behaviour',
        'description' => 'Instance super-admin works with zero organisations and is not a Spatie null-team assignment',
        'decision' => 'knowledge/decisions/2026-11-20-core-hardening',
    ],
    [
        'id' => 'C31',
        'tag' => 'behaviour',
        'description' => 'API resources never expose internal bigint user/key/org ids; only public UUID ids',
        'decision' => 'knowledge/decisions/2026-11-20-core-hardening',
    ],
    [
        'id' => 'C32',
        'tag' => 'behaviour',
        'description' => 'Encrypted casts cover 2FA/webhook/OIDC/SAML/passkey secrets; key_hash stays plain SHA-256; APP_PREVIOUS_KEYS rotation works',
        'decision' => 'knowledge/decisions/2026-11-20-core-hardening',
    ],
    [
        'id' => 'C33',
        'tag' => 'file',
        'description' => 'database/migrations/.frozen is absent before the first stable v*; after a stable tag the baseline may exist',
        'decision' => 'knowledge/decisions/2026-11-20-migrations-freeze-at-first-stable-release',
    ],
    [
        'id' => 'C34',
        'tag' => 'file',
        'description' => 'No add_/alter_/drop_ migration before the first stable tag; pre-1.0 schema belongs in create_',
        'decision' => 'knowledge/decisions/2026-11-20-migrations-freeze-at-first-stable-release',
    ],
    [
        'id' => 'C35',
        'tag' => 'file',
        'description' => 'graphify-out/ ignored in .gitignore and .graphifyignore',
        'decision' => 'knowledge/decisions/2026-11-20-core-hardening',
    ],
    [
        'id' => 'C36',
        'tag' => 'file',
        'description' => 'App code never calls permission:setup-teams',
        'decision' => 'knowledge/decisions/2026-11-19-fleet-core-schema',
    ],
    [
        'id' => 'C37',
        'tag' => 'file',
        'description' => 'Every conformance divergence has reason, decision and review_by; unknown ids fail; expired review_by fails; stale (still green) declarations fail',
        'decision' => 'knowledge/decisions/2026-11-20-core-hardening',
    ],
];
