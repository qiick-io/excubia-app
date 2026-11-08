import { router, useForm } from '@inertiajs/react';
import { BellOff, MoreHorizontal, Plus, Trash2, Wrench } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { EmptyStatus } from '@/components/empty-status';
import { PageLayout, TablePanel } from '@/components/layout/page-layout';
import { SeverityBadge } from '@/components/reports/severity-badge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
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
import { Checkbox } from '@/components/ui/checkbox';
import {
    Drawer,
    DrawerBody,
    DrawerContent,
    DrawerDescription,
    DrawerFooter,
    DrawerHeader,
    DrawerTitle,
} from '@/components/ui/drawer';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import projectsRoutes from '@/routes/projects';
import type {
    AlertHistoryRow,
    AlertStatus,
    MaintenanceWindowRow,
    ProjectAlerts,
} from '@/types';

const DURATIONS = ['1h', '24h', '7d', 'forever'] as const;

const WEEKDAYS = [1, 2, 3, 4, 5, 6, 7] as const;

const STATUS_VARIANTS: Record<
    AlertStatus,
    'success' | 'destructive' | 'info' | 'muted' | 'warning'
> = {
    sent: 'success',
    failed: 'destructive',
    queued: 'info',
    suppressed: 'warning',
    skipped: 'muted',
};

function formatTime(value: string): string {
    return new Date(value).toLocaleString();
}

/** The browser's timezone, the default of a new window. */
function browserTimezone(): string {
    return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
}

/**
 * The project's Alerts tab (#114): a notice while maintenance mode pauses alerts, the
 * maintenance windows and the mutes in force side by side, then the alert history (sent,
 * failed, suppressed with its reason, skipped). Each history row can mute its rule or its issue
 * for 1 h, 24 h, 7 d or forever; editors add windows in a drawer.
 */
