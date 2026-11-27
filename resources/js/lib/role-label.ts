import type { TFunction } from 'i18next';

/** The built-in roles (`RoleEnum`): only these have a translated name; custom roles keep theirs. */
const BUILT_IN_ROLES = new Set(['super-admin', 'admin', 'reader']);

/**
 * A role's name for people (#430): the translation of a built-in role, else the role's own name.
 */
export function roleLabel(t: TFunction, roleName: string): string {
    return BUILT_IN_ROLES.has(roleName)
        ? t(`roles.names.${roleName}`)
        : roleName;
}
