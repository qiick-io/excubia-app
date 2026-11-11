import type { ColumnDef } from '@tanstack/react-table';
import { router } from '@inertiajs/react';
import {
    Bug,
    CheckCheck,
    EyeOff,
    RotateCcw,
    SearchX,
    Sparkles,
    UserCheck,
} from 'lucide-react';
import type { RowSelectionState } from '@tanstack/react-table';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
    DataTable,
    dataTableSelectColumn,
} from '@/components/admin/data-table';
import { DataTableToolbar } from '@/components/admin/data-table-toolbar';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { EmptyStatus } from '@/components/empty-status';
import { InboxSavedViews } from '@/components/inbox-list';
import type { InboxSavedView } from '@/components/inbox-list';
import { PageLayout } from '@/components/layout/page-layout';
import { useDrawerDeepLink } from '@/hooks/use-drawer-deep-link';
import { useListShortcuts } from '@/hooks/use-list-shortcuts';
import {
    getQueryParam,
    locationPageUrl,
    patchLocationQuery,
} from '@/lib/admin-query-params';
import { isFirstSeenWithinLastDay, savedViewHref } from '@/lib/inbox-views';
import {
    AssignedToMeToggle,
    AssigneeSelect,
    isAssignedToMeLinked,
    useCurrentUserId,
    type AssigneeOption,
} from '@/components/reports/assignee-select';
import { useCopyWithToast } from '@/components/reports/fix-actions';
import { ReleaseLabel, shortRelease } from '@/components/reports/release-label';
import { StackedBarChart } from '@/components/reports/severity-chart';
import type { ChartZoomHandler } from '@/components/reports/severity-chart';
import {
    useRangeTexts,
    useZoomToRange,
    deployMarkers,
    TimeRangePicker,
} from '@/components/reports/time-range-picker';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Drawer,
    DrawerBody,
    DrawerContent,
    DrawerDescription,
    DrawerFooter,
    DrawerHeader,
    DrawerTitle,
} from '@/components/ui/drawer';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { cn } from '@/lib/utils';
import projectsRoutes from '@/routes/projects';
import type { ExceptionGroupRow, RuntimeDeploy, TimeRange } from '@/types';

const EXCEPTIONS_GUIDE_URL =
    'https://github.com/qiick-io/excubia-core/blob/main/docs/guides/exception-capture.md';

type StatusFilter = ExceptionGroupRow['status'] | 'all';

const STATUS_FILTERS: readonly StatusFilter[] = [
    'open',
    'resolved',
    'ignored',
    'all',
];

/** The status filter from `?status=`, open by default. */
function statusFromUrl(): StatusFilter {
    const value = getQueryParam(locationPageUrl(), 'status');

    return STATUS_FILTERS.includes(value as StatusFilter)
        ? (value as StatusFilter)
        : 'open';
}

const STATUS_VARIANTS = {
    open: 'destructive',
    resolved: 'success',
    ignored: 'muted',
} as const;

/**
 * Exceptions of a project grouped into issues (#26), as an admin list (app-ui
 * `docs/list-page.md`): search on the left, status and time range (#113) segmented controls on
 * the right, a table of the groups seen in the range, and a detail drawer with the assignee
 * (#254), the occurrences over the range, the stack and the actions (resolve, ignore, reopen,
 * copy a fix prompt). "Assigned to me" narrows the list to the groups the user is on.
 */
