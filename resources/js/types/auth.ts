/** Authenticated user record from shared Inertia props. */
export type User = {
    id: number;
    first_name: string;
    last_name: string | null;
    /** First and last name. */
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    locale?: string;
    two_factor_enabled?: boolean;
    /** Weekly email digest (#114). */
    has_weekly_digest?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

/** Shared auth context: user, permissions, roles, and super-admin flag. */
export type Auth = {
    user: User | null;
    permissions: string[];
    roleNames: string[];
    isSuperAdmin: boolean;
};

/** Passkey registered by the current user (Laravel passkeys). */
export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};
