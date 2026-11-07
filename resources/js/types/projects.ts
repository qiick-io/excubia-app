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
    /** excubia-core version that built the report (1.0 and later). */
    excubia_version: string | null;
    /** PHP / Laravel / Node versions and support windows (excubia-core 1.0 and later). */
    runtime: Record<RuntimeName, RuntimeVersion | null> | null;
    checks: Record<'pass' | 'warn' | 'fail' | 'skipped', number>;
    top_finding: {
        severity: Severity;
        subject: string;
        message: string;
        check_name: string | null;
    } | null;
};

export type RuntimeName = 'php' | 'laravel' | 'node';

/** One runtime of the report's `project` block. Dates are `YYYY-MM-DD`. */
export type RuntimeVersion = {
    version: string | null;
    cycle: string | null;
    security_support_ends_on: string | null;
    active_support_ends_on: string | null;
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
    /** sha256 of check id, subject and advisory id (excubia-core 1.0). */
    fingerprint?: string;
    /** Check-specific keys (excubia-core 1.0), e.g. package, lockedVersion, fixedVersions. */
    details?: Record<string, unknown>;
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
    generatedAt?: string;
    excubiaVersion?: string;
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

/** p50, p95 and p99 in ms from the latency histograms; null without histograms. */
export type RuntimePercentiles = {
    p50: number | null;
    p95: number | null;
    p99: number | null;
};

/** A row of a timed runtime table (ProjectRuntimeService): its dimensions plus totals. */
export type RuntimeRow = {
    count: number;
    errors: number;
    averageMs: number | null;
    maxMs: number | null;
    outcomes: Record<string, number>;
    lastAt: string | null;
} & RuntimePercentiles &
    Record<string, unknown>;

/** A row of a runtime card without durations: its dimensions plus card-specific numbers. */
export type RuntimeCountRow = {
    count: number;
    lastAt: string | null;
} & Record<string, unknown>;

/** The latest sample of one server (`server` aggregates). Gauges are null when unknown. */
export type RuntimeServer = {
    server: string;
    loadAverage1: number | null;
    loadAverage5: number | null;
    loadAverage15: number | null;
    cpuCores: number | null;
    cpuLimit: number | null;
    memoryUsedMb: number | null;
    memoryTotalMb: number | null;
    diskUsedGb: number | null;
    diskTotalGb: number | null;
    isContainer: boolean;
    lastAt: string;
};

/** The runtime of a project over the last hours (#24, #107). Counts are estimated real counts. */
export type RuntimeSummary = {
    hours: number;
    isSampled: boolean;
    /** Entries of runtime types this app does not know, skipped on ingest (all time). */
    skippedAggregates: number;
    requests: {
        count: number;
        errors: number;
        averageMs: number | null;
        maxMs: number | null;
        hourly: { hour: string; ok: number; errors: number }[];
        routes: RuntimeRow[];
    } & RuntimePercentiles;
    slowQueries: RuntimeRow[];
    jobs: RuntimeRow[];
    scheduledTasks: RuntimeRow[];
    outgoingHttp: RuntimeRow[];
    queueWait: RuntimeRow[];
    commands: RuntimeRow[];
    requestMemory: RuntimeCountRow[];
    jobMemory: RuntimeCountRow[];
    cache: {
        hits: number;
        misses: number;
        hitRate: number | null;
        rows: RuntimeCountRow[];
    };
    mail: RuntimeCountRow[];
    notifications: RuntimeCountRow[];
    logLevels: {
        levels: { level: string; count: number }[];
        rows: RuntimeCountRow[];
        hourly: { hour: string; count: number }[];
    };
    deprecations: RuntimeCountRow[];
    duplicateQueries: RuntimeCountRow[];
    servers: RuntimeServer[];
};

export type ExceptionFrame = {
    file: string | null;
    line: number | null;
    function: string | null;
    inApp: boolean;
};

/** An exception group of a project (#26), masked by excubia-core. */
export type ExceptionGroupRow = {
    id: number;
    fingerprint: string;
    environment: string;
    class: string;
    message: string;
    frames: ExceptionFrame[];
    previous: string[];
    context: { kind: 'request' | 'job' | 'command' | 'unknown'; name: string };
    count: number;
    first_seen: string;
    last_seen: string;
    status: 'open' | 'resolved' | 'ignored';
    resolved_at: string | null;
    fix: { command: string | null; prompt: string };
};

/** Status of a scheduled task (#110), see `ScheduledTaskStatusEnum`. */
export type ScheduledTaskStatus =
    | 'pending'
    | 'on_time'
    | 'late'
    | 'missed'
    | 'failed'
    | 'skipped'
    | 'scheduler_down'
    | 'not_expected'
    | 'paused'
    | 'unknown';

/** A task of the project's schedule inventory with its status (#110). Times are ISO 8601 UTC. */
export type ScheduledTaskRow = {
    id: number;
    task: string;
    expression: string;
    timezone: string;
    environments: string[];
    without_overlapping: boolean;
    on_one_server: boolean;
    runs_in_maintenance_mode: boolean;
    late_after_minutes: number;
    is_heartbeat: boolean;
    status: ScheduledTaskStatus;
    is_low_confidence: boolean;
    last_due_at: string | null;
    next_due_at: string | null;
    last_run_at: string | null;
    last_outcome: 'finished' | 'failed' | 'skipped' | null;
    status_changed_at: string | null;
    evaluated_at: string | null;
};

/** Status of an alert in the project's history (#114). */
export type AlertStatus =
    | 'queued'
    | 'sent'
    | 'failed'
    | 'suppressed'
    | 'skipped';

/** One alert of the project's history (#114). */
export type AlertHistoryRow = {
    id: number;
    event: string;
    severity: Severity;
    summary: string;
    /** Whether the alert is about one issue, so the issue can be muted. */
    has_subject: boolean;
    status: AlertStatus;
    reason: 'muted' | 'maintenance_window' | 'maintenance_mode' | null;
    /** Channel → queued | sent | failed. */
    deliveries: Record<string, 'queued' | 'sent' | 'failed'>;
    created_at: string;
};

/** A mute in force (#114); `until` null mutes forever. */
export type AlertMuteRow = {
    id: number;
    event: string;
    is_issue: boolean;
    label: string | null;
    until: string | null;
};

/** A maintenance window of a project (#114). */
export type MaintenanceWindowRow = {
    id: number;
    name: string;
    recurrence: 'once' | 'weekly';
    timezone: string;
    starts_at: string | null;
    ends_at: string | null;
    /** ISO weekdays, 1 Monday … 7 Sunday. */
    weekdays: number[];
    start_time: string | null;
    duration_minutes: number | null;
    is_active: boolean;
};

/** The project's Alerts tab (#114). */
export type ProjectAlerts = {
    history: AlertHistoryRow[];
    mutes: AlertMuteRow[];
    windows: MaintenanceWindowRow[];
    isInMaintenanceMode: boolean;
};