export function ExceptionsView({
    projectId,
    slug,
    groups,
    hasExceptions,
    range,
    deploys,
    canManage,
    assignees = [],
}: {
    projectId: number;
    slug: string;
    groups: ExceptionGroupRow[];
    /** Whether the project has groups at all, also outside the range. */
    hasExceptions: boolean;
    range: TimeRange;
    deploys: RuntimeDeploy[];
    canManage: boolean;
    /** Who a group may be assigned to (#254). */
    assignees?: AssigneeOption[];
}) {
    const { t } = useTranslation();
    const rangeTexts = useRangeTexts(range);
    const zoom = useZoomToRange(slug, 'exceptions');
    const [search, setSearch] = useState('');
    const [status, setStatusFilter] = useState<StatusFilter>(statusFromUrl);
    const currentUserId = useCurrentUserId();
    const [isMine, setIsMineFilter] = useState(isAssignedToMeLinked);
    const [isNewOnly, setIsNewOnly] = useState(
        () => getQueryParam(locationPageUrl(), 'new') === '24h',
    );
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const selected = groups.find((group) => group.id === selectedId) ?? null;
    const [rowSelection, setRowSelection] = useState<RowSelectionState>({});
    const selectedIds = Object.keys(rowSelection)
        .filter((id) => rowSelection[id])
        .map(Number);
    // `?exception=<id>` opens a group's drawer, so the palette and links can point at one.
    const deepLink = useDrawerDeepLink({
        editParam: 'exception',
        onEdit: (id) => setSelectedId(Number(id)),
    });

    // The filters live in the URL, so a saved view is a link and a view can be shared.
    const setStatus = (value: StatusFilter): void => {
        setStatusFilter(value);
        patchLocationQuery({ status: value === 'open' ? null : value });
    };
    const setIsMine = (value: boolean): void => {
        setIsMineFilter(value);
        patchLocationQuery({ assigned: value ? 'me' : null });
    };
    const clearNewOnly = (): void => {
        setIsNewOnly(false);
        patchLocationQuery({ new: null });
    };

    const filtered = useMemo(() => {
        const needle = search.trim().toLowerCase();

        return groups.filter(
            (group) =>
                (status === 'all' || group.status === status) &&
                (!isMine || group.assignee?.id === currentUserId) &&
                (!isNewOnly || isFirstSeenWithinLastDay(group.first_seen_at)) &&
                (needle === '' ||
                    `${group.class} ${group.message} ${group.context.name}`
                        .toLowerCase()
                        .includes(needle)),
        );
    }, [groups, search, status, isMine, isNewOnly, currentUserId]);

    // Keyboard triage (#277): j / k, Enter, x, e resolve, a assign to me.
    const listShortcuts = useListShortcuts({
        rows: filtered,
        getRowId: (group) => String(group.id),
        isEnabled: selected === null,
        onOpen: (group) => {
            setSelectedId(group.id);
            deepLink.syncEdit(group.id);
        },
        onToggleSelect: canManage
            ? (group) =>
                  setRowSelection((current) => ({
                      ...current,
                      [String(group.id)]: !current[String(group.id)],
                  }))
            : undefined,
        primaryAction: {
            keys: 'e',
            description: t('exceptions.resolve'),
            isEnabled: canManage,
            handler: (group) =>
                router.patch(
                    projectsRoutes.exceptions.update.url({
                        project: projectId,
                        exceptionGroup: group.id,
                    }),
                    { status: 'resolved' },
                    { preserveScroll: true, preserveState: true },
                ),
        },
        secondaryAction: {
            keys: 'a',
            description: t('exceptions.assignToMe'),
            isEnabled:
                canManage &&
                currentUserId !== null &&
                assignees.some((option) => option.id === currentUserId),
            handler: (group) =>
                router.put(
                    projectsRoutes.exceptions.assignee.url({
                        project: projectId,
                        exceptionGroup: group.id,
                    }),
                    { assigned_user_id: currentUserId },
                    { preserveScroll: true, preserveState: true },
                ),
        },
    });

    const picker = (
        <TimeRangePicker slug={slug} view="exceptions" range={range} />
    );

    if (groups.length === 0) {
        return (
            <PageLayout filtersRight={hasExceptions ? picker : undefined}>
                {hasExceptions ? (
                    <PageEmptyState
                        icon={SearchX}
                        title={t('exceptions.emptyRangeTitle')}
                        description={t('exceptions.emptyRangeDescription', {
                            during: rangeTexts.during,
                        })}
                    />
                ) : (
                    <PageEmptyState
                        icon={Bug}
                        title={t('exceptions.emptyTitle')}
                        description={t('exceptions.emptyDescription')}
                        docsHref={EXCEPTIONS_GUIDE_URL}
                        docsLabel={t('exceptions.guide')}
                    />
                )}
            </PageLayout>
        );
    }

    const hasActiveFilters =
        search.trim() !== '' || status !== 'open' || isMine || isNewOnly;

    const openGroups = groups.filter((group) => group.status === 'open');
    const savedViews: InboxSavedView[] = [
        {
            id: 'unresolved',
            label: t('exceptions.views.unresolved'),
            href: savedViewHref({}),
            count: openGroups.length,
        },
        {
            id: 'mine',
            label: t('exceptions.views.mine'),
            href: savedViewHref({ assigned: 'me' }),
            count: openGroups.filter(
                (group) => group.assignee?.id === currentUserId,
            ).length,
        },
        {
            id: 'new',
            label: t('exceptions.views.new'),
            href: savedViewHref({ new: '24h' }),
            count: openGroups.filter((group) =>
                isFirstSeenWithinLastDay(group.first_seen_at),
            ).length,
        },
    ];
    const activeSavedViewId =
        status !== 'open' || search.trim() !== ''
            ? undefined
            : isMine && !isNewOnly
              ? 'mine'
              : isNewOnly && !isMine
                ? 'new'
                : !isMine && !isNewOnly
                  ? 'unresolved'
                  : undefined;

    const changeStatus = (nextStatus: ExceptionGroupRow['status']): void => {
        router.patch(
            projectsRoutes.exceptions.bulk.status.url(projectId),
            { ids: selectedIds, status: nextStatus },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => setRowSelection({}),
            },
        );
    };
    const assignSelection = (assigneeId: number | null): void => {
        router.put(
            projectsRoutes.exceptions.bulk.assignee.url(projectId),
            { ids: selectedIds, assigned_user_id: assigneeId },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => setRowSelection({}),
            },
        );
    };
    const bulkActions = (
        <>
            <Button
                type="button"
                variant="outline"
                onClick={() => changeStatus('resolved')}
                data-test="bulk-resolved"
            >
                <CheckCheck className="size-4" aria-hidden="true" />
                {t('exceptions.resolve')}
            </Button>
            <Button
                type="button"
                variant="outline"
                onClick={() => changeStatus('ignored')}
                data-test="bulk-ignored"
            >
                <EyeOff className="size-4" aria-hidden="true" />
                {t('exceptions.ignore')}
            </Button>
            <Button
                type="button"
                variant="outline"
                onClick={() => changeStatus('open')}
                data-test="bulk-open"
            >
                <RotateCcw className="size-4" aria-hidden="true" />
                {t('exceptions.reopen')}
            </Button>
            {currentUserId !== null &&
            assignees.some((option) => option.id === currentUserId) ? (
                <Button
                    type="button"
                    variant="outline"
                    onClick={() => assignSelection(currentUserId)}
                >
                    <UserCheck className="size-4" aria-hidden="true" />
                    {t('exceptions.assignToMe')}
                </Button>
            ) : null}
        </>
    );

    const exceptionColumns: ColumnDef<ExceptionGroupRow>[] = [
        ...(canManage
            ? [
                  dataTableSelectColumn<ExceptionGroupRow>({
                      selectAllLabel: t('exceptions.selectAll'),
                      getRowLabel: (group) =>
                          t('exceptions.selectRow', { name: group.class }),
                  }),
              ]
            : []),
        {
            id: 'exception',
            header: t('exceptions.exception'),
            meta: { cellClassName: 'max-w-[32rem]' },
            cell: ({ row }) => {
                const group = row.original;

                return (
                    <>
                        <div className="truncate font-mono text-xs font-medium">
                            {group.class}
                        </div>
                        <div className="truncate text-sm text-muted-foreground">
                            {group.message}
                        </div>
                    </>
                );
            },
        },
        {
            id: 'context',
            header: t('exceptions.context'),
            meta: { cellClassName: 'max-w-[14rem]' },
            cell: ({ row }) => {
                const group = row.original;

                return (
                    <>
                        <div className="truncate text-sm">
                            {group.context.name}
                        </div>
                        <div className="text-xs text-muted-foreground">
                            {t(`exceptions.kind.${group.context.kind}`)}
                        </div>
                    </>
                );
            },
        },
        {
            id: 'environment',
            header: t('exceptions.environment'),
            cell: ({ row }) => {
                const group = row.original;

                return (
                    <>
                        <Badge variant="outline">{group.environment}</Badge>
                    </>
                );
            },
        },
        {
            id: 'inRange',
            header: t('exceptions.inRange'),
            meta: {
                headerClassName: 'text-right',
                cellClassName: 'text-right tabular-nums',
            },
            cell: ({ row }) => {
                const group = row.original;

                return <>{(group.range_count ?? 0).toLocaleString()}</>;
            },
        },
        {
            id: 'count',
            header: t('exceptions.count'),
            meta: {
                headerClassName: 'text-right',
                cellClassName: 'text-right tabular-nums',
            },
            cell: ({ row }) => {
                const group = row.original;

                return <>{group.count.toLocaleString()}</>;
            },
        },
        {
            id: 'assignee',
            header: t('assignee.column'),
            meta: { cellClassName: 'max-w-[10rem] truncate text-sm' },
            cell: ({ row }) => <>{row.original.assignee?.name ?? '—'}</>,
        },
        {
            id: 'lastSeen',
            header: t('exceptions.lastSeen'),
            meta: {
                headerClassName: 'text-right',
                cellClassName:
                    'text-right text-xs text-muted-foreground tabular-nums',
            },
            cell: ({ row }) => {
                const group = row.original;

                return <>{new Date(group.last_seen_at).toLocaleString()}</>;
            },
        },
        {
            id: 'statusLabel',
            header: t('exceptions.statusLabel'),
            cell: ({ row }) => {
                const group = row.original;

                return (
                    <>
                        <div className="flex flex-wrap gap-1">
                            <Badge variant={STATUS_VARIANTS[group.status]}>
                                {t(`exceptions.status.${group.status}`)}
                            </Badge>
                            {group.regressed_release !== null ? (
                                <Badge
                                    variant="warning"
                                    title={group.regressed_release}
                                >
                                    {t('exceptions.regressedIn', {
                                        release: shortRelease(
                                            group.regressed_release,
                                        ),
                                    })}
                                </Badge>
                            ) : null}
                        </div>
                    </>
                );
            },
        },
    ];

    return (
        <Drawer
            direction="right"
            open={selected !== null}
            onOpenChange={(open) => {
                if (!open) {
                    setSelectedId(null);
                    deepLink.syncClosed();
                }
            }}
        >
            <PageLayout
                subheader={
                    <InboxSavedViews
                        views={savedViews}
                        activeViewId={activeSavedViewId}
                    />
                }
                filters={
                    <DataTableToolbar
                        search={search}
                        onSearchChange={setSearch}
                        searchPlaceholder={t('exceptions.search')}
                        selectedCount={selectedIds.length}
                        onClearSelection={() => setRowSelection({})}
                        bulkActions={bulkActions}
                    />
                }
                filtersRight={
                    <div className="flex flex-wrap items-center gap-1.5">
                        <ToggleGroup
                            type="single"
                            variant="outline"
                            value={status}
                            onValueChange={(value) => {
                                if (value) {
                                    setStatus(value as StatusFilter);
                                }
                            }}
                            aria-label={t('exceptions.statusFilter')}
                        >
                            {(
                                ['open', 'resolved', 'ignored', 'all'] as const
                            ).map((item) => (
                                <ToggleGroupItem
                                    key={item}
                                    value={item}
                                    className="px-2.5"
                                >
                                    {t(`exceptions.status.${item}`)}
                                </ToggleGroupItem>
                            ))}
                        </ToggleGroup>
                        <AssignedToMeToggle
                            isPressed={isMine}
                            onPressedChange={setIsMine}
                        />
                        {picker}
                    </div>
                }
            >
                {filtered.length === 0 ? (
                    <PageEmptyState
                        icon={SearchX}
                        title={
                            <EmptyStatus>
                                {t('exceptions.noMatchTitle')}
                            </EmptyStatus>
                        }
                        description={t('exceptions.noMatchDescription')}
                        action={
                            hasActiveFilters ? (
                                <Button
                                    type="button"
                                    onClick={() => {
                                        setSearch('');
                                        setIsMine(false);
                                        clearNewOnly();
                                        setStatus('all');
                                    }}
                                >
                                    {t('common.clearFilters')}
                                </Button>
                            ) : undefined
                        }
                    />
                ) : (
                    <DataTable
                        columns={exceptionColumns}
                        data={filtered}
                        getRowId={(group) => String(group.id)}
                        rowSelection={canManage ? rowSelection : undefined}
                        onRowSelectionChange={setRowSelection}
                        onRowClick={(group) => {
                            setSelectedId(group.id);
                            deepLink.syncEdit(group.id);
                        }}
                        getRowProps={(group) => {
                            const activeRowProps =
                                listShortcuts.getActiveRowProps(group);

                            return {
                                ...activeRowProps,
                                className: cn(
                                    'cursor-pointer',
                                    activeRowProps.className,
                                ),
                            };
                        }}
                    />
                )}
            </PageLayout>

            {selected ? (
                <ExceptionDrawer
                    projectId={projectId}
                    group={selected}
                    range={range}
                    deploys={deploys}
                    canManage={canManage}
                    assignees={assignees}
                    onZoom={zoom}
                />
            ) : null}
        </Drawer>
    );
}

