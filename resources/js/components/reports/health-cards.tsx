import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import {
    CHECK_STATUS_VARIANTS,
    CHECKS_DOCS_URL,
} from '@/components/reports/check-details';
import { TableCellDetails } from '@/components/table-cell-details';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardAction,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import projectsRoutes from '@/routes/projects';
import type { CheckStatus, ReportHealth } from '@/types';

/** Rows of the license inventory shown on the overview; the Licenses tab lists them all. */
const OVERVIEW_LICENSES = 5;

/** Status of one check of the report, or null when it is not in the report. */
function statusOf(health: ReportHealth, checkId: string): CheckStatus | null {
    for (const group of health.groups) {
        const check = group.checks.find((item) => item.checkId === checkId);

        if (check) {
            return check.status;
        }
    }

    return null;
}

/** A check that ran: its status is one the badge and the bar can show. */
type RanCheckStatus = Exclude<CheckStatus, 'skipped'>;

/**
 * Colour of a progress bar backed by a check (#289): the bar and its track take the status
 * colour of the badge next to it, so the colour and the badge always tell the same thing.
 */
const STATUS_PROGRESS_CLASSES = {
    pass: 'bg-success/20 [&_[data-slot=progress-indicator]]:bg-success',
    warn: 'bg-warning/20 [&_[data-slot=progress-indicator]]:bg-warning',
    fail: 'bg-destructive/20 [&_[data-slot=progress-indicator]]:bg-destructive',
} as const satisfies Record<RanCheckStatus, string>;

/** One infrastructure number of the card: the check behind it, its label and its value. */
type InfrastructureMetric = {
    checkId: string;
    label: string;
    /** Null when the report has no number for it. */
    value: ReactNode | null;
};

/** A label, its value and the check status that backs it, on at most two lines. */
function MetricRow({
    label,
    status,
    children,
}: {
    label: string;
    status: RanCheckStatus;
    children: ReactNode;
}) {
    const { t } = useTranslation();

    return (
        <TableRow>
            <TableCell className="font-medium">{label}</TableCell>
            <TableCell className="max-w-0 min-w-40 whitespace-normal">
                {children}
            </TableCell>
            <TableCell className="text-right">
                <Badge variant={CHECK_STATUS_VARIANTS[status]}>
                    {t(`report.status.${status}`)}
                </Badge>
            </TableCell>
        </TableRow>
    );
}

/**
 * Infrastructure numbers of the latest report (#109, compacted in #289): disk use, database size,
 * open connections, queue backlog, Redis memory and backups, each with its check status. Every
 * row stays within two lines: lists (largest tables, several Redis connections or backup disks)
 * open in the `table-cell-details` popover, and a bar takes the colour of its check status.
 * A check that passed without numbers reads "within limits"; checks that were skipped or are not
 * in the report share one muted line with a link to the docs that turn them on.
 */
