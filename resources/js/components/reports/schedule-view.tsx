import { CalendarClock, CircleAlert } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { PageLayout, TablePanel } from '@/components/layout/page-layout';
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
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
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

    return (
        <PageLayout
            description={t('schedule.description')}
            filtersRight={
                <TimeRangePicker slug={slug} view="schedule" range={range} />
            }
        >
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
                        markers={deployMarkers(deploys, t('runtime.deploy'))}
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
            <TablePanel>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{t('schedule.task')}</TableHead>
                            <TableHead>{t('schedule.runs')}</TableHead>
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
                                    {t('schedule.runsSummary', task.runs)}
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
