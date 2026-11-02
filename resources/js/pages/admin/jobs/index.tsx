import { Head, router } from '@inertiajs/react';
import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { TruncatedText } from '@/components/admin/truncated-text';
import { PageLayout, TablePanel } from '@/components/layout/page-layout';
import { Button } from '@/components/ui/button';
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
import jobsRoutes from '@/routes/jobs';
import { edit as editProfile } from '@/routes/profile';
import type { BreadcrumbItem } from '@/types';

/** A row of the `jobs` table (`JobMonitorQueryService`). */
type PendingJob = {
    id: number;
    queue: string;
    display_name: string;
    attempts: number;
    available_at: number;
    created_at: number;
};

/** A row of the `failed_jobs` table (`JobMonitorQueryService`). */
type FailedJob = {
    id: number;
    uuid: string;
    queue: string;
    display_name: string;
    exception: string;
    failed_at: string;
};

/**
 * Queue monitor: pending sample + failed jobs with retry/delete.
 * The page itself requires `jobs.manage`, so the actions are always shown.
 */
export default function JobsIndex({
    pending_count,
    pending,
    failed,
}: {
    pending_count: number;
    pending: PendingJob[];
    failed: FailedJob[];
}) {
    const { t } = useTranslation();

    const breadcrumbs: BreadcrumbItem[] = useMemo(
        () => [
            { title: t('settings.layout.title'), href: editProfile() },
            { title: t('settings.layout.jobs'), href: jobsRoutes.index.url() },
        ],
        [t],
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('settings.layout.jobs')} />
            <SettingsLayout wide>
                <PageLayout
                    description={t('jobs.pendingCount', {
                        count: pending_count,
                    })}
                >
                    <div className="mb-8 space-y-3">
                        <h2 className="text-lg font-medium">
                            {t('jobs.pendingTitle')}
                        </h2>
                        <TablePanel>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="text-right">
                                            {t('jobs.columns.id')}
                                        </TableHead>
                                        <TableHead>
                                            {t('jobs.columns.job')}
                                        </TableHead>
                                        <TableHead>
                                            {t('jobs.columns.queue')}
                                        </TableHead>
                                        <TableHead className="text-right">
                                            {t('jobs.columns.attempts')}
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {pending.length === 0 ? (
                                        <TableRow>
                                            <TableCell
                                                colSpan={4}
                                                className="text-muted-foreground"
                                            >
                                                {t('jobs.pendingEmpty')}
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        pending.map((job) => (
                                            <TableRow key={job.id}>
                                                <TableCell className="text-right tabular-nums">
                                                    {job.id}
                                                </TableCell>
                                                <TableCell className="max-w-[14rem] font-mono text-xs">
                                                    <TruncatedText
                                                        text={job.display_name}
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                    {job.queue}
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {job.attempts}
                                                </TableCell>
                                            </TableRow>
                                        ))
                                    )}
                                </TableBody>
                            </Table>
                        </TablePanel>
                    </div>

                    <div className="space-y-3">
                        <h2 className="text-lg font-medium">
                            {t('jobs.failedTitle')}
                        </h2>
                        <TablePanel>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>
                                            {t('jobs.columns.job')}
                                        </TableHead>
                                        <TableHead>
                                            {t('jobs.columns.queue')}
                                        </TableHead>
                                        <TableHead className="text-right">
                                            {t('jobs.columns.failedAt')}
                                        </TableHead>
                                        <TableHead>
                                            {t('jobs.columns.exception')}
                                        </TableHead>
                                        <TableHead className="text-right">
                                            {t('jobs.columns.actions')}
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {failed.length === 0 ? (
                                        <TableRow>
                                            <TableCell
                                                colSpan={5}
                                                className="text-muted-foreground"
                                            >
                                                {t('jobs.failedEmpty')}
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        failed.map((job) => (
                                            <TableRow
                                                key={job.uuid}
                                                className="align-top"
                                            >
                                                <TableCell className="max-w-[14rem] font-mono text-xs">
                                                    <TruncatedText
                                                        text={job.display_name}
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                    {job.queue}
                                                </TableCell>
                                                <TableCell className="text-right whitespace-nowrap tabular-nums">
                                                    {job.failed_at}
                                                </TableCell>
                                                <TableCell className="max-w-md font-mono text-xs text-muted-foreground">
                                                    <TruncatedText
                                                        text={job.exception}
                                                    />
                                                </TableCell>
                                                <TableCell className="space-x-2 text-right whitespace-nowrap">
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() =>
                                                            router.post(
                                                                jobsRoutes.retry.url(
                                                                    job.uuid,
                                                                ),
                                                            )
                                                        }
                                                    >
                                                        {t('jobs.retry')}
                                                    </Button>
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="destructive"
                                                        onClick={() =>
                                                            router.delete(
                                                                jobsRoutes.destroy.url(
                                                                    job.uuid,
                                                                ),
                                                            )
                                                        }
                                                    >
                                                        {t('jobs.delete')}
                                                    </Button>
                                                </TableCell>
                                            </TableRow>
                                        ))
                                    )}
                                </TableBody>
                            </Table>
                        </TablePanel>
                        <p className="text-xs text-muted-foreground">
                            {t('jobs.footnote')}
                        </p>
                    </div>
                </PageLayout>
            </SettingsLayout>
        </AppLayout>
    );
}
