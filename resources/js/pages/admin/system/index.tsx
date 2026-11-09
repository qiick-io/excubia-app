import type { ColumnDef } from '@tanstack/react-table';
import { Head, Link } from '@inertiajs/react';
import { ExternalLink } from 'lucide-react';
import type { ReactNode } from 'react';
import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { DataTable } from '@/components/admin/data-table';
import { PageLayout } from '@/components/layout/page-layout';
import { Badge } from '@/components/ui/badge';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { edit as editProfile } from '@/routes/profile';
import projectsRoutes from '@/routes/projects';
import systemRoutes from '@/routes/system';
import type { BreadcrumbItem } from '@/types';

type HealthStatus = 'ok' | 'warning' | 'error' | 'unknown';

/** Every item of `SystemHealthService::snapshot()` carries its status and fix-hint anchor. */
type HealthItem = { status: HealthStatus; hint: string };

type SystemHealthProps = {
    docsUrl: string;
    scheduler: HealthItem & { lastRunAt: string | null };
    queue: HealthItem & {
        connection: string;
        lastRunAt: string | null;
        pending: number | null;
        failed: number;
    };
    pushes: HealthItem & {
        staleAfterHours: number;
        projects: {
            name: string;
            slug: string;
            reportAt: string | null;
            isReportStale: boolean;
            runtimeAt: string | null;
            isRuntimeStale: boolean;
            exceptionsAt: string | null;
        }[];
    };
    rejections: HealthItem & {
        total: number;
        rows: {
            endpoint: string;
            status: number;
            reason: string;
            count: number;
        }[];
    };
    skippedRuntime: HealthItem & {
        projects: { name: string; slug: string; count: number }[];
    };
    alertFailures: HealthItem & {
        total: number;
        latest: {
            project: string;
            event: string;
            channels: string[];
            at: string;
        }[];
    };
    rollups: HealthItem & { isBackfillPending: boolean };
    exampleAdmin: HealthItem & { exists: boolean; hasDefaultPassword: boolean };
    version: HealthItem & {
        app: string;
        core: string | null;
        coreConstraint: string;
        protocolVersions: number[];
    };
};

const STATUS_VARIANTS = {
    ok: 'success',
    warning: 'warning',
    error: 'destructive',
    unknown: 'muted',
} as const;

function formatTime(value: string | null, never: string): string {
    return value === null ? never : new Date(value).toLocaleString();
}

/**
 * One health item: title, status badge and the link to its fix hint in `docs/self-hosting.md`.
 */
function HealthSection({
    id,
    title,
    item,
    docsUrl,
    children,
}: {
    id: string;
    title: string;
    item: HealthItem;
    docsUrl: string;
    children: ReactNode;
}) {
    const { t } = useTranslation();

    return (
        <section aria-labelledby={id} className="space-y-3">
            <div className="flex flex-wrap items-center gap-2">
                <h2 id={id} className="text-lg font-medium">
                    {title}
                </h2>
                <Badge variant={STATUS_VARIANTS[item.status]}>
                    {t(`system.status.${item.status}`)}
                </Badge>
                <a
                    href={`${docsUrl}#${item.hint}`}
                    target="_blank"
                    rel="noreferrer noopener"
                    className="ml-auto inline-flex items-center gap-0.5 text-sm text-primary underline underline-offset-4"
                >
                    {t('system.fixHint', { item: title })}
                    <ExternalLink className="size-3" aria-hidden />
                </a>
            </div>
            {children}
        </section>
    );
}

/**
 * `/settings/system` (#117): the health of this Excubia install (scheduler, queue worker,
 * pushes, rejections, skipped runtime types, alert delivery, rollups, example admin account,
 * version). Behind
 * `can-show-system`; read-only.
 */