export function AlertsView({
    projectId,
    alerts,
    canManage,
}: {
    projectId: number;
    alerts: ProjectAlerts;
    canManage: boolean;
}) {
    const { t } = useTranslation();
    const [isAddingWindow, setIsAddingWindow] = useState(false);

    const remove = (url: string): void =>
        router.delete(url, { preserveScroll: true });

    return (
        <Drawer
            direction="right"
            open={isAddingWindow}
            onOpenChange={setIsAddingWindow}
        >
            <PageLayout scrollContent description={t('alerts.description')}>
                <div className="flex flex-col gap-4">
                    {alerts.isInMaintenanceMode ? (
                        <Alert variant="warning">
                            <Wrench />
                            <AlertTitle>
                                {t('alerts.maintenanceModeTitle')}
                            </AlertTitle>
                            <AlertDescription>
                                {t('alerts.maintenanceModeDescription')}
                            </AlertDescription>
                        </Alert>
                    ) : null}

                    <div className="grid gap-4 lg:grid-cols-2">
                        <Card>
                            <CardHeader>
                                <CardTitle>
                                    {t('alerts.windowsTitle')}
                                </CardTitle>
                                <CardDescription>
                                    {t('alerts.windowsDescription')}
                                </CardDescription>
                                {canManage ? (
                                    <CardAction>
                                        <Button
                                            variant="outline"
                                            onClick={() =>
                                                setIsAddingWindow(true)
                                            }
                                        >
                                            <Plus />
                                            {t('alerts.addWindow')}
                                        </Button>
                                    </CardAction>
                                ) : null}
                            </CardHeader>
                            <CardContent>
                                {alerts.windows.length === 0 ? (
                                    <EmptyStatus>
                                        {t('alerts.noWindows')}
                                    </EmptyStatus>
                                ) : (
                                    <ul className="divide-y">
                                        {alerts.windows.map((window) => (
                                            <li
                                                key={window.id}
                                                className="flex items-center gap-3 py-2"
                                            >
                                                <div className="min-w-0 flex-1">
                                                    <div className="flex items-center gap-2 font-medium">
                                                        <span className="truncate">
                                                            {window.name}
                                                        </span>
                                                        {window.is_active ? (
                                                            <Badge variant="warning">
                                                                {t(
                                                                    'alerts.windowActive',
                                                                )}
                                                            </Badge>
                                                        ) : null}
                                                    </div>
                                                    <div className="text-xs text-muted-foreground">
                                                        <WindowSchedule
                                                            window={window}
                                                        />
                                                    </div>
                                                </div>
                                                {canManage ? (
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        aria-label={t(
                                                            'alerts.removeWindow',
                                                            {
                                                                name: window.name,
                                                            },
                                                        )}
                                                        onClick={() =>
                                                            remove(
                                                                projectsRoutes.maintenanceWindows.destroy.url(
                                                                    {
                                                                        project:
                                                                            projectId,
                                                                        maintenanceWindow:
                                                                            window.id,
                                                                    },
                                                                ),
                                                            )
                                                        }
                                                    >
                                                        <Trash2 />
                                                    </Button>
                                                ) : null}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>{t('alerts.mutesTitle')}</CardTitle>
                                <CardDescription>
                                    {t('alerts.mutesDescription')}
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                {alerts.mutes.length === 0 ? (
                                    <EmptyStatus>
                                        {t('alerts.noMutes')}
                                    </EmptyStatus>
                                ) : (
                                    <ul className="divide-y">
                                        {alerts.mutes.map((mute) => (
                                            <li
                                                key={mute.id}
                                                className="flex items-center gap-3 py-2"
                                            >
                                                <div className="min-w-0 flex-1">
                                                    <div className="truncate font-mono text-sm">
                                                        {mute.event}
                                                    </div>
                                                    <div className="truncate text-xs text-muted-foreground">
                                                        {mute.is_issue
                                                            ? mute.label
                                                            : t(
                                                                  'alerts.wholeRule',
                                                              )}
                                                        {' · '}
                                                        {mute.until === null
                                                            ? t(
                                                                  'alerts.forever',
                                                              )
                                                            : t(
                                                                  'alerts.until',
                                                                  {
                                                                      time: formatTime(
                                                                          mute.until,
                                                                      ),
                                                                  },
                                                              )}
                                                    </div>
                                                </div>
                                                {canManage ? (
                                                    <Button
                                                        variant="outline"
                                                        onClick={() =>
                                                            remove(
                                                                projectsRoutes.alertMutes.destroy.url(
                                                                    {
                                                                        project:
                                                                            projectId,
                                                                        alertMute:
                                                                            mute.id,
                                                                    },
                                                                ),
                                                            )
                                                        }
                                                    >
                                                        {t('alerts.unmute')}
                                                    </Button>
                                                ) : null}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </CardContent>
                        </Card>
                    </div>

                    <Card className="gap-0 py-0">
                        <CardHeader className="py-4">
                            <CardTitle>{t('alerts.historyTitle')}</CardTitle>
                            <CardDescription>
                                {t('alerts.historyDescription')}
                            </CardDescription>
                        </CardHeader>
                        {alerts.history.length === 0 ? (
                            <CardContent className="pb-4">
                                <EmptyStatus>
                                    {t('alerts.noHistory')}
                                </EmptyStatus>
                            </CardContent>
                        ) : (
                            <TablePanel>
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>
                                                {t('alerts.time')}
                                            </TableHead>
                                            <TableHead>
                                                {t('alerts.alert')}
                                            </TableHead>
                                            <TableHead>
                                                {t('alerts.statusLabel')}
                                            </TableHead>
                                            {canManage ? (
                                                <TableHead>
                                                    <span className="sr-only">
                                                        {t('alerts.actions')}
                                                    </span>
                                                </TableHead>
                                            ) : null}
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {alerts.history.map((alert) => (
                                            <HistoryRow
                                                key={alert.id}
                                                projectId={projectId}
                                                alert={alert}
                                                canManage={canManage}
                                            />
                                        ))}
                                    </TableBody>
                                </Table>
                            </TablePanel>
                        )}
                    </Card>
                </div>
            </PageLayout>
            {canManage ? (
                <MaintenanceWindowDrawer
                    projectId={projectId}
                    onDone={() => setIsAddingWindow(false)}
                />
            ) : null}
        </Drawer>
    );
}

/** One alert of the history, with the mute menu for editors. */
function HistoryRow({
    projectId,
    alert,
    canManage,
}: {
    projectId: number;
    alert: AlertHistoryRow;
    canManage: boolean;
}) {
    const { t } = useTranslation();
    const channels = Object.entries(alert.deliveries);

    const mute = (scope: 'rule' | 'issue', duration: string): void =>
        router.post(
            projectsRoutes.alertMutes.store.url(projectId),
            { alert_id: alert.id, scope, duration },
            { preserveScroll: true },
        );

    return (
        <TableRow>
            <TableCell className="text-xs whitespace-nowrap tabular-nums">
                {formatTime(alert.created_at)}
            </TableCell>
            <TableCell className="max-w-[36rem]">
                <div className="flex items-center gap-2">
                    <SeverityBadge severity={alert.severity} />
                    <span className="font-mono text-xs">{alert.event}</span>
                </div>
                <div className="truncate text-sm">{alert.summary}</div>
            </TableCell>
            <TableCell>
                <Badge variant={STATUS_VARIANTS[alert.status]}>
                    {t(`alerts.status.${alert.status}`)}
                </Badge>
                <div className="mt-1 text-xs text-muted-foreground">
                    {alert.reason !== null
                        ? t(`alerts.reason.${alert.reason}`)
                        : channels
                              .map(
                                  ([channel, state]) =>
                                      `${t(`alerts.channel.${channel}`)}: ${t(`alerts.delivery.${state}`)}`,
                              )
                              .join(' · ')}
                </div>
            </TableCell>
            {canManage ? (
                <TableCell className="text-right">
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                variant="ghost"
                                size="icon"
                                aria-label={t('alerts.muteMenu')}
                            >
                                <MoreHorizontal />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            {(alert.has_subject
                                ? (['rule', 'issue'] as const)
                                : (['rule'] as const)
                            ).map((scope, index) => (
                                <DropdownMenuGroup key={scope}>
                                    {index > 0 ? (
                                        <DropdownMenuSeparator />
                                    ) : null}
                                    <DropdownMenuLabel>
                                        {t(`alerts.mute.${scope}`)}
                                    </DropdownMenuLabel>
                                    {DURATIONS.map((duration) => (
                                        <DropdownMenuItem
                                            key={duration}
                                            onSelect={() =>
                                                mute(scope, duration)
                                            }
                                        >
                                            <BellOff />
                                            {t(`alerts.duration.${duration}`)}
                                        </DropdownMenuItem>
                                    ))}
                                </DropdownMenuGroup>
                            ))}
                        </DropdownMenuContent>
                    </DropdownMenu>
                </TableCell>
            ) : null}
        </TableRow>
    );
}

/** When a window applies, in words: its dates, or its weekdays, start and length. */
function WindowSchedule({ window }: { window: MaintenanceWindowRow }) {
    const { t } = useTranslation();

    if (window.recurrence === 'once') {
        return (
            <>
                {formatTime(window.starts_at ?? '')} –{' '}
                {formatTime(window.ends_at ?? '')}
            </>
        );
    }

    return (
        <>
            {window.weekdays
                .map((day) => t(`alerts.weekdays.${day}`))
                .join(', ')}{' '}
            · {window.start_time} ·{' '}
            {t('alerts.minutes', { count: window.duration_minutes ?? 0 })} ·{' '}
            {window.timezone}
        </>
    );
}

/**
 * Drawer form for a new maintenance window: one-off (local start and end) or weekly (weekdays,
 * local start time, length), both in a timezone, the browser's by default.
 */
function MaintenanceWindowDrawer({
    projectId,
    onDone,
}: {
    projectId: number;
    onDone: () => void;
}) {
    const { t } = useTranslation();
    const form = useForm({
        name: '',
        recurrence: 'once' as 'once' | 'weekly',
        timezone: browserTimezone(),
        starts_at: '',
        ends_at: '',
        weekdays: [] as number[],
        start_time: '02:00',
        duration_minutes: '60',
    });
    const timezones = Intl.supportedValuesOf('timeZone');

    const submit = (): void => {
        form.post(projectsRoutes.maintenanceWindows.store.url(projectId), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onDone();
            },
        });
    };

    return (
        <DrawerContent>
            <DrawerHeader>
                <DrawerTitle>{t('alerts.addWindow')}</DrawerTitle>
                <DrawerDescription>
                    {t('alerts.windowFormDescription')}
                </DrawerDescription>
            </DrawerHeader>
            <form
                className="flex min-h-0 flex-1 flex-col overflow-hidden"
                noValidate
                onSubmit={(event) => {
                    event.preventDefault();
                    submit();
                }}
            >
                <DrawerBody>
                    <FieldGroup className="gap-4">
                        <Field
                            data-invalid={form.errors.name ? true : undefined}
                        >
                            <FieldLabel htmlFor="window_name">
                                {t('common.name')}
                            </FieldLabel>
                            <Input
                                id="window_name"
                                value={form.data.name}
                                maxLength={100}
                                required
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                                aria-invalid={
                                    form.errors.name ? true : undefined
                                }
                            />
                            <FieldError>{form.errors.name}</FieldError>
                        </Field>

                        <Field>
                            <FieldLabel id="window_recurrence_label">
                                {t('alerts.recurrence')}
                            </FieldLabel>
                            <ToggleGroup
                                type="single"
                                variant="outline"
                                value={form.data.recurrence}
                                onValueChange={(value) => {
                                    if (value) {
                                        form.setData(
                                            'recurrence',
                                            value as 'once' | 'weekly',
                                        );
                                    }
                                }}
                                aria-labelledby="window_recurrence_label"
                            >
                                <ToggleGroupItem
                                    value="once"
                                    className="px-2.5"
                                >
                                    {t('alerts.once')}
                                </ToggleGroupItem>
                                <ToggleGroupItem
                                    value="weekly"
                                    className="px-2.5"
                                >
                                    {t('alerts.weekly')}
                                </ToggleGroupItem>
                            </ToggleGroup>
                        </Field>

                        {form.data.recurrence === 'once' ? (
                            <>
                                <DateTimeField
                                    id="window_starts_at"
                                    label={t('alerts.startsAt')}
                                    type="datetime-local"
                                    value={form.data.starts_at}
                                    error={form.errors.starts_at}
                                    onChange={(value) =>
                                        form.setData('starts_at', value)
                                    }
                                />
                                <DateTimeField
                                    id="window_ends_at"
                                    label={t('alerts.endsAt')}
                                    type="datetime-local"
                                    value={form.data.ends_at}
                                    error={form.errors.ends_at}
                                    onChange={(value) =>
                                        form.setData('ends_at', value)
                                    }
                                />
                            </>
                        ) : (
                            <>
                                <Field
                                    data-invalid={
                                        form.errors.weekdays ? true : undefined
                                    }
                                >
                                    <FieldLabel id="window_weekdays_label">
                                        {t('alerts.weekdaysLabel')}
                                    </FieldLabel>
                                    <div
                                        role="group"
                                        aria-labelledby="window_weekdays_label"
                                        className="flex flex-wrap gap-3"
                                    >
                                        {WEEKDAYS.map((day) => (
                                            <div
                                                key={day}
                                                className="flex items-center gap-2"
                                            >
                                                <Checkbox
                                                    id={`window_weekday_${day}`}
                                                    checked={form.data.weekdays.includes(
                                                        day,
                                                    )}
                                                    onCheckedChange={(
                                                        checked,
                                                    ) =>
                                                        form.setData(
                                                            'weekdays',
                                                            checked === true
                                                                ? [
                                                                      ...form
                                                                          .data
                                                                          .weekdays,
                                                                      day,
                                                                  ]
                                                                : form.data.weekdays.filter(
                                                                      (item) =>
                                                                          item !==
                                                                          day,
                                                                  ),
                                                        )
                                                    }
                                                />
                                                <Label
                                                    htmlFor={`window_weekday_${day}`}
                                                >
                                                    {t(
                                                        `alerts.weekdays.${day}`,
                                                    )}
                                                </Label>
                                            </div>
                                        ))}
                                    </div>
                                    <FieldError>
                                        {form.errors.weekdays}
                                    </FieldError>
                                </Field>
                                <DateTimeField
                                    id="window_start_time"
                                    label={t('alerts.startTime')}
                                    type="time"
                                    value={form.data.start_time}
                                    error={form.errors.start_time}
                                    onChange={(value) =>
                                        form.setData('start_time', value)
                                    }
                                />
                                <DateTimeField
                                    id="window_duration"
                                    label={t('alerts.durationMinutes')}
                                    type="number"
                                    value={form.data.duration_minutes}
                                    error={form.errors.duration_minutes}
                                    onChange={(value) =>
                                        form.setData('duration_minutes', value)
                                    }
                                />
                            </>
                        )}

                        <Field
                            data-invalid={
                                form.errors.timezone ? true : undefined
                            }
                        >
                            <FieldLabel htmlFor="window_timezone">
                                {t('alerts.timezone')}
                            </FieldLabel>
                            <NativeSelect
                                id="window_timezone"
                                value={form.data.timezone}
                                onChange={(event) =>
                                    form.setData('timezone', event.target.value)
                                }
                            >
                                {timezones.map((timezone) => (
                                    <NativeSelectOption
                                        key={timezone}
                                        value={timezone}
                                    >
                                        {timezone}
                                    </NativeSelectOption>
                                ))}
                            </NativeSelect>
                            <FieldError>{form.errors.timezone}</FieldError>
                        </Field>
                    </FieldGroup>
                </DrawerBody>
                <DrawerFooter className="flex flex-row justify-end gap-2">
                    <Button type="button" variant="outline" onClick={onDone}>
                        {t('common.cancel')}
                    </Button>
                    <Button type="submit" disabled={form.processing}>
                        {t('alerts.addWindow')}
                    </Button>
                </DrawerFooter>
            </form>
        </DrawerContent>
    );
}

/** A labelled date, time or number input with its error. */
function DateTimeField({
    id,
    label,
    type,
    value,
    error,
    onChange,
}: {
    id: string;
    label: string;
    type: 'datetime-local' | 'time' | 'number';
    value: string;
    error?: string;
    onChange: (value: string) => void;
}) {
    return (
        <Field data-invalid={error ? true : undefined}>
            <FieldLabel htmlFor={id}>{label}</FieldLabel>
            <Input
                id={id}
                type={type}
                value={value}
                min={type === 'number' ? 1 : undefined}
                onChange={(event) => onChange(event.target.value)}
                aria-invalid={error ? true : undefined}
            />
            <FieldError>{error}</FieldError>
        </Field>
    );
}
