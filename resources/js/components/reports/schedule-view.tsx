import type { ColumnDef } from '@tanstack/react-table';
import { CalendarClock, CircleAlert } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { DataTable } from '@/components/admin/data-table';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { PageLayout } from '@/components/layout/page-layout';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { StackedBarChart } from '@/components/reports/severity-chart';
import {
    bucketLabel,
    deployMarkers,
    TimeRangePicker,
} from '@/components/reports/time-range-picker';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type {
    RuntimeDeploy,
    ScheduledTaskRow,
    ScheduledTaskStatus,
    ScheduleTimelinePoint,
    TimeRange,
} from '@/types';

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
 * timezone, its runs in the time range (#113), its last run, its next due time and its status,
 * under a chart of all runs per outcome over the range. A destructive alert on top when the
 * `excubia:heartbeat` task is late or missed, i.e. the whole scheduler is down.
 */
export function ScheduleView({
    slug,
    tasks,
    timeline,
    range,
    deploys,
}: {
    slug: string;
    tasks: ScheduledTaskRow[];
    timeline: ScheduleTimelinePoint[];
    range: TimeRange;
    deploys: RuntimeDeploy[];
}) {
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
    const phrases = {
        bucket: t(`timeRange.bucket.${range}`),
        during: t(`timeRange.during.${range}`),
    };
    const totals = { finished: 0, failed: 0, skipped: 0 };

    for (const point of timeline) {
        totals.finished += point.finished;
        totals.failed += point.failed;
        totals.skipped += point.skipped;
    }

    const taskColumns: ColumnDef<ScheduledTaskRow>[] = [
        {
            id: 'task',
            header: t('schedule.task'),
            meta: { cellClassName: 'max-w-[32rem]' },
            cell: ({ row }) => {
                const task = row.original;

                return (
                    <>
                        <div className="truncate font-medium">{task.task}</div>
                        <div className="flex flex-wrap items-center gap-1 text-xs text-muted-foreground">
                            <span className="font-mono">{task.expression}</span>
                            <span>· {task.timezone}</span>
                            {task.environments.map((environment) => (
                                <Badge key={environment} variant="outline">
                                    {environment}
                                </Badge>
                            ))}
                        </div>
                    </>
                );
            },
        },
        {
            id: 'runs',
            header: t('schedule.runs'),
            meta: { cellClassName: 'text-xs tabular-nums' },
            cell: ({ row }) => {
                const task = row.original;

                return <>{t('schedule.runsSummary', task.runs)}</>;
            },
        },
        {
            id: 'lastRun',
            header: t('schedule.lastRun'),
            meta: { cellClassName: 'text-xs tabular-nums' },
            cell: ({ row }) => {
                const task = row.original;

                return (
                    <>
                        <div>{formatTime(task.last_run_at)}</div>
                        {task.last_outcome !== null ? (
                            <div className="text-muted-foreground">
                                {t(`schedule.outcome.${task.last_outcome}`)}
                            </div>
                        ) : null}
                    </>
                );
            },
        },
        {
            id: 'nextDue',
            header: t('schedule.nextDue'),
            meta: { cellClassName: 'text-xs tabular-nums' },
            cell: ({ row }) => {
                const task = row.original;

                return <>{formatTime(task.next_due_at)}</>;
            },
        },
        {
            id: 'statusLabel',
            header: t('schedule.statusLabel'),
            cell: ({ row }) => {
                const task = row.original;

                return (
                    <>
                        <Badge variant={STATUS_VARIANTS[task.status]}>
                            {t(`schedule.status.${task.status}`)}
                        </Badge>
                        {task.is_low_confidence ? (
                            <div className="mt-1 text-xs text-muted-foreground">
                                {t('schedule.lowConfidence')}
                            </div>
                        ) : null}
                    </>
                );
            },
        },
    ];

    return (
        <PageLayout
            description={t('schedule.description')}
            filtersRight={
                <TimeRangePicker slug={slug} view="schedule" range={range} />
            }
        >
            {/* PageLayout stacks its children without a gap. */}
            <div className="flex flex-col gap-4">
                {isSchedulerDown ? (
                    <Alert variant="destructive">
                        <CircleAlert />
                        <AlertTitle>
                            {t('schedule.schedulerDownTitle')}
                        </AlertTitle>
                        <AlertDescription>
                            {t('schedule.schedulerDownDescription', {
                                time: formatTime(heartbeat.last_run_at),
                            })}
                        </AlertDescription>
                    </Alert>
                ) : null}
                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle>{t('schedule.runsOverTime')}</CardTitle>
                        <CardDescription>
                            {t('schedule.runsOverTimeDescription', phrases)}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="h-48">
                        <StackedBarChart
                            data={timeline.map((point) => ({
                                label: point.at,
                                finished: point.finished,
                                failed: point.failed,
                                skipped: point.skipped,
                            }))}
                            formatLabel={bucketLabel(range)}
                            markers={deployMarkers(
                                deploys,
                                t('runtime.deploy'),
                            )}
                            series={[
                                {
                                    key: 'finished',
                                    label: t('schedule.outcome.finished'),
                                    color: 'var(--runtime-chart-ok)',
                                },
                                {
                                    key: 'failed',
                                    label: t('schedule.outcome.failed'),
                                    color: 'var(--runtime-chart-error)',
                                },
                                {
                                    key: 'skipped',
                                    label: t('schedule.outcome.skipped'),
                                    color: 'var(--color-muted-foreground)',
                                },
                            ]}
                            label={t('schedule.runsChartLabel', {
                                ...totals,
                                ...phrases,
                            })}
                        />
                    </CardContent>
                </Card>
                <DataTable
                    columns={taskColumns}
                    data={tasks}
                    getRowId={(task) => String(task.id)}
                />
            </div>
        </PageLayout>
    );
}
