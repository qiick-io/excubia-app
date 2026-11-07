import { Head, Link } from '@inertiajs/react';
import { ExternalLink } from 'lucide-react';
import type { ReactNode } from 'react';
import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { PageLayout, TablePanel } from '@/components/layout/page-layout';
import { Badge } from '@/components/ui/badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
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
 * pushes, rejections, skipped runtime types, alert delivery, rollups, version). Behind
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
        <TableCell className="whitespace-nowrap tabular-nums">
            {formatTime(value, never)}
            {isStale ? (
                <Badge variant="warning" className="ml-2">
                    {t('system.pushes.stale')}
                </Badge>
            ) : null}
        </TableCell>
    );

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
                            <TablePanel>
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>
                                                {t('system.columns.project')}
                                            </TableHead>
                                            <TableHead>
                                                {t('system.pushes.reports')}
                                            </TableHead>
                                            <TableHead>
                                                {t('system.pushes.runtime')}
                                            </TableHead>
                                            <TableHead>
                                                {t('system.pushes.exceptions')}
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {pushes.projects.length === 0 ? (
                                            <TableRow>
                                                <TableCell
                                                    colSpan={4}
                                                    className="text-muted-foreground"
                                                >
                                                    {t('system.pushes.empty')}
                                                </TableCell>
                                            </TableRow>
                                        ) : (
                                            pushes.projects.map((project) => (
                                                <TableRow key={project.slug}>
                                                    <TableCell>
                                                        <Link
                                                            href={projectsRoutes.show.url(
                                                                project.slug,
                                                            )}
                                                            className="underline underline-offset-4"
                                                        >
                                                            {project.name}
                                                        </Link>
                                                    </TableCell>
                                                    {staleCell(
                                                        project.reportAt,
                                                        project.isReportStale,
                                                    )}
                                                    {staleCell(
                                                        project.runtimeAt,
                                                        project.isRuntimeStale,
                                                    )}
                                                    {staleCell(
                                                        project.exceptionsAt,
                                                        false,
                                                    )}
                                                </TableRow>
                                            ))
                                        )}
                                    </TableBody>
                                </Table>
                            </TablePanel>
                        </HealthSection>

                        <HealthSection
                            id="system-rejections"
                            title={t('system.rejections.title')}
                            item={rejections}
                            docsUrl={docsUrl}
                        >
                            <TablePanel>
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>
                                                {t(
                                                    'system.rejections.endpoint',
                                                )}
                                            </TableHead>
                                            <TableHead className="text-right">
                                                {t('system.rejections.status')}
                                            </TableHead>
                                            <TableHead>
                                                {t('system.rejections.reason')}
                                            </TableHead>
                                            <TableHead className="text-right">
                                                {t('system.columns.count')}
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {rejections.rows.length === 0 ? (
                                            <TableRow>
                                                <TableCell
                                                    colSpan={4}
                                                    className="text-muted-foreground"
                                                >
                                                    {t(
                                                        'system.rejections.empty',
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ) : (
                                            rejections.rows.map((row) => (
                                                <TableRow
                                                    key={`${row.endpoint}-${row.status}-${row.reason}`}
                                                >
                                                    <TableCell className="font-mono text-xs">
                                                        /api/{row.endpoint}
                                                    </TableCell>
                                                    <TableCell className="text-right tabular-nums">
                                                        {row.status}
                                                    </TableCell>
                                                    <TableCell className="font-mono text-xs">
                                                        {row.reason}
                                                    </TableCell>
                                                    <TableCell className="text-right tabular-nums">
                                                        {row.count}
                                                    </TableCell>
                                                </TableRow>
                                            ))
                                        )}
                                    </TableBody>
                                </Table>
                            </TablePanel>
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
                                    <TablePanel>
                                        <Table>
                                            <TableHeader>
                                                <TableRow>
                                                    <TableHead>
                                                        {t(
                                                            'system.columns.project',
                                                        )}
                                                    </TableHead>
                                                    <TableHead>
                                                        {t(
                                                            'system.alertFailures.event',
                                                        )}
                                                    </TableHead>
                                                    <TableHead>
                                                        {t(
                                                            'system.alertFailures.channels',
                                                        )}
                                                    </TableHead>
                                                    <TableHead className="text-right">
                                                        {t(
                                                            'system.alertFailures.at',
                                                        )}
                                                    </TableHead>
                                                </TableRow>
                                            </TableHeader>
                                            <TableBody>
                                                {alertFailures.latest.map(
                                                    (failure, index) => (
                                                        <TableRow
                                                            key={`${failure.at}-${index}`}
                                                        >
                                                            <TableCell>
                                                                {
                                                                    failure.project
                                                                }
                                                            </TableCell>
                                                            <TableCell className="font-mono text-xs">
                                                                {failure.event}
                                                            </TableCell>
                                                            <TableCell>
                                                                {failure.channels.join(
                                                                    ', ',
                                                                )}
                                                            </TableCell>
                                                            <TableCell className="text-right whitespace-nowrap tabular-nums">
                                                                {formatTime(
                                                                    failure.at,
                                                                    never,
                                                                )}
                                                            </TableCell>
                                                        </TableRow>
                                                    ),
                                                )}
                                            </TableBody>
                                        </Table>
                                    </TablePanel>
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
