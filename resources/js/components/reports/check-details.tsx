import type { TFunction } from 'i18next';
import { BookOpen, ExternalLink } from 'lucide-react';
import { Fragment } from 'react';
import { useTranslation } from 'react-i18next';
import type { AssigneeOption } from '@/components/reports/assignee-select';
import { FindingLifecycleSection } from '@/components/reports/finding-lifecycle';
import type { FindingFix } from '@/components/reports/fix-actions';
import { FixActions } from '@/components/reports/fix-actions';
import { SeverityBadge } from '@/components/reports/severity-badge';
import { Badge } from '@/components/ui/badge';
import {
    DrawerBody,
    DrawerContent,
    DrawerDescription,
    DrawerFooter,
    DrawerHeader,
    DrawerTitle,
} from '@/components/ui/drawer';
import type {
    CheckCategory,
    CheckStatus,
    FindingLifecycle,
    IssueRow,
} from '@/types';

/** The checks table of the excubia-core README: what each check id looks at and when it warns. */
export const CHECKS_DOCS_URL =
    'https://github.com/qiick-io/excubia-core/blob/main/README.md#checks';

export const CHECK_CATEGORIES: CheckCategory[] = [
    'infrastructure',
    'configuration',
    'dependencies',
    'other',
];

export const CHECK_STATUS_VARIANTS = {
    fail: 'destructive',
    warn: 'warning',
    pass: 'success',
    skipped: 'muted',
} as const satisfies Record<CheckStatus, string>;

/**
 * The label of a check id; a check id this app does not know yet keeps the name core sent.
 */
export function checkLabel(
    t: TFunction,
    checkId: string,
    name?: string | null,
): string {
    return t(`checks.${checkId}.label`, { defaultValue: name || checkId });
}

/** What the check looks at, in one sentence; a generic line for unknown check ids. */
function checkDescription(t: TFunction, checkId: string): string {
    return t(`checks.${checkId}.description`, {
        defaultValue: t('checks.unknownDescription'),
    });
}

/** "oldestAgeMinutes" → "Oldest age minutes", for detail keys without a translation. */
function humanize(key: string): string {
    const words = key.replace(/([a-z0-9])([A-Z])/g, '$1 $2').toLowerCase();

    return words.charAt(0).toUpperCase() + words.slice(1);
}

/**
 * One `details` value, formatted by the key's naming in core's report schema: `*Mb` sizes,
 * `*Percent` and `hitRate` percentages, `*Minutes` / `*Hours` ages, booleans, lists.
 */
function formatValue(t: TFunction, key: string, value: unknown): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    if (typeof value === 'boolean') {
        return value ? t('details.yes') : t('details.no');
    }

    if (typeof value === 'number') {
        if (key.endsWith('Mb')) {
            return t('details.megabytes', { value: value.toLocaleString() });
        }

        if (key.endsWith('Percent') || key === 'hitRate') {
            return t('details.percent', { value: value.toLocaleString() });
        }

        if (key.endsWith('Minutes') || key.startsWith('minutesSince')) {
            return t('details.minutes', { count: value });
        }

        if (key.endsWith('Hours')) {
            return t('details.hours', { count: value });
        }

        return value.toLocaleString();
    }

    if (Array.isArray(value)) {
        return value.length === 0
            ? '—'
            : value
                  .map((item) =>
                      typeof item === 'object' ? JSON.stringify(item) : item,
                  )
                  .join(', ');
    }

    return typeof value === 'string' ? value : JSON.stringify(value);
}

/**
 * The structured `details` of a finding as a definition list. Every key of every check id is
 * labelled (`details.<key>`, else the key in words) and formatted by its naming, so a detail
 * core adds later still reads well. A list of rows (`largestTables`) becomes a small table.
 */
