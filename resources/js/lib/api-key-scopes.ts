/**
 * The API key permission vocabulary of every qiick product (docs/api-keys.md): a scope is
 * `<resource>:<action>`, the action is `read` or `write`, and `write` implies `read` on the
 * same resource. A key stores one scope per resource, the highest it holds, so the scope
 * picker and the list never have to reconcile `posts:read` next to `posts:write`.
 *
 * No imports: `node --test` loads this file directly (scripts/api-key-scopes.test.mjs).
 */

/** A valid scope string. Anything else is refused at the trust boundary, never stored. */
export const API_KEY_SCOPE_PATTERN = /^[a-z][a-z0-9-]*:(read|write)$/;

export type ApiKeyScopeAction = 'read' | 'write';

/** What a key may do with one resource; `none` is the absence of a scope. */
export type ApiKeyAccessLevel = 'none' | ApiKeyScopeAction;

/** Where a key stands; expired and revoked keys fail closed on the server. */
export type ApiKeyStatus = 'active' | 'expired' | 'revoked';

/**
 * @param scope - A string from a request or the database
 * @returns Whether it follows the `<resource>:(read|write)` vocabulary
 */
export function isApiKeyScope(scope: string): boolean {
    return API_KEY_SCOPE_PATTERN.test(scope);
}

/**
 * The access a key holds on one resource. `write` wins over `read`, because write implies
 * read; strings outside the vocabulary are ignored.
 *
 * @param scopes - The key's scopes
 * @param resource - The resource name (`posts`, `mcp`)
 */
export function apiKeyAccessLevel(
    scopes: readonly string[],
    resource: string,
): ApiKeyAccessLevel {
    if (scopes.includes(`${resource}:write`)) {
        return 'write';
    }

    if (scopes.includes(`${resource}:read`)) {
        return 'read';
    }

    return 'none';
}

/**
 * The scopes with one resource set to a new access level: its previous scopes are dropped
 * and at most one, the highest, is added. The other resources keep their order.
 *
 * @param scopes - The key's current scopes
 * @param resource - The resource to change
 * @param level - Its new access level
 * @returns A new array; the input is not changed
 */
export function withApiKeyAccessLevel(
    scopes: readonly string[],
    resource: string,
    level: ApiKeyAccessLevel,
): string[] {
    const otherScopes = scopes.filter(
        (scope) => !scope.startsWith(`${resource}:`),
    );

    return level === 'none'
        ? otherScopes
        : [...otherScopes, `${resource}:${level}`];
}

/**
 * Revoked wins over expired: a revoked key stays revoked whatever its expiry says.
 *
 * @param key - The key's `revoked_at` and `expires_at` (ISO-8601 or null)
 * @param now - The moment to judge against
 */
export function apiKeyStatus(
    key: { revoked_at: string | null; expires_at: string | null },
    now: Date,
): ApiKeyStatus {
    if (key.revoked_at !== null) {
        return 'revoked';
    }

    if (
        key.expires_at !== null &&
        new Date(key.expires_at).getTime() <= now.getTime()
    ) {
        return 'expired';
    }

    return 'active';
}