export function InfrastructureCard({
    health,
    className,
}: {
    health: ReportHealth;
    className?: string;
}) {
    const { t } = useTranslation();
    const infrastructure = health.infrastructure;
    const database = infrastructure.database;
    const diskStatus = statusOf(health, 'laravel-disk-space');

    const redisLabels = infrastructure.redis.map((redis) =>
        t('health.infrastructure.redisValue', {
            connection: redis.connection,
            used: redis.usedMb.toLocaleString(),
            max: redis.maxMb === null ? '∞' : redis.maxMb.toLocaleString(),
        }),
    );
    const backupLabels = infrastructure.backups.map((backup) =>
        t('health.infrastructure.backupValue', {
            disk: backup.disk,
            age:
                backup.ageHours === null
                    ? '—'
                    : t('details.hours', { count: backup.ageHours }),
            count: backup.count ?? 0,
        }),
    );

    const metrics: InfrastructureMetric[] = [
        {
            checkId: 'laravel-disk-space',
            label: t('health.infrastructure.disk'),
            value:
                infrastructure.diskUsedPercent === null ? null : (
                    <div className="flex items-center gap-2">
                        <Progress
                            value={infrastructure.diskUsedPercent}
                            className={cn(
                                'max-w-40',
                                diskStatus !== null && diskStatus !== 'skipped'
                                    ? STATUS_PROGRESS_CLASSES[diskStatus]
                                    : undefined,
                            )}
                            aria-label={t('health.infrastructure.disk')}
                        />
                        <span className="tabular-nums">
                            {t('details.percent', {
                                value: infrastructure.diskUsedPercent,
                            })}
                        </span>
                    </div>
                ),
        },
        {
            checkId: 'laravel-database-size',
            label: t('health.infrastructure.databaseSize'),
            value:
                database === null ? null : (
                    <TableCellDetails
                        variant="bar"
                        summary={
                            <span className="tabular-nums">
                                {t('health.infrastructure.databaseValue', {
                                    size: t('details.megabytes', {
                                        value: database.sizeMb.toLocaleString(),
                                    }),
                                    connection: database.connection,
                                })}
                            </span>
                        }
                        title={t('health.infrastructure.largestTables')}
                        triggerLabel={t(
                            'health.infrastructure.showLargestTables',
                            { count: database.largestTables.length },
                        )}
                        items={database.largestTables.map((table) => ({
                            id: table.table,
                            label: table.table,
                            value: table.sizeMb,
                            valueLabel: t('details.megabytes', {
                                value: table.sizeMb.toLocaleString(),
                            }),
                        }))}
                    />
                ),
        },
        {
            checkId: 'laravel-database-connections',
            label: t('health.infrastructure.connections'),
            value:
                infrastructure.databaseConnections === null
                    ? null
                    : t('health.infrastructure.connectionsValue', {
                          count: infrastructure.databaseConnections,
                      }),
        },
        {
            checkId: 'laravel-queue-backlog',
            label: t('health.infrastructure.queueBacklog'),
            value:
                infrastructure.queueBacklog === null
                    ? null
                    : t('health.infrastructure.backlogValue', {
                          count: infrastructure.queueBacklog.pendingCount,
                          queues: infrastructure.queueBacklog.queues,
                          minutes:
                              infrastructure.queueBacklog.oldestAgeMinutes ??
                              '—',
                      }),
        },
        {
            checkId: 'laravel-redis-memory',
            label: t('health.infrastructure.redis'),
            value: listValue(
                redisLabels,
                t('health.infrastructure.redis'),
                t('health.infrastructure.showRedis', {
                    count: redisLabels.length,
                }),
            ),
        },
        {
            checkId: 'laravel-backups',
            label: t('health.infrastructure.backups'),
            value: listValue(
                backupLabels,
                t('health.infrastructure.backups'),
                t('health.infrastructure.showBackups', {
                    count: backupLabels.length,
                }),
            ),
        },
    ];

    const ranMetrics = metrics.flatMap((metric) => {
        const status = statusOf(health, metric.checkId);

        return status === null || status === 'skipped'
            ? []
            : [{ ...metric, status }];
    });
    const notConfiguredLabels = metrics
        .filter((metric) => {
            const status = statusOf(health, metric.checkId);

            return status === null || status === 'skipped';
        })
        .map((metric) => metric.label);

    return (
        <Card className={className}>
            <CardHeader className="pb-3">
                <CardTitle>{t('health.infrastructure.title')}</CardTitle>
                <CardDescription>
                    {t('health.infrastructure.description')}
                </CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col gap-3">
                {ranMetrics.length > 0 ? (
                    <Table>
                        <TableBody>
                            {ranMetrics.map((metric) => (
                                <MetricRow
                                    key={metric.checkId}
                                    label={metric.label}
                                    status={metric.status}
                                >
                                    {metric.value ??
                                        (metric.status === 'pass'
                                            ? t('health.withinLimits')
                                            : '—')}
                                </MetricRow>
                            ))}
                        </TableBody>
                    </Table>
                ) : null}
                {notConfiguredLabels.length > 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('health.infrastructure.notConfigured', {
                            checks: notConfiguredLabels.join(', '),
                        })}{' '}
                        <a
                            href={CHECKS_DOCS_URL}
                            target="_blank"
                            rel="noreferrer noopener"
                            className="text-primary underline-offset-4 hover:underline"
                        >
                            {t('health.infrastructure.notConfiguredDocs')}
                        </a>
                    </p>
                ) : null}
            </CardContent>
        </Card>
    );
}

