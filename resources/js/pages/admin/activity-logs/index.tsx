import type { ColumnDef } from '@tanstack/react-table';
import { Head, router } from '@inertiajs/react';
import { useCallback, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
    DataTable,
    dataTableExpandColumn,
} from '@/components/admin/data-table';
import { TruncatedText } from '@/components/admin/truncated-text';
import { UserMultiSelect } from '@/components/admin/user-multi-select';
import {
    FilterSearch,
    filterSelectClassName,
} from '@/components/layout/page-header';
import { PageLayout } from '@/components/layout/page-layout';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { useDebouncedServerSearch } from '@/hooks/use-debounced-server-search';
import AppLayout from '@/layouts/app-layout';
import { listPerPageQuery } from '@/lib/list-per-page';
import { normalizePaginated } from '@/lib/pagination';
import type { LaravelPaginated } from '@/lib/pagination';
import { cn } from '@/lib/utils';
import activityLogsRoutes from '@/routes/activity-logs';
import type {
    AdminActivityLogRow,
    AdminUserRef,
    BreadcrumbItem,
    Paginated,
} from '@/types';

type Filters = {
    search?: string;
    user_ids?: number[];
    user_id?: number | null;
    event?: string;
    log_name?: string;
    date_from?: string;
    date_to?: string;
};

function resolveInitialUserIds(filters: Filters): number[] {
    if (Array.isArray(filters.user_ids) && filters.user_ids.length > 0) {
        return filters.user_ids.map(Number).filter((id) => id > 0);
    }

    if (filters.user_id) {
        return [Number(filters.user_id)];
    }

    return [];
}

/**
 * Searchable activity log (`can-show-activity-logs`) with user, action, log and date filters and
 * expandable details (log, IP, changes). Copied from qiick-io/externa-core.
 */
