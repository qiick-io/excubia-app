/**
 * FE mirrors of the backend `max:` validation rules (the backend is the source of truth).
 */
export const STRING_LIMITS = {
    SEARCH: 255,
    NAME: 255,
    GROUP_NAME: 100,
    EMAIL: 255,
    DESCRIPTION: 1000,
} as const;
