/** Who sees a project: everyone with projects.view, or admins only (#70). */
export type ProjectVisibility = 'everyone' | 'admins';

/** One row of `App\Http\Resources\ProjectResource`. */
export type ProjectRow = {
    id: number;
    name: string;
    slug: string;
    is_enabled: boolean;
    /** Excubia itself (#70): seeded, reported locally, never deleted. */
    is_system: boolean;
    visibility: ProjectVisibility;
    has_push_token: boolean;
    last_report_at: string | null;
    /** Never reported, or last report older than the stale setting. */
    is_stale: boolean;
    /** Present on the dashboard and the project page only. */
    latest_report?: HealthReportSummary | null;
    created_at: string;
    updated_at: string;
};

/** Plain push token flashed once by `ProjectController` after create or rotate. */
export type PushTokenFlash = {
    project: string;
    token: string;
    /** Base URL the project pushes to (`EXCUBIA_APP_URL`). */
    url: string;
};

/** Finding severities of the excubia-core report, worst first. */
export type Severity = 'critical' | 'high' | 'medium' | 'low' | 'info';

export type CheckStatus = 'pass' | 'warn' | 'fail' | 'skipped';

/** One row of `App\Http\Resources\HealthReportResource`. */
export type HealthReportSummary = {
    id: number;
    received_at: string;
    generated_at: string;
    /** Worst severity of the findings that warn or fail; null when there are none. */
    worst_severity: Severity | null;
    counts: Record<Severity, number>;
};

/** A finding of the excubia-core JSON report (`docs/report-schema.json`). */
export type ReportFinding = {
    checkId: string;
    severity: Severity;
    status: CheckStatus;
    subject: string;
    message: string;
    fixHint: string;
    evidenceUrl: string | null;
    advisoryId?: string | null;
    skipReason?: string;
};

export type ReportCheck = {
    checkId: string;
    name: string;
    status: CheckStatus;
    findings: ReportFinding[];
};

/** The excubia-core JSON report, schema version 1. */
export type HealthReportBody = {
    schemaVersion: number;
    checks: ReportCheck[];
    upgradePlan: { stepNumber: number; message: string }[];
};

/** One of the user's assistant conversations (`AssistantConversationService`). */
export type AssistantConversation = {
    id: string;
    title: string;
    updated_at: string | null;
};

/** A user or assistant message of a conversation. */
export type AssistantMessage = {
    id: string;
    role: 'user' | 'assistant';
    content: string;
    is_failed: boolean;
    created_at: string | null;
};