/**
 * The value of a check that reports one line per connection or disk: the line itself when there
 * is one, the first line and "+N" with the full list in the `table-cell-details` popover when
 * there are more, null when there are none.
 */
function listValue(
    labels: string[],
    title: string,
    triggerLabel: string,
): ReactNode | null {
    if (labels.length === 0) {
        return null;
    }

    if (labels.length === 1) {
        return labels[0];
    }

    return (
        <TableCellDetails
            title={title}
            triggerLabel={triggerLabel}
            items={labels.map((label) => ({ id: label, label }))}
        />
    );
}

/**
 * Dependencies of the latest report (#108, #109): outdated direct dependencies per ecosystem by
 * patch, minor and major, and the license inventory with the flagged packages.
 */
export function DependenciesCard({
    slug,
    health,
    className,
}: {
    slug: string;
    health: ReportHealth;
    className?: string;
}) {
    const { t } = useTranslation();
    const licenses = health.licenses;
    const flaggedCount = (kind: string) =>
        licenses?.flagged.filter((item) => item.kind === kind).length ?? 0;

    return (
        <Card className={className}>
            <CardHeader className="pb-3">
                <CardTitle>{t('health.dependencies.title')}</CardTitle>
                <CardDescription>
                    {t('health.dependencies.description')}
                </CardDescription>
                {licenses ? (
                    <CardAction>
                        <Button variant="outline" size="sm" asChild>
                            <Link
                                href={projectsRoutes.view.url({
                                    project: slug,
                                    view: 'licenses',
                                })}
                            >
                                {t('health.dependencies.allLicenses')}
                            </Link>
                        </Button>
                    </CardAction>
                ) : null}
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>
                                {t('health.dependencies.outdated')}
                            </TableHead>
                            <TableHead className="text-right">
                                {t('health.dependencies.patch')}
                            </TableHead>
                            <TableHead className="text-right">
                                {t('health.dependencies.minor')}
                            </TableHead>
                            <TableHead className="text-right">
                                {t('health.dependencies.major')}
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {(['composer', 'npm'] as const).map((ecosystem) => {
                            const counts = health.outdated[ecosystem];

                            return (
                                <TableRow key={ecosystem}>
                                    <TableCell className="font-medium">
                                        {t(`health.ecosystem.${ecosystem}`)}
                                    </TableCell>
                                    {counts === null ? (
                                        <TableCell
                                            colSpan={3}
                                            className="text-right text-sm text-muted-foreground"
                                        >
                                            {t('health.notChecked')}
                                        </TableCell>
                                    ) : (
                                        (
                                            ['patch', 'minor', 'major'] as const
                                        ).map((level) => (
                                            <TableCell
                                                key={level}
                                                className="text-right tabular-nums"
                                            >
                                                {counts[level]}
                                            </TableCell>
                                        ))
                                    )}
                                </TableRow>
                            );
                        })}
                    </TableBody>
                </Table>
                {licenses === null ? (
                    <p className="text-sm text-muted-foreground">
                        {t('licenses.unavailable')}
                    </p>
                ) : (
                    <div className="flex flex-col gap-2">
                        <div className="flex flex-wrap items-center gap-1.5 text-sm">
                            <span className="font-medium">
                                {t('licenses.packages', {
                                    count: licenses.packageCount,
                                })}
                            </span>
                            {(['denied', 'copyleft', 'unknown'] as const).map(
                                (kind) =>
                                    flaggedCount(kind) > 0 ? (
                                        <Badge
                                            key={kind}
                                            variant={
                                                kind === 'denied'
                                                    ? 'destructive'
                                                    : 'warning'
                                            }
                                        >
                                            {t(`licenses.kindCount.${kind}`, {
                                                count: flaggedCount(kind),
                                            })}
                                        </Badge>
                                    ) : null,
                            )}
                        </div>
                        <ul className="flex flex-wrap gap-1.5">
                            {licenses.byLicense
                                .slice(0, OVERVIEW_LICENSES)
                                .map((row) => (
                                    <li key={row.license}>
                                        <Badge variant="outline">
                                            <span className="font-mono">
                                                {row.license}
                                            </span>
                                            <span className="tabular-nums">
                                                {row.total}
                                            </span>
                                        </Badge>
                                    </li>
                                ))}
                        </ul>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