export default function SystemIndex(props: SystemHealthProps) {
    const {
        docsUrl,
        scheduler,
        queue,
        pushes,
        rejections,
        skippedRuntime,
        alertFailures,
        rollups,
        exampleAdmin,
        version,
    } = props;
    const { t } = useTranslation();
    const never = t('system.never');

    const breadcrumbs: BreadcrumbItem[] = useMemo(
        () => [
            { title: t('settings.layout.title'), href: editProfile() },
            {
                title: t('settings.layout.system'),
                href: systemRoutes.index.url(),
            },
        ],
        [t],
    );

    const staleCell = (value: string | null, isStale: boolean) => (
        <>
            {formatTime(value, never)}
            {isStale ? (
                <Badge variant="warning" className="ml-2">
                    {t('system.pushes.stale')}
                </Badge>
            ) : null}
        </>
    );

    const pushColumns: ColumnDef<(typeof pushes.projects)[number]>[] = [
        {
            id: 'project',
            header: t('system.columns.project'),
            cell: ({ row }) => (
                <Link
                    href={projectsRoutes.show.url(row.original.slug)}
                    className="underline underline-offset-4"
                >
                    {row.original.name}
                </Link>
            ),
        },
        {
            id: 'reports',
            header: t('system.pushes.reports'),
            meta: { cellClassName: 'whitespace-nowrap tabular-nums' },
            cell: ({ row }) =>
                staleCell(row.original.reportAt, row.original.isReportStale),
        },
        {
            id: 'runtime',
            header: t('system.pushes.runtime'),
            meta: { cellClassName: 'whitespace-nowrap tabular-nums' },
            cell: ({ row }) =>
                staleCell(row.original.runtimeAt, row.original.isRuntimeStale),
        },
        {
            id: 'exceptions',
            header: t('system.pushes.exceptions'),
            meta: { cellClassName: 'whitespace-nowrap tabular-nums' },
            cell: ({ row }) => staleCell(row.original.exceptionsAt, false),
        },
    ];

    const failureColumns: ColumnDef<(typeof alertFailures.latest)[number]>[] = [
        {
            id: 'project',
            header: t('system.columns.project'),
            cell: ({ row }) => {
                const failure = row.original;

                return <>{failure.project}</>;
            },
        },
        {
            id: 'event',
            header: t('system.alertFailures.event'),
            meta: { cellClassName: 'font-mono text-xs' },
            cell: ({ row }) => {
                const failure = row.original;

                return <>{failure.event}</>;
            },
        },
        {
            id: 'channels',
            header: t('system.alertFailures.channels'),
            cell: ({ row }) => {
                const failure = row.original;

                return <>{failure.channels.join(', ')}</>;
            },
        },
        {
            id: 'at',
            header: t('system.alertFailures.at'),
            meta: {
                headerClassName: 'text-right',
                cellClassName: 'text-right whitespace-nowrap tabular-nums',
            },
            cell: ({ row }) => {
                const failure = row.original;

                return <>{formatTime(failure.at, never)}</>;
            },
        },
    ];

    const rejectionColumns: ColumnDef<(typeof rejections.rows)[number]>[] = [
        {
            id: 'endpoint',
            header: t('system.rejections.endpoint'),
            meta: { cellClassName: 'font-mono text-xs' },
            cell: ({ row: tableRow }) => {
                const row = tableRow.original;

                return <>/api/{row.endpoint}</>;
            },
        },
        {
            id: 'status',
            header: t('system.rejections.status'),
            meta: {
                headerClassName: 'text-right',
                cellClassName: 'text-right tabular-nums',
            },
            cell: ({ row: tableRow }) => {
                const row = tableRow.original;

                return <>{row.status}</>;
            },
        },
        {
            id: 'reason',
            header: t('system.rejections.reason'),
            meta: { cellClassName: 'font-mono text-xs' },
            cell: ({ row: tableRow }) => {
                const row = tableRow.original;

                return <>{row.reason}</>;
            },
        },
        {
            id: 'count',
            header: t('system.columns.count'),
            meta: {
                headerClassName: 'text-right',
                cellClassName: 'text-right tabular-nums',
            },
            cell: ({ row: tableRow }) => {
                const row = tableRow.original;

                return <>{row.count}</>;
            },
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('settings.layout.system')} />
            <h1 className="sr-only">{t('settings.layout.system')}</h1>
            <SettingsLayout wide>
                <PageLayout description={t('system.description')}>
                    <div className="space-y-10">
                        <HealthSection
                            id="system-scheduler"
                            title={t('system.scheduler.title')}
                            item={scheduler}
                            docsUrl={docsUrl}
                        >
                            <p className="text-sm text-muted-foreground">
                                {t('system.scheduler.lastRun', {
                                    time: formatTime(
                                        scheduler.lastRunAt,
                                        never,
                                    ),
                                })}
                            </p>
                        </HealthSection>

                        <HealthSection
                            id="system-queue"
                            title={t('system.queue.title')}
                            item={queue}
                            docsUrl={docsUrl}
                        >
                            <dl className="grid gap-3 text-sm sm:grid-cols-4">
                                <div className="grid gap-1">
                                    <dt className="text-muted-foreground">
                                        {t('system.queue.connection')}
                                    </dt>
                                    <dd className="font-mono">
                                        {queue.connection}
                                    </dd>
                                </div>
                                <div className="grid gap-1">
                                    <dt className="text-muted-foreground">
                                        {t('system.queue.lastRun')}
                                    </dt>
                                    <dd className="tabular-nums">
                                        {formatTime(queue.lastRunAt, never)}
                                    </dd>
                                </div>
                                <div className="grid gap-1">
                                    <dt className="text-muted-foreground">
                                        {t('system.queue.pending')}
                                    </dt>
                                    <dd className="tabular-nums">
                                        {queue.pending ?? '—'}
                                    </dd>
                                </div>
                                <div className="grid gap-1">
                                    <dt className="text-muted-foreground">
                                        {t('system.queue.failed')}
                                    </dt>
                                    <dd className="tabular-nums">
                                        {queue.failed}
                                    </dd>
                                </div>
                            </dl>
                        </HealthSection>

                        <HealthSection
                            id="system-pushes"
                            title={t('system.pushes.title')}
                            item={pushes}
                            docsUrl={docsUrl}
                        >
                            <p className="text-sm text-muted-foreground">
                                {t('system.pushes.description', {
                                    hours: pushes.staleAfterHours,
                                })}
                            </p>
                            <DataTable
                                columns={pushColumns}
                                data={pushes.projects}
                                getRowId={(project) => project.slug}
                                emptyState={
                                    <span className="text-muted-foreground">
                                        {t('system.pushes.empty')}
                                    </span>
                                }
                            />
                        </HealthSection>

                        <HealthSection
                            id="system-rejections"
                            title={t('system.rejections.title')}
                            item={rejections}
                            docsUrl={docsUrl}
                        >
                            <DataTable
                                columns={rejectionColumns}
                                data={rejections.rows}
                                getRowId={(row) =>
                                    String(
                                        `${row.endpoint}-${row.status}-${row.reason}`,
                                    )
                                }
                                emptyState={
                                    <span className="text-muted-foreground">
                                        {t('system.rejections.empty')}
                                    </span>
                                }
                            />
                        </HealthSection>

                        <HealthSection
                            id="system-skipped-runtime"
                            title={t('system.skippedRuntime.title')}
                            item={skippedRuntime}
                            docsUrl={docsUrl}
                        >
                            {skippedRuntime.projects.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    {t('system.skippedRuntime.empty')}
                                </p>
                            ) : (
                                <ul className="space-y-1 text-sm">
                                    {skippedRuntime.projects.map((project) => (
                                        <li key={project.slug}>
                                            {t('system.skippedRuntime.row', {
                                                project: project.name,
                                                count: project.count,
                                            })}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </HealthSection>

                        <HealthSection
                            id="system-alert-failures"
                            title={t('system.alertFailures.title')}
                            item={alertFailures}
                            docsUrl={docsUrl}
                        >
                            {alertFailures.latest.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    {t('system.alertFailures.empty')}
                                </p>
                            ) : (
                                <>
                                    <p className="text-sm text-muted-foreground">
                                        {t('system.alertFailures.total', {
                                            count: alertFailures.total,
                                        })}
                                    </p>
                                    <DataTable
                                        columns={failureColumns}
                                        data={alertFailures.latest}
                                        getRowId={(failure) =>
                                            `${failure.at}-${failure.project}-${failure.event}`
                                        }
                                    />
                                </>
                            )}
                        </HealthSection>

                        <HealthSection
                            id="system-rollups"
                            title={t('system.rollups.title')}
                            item={rollups}
                            docsUrl={docsUrl}
                        >
                            <p className="text-sm text-muted-foreground">
                                {rollups.isBackfillPending
                                    ? t('system.rollups.pending')
                                    : t('system.rollups.done')}
                            </p>
                        </HealthSection>

                        <HealthSection
                            id="system-example-admin"
                            title={t('system.exampleAdmin.title')}
                            item={exampleAdmin}
                            docsUrl={docsUrl}
                        >
                            <p className="text-sm text-muted-foreground">
                                {exampleAdmin.hasDefaultPassword
                                    ? t('system.exampleAdmin.defaultPassword')
                                    : exampleAdmin.exists
                                      ? t('system.exampleAdmin.exists')
                                      : t('system.exampleAdmin.none')}
                            </p>
                        </HealthSection>

                        <HealthSection
                            id="system-version"
                            title={t('system.version.title')}
                            item={version}
                            docsUrl={docsUrl}
                        >
                            <dl className="grid gap-3 text-sm sm:grid-cols-4">
                                <div className="grid gap-1">
                                    <dt className="text-muted-foreground">
                                        {t('system.version.app')}
                                    </dt>
                                    <dd className="font-mono">{version.app}</dd>
                                </div>
                                <div className="grid gap-1">
                                    <dt className="text-muted-foreground">
                                        {t('system.version.core')}
                                    </dt>
                                    <dd className="font-mono">
                                        {version.core ?? '—'}
                                    </dd>
                                </div>
                                <div className="grid gap-1">
                                    <dt className="text-muted-foreground">
                                        {t('system.version.accepts')}
                                    </dt>
                                    <dd className="font-mono">
                                        {version.coreConstraint}
                                    </dd>
                                </div>
                                <div className="grid gap-1">
                                    <dt className="text-muted-foreground">
                                        {t('system.version.protocol')}
                                    </dt>
                                    <dd className="font-mono">
                                        {version.protocolVersions.join(', ')}
                                    </dd>
                                </div>
                            </dl>
                        </HealthSection>
                    </div>
                </PageLayout>
            </SettingsLayout>
        </AppLayout>
    );
}
