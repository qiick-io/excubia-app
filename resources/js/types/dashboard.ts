import type { FindingFix } from '@/components/reports/fix-actions';
import type { FlaggedLicense, RuntimeName, Severity } from '@/types';

/**
 * Props of the dashboard page (`pages/dashboard.tsx`) and its cards in `components/dashboard/`:
 * the cross-project overview built by the server for the selected scope and period.
 */
export type TrendPoint = { date: string } & Record<Severity, number>;
type ProjectRef = { slug: string; name: string };

export type TopIssue = {
    key: string;
    severity: Severity;
    checkId: string;
    checkName: string | null;
    package: string;
    message: string;
    fixHint: string;
    advisoryId: string | null;
    evidenceUrl: string | null;
    /** The next Laravel major of a `laravel-next-blocker` finding. */
    targetMajor: number | null;
    installed: string[];
    fixedVersions: string[];
    projects: (ProjectRef & { installed: string | null })[];
    /** Packages that share this check and message (#291); empty for a single package. */
    packages: { key: string; package: string; projects: ProjectRef[] }[];
    /** Null on a grouped row: each package has its own fix. */
    fix: FindingFix | null;
};

export type EndOfSupportRow = {
    project: ProjectRef;
    runtime: RuntimeName;
    version: string | null;
    cycle: string | null;
    securitySupportEndsOn: string | null;
    activeSupportEndsOn: string | null;
};

export type ConfigurationRow = {
    project: ProjectRef;
    checkId: string;
    checkName: string | null;
    severity: Severity;
    subject: string;
    message: string;
    fixHint: string;
    fix: FindingFix;
};

export type LicenseRow = {
    license: string;
    kind: FlaggedLicense['kind'];
    packages: string[];
    projects: ProjectRef[];
};

export type Overview = {
    kpis: {
        findings: {
            counts: Record<Severity, number>;
            total: number;
            previous: number | null;
        };
        atRisk: { value: number; previous: number | null };
        endOfSupport: { value: number; previous: number | null };
        stale: { value: number; total: number };
    };
    topIssues: TopIssue[];
    topIssuesTotal: number;
    endOfSupport: EndOfSupportRow[];
    configuration: ConfigurationRow[];
    /** Denied, copyleft and unknown licenses of the latest reports (#108). */
    licenses: LicenseRow[];
    runtime: {
        requests: number;
        errors: number;
        previousRequests: number;
    } | null;
    exceptions: {
        open: number;
        new: number;
        top: {
            id: number;
            class: string;
            message: string;
            count: number;
            lastSeen: string;
            context: string;
            project: ProjectRef;
        }[];
    } | null;
};

/** A project's open items assigned to the signed-in user (#254). */
export type AssignedRow = {
    slug: string;
    name: string;
    exceptions: number;
    findings: number;
};