export default function AdminActivityLogsIndex({
    activityLogs: activityLogsProp,
    users: usersProp,
    events = [],
    logNames = ['default', 'auth', 'settings'],
    filters = {},
}: {
    activityLogs:
        | LaravelPaginated<AdminActivityLogRow>
        | Paginated<AdminActivityLogRow>;
    users: AdminUserRef[];
    events?: string[];
    logNames?: string[];
    filters?: Filters;
}) {
    const { t } = useTranslation();
    const activityLogs = normalizePaginated(activityLogsProp);
    const users = useMemo(() => usersProp ?? [], [usersProp]);
    const [search, setSearch] = useState(filters.search ?? '');
    const [userIds, setUserIds] = useState<number[]>(() =>
        resolveInitialUserIds(filters),
    );
    const [event, setEvent] = useState(filters.event ?? '');
    const [logName, setLogName] = useState(filters.log_name ?? '');
    const [dateFrom, setDateFrom] = useState(filters.date_from ?? '');
    const [dateTo, setDateTo] = useState(filters.date_to ?? '');

    const breadcrumbs: BreadcrumbItem[] = useMemo(
        () => [
            {
                title: t('activityLog.title'),
                href: activityLogsRoutes.index.url(),
            },
        ],
        [t],
    );

    const visit = useCallback(
        (overrides: Partial<Filters> = {}) => {
            const nextUserIds =
                overrides.user_ids !== undefined ? overrides.user_ids : userIds;
            const nextSearch =
                overrides.search !== undefined ? overrides.search : search;
            const nextEvent =
                overrides.event !== undefined ? overrides.event : event;
            const nextLogName =
                overrides.log_name !== undefined ? overrides.log_name : logName;
            const nextDateFrom =
                overrides.date_from !== undefined
                    ? overrides.date_from
                    : dateFrom;
            const nextDateTo =
                overrides.date_to !== undefined ? overrides.date_to : dateTo;

            router.get(
                activityLogsRoutes.index.url({
                    query: {
                        search: nextSearch || undefined,
                        user_ids:
                            nextUserIds && nextUserIds.length > 0
                                ? nextUserIds
                                : undefined,
                        event: nextEvent || undefined,
                        log_name: nextLogName || undefined,
                        date_from: nextDateFrom || undefined,
                        date_to: nextDateTo || undefined,
                        per_page: listPerPageQuery(activityLogs.per_page),
                    },
                }),
                {},
                { preserveState: true, preserveScroll: true },
            );
        },
        [
            search,
            userIds,
            event,
            logName,
            dateFrom,
            dateTo,
            activityLogs.per_page,
        ],
    );

    const onDebouncedSearch = useCallback(
        (value: string): void => {
            visit({ search: value || undefined });
        },
        [visit],
    );

    useDebouncedServerSearch(search, filters.search ?? '', onDebouncedSearch);

    const formatDate = (value: string): string =>
        new Date(value).toLocaleString();

    const eventLabel = (eventName: string | null | undefined): string => {
        if (!eventName) {
            return '—';
        }

        return t(`activityLog.events.${eventName}`, {
            defaultValue: eventName,
        });
    };

    const logNameLabel = (name: string): string =>
        t(`activityLog.logs.${name}`, { defaultValue: name });

    const renderChanges = (row: AdminActivityLogRow): string => {
        const hasChanges = row.changes && Object.keys(row.changes).length > 0;
        const meta = row.properties?.meta ?? {};
        const hasMeta = Object.keys(meta).length > 0;

        if (!hasChanges && !hasMeta) {
            return t('activityLog.noChanges');
        }

        if (hasChanges && hasMeta) {
            return JSON.stringify({ changes: row.changes, meta }, null, 2);
        }

        return JSON.stringify(hasChanges ? row.changes : meta, null, 2);
    };

    const initialSelectedUsers = useMemo(() => {
        if (userIds.length === 0) {
            return [];
        }

        return users.filter((user) => userIds.includes(user.id));
    }, [users, userIds]);

    const columns: ColumnDef<AdminActivityLogRow>[] = [
        dataTableExpandColumn<AdminActivityLogRow>({
            expandLabel: t('activityLog.expand'),
            collapseLabel: t('activityLog.collapse'),
        }),
        {
            id: 'created_at',
            header: t('activityLog.columns.date'),
            meta: { cellClassName: 'text-sm whitespace-nowrap tabular-nums' },
            cell: ({ row }) => formatDate(row.original.created_at),
        },
        {
            id: 'user',
            header: t('activityLog.columns.user'),
            cell: ({ row }) =>
                row.original.causer ? (
                    <div className="text-sm">
                        <div>{row.original.causer.name}</div>
                        <div className="text-xs text-muted-foreground">
                            {row.original.causer.email}
                        </div>
                    </div>
                ) : (
                    <span className="text-sm text-muted-foreground">
                        {t('activityLog.system')}
                    </span>
                ),
        },
        {
            id: 'action',
            header: t('activityLog.columns.action'),
            cell: ({ row }) => (
                <Badge variant="secondary">
                    {eventLabel(row.original.event)}
                </Badge>
            ),
        },
        {
            id: 'description',
            header: t('activityLog.columns.description'),
            meta: { cellClassName: 'max-w-xs text-sm' },
            cell: ({ row }) => (
                <TruncatedText text={row.original.description || '—'} />
            ),
        },
        {
            id: 'subject',
            header: t('activityLog.columns.subject'),
            meta: { cellClassName: 'text-sm' },
            cell: ({ row }) =>
                row.original.subject ? (
                    <>
                        {row.original.subject.type} {row.original.subject.label}
                    </>
                ) : (
                    '—'
                ),
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('activityLog.title')} />

            <PageLayout
                description={t('activityLog.description')}
                filters={
                    <>
                        <FilterSearch
                            value={search}
                            onChange={setSearch}
                            placeholder={t('activityLog.searchPlaceholder')}
                            className="max-w-[12rem] min-w-[8rem] flex-none"
                        />
                        <UserMultiSelect
                            value={userIds}
                            onChange={(next) => {
                                setUserIds(next);
                                visit({ user_ids: next });
                            }}
                            placeholder={t('activityLog.allUsers')}
                            initialUsers={initialSelectedUsers}
                            className={cn(
                                filterSelectClassName,
                                'h-auto min-h-9 max-w-[12rem] min-w-[8rem] shrink-0',
                            )}
                        />
                        <NativeSelect
                            value={event}
                            onChange={(changeEvent) => {
                                setEvent(changeEvent.target.value);
                                visit({
                                    event:
                                        changeEvent.target.value || undefined,
                                });
                            }}
                            className="max-w-[10rem] min-w-[7rem] shrink-0"
                            aria-label={t('activityLog.filterByAction')}
                            title={
                                event
                                    ? eventLabel(event)
                                    : t('activityLog.allActions')
                            }
                        >
                            <option value="">
                                {t('activityLog.allActions')}
                            </option>
                            {events.map((eventName) => (
                                <option key={eventName} value={eventName}>
                                    {eventLabel(eventName)}
                                </option>
                            ))}
                        </NativeSelect>
                        <NativeSelect
                            value={logName}
                            onChange={(changeEvent) => {
                                setLogName(changeEvent.target.value);
                                visit({
                                    log_name:
                                        changeEvent.target.value || undefined,
                                });
                            }}
                            className="max-w-[10rem] min-w-[7rem] shrink-0"
                            aria-label={t('activityLog.filterByLog')}
                            title={
                                logName
                                    ? logNameLabel(logName)
                                    : t('activityLog.allLogs')
                            }
                        >
                            <option value="">{t('activityLog.allLogs')}</option>
                            {logNames.map((name) => (
                                <option key={name} value={name}>
                                    {logNameLabel(name)}
                                </option>
                            ))}
                        </NativeSelect>
                        <Input
                            type="date"
                            value={dateFrom}
                            onChange={(changeEvent) => {
                                setDateFrom(changeEvent.target.value);
                                visit({
                                    date_from:
                                        changeEvent.target.value || undefined,
                                });
                            }}
                            className="h-9 w-auto shrink-0"
                            title={dateFrom || undefined}
                            aria-label={t('activityLog.dateFrom')}
                        />
                        <Input
                            type="date"
                            value={dateTo}
                            onChange={(changeEvent) => {
                                setDateTo(changeEvent.target.value);
                                visit({
                                    date_to:
                                        changeEvent.target.value || undefined,
                                });
                            }}
                            className="h-9 w-auto shrink-0"
                            aria-label={t('activityLog.dateTo')}
                        />
                    </>
                }
            >
                <DataTable
                    columns={columns}
                    data={activityLogs.data ?? []}
                    getRowId={(row) => String(row.id)}
                    pagination={activityLogs}
                    renderExpandedRow={(row) => (
                        <div className="space-y-3 py-2">
                            <div className="grid gap-2 text-sm sm:grid-cols-2">
                                <div>
                                    <span className="text-muted-foreground">
                                        {t('activityLog.logName')}:
                                    </span>{' '}
                                    {logNameLabel(row.log_name ?? 'default')}
                                </div>
                                <div>
                                    <span className="text-muted-foreground">
                                        {t('activityLog.ipAddress')}:
                                    </span>{' '}
                                    {row.properties.ip ?? '—'}
                                </div>
                            </div>
                            <pre className="overflow-x-auto rounded-md bg-muted p-3 text-xs">
                                {renderChanges(row)}
                            </pre>
                        </div>
                    )}
                    emptyState={
                        <span className="text-muted-foreground">
                            {t('activityLog.empty')}
                        </span>
                    }
                />
            </PageLayout>
        </AppLayout>
    );
}