function FindingDetails({ details }: { details: Record<string, unknown> }) {
    const { t } = useTranslation();
    const entries = Object.entries(details);

    if (entries.length === 0) {
        return null;
    }

    return (
        <dl className="grid grid-cols-[minmax(8rem,auto)_1fr] gap-x-4 gap-y-1.5 text-sm">
            {entries.map(([key, value]) => {
                const label = t(`details.${key}`, {
                    defaultValue: humanize(key),
                });
                const rows =
                    Array.isArray(value) &&
                    value.length > 0 &&
                    value.every(
                        (item) => typeof item === 'object' && item !== null,
                    )
                        ? (value as Record<string, unknown>[])
                        : null;

                return (
                    <Fragment key={key}>
                        <dt className="text-muted-foreground">{label}</dt>
                        <dd className="min-w-0 font-mono text-xs break-words">
                            {rows ? (
                                <ul className="flex flex-col gap-0.5">
                                    {rows.map((row, index) => (
                                        <li key={index}>
                                            {Object.entries(row)
                                                .map(([rowKey, rowValue]) =>
                                                    formatValue(
                                                        t,
                                                        rowKey,
                                                        rowValue,
                                                    ),
                                                )
                                                .join(' · ')}
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                formatValue(t, key, value)
                            )}
                        </dd>
                    </Fragment>
                );
            })}
        </dl>
    );
}

/**
 * One finding in the right drawer (as the exceptions drawer): what the check looks at with a
 * docs link, the message and evidence, the structured details, the fix hint, the lifecycle
 * across reports (#18) when given, and the ready fix. Render inside a `Drawer`.
 */
export function FindingDrawer({
    finding,
    fix,
    lifecycle,
    projectId,
    canDismiss = false,
    assignees = [],
}: {
    finding: IssueRow;
    fix: FindingFix | undefined;
    lifecycle?: FindingLifecycle;
    projectId?: number;
    canDismiss?: boolean;
    /** Who the finding may be assigned to (#254). */
    assignees?: AssigneeOption[];
}) {
    const { t } = useTranslation();

    return (
        <DrawerContent className="data-[vaul-drawer-direction=right]:max-w-2xl">
            <DrawerHeader>
                <DrawerTitle>
                    {checkLabel(t, finding.checkId, finding.checkName)}
                </DrawerTitle>
                <DrawerDescription className="font-mono text-xs break-all">
                    {finding.subject}
                    {finding.advisoryId ? ` · ${finding.advisoryId}` : ''}
                </DrawerDescription>
            </DrawerHeader>
            <DrawerBody className="gap-4">
                <div className="flex flex-wrap items-center gap-1.5">
                    <SeverityBadge severity={finding.severity} />
                    <Badge variant={CHECK_STATUS_VARIANTS[finding.status]}>
                        {t(`report.status.${finding.status}`)}
                    </Badge>
                    <Badge variant="outline">
                        {t(`checks.category.${finding.category}`)}
                    </Badge>
                    <span className="font-mono text-xs text-muted-foreground">
                        {finding.checkId}
                    </span>
                </div>
                <section className="flex flex-col gap-1">
                    <p className="text-sm text-muted-foreground">
                        {checkDescription(t, finding.checkId)}
                    </p>
                    <a
                        href={CHECKS_DOCS_URL}
                        target="_blank"
                        rel="noreferrer noopener"
                        className="inline-flex items-center gap-1 text-sm text-primary underline-offset-4 hover:underline"
                    >
                        <BookOpen className="size-3.5" aria-hidden />
                        {t('checks.docs')}
                    </a>
                </section>
                <section className="flex flex-col gap-1">
                    <h3 className="text-sm font-medium">
                        {t('report.message')}
                    </h3>
                    <p className="text-sm">
                        {finding.message}
                        {finding.evidenceUrl ? (
                            <a
                                href={finding.evidenceUrl}
                                target="_blank"
                                rel="noreferrer noopener"
                                className="ml-1 inline-flex items-center gap-0.5 text-primary underline underline-offset-4"
                            >
                                {t('report.evidence')}
                                <ExternalLink className="size-3" aria-hidden />
                            </a>
                        ) : null}
                    </p>
                </section>
                {finding.details && Object.keys(finding.details).length > 0 ? (
                    <section className="flex flex-col gap-2">
                        <h3 className="text-sm font-medium">
                            {t('details.title')}
                        </h3>
                        <FindingDetails details={finding.details} />
                    </section>
                ) : null}
                {finding.fixHint ? (
                    <section className="flex flex-col gap-1">
                        <h3 className="text-sm font-medium">
                            {t('report.fixHint')}
                        </h3>
                        <p className="rounded-md bg-muted p-3 font-mono text-xs break-words whitespace-pre-wrap">
                            {finding.fixHint}
                        </p>
                    </section>
                ) : null}
                {lifecycle !== undefined && projectId !== undefined ? (
                    <FindingLifecycleSection
                        finding={finding}
                        lifecycle={lifecycle}
                        projectId={projectId}
                        canDismiss={canDismiss}
                        assignees={assignees}
                    />
                ) : null}
            </DrawerBody>
            {fix ? (
                <DrawerFooter className="flex flex-row flex-wrap justify-end gap-2">
                    <FixActions fix={fix} />
                </DrawerFooter>
            ) : null}
        </DrawerContent>
    );
}
