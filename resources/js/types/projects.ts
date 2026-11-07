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

/**
 * One check of the minute-level health samples (#115, `HealthStatusService::forPage()`): the
 * worst of its servers' latest readings, and each server with its last sample time.
 */
export type LiveHealthCheck = {
    checkId: string;
    status: CheckStatus;
    severity: Severity;
    findingCount: number;
    lastSampleAt: string;
    servers: {
        server: string;
        status: CheckStatus;
        lastSampleAt: string;
        /** No sample for five minutes: the scheduler or the push may be down. */
        isStale: boolean;
    }[];
};

/** One row of `App\Http\Resources\HealthReportResource`. */
export type HealthReportSummary = {
    id: number;
    received_at: string;
    generated_at: string;
    /** Release identifier of the deployed code (excubia-core 1.0.0-beta.2 and later). */
    release: string | null;
    /** Set when the report came from `excubia push --deploy`: a deploy marker (#106). */
    deployed_at: string | null;
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
    /** Worst status of the infrastructure checks (#109); null when none ran. */
    infrastructure: Exclude<CheckStatus, 'skipped'> | null;
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

/** Group of a check id (`CheckCategoryEnum`); `other` for ids this app does not know yet. */
export type CheckCategory =
    | 'infrastructure'
    | 'configuration'
    | 'dependencies'
    | 'other';

/** A finding that warns or fails, in page order (`ReportFindingsService::prioritized`). */
export type IssueRow = ReportFinding & {
    checkName: string | null;
    category: CheckCategory;
};

/** One step of a finding's history (#18). */
export type FindingHistoryEvent = {
    event: 'opened' | 'resolved' | 'reopened' | 'dismissed' | 'undismissed';
    occurredAt: string;
    release: string | null;
    by: string | null;
    reason: string | null;
    until: string | null;
};

/** A finding across reports (`FindingLifecycleService::forPage`, #18). */
export type FindingLifecycle = {
    id: number;
    status: 'open' | 'dismissed' | 'resolved';
    firstSeenAt: string;
    lastSeenAt: string;
    resolvedAt: string | null;
    reopenedCount: number;
    /** The active dismissal; `until` is the last day (`YYYY-MM-DD`), null for good. */
    dismissal: {
        reason: string | null;
        until: string | null;
        by: string | null;
        at: string | null;
    } | null;
    history: FindingHistoryEvent[];
};

/** A row of the Findings tab: the last report row of the finding and its lifecycle. */
export type LifecycleRow = IssueRow & { lifecycle: FindingLifecycle };

/** Counts of outdated direct dependencies, each at its furthest level. */
export type OutdatedCounts = { patch: number; minor: number; major: number };

/** A denied, strong copyleft or unknown license of a package (`dependency-licenses`). */
export type FlaggedLicense = {
    package: string;
    lockedVersion: string;
    licenses: string[];
    ecosystem: string;
    kind: 'denied' | 'copyleft' | 'unknown';
    severity: Severity;
};

/** Health summary of the latest report (`ReportHealthService::summary`, #108, #109). */
export type ReportHealth = {
    groups: {
        category: CheckCategory;
        status: CheckStatus;
        checks: {
            checkId: string;
            name: string;
            status: CheckStatus;
            issues: number;
        }[];
    }[];
    infrastructure: {
        status: Exclude<CheckStatus, 'skipped'> | null;
        diskUsedPercent: number | null;
        database: {
            connection: string;
            sizeMb: number;
            largestTables: { table: string; sizeMb: number }[];
        } | null;
        databaseConnections: number | null;
        queueBacklog: {
            queues: number;
            pendingCount: number;
            oldestAgeMinutes: number | null;
        } | null;
        redis: {
            connection: string;
            usedMb: number;
            maxMb: number | null;
            policy: string | null;
        }[];
        backups: {
            disk: string;
            ageHours: number | null;
            sizeMb: number | null;
            count: number | null;
        }[];
    };
    outdated: {
        composer: OutdatedCounts | null;
        npm: OutdatedCounts | null;
    };
    licenses: {
        packageCount: number;
        byLicense: {
            license: string;
            composer: number;
            npm: number;
            total: number;
        }[];
        flagged: FlaggedLicense[];
    } | null;
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

/** A time range of the runtime, exceptions and schedule tabs (#113), `TimeRangeEnum`. */
export type TimeRange = '1h' | '6h' | '24h' | '7d' | '14d';

/**
 * The runtime of a project over a time range (#24, #107, #113). Counts are estimated real
 * counts; `timeline` has one point per chart bucket, `at` being its ISO start.
 */
export type RuntimeSummary = {
    range: TimeRange;
    hours: number;
    isSampled: boolean;
    /** Entries of runtime types this app does not know, skipped on ingest (all time). */
    skippedAggregates: number;
    requests: {
        count: number;
        errors: number;
        averageMs: number | null;
        maxMs: number | null;
        timeline: { at: string; ok: number; errors: number }[];
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
        timeline: { at: string; count: number }[];
    };
    deprecations: RuntimeCountRow[];
    duplicateQueries: RuntimeCountRow[];
    servers: RuntimeServer[];
    /** Deploys of the range, oldest first (#106); `bucket` matches a `timeline` point. */
    deploys: RuntimeDeploy[];
};

/** A deploy marker: a report sent by `excubia push --deploy` (#106). */
export type RuntimeDeploy = {
    release: string | null;
    deployedAt: string;
    bucket: string;
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
    /** Releases of the first and latest occurrence; null when the core sent none (#106). */
    first_release: string | null;
    last_release: string | null;
    /** Release a resolved group came back in. */
    regressed_release: string | null;
    status: 'open' | 'resolved' | 'ignored';
    resolved_at: string | null;
    fix: { command: string | null; prompt: string };
    /** On the Exceptions tab (#113): occurrences in the range and per chart bucket. */
    range_count?: number;
    timeline?: { at: string; count: number }[];
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
    /** Estimated runs per outcome in the range (#113). */
    runs: Record<'finished' | 'failed' | 'skipped', number>;
};

/** Estimated runs of all scheduled tasks per chart bucket and outcome (#113). */
export type ScheduleTimelinePoint = {
    at: string;
    finished: number;
    failed: number;
    skipped: number;
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
