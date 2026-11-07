import { CalendarClock, CircleAlert } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { PageLayout, TablePanel } from '@/components/layout/page-layout';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import type { ScheduledTaskRow, ScheduledTaskStatus } from '@/types';

const SCHEDULE_GUIDE_URL =
    'https://github.com/qiick-io/excubia-core/blob/main/docs/guides/excubia-app.md#scheduled-tasks';

const STATUS_VARIANTS: Record<
    ScheduledTaskStatus,
    'success' | 'warning' | 'destructive' | 'info' | 'muted'
> = {
    on_time: 'success',
    late: 'warning',
    missed: 'destructive',
    failed: 'destructive',
    scheduler_down: 'destructive',
    skipped: 'info',
    not_expected: 'muted',
    paused: 'muted',
    pending: 'muted',
    unknown: 'muted',
};

function formatTime(value: string | null): string {
    return value === null ? '—' : new Date(value).toLocaleString();
}

/**
 * The project's scheduled tasks (#110), in schedule order: each task with its expression and
 * timezone, its last run, its next due time and its status. A destructive alert on top when the
 * `excubia:heartbeat` task is late or missed, i.e. the whole scheduler is down.
 */
export function ScheduleView({ tasks }: { tasks: ScheduledTaskRow[] }) {
    const { t } = useTranslation();

    if (tasks.length === 0) {
        return (
            <PageLayout>
                <PageEmptyState
                    icon={CalendarClock}
                    title={t('schedule.emptyTitle')}
                    description={t('schedule.emptyDescription')}
                    docsHref={SCHEDULE_GUIDE_URL}
                    docsLabel={t('schedule.guide')}
                />
            </PageLayout>
        );
    }

    const heartbeat = tasks.find((task) => task.is_heartbeat) ?? null;
    const isSchedulerDown =
        heartbeat !== null &&
        (heartbeat.status === 'late' || heartbeat.status === 'missed');

    return (
        <PageLayout description={t('schedule.description')}>
            {isSchedulerDown ? (
                <Alert variant="destructive">
                    <CircleAlert />
                    <AlertTitle>{t('schedule.schedulerDownTitle')}</AlertTitle>
                    <AlertDescription>
                        {t('schedule.schedulerDownDescription', {
                            time: formatTime(heartbeat.last_run_at),
                        })}
                    </AlertDescription>
                </Alert>
            ) : null}
            <TablePanel>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{t('schedule.task')}</TableHead>
                            <TableHead>{t('schedule.lastRun')}</TableHead>
                            <TableHead>{t('schedule.nextDue')}</TableHead>
                            <TableHead>{t('schedule.statusLabel')}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {tasks.map((task) => (
                            <TableRow key={task.id}>
                                <TableCell className="max-w-[32rem]">
                                    <div className="truncate font-medium">
                                        {task.task}
                                    </div>
                                    <div className="flex flex-wrap items-center gap-1 text-xs text-muted-foreground">
                                        <span className="font-mono">
                                            {task.expression}
                                        </span>
                                        <span>· {task.timezone}</span>
                                        {task.environments.map(
                                            (environment) => (
                                                <Badge
                                                    key={environment}
                                                    variant="outline"
                                                >
                                                    {environment}
                                                </Badge>
                                            ),
                                        )}
                                    </div>
                                </TableCell>
                                <TableCell className="text-xs tabular-nums">
                                    <div>{formatTime(task.last_run_at)}</div>
                                    {task.last_outcome !== null ? (
                                        <div className="text-muted-foreground">
                                            {t(
                                                `schedule.outcome.${task.last_outcome}`,
                                            )}
                                        </div>
                                    ) : null}
                                </TableCell>
                                <TableCell className="text-xs tabular-nums">
                                    {formatTime(task.next_due_at)}
                                </TableCell>
                                <TableCell>
                                    <Badge
                                        variant={STATUS_VARIANTS[task.status]}
                                    >
                                        {t(`schedule.status.${task.status}`)}
                                    </Badge>
                                    {task.is_low_confidence ? (
                                        <div className="mt-1 text-xs text-muted-foreground">
                                            {t('schedule.lowConfidence')}
                                        </div>
                                    ) : null}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </TablePanel>
        </PageLayout>
    );
}