/**
 * One exception group: masked message, occurrences over the range with the deploy markers,
 * chain, stack (project frames emphasised), timing, and the actions.
 */
function ExceptionDrawer({
    projectId,
    group,
    range,
    deploys,
    canManage,
    assignees,
    onZoom,
}: {
    projectId: number;
    group: ExceptionGroupRow;
    range: TimeRange;
    deploys: RuntimeDeploy[];
    canManage: boolean;
    assignees: AssigneeOption[];
    onZoom: ChartZoomHandler;
}) {
    const { t } = useTranslation();
    const rangeTexts = useRangeTexts(range);
    const phrases = { bucket: rangeTexts.bucket, during: rangeTexts.during };
    const copyWithToast = useCopyWithToast();

    const setStatus = (status: ExceptionGroupRow['status']): void => {
        router.patch(
            projectsRoutes.exceptions.update.url({
                project: projectId,
                exceptionGroup: group.id,
            }),
            { status },
            { preserveScroll: true, preserveState: true },
        );
    };

    return (
        <DrawerContent className="data-[vaul-drawer-direction=right]:max-w-2xl">
            <DrawerHeader>
                <DrawerTitle className="font-mono text-sm break-all">
                    {group.class}
                </DrawerTitle>
                <DrawerDescription>
                    {t('exceptions.summary', {
                        count: group.count,
                        environment: group.environment,
                        first: new Date(group.first_seen_at).toLocaleString(),
                        last: new Date(group.last_seen_at).toLocaleString(),
                    })}
                </DrawerDescription>
            </DrawerHeader>
            <DrawerBody className="gap-4">
                <AssigneeSelect
                    id={`exception-assignee-${group.id}`}
                    url={projectsRoutes.exceptions.assignee.url({
                        project: projectId,
                        exceptionGroup: group.id,
                    })}
                    assignee={group.assignee}
                    options={assignees}
                    canAssign={canManage}
                />
                <section className="flex flex-col gap-1">
                    <h3 className="text-sm font-medium">
                        {t('exceptions.occurrences')}
                    </h3>
                    <p className="text-xs text-muted-foreground">
                        {t('exceptions.occurrencesDescription', phrases)}
                    </p>
                    <div className="h-40">
                        <StackedBarChart
                            data={(group.timeline ?? []).map((point) => ({
                                label: point.at,
                                count: point.count,
                            }))}
                            formatLabel={rangeTexts.bucketText}
                            onZoom={onZoom}
                            markers={deployMarkers(
                                deploys,
                                t('runtime.deploy'),
                            )}
                            series={[
                                {
                                    key: 'count',
                                    label: t('exceptions.occurrences'),
                                    color: 'var(--runtime-chart-error)',
                                },
                            ]}
                            label={t('exceptions.occurrencesChartLabel', {
                                count: group.range_count ?? 0,
                                ...phrases,
                            })}
                        />
                    </div>
                </section>
                <section className="flex flex-col gap-1">
                    <h3 className="text-sm font-medium">
                        {t('exceptions.message')}
                    </h3>
                    <p className="rounded-md bg-muted p-3 font-mono text-xs break-words whitespace-pre-wrap">
                        {group.message || '—'}
                    </p>
                </section>
                <section className="flex flex-wrap gap-x-6 gap-y-1 text-sm">
                    <span>
                        <span className="text-muted-foreground">
                            {t(`exceptions.kind.${group.context.kind}`)}:
                        </span>{' '}
                        {group.context.name}
                    </span>
                    {group.first_release !== null ? (
                        <span>
                            <span className="text-muted-foreground">
                                {t('exceptions.firstRelease')}:
                            </span>{' '}
                            <ReleaseLabel release={group.first_release} />
                        </span>
                    ) : null}
                    {group.last_release !== null ? (
                        <span>
                            <span className="text-muted-foreground">
                                {t('exceptions.lastRelease')}:
                            </span>{' '}
                            <ReleaseLabel release={group.last_release} />
                        </span>
                    ) : null}
                    {group.regressed_release !== null ? (
                        <Badge
                            variant="warning"
                            title={group.regressed_release}
                        >
                            {t('exceptions.regressedIn', {
                                release: shortRelease(group.regressed_release),
                            })}
                        </Badge>
                    ) : null}
                    {group.previous.length > 0 ? (
                        <span>
                            <span className="text-muted-foreground">
                                {t('exceptions.causedBy')}:
                            </span>{' '}
                            <span className="font-mono text-xs">
                                {group.previous.join(' ← ')}
                            </span>
                        </span>
                    ) : null}
                </section>
                <section className="flex flex-col gap-1">
                    <h3 className="text-sm font-medium">
                        {t('exceptions.stack')}
                    </h3>
                    <ol className="divide-y rounded-md border">
                        {group.frames.map((frame, index) => (
                            <li
                                key={index}
                                className={cn(
                                    'px-3 py-1.5 font-mono text-xs break-all',
                                    frame.inApp
                                        ? 'bg-accent/40 text-foreground'
                                        : 'text-muted-foreground',
                                )}
                            >
                                <div>
                                    {frame.file ?? t('exceptions.internal')}
                                    {frame.line !== null
                                        ? `:${frame.line}`
                                        : ''}
                                </div>
                                {frame.function ? (
                                    <div className="opacity-80">
                                        {frame.function}
                                    </div>
                                ) : null}
                            </li>
                        ))}
                    </ol>
                    <p className="text-xs text-muted-foreground">
                        {t('exceptions.stackHint')}
                    </p>
                </section>
            </DrawerBody>
            <DrawerFooter className="flex flex-row flex-wrap justify-end gap-2">
                <Button
                    type="button"
                    variant="outline"
                    className="text-ai-text"
                    onClick={() => void copyWithToast(group.fix.prompt)}
                >
                    <Sparkles className="size-4" />
                    {t('fix.copyPrompt')}
                </Button>
                {canManage && group.status !== 'ignored' ? (
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => setStatus('ignored')}
                    >
                        {t('exceptions.ignore')}
                    </Button>
                ) : null}
                {canManage && group.status !== 'open' ? (
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => setStatus('open')}
                    >
                        {t('exceptions.reopen')}
                    </Button>
                ) : null}
                {canManage && group.status !== 'resolved' ? (
                    <Button type="button" onClick={() => setStatus('resolved')}>
                        {t('exceptions.resolve')}
                    </Button>
                ) : null}
            </DrawerFooter>
        </DrawerContent>
    );
}
