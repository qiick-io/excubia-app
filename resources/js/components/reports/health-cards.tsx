import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { CHECK_STATUS_VARIANTS } from '@/components/reports/check-details';
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

/** A label, its value and the check status that backs it. */
function MetricRow({
    label,
    status,
    children,
}: {
    label: string;
    status: CheckStatus | null;
    children: ReactNode;
}) {
    const { t } = useTranslation();

    return (
        <TableRow>
            <TableCell className="font-medium">{label}</TableCell>
            <TableCell className="whitespace-normal">{children}</TableCell>
            <TableCell className="text-right">
                {status === null ? (
                    <span className="text-sm text-muted-foreground">
                        {t('health.notChecked')}
                    </span>
                ) : (
                    <Badge variant={CHECK_STATUS_VARIANTS[status]}>
                        {t(`report.status.${status}`)}
                    </Badge>
                )}
            </TableCell>
        </TableRow>
    );
}

/**
 * Infrastructure numbers of the latest report (#109): disk use, database size and largest
 * tables, open connections, queue backlog, Redis memory and backups, each with its check status.
 * A check that passed without numbers reads "within limits".
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
    const fallback = (checkId: string) =>
        statusOf(health, checkId) === 'pass' ? t('health.withinLimits') : '—';

    return (
        <Card className={className}>
            <CardHeader className="pb-3">
                <CardTitle>{t('health.infrastructure.title')}</CardTitle>
                <CardDescription>
                    {t('health.infrastructure.description')}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Table>
                    <TableBody>
                        <MetricRow
                            label={t('health.infrastructure.disk')}
                            status={statusOf(health, 'laravel-disk-space')}
                        >
                            {infrastructure.diskUsedPercent === null ? (
                                fallback('laravel-disk-space')
                            ) : (
                                <div className="flex items-center gap-2">
                                    <Progress
                                        value={infrastructure.diskUsedPercent}
                                        className="max-w-40"
                                        aria-label={t(
                                            'health.infrastructure.disk',
                                        )}
                                    />
                                    <span className="tabular-nums">
                                        {t('details.percent', {
                                            value: infrastructure.diskUsedPercent,
                                        })}
                                    </span>
                                </div>
                            )}
                        </MetricRow>
                        <MetricRow
                            label={t('health.infrastructure.databaseSize')}
                            status={statusOf(health, 'laravel-database-size')}
                        >
                            {infrastructure.database === null ? (
                                fallback('laravel-database-size')
                            ) : (
                                <div className="flex flex-col gap-0.5">
                                    <span className="tabular-nums">
                                        {t('details.megabytes', {
                                            value: infrastructure.database.sizeMb.toLocaleString(),
                                        })}{' '}
                                        <span className="text-muted-foreground">
                                            (
                                            {infrastructure.database.connection}
                                            )
                                        </span>
                                    </span>
                                    {infrastructure.database.largestTables.map(
                                        (table) => (
                                            <span
                                                key={table.table}
                                                className="font-mono text-xs text-muted-foreground"
                                            >
                                                {table.table} ·{' '}
                                                {t('details.megabytes', {
                                                    value: table.sizeMb.toLocaleString(),
                                                })}
                                            </span>
                                        ),
                                    )}
                                </div>
                            )}
                        </MetricRow>
                        <MetricRow
                            label={t('health.infrastructure.connections')}
                            status={statusOf(
                                health,
                                'laravel-database-connections',
                            )}
                        >
                            {infrastructure.databaseConnections === null
                                ? fallback('laravel-database-connections')
                                : infrastructure.databaseConnections.toLocaleString()}
                        </MetricRow>
                        <MetricRow
                            label={t('health.infrastructure.queueBacklog')}
                            status={statusOf(health, 'laravel-queue-backlog')}
                        >
                            {infrastructure.queueBacklog === null
                                ? fallback('laravel-queue-backlog')
                                : t('health.infrastructure.backlogValue', {
                                      count: infrastructure.queueBacklog
                                          .pendingCount,
                                      queues: infrastructure.queueBacklog
                                          .queues,
                                      minutes:
                                          infrastructure.queueBacklog
                                              .oldestAgeMinutes ?? '—',
                                  })}
                        </MetricRow>
                        <MetricRow
                            label={t('health.infrastructure.redis')}
                            status={statusOf(health, 'laravel-redis-memory')}
                        >
                            {infrastructure.redis.length === 0
                                ? fallback('laravel-redis-memory')
                                : infrastructure.redis.map((redis) => (
                                      <div key={redis.connection}>
                                          {t(
                                              'health.infrastructure.redisValue',
                                              {
                                                  connection: redis.connection,
                                                  used: redis.usedMb.toLocaleString(),
                                                  max:
                                                      redis.maxMb === null
                                                          ? '∞'
                                                          : redis.maxMb.toLocaleString(),
                                              },
                                          )}
                                      </div>
                                  ))}
                        </MetricRow>
                        <MetricRow
                            label={t('health.infrastructure.backups')}
                            status={statusOf(health, 'laravel-backups')}
                        >
                            {infrastructure.backups.length === 0
                                ? fallback('laravel-backups')
                                : infrastructure.backups.map((backup) => (
                                      <div key={backup.disk}>
                                          {t(
                                              'health.infrastructure.backupValue',
                                              {
                                                  disk: backup.disk,
                                                  age:
                                                      backup.ageHours === null
                                                          ? '—'
                                                          : t('details.hours', {
                                                                count: backup.ageHours,
                                                            }),
                                                  count: backup.count ?? 0,
                                              },
                                          )}
                                      </div>
                                  ))}
                        </MetricRow>
                    </TableBody>
                </Table>
            </CardContent>
        </Card>
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
