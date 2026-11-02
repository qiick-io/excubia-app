/** Normalized Laravel paginator shape used across admin list views. */
export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links?: { url: string | null; label: string; active: boolean }[];
};

/** Permission names grouped by resource (`users.manage` → `users`) for the role form. */
export type PermissionGroup = {
    section: string;
    label: string;
    permissions: string[];
};

/** Generic id/label pair for admin select components. */
export type AdminSelectOption = {
    id: number;
    label: string;
    name?: string;
    email?: string;
};

export type AdminRoleRef = {
    id: number;
    name: string;
};

export type AdminGroupRef = {
    id: number;
    name: string;
};

export type AdminUserRef = {
    id: number;
    name: string;
    email: string;
};

/** One row of `App\Http\Resources\UserResource`. */
export type AdminUserRow = {
    id: number;
    name: string;
    email: string;
    is_active: boolean;
    is_sso_linked: boolean;
    email_verified_at: string | null;
    roles: AdminRoleRef[];
    groups: AdminGroupRef[];
    can_update: boolean;
    can_delete: boolean;
    created_at: string;
    updated_at: string;
};

/** One row of `App\Http\Resources\UserGroupResource`. */
export type AdminGroupRow = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    users_count?: number;
    roles: AdminRoleRef[];
    users?: AdminUserRef[];
    can_update: boolean;
    created_at: string;
    updated_at: string;
};

/** One row of `App\Http\Resources\RoleResource`. */
export type AdminRoleRow = {
    id: number;
    name: string;
    is_built_in: boolean;
    can_edit: boolean;
    permissions_count?: number;
    permissions?: string[];
    created_at: string;
    updated_at: string;
};
