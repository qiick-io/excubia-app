/** One main-sidebar module: visibility and whether it can be hidden. */
export type SidebarModuleSetting = {
    id: string;
    enabled: boolean;
    locked: boolean;
};

/** Project settings shared with every page (HandleInertiaRequests). */
export type SharedProjectSettings = {
    sidebarModules: SidebarModuleSetting[];
    reportBugUrl: string | null;
};

/** Password policies accepted by the Authentication & security page. */
export type PasswordPolicy = 'weak' | 'medium' | 'strong';

/** Light/dark/system theme. */
export type DefaultAppearance = 'system' | 'light' | 'dark';

/**
 * Project settings as edited on the settings pages (SettingsRepositoryService::forEdit()).
 * Each page receives a subset; the OIDC client secret is never sent, only `has_oidc_client_secret`.
 */
export type ProjectSettingsForm = {
    app_name: string | null;
    description: string | null;
    url: string | null;
    report_issue_url: string | null;
    report_bug_url: string | null;
    report_error_url: string | null;
    stale_after_hours: number;
    reports_kept_per_project: number;
    runtime_retention_days: number;
    exceptions_retention_days: number;
    activity_retention_days: number;
    default_language: string;
    default_appearance: DefaultAppearance;
    sidebar_modules: SidebarModuleSetting[];
    password_policy: PasswordPolicy;
    login_max_attempts: number;
    two_factor_required: boolean;
    oidc_enabled: boolean;
    oidc_issuer: string | null;
    oidc_client_id: string | null;
    has_oidc_client_secret: boolean;
    oidc_button_label: string | null;
    oidc_jit_provisioning: boolean;
};
