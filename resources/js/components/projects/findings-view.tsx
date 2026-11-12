import type { ColumnDef } from '@tanstack/react-table';
import { router } from '@inertiajs/react';
import {
    ExternalLink,
    ListChecks,
    SearchX,
    Sparkles,
    UserCheck,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
    DataTable,
    dataTableSelectColumn,
} from '@/components/admin/data-table';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { EmptyStatus } from '@/components/empty-status';
import { DataTableToolbar } from '@/components/admin/data-table-toolbar';
import { PageLayout } from '@/components/layout/page-layout';
import {
    CHECK_CATEGORIES,
    checkLabel,
    FindingDrawer,
} from '@/components/reports/check-details';
import {
    AssignedToMeToggle,
    isAssignedToMeLinked,
    useCurrentUserId,
    type AssigneeOption,
} from '@/components/reports/assignee-select';
import {
    formatDateTime,
    LifecycleBadges,
} from '@/components/reports/finding-lifecycle';
import type { FindingFix } from '@/components/reports/fix-actions';
import {
    combinedPrompt,
    FixActions,
    useCopyWithToast,
} from '@/components/reports/fix-actions';
import { SEVERITIES, SeverityBadge } from '@/components/reports/severity-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Drawer } from '@/components/ui/drawer';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { InboxSavedViews } from '@/components/inbox-list';
import type { InboxSavedView } from '@/components/inbox-list';
import { useDrawerDeepLink } from '@/hooks/use-drawer-deep-link';
import { useListShortcuts } from '@/hooks/use-list-shortcuts';
import {
    getQueryParam,
    locationPageUrl,
    patchLocationQuery,
} from '@/lib/admin-query-params';
import { isFirstSeenWithinLastDay, savedViewHref } from '@/lib/inbox-views';
import projectsRoutes from '@/routes/projects';
import type { CheckCategory, IssueRow, LifecycleRow, Severity } from '@/types';
import { relativeTime, findingKey } from '@/lib/report-format';

type FindingStatusFilter = LifecycleRow['lifecycle']['status'] | 'all';

const FINDING_STATUS_FILTERS: readonly FindingStatusFilter[] = [
    'open',
    'dismissed',
    'resolved',
    'all',
];

/** The Findings tab's status filter from `?status=` (#276), open by default. */
function findingStatusFromUrl(): FindingStatusFilter {
    const value = getQueryParam(locationPageUrl(), 'status');

    return FINDING_STATUS_FILTERS.includes(value as FindingStatusFilter)
        ? (value as FindingStatusFilter)
        : 'open';
}

/**
 * The findings across reports (#18), problems first, as an admin list: search and filters
 * (lifecycle status, severity, group, check) on the left, the table in a TablePanel (app-ui
 * `docs/list-page.md`), and a detail drawer with the check's explanation, structured details,
 * lifecycle and history (`?finding=<key>` opens it). Open findings include those whose check did
 * not run in the latest report. Filtering happens in the browser: the list is small.
 */
export function FindingsView({
    rows,
    fixes,
    projectId,
    latestReceivedAt,
    canDismiss,
    assignees,
}: {
    rows: LifecycleRow[];
    fixes: Record<string, FindingFix>;
    projectId: number;
    latestReceivedAt: string | null;
    canDismiss: boolean;
    assignees: AssigneeOption[];
}) {
    const { t, i18n } = useTranslation();
    const [status, setStatusFilter] =
        useState<FindingStatusFilter>(findingStatusFromUrl);
    const currentUserId = useCurrentUserId();
    const [isMine, setIsMineFilter] = useState(isAssignedToMeLinked);
    const [isNewOnly, setIsNewOnly] = useState(
        () => getQueryParam(locationPageUrl(), 'new') === '24h',
    );
    // The filters live in the URL (#276), so a saved view is a link and a view can be shared.
    const setStatus = (value: FindingStatusFilter): void => {
        setStatusFilter(value);
        patchLocationQuery({ status: value === 'open' ? null : value });
    };
    const setIsMine = (value: boolean): void => {
        setIsMineFilter(value);
        patchLocationQuery({ assigned: value ? 'me' : null });
    };
    const copyWithToast = useCopyWithToast();
    const [selected, setSelected] = useState<string[]>([]);
    const [search, setSearch] = useState('');
    const [severity, setSeverity] = useState<Severity | 'all'>('all');
    const [category, setCategory] = useState<CheckCategory | 'all'>('all');
    const [checkId, setCheckId] = useState('all');
    const [openKey, setOpenKey] = useState<string | null>(null);
    const openFinding = rows.find((row) => findingKey(row) === openKey) ?? null;
    const { syncEdit, syncClosed } = useDrawerDeepLink({
        editParam: 'finding',
        onEdit: setOpenKey,
    });

    const checks = useMemo(
        () =>
            [
                ...new Map(
                    rows.map((row) => [
                        row.checkId,
                        checkLabel(t, row.checkId, row.checkName),
                    ]),
                ),
            ].map(([id, name]) => ({ id, name })),
        [rows, t],
    );
    const categories = CHECK_CATEGORIES.filter((item) =>
        rows.some((row) => row.category === item),
    );

    const needle = search.trim().toLowerCase();
    const filtered = rows.filter(
        (row) =>
            (status === 'all' || row.lifecycle.status === status) &&
            (!isMine || row.lifecycle.assignee?.id === currentUserId) &&
            (!isNewOnly ||
                isFirstSeenWithinLastDay(row.lifecycle.firstSeenAt)) &&
            (severity === 'all' || row.severity === severity) &&
            (category === 'all' || row.category === category) &&
            (checkId === 'all' || row.checkId === checkId) &&
            (needle === '' ||
                `${row.subject} ${row.message} ${row.advisoryId ?? ''}`
                    .toLowerCase()
                    .includes(needle)),
    );
    const hasActiveFilters =
        needle !== '' ||
        (status !== 'open' && status !== 'resolved') ||
        isMine ||
        isNewOnly ||
        severity !== 'all' ||
        category !== 'all' ||
        checkId !== 'all';

    const openRows = rows.filter((row) => row.lifecycle.status === 'open');
    const savedViews: InboxSavedView[] = [
        {
            id: 'open',
            label: t('findings.views.open'),
            href: savedViewHref({}),
            count: openRows.length,
        },
        {
            id: 'mine',
            label: t('findings.views.mine'),
            href: savedViewHref({ assigned: 'me' }),
            count: openRows.filter(
                (row) => row.lifecycle.assignee?.id === currentUserId,
            ).length,
        },
        {
            id: 'new',
            label: t('findings.views.new'),
            href: savedViewHref({ new: '24h' }),
            count: openRows.filter((row) =>
                isFirstSeenWithinLastDay(row.lifecycle.firstSeenAt),
            ).length,
        },
    ];
    const hasOtherFilters =
        status !== 'open' ||
        needle !== '' ||
        severity !== 'all' ||
        category !== 'all' ||
        checkId !== 'all';
    const activeSavedViewId = hasOtherFilters
        ? undefined
        : isMine && !isNewOnly
          ? 'mine'
          : isNewOnly && !isMine
            ? 'new'
            : !isMine && !isNewOnly
              ? 'open'
              : undefined;
    // Keyboard triage (#277): j / k, Enter, x, a assign to me.
    const listShortcuts = useListShortcuts({
        rows: filtered,
        getRowId: findingKey,
        isEnabled: openFinding === null,
        onOpen: (row) => {
            setOpenKey(findingKey(row));
            syncEdit(findingKey(row));
        },
        onToggleSelect: (row) => {
            const key = findingKey(row);
            setSelected((current) =>
                current.includes(key)
                    ? current.filter((item) => item !== key)
                    : [...current, key],
            );
        },
        secondaryAction: {
            keys: 'a',
            description: t('findings.assignToMe'),
            isEnabled:
                canDismiss &&
                currentUserId !== null &&
                assignees.some((option) => option.id === currentUserId),
            handler: (row) =>
                router.put(
                    projectsRoutes.findings.assignee.url({
                        project: projectId,
                        finding: row.lifecycle.id,
                    }),
                    { assigned_user_id: currentUserId },
                    { preserveScroll: true, preserveState: true },
                ),
        },
    });
    const selectedFindingIds = rows
        .filter((row) => selected.includes(findingKey(row)))
        .map((row) => row.lifecycle.id);
    const canAssignToMe =
        canDismiss &&
        currentUserId !== null &&
        assignees.some((option) => option.id === currentUserId);
    const assignSelectionToMe = (): void => {
        router.put(
            projectsRoutes.findings.bulk.assignee.url(projectId),
            { ids: selectedFindingIds, assigned_user_id: currentUserId },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => setSelected([]),
            },
        );
    };
    const selectedFixes = selected
        .map((key) => fixes[key])
        .filter((fix): fix is FindingFix => fix !== undefined);

    const findingColumns: ColumnDef<LifecycleRow>[] = [
        dataTableSelectColumn<LifecycleRow>({
            selectAllLabel: t('a11y.selectAll'),
            getRowLabel: (row) => t('fix.select', { subject: row.subject }),
        }),
        {
            id: 'severityLabel',
            header: t('report.severityLabel'),
            cell: ({ row: tableRow }) => {
                const row = tableRow.original;

                return (
                    <>
                        <SeverityBadge severity={row.severity} />
                    </>
                );
            },
        },
        {
            id: 'subject',
            header: t('report.subject'),
            meta: { cellClassName: 'max-w-[16rem]' },
            cell: ({ row: tableRow }) => {
                const row = tableRow.original;

                return (
                    <>
                        <button
                            type="button"
                            className="block max-w-full truncate text-left font-medium underline-offset-4 hover:underline"
                            onClick={() => {
                                setOpenKey(findingKey(row));
                                syncEdit(findingKey(row));
                            }}
                        >
                            {detailText(row, 'package') ?? row.subject}
                        </button>
                        <div className="truncate text-xs text-muted-foreground">
                            {checkLabel(t, row.checkId, row.checkName)}
                        </div>
                    </>
                );
            },
        },
        {
            id: 'installed',
            header: t('report.installed'),
            meta: { cellClassName: 'font-mono text-xs' },
            cell: ({ row: tableRow }) => {
                const row = tableRow.original;

                return <>{detailText(row, 'lockedVersion') ?? '—'}</>;
            },
        },
        {
            id: 'fixedIn',
            header: t('report.fixedIn'),
            meta: { cellClassName: 'font-mono text-xs whitespace-normal' },
            cell: ({ row: tableRow }) => {
                const row = tableRow.original;

                return <>{detailList(row, 'fixedVersions') ?? '—'}</>;
            },
        },
        {
            id: 'message',
            header: t('report.message'),
            meta: { cellClassName: 'min-w-[16rem] whitespace-normal' },
            cell: ({ row: tableRow }) => {
                const row = tableRow.original;

                return (
                    <>
                        {row.message}
                        {row.evidenceUrl ? (
                            <a
                                href={row.evidenceUrl}
                                target="_blank"
                                rel="noreferrer noopener"
                                className="ml-1 inline-flex items-center gap-0.5 text-primary underline underline-offset-4"
                            >
                                {t('report.evidence')}
                                <ExternalLink className="size-3" aria-hidden />
                            </a>
                        ) : null}
                    </>
                );
            },
        },
        {
            id: 'fixHint',
            header: t('report.fixHint'),
            meta: {
                cellClassName:
                    'min-w-[14rem] font-mono text-xs whitespace-normal',
            },
            cell: ({ row: tableRow }) => {
                const row = tableRow.original;

                return <>{row.fixHint}</>;
            },
        },
        {
            id: 'assignee',
            header: t('assignee.column'),
            meta: { cellClassName: 'min-w-[8rem] text-sm' },
            cell: ({ row: tableRow }) => (
                <>{tableRow.original.lifecycle.assignee?.name ?? '—'}</>
            ),
        },
        {
            id: 'firstSeen',
            header: t('findings.firstSeen'),
            meta: { cellClassName: 'min-w-[10rem] whitespace-normal' },
            cell: ({ row: tableRow }) => {
                const row = tableRow.original;

                return (
                    <>
                        <time
                            dateTime={row.lifecycle.firstSeenAt}
                            title={formatDateTime(
                                row.lifecycle.firstSeenAt,
                                i18n.language,
                            )}
                            className="text-sm"
                        >
                            {relativeTime(
                                row.lifecycle.firstSeenAt,
                                i18n.language,
                            )}
                        </time>
                        <div className="mt-1 flex flex-wrap gap-1">
                            {row.lifecycle.resolvedAt !== null ? (
                                <Badge variant="success">
                                    {t('findings.fixedOn', {
                                        date: formatDateTime(
                                            row.lifecycle.resolvedAt,
                                            i18n.language,
                                        ),
                                    })}
                                </Badge>
                            ) : null}
                            <LifecycleBadges lifecycle={row.lifecycle} />
                        </div>
                        {row.lifecycle.status !== 'resolved' &&
                        latestReceivedAt !== null &&
                        Date.parse(row.lifecycle.lastSeenAt) <
                            Date.parse(latestReceivedAt) ? (
                            <div className="mt-1 text-xs text-muted-foreground">
                                {t('findings.notInLatest')}
                            </div>
                        ) : null}
                    </>
                );
            },
        },
        {
            id: 'actions',
            header: t('fix.actions'),
            meta: {
                headerClassName: 'w-[1%] text-right',
                cellClassName: 'text-right',
            },
            cell: ({ row: tableRow }) => {
                const row = tableRow.original;

                return (
                    <>
                        <FixActions fix={fixes[findingKey(row)]} />
                    </>
                );
            },
        },
    ];

    return (
        <Drawer
            direction="right"
            open={openFinding !== null}
            onOpenChange={(open) => {
                if (!open) {
                    setOpenKey(null);
                    syncClosed();
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
                        searchPlaceholder={t('report.searchFindings')}
                        selectedCount={selected.length}
                        onClearSelection={() => setSelected([])}
                        bulkActions={
                            <>
                                {canAssignToMe ? (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        data-test="bulk-assign-me"
                                        onClick={assignSelectionToMe}
                                    >
                                        <UserCheck
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                        {t('findings.assignToMe')}
                                    </Button>
                                ) : null}
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="text-ai-text"
                                    onClick={() =>
                                        void copyWithToast(
                                            combinedPrompt(selectedFixes),
                                        )
                                    }
                                >
                                    <Sparkles className="size-4" />
                                    {t('fix.copyPromptForSelected', {
                                        count: selectedFixes.length,
                                    })}
                                </Button>
                            </>
                        }
                    />
                }
                filtersRight={
                    selected.length > 0 ? null : (
                        <div className="flex flex-wrap items-center gap-1.5">
                            <ToggleGroup
                                type="single"
                                variant="outline"
                                value={status}
                                onValueChange={(value) => {
                                    if (value) {
                                        setStatus(value as FindingStatusFilter);
                                    }
                                }}
                                aria-label={t('findings.statusFilter')}
                            >
                                {(
                                    [
                                        'open',
                                        'dismissed',
                                        'resolved',
                                        'all',
                                    ] as const
                                ).map((item) => (
                                    <ToggleGroupItem
                                        key={item}
                                        value={item}
                                        className="px-2.5"
                                    >
                                        {t(`findings.status.${item}`)}
                                    </ToggleGroupItem>
                                ))}
                            </ToggleGroup>
                            <AssignedToMeToggle
                                isPressed={isMine}
                                onPressedChange={setIsMine}
                            />
                            <Select
                                value={severity}
                                onValueChange={(value) =>
                                    setSeverity(value as Severity | 'all')
                                }
                            >
                                <SelectTrigger
                                    aria-label={t('report.severityLabel')}
                                    className="w-[8.5rem]"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent align="end">
                                    <SelectItem value="all">
                                        {t('report.allSeverities')}
                                    </SelectItem>
                                    {SEVERITIES.map((item) => (
                                        <SelectItem key={item} value={item}>
                                            {t(`report.severity.${item}`)}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Select
                                value={category}
                                onValueChange={(value) =>
                                    setCategory(value as CheckCategory | 'all')
                                }
                            >
                                <SelectTrigger
                                    aria-label={t('checks.categoryLabel')}
                                    className="w-[10rem]"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent align="end">
                                    <SelectItem value="all">
                                        {t('checks.allCategories')}
                                    </SelectItem>
                                    {categories.map((item) => (
                                        <SelectItem key={item} value={item}>
                                            {t(`checks.category.${item}`)}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Select value={checkId} onValueChange={setCheckId}>
                                <SelectTrigger
                                    aria-label={t('report.check')}
                                    className="w-[12rem]"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent align="end">
                                    <SelectItem value="all">
                                        {t('report.allChecks')}
                                    </SelectItem>
                                    {checks.map((check) => (
                                        <SelectItem
                                            key={check.id}
                                            value={check.id}
                                        >
                                            {check.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    )
                }
            >
                {filtered.length === 0 ? (
                    <PageEmptyState
                        icon={hasActiveFilters ? SearchX : ListChecks}
                        title={
                            <EmptyStatus>
                                {hasActiveFilters
                                    ? t('report.noMatchingFindings')
                                    : status === 'resolved'
                                      ? t('findings.noResolved')
                                      : t('report.noIssues')}
                            </EmptyStatus>
                        }
                        description={
                            hasActiveFilters
                                ? t('report.noMatchingFindingsDescription')
                                : status === 'resolved'
                                  ? t('findings.noResolvedDescription')
                                  : t('report.noIssuesDescription')
                        }
                        action={
                            hasActiveFilters ? (
                                <Button
                                    type="button"
                                    onClick={() => {
                                        setSearch('');
                                        setIsMine(false);
                                        setIsNewOnly(false);
                                        patchLocationQuery({ new: null });
                                        setStatus('open');
                                        setSeverity('all');
                                        setCategory('all');
                                        setCheckId('all');
                                    }}
                                >
                                    {t('common.clearFilters')}
                                </Button>
                            ) : undefined
                        }
                    />
                ) : (
                    <DataTable
                        columns={findingColumns}
                        data={filtered}
                        getRowId={findingKey}
                        getRowProps={listShortcuts.getActiveRowProps}
                        rowSelection={Object.fromEntries(
                            selected.map((key) => [key, true]),
                        )}
                        onRowSelectionChange={(updater) => {
                            const current = Object.fromEntries(
                                selected.map((key) => [key, true]),
                            );
                            const next =
                                typeof updater === 'function'
                                    ? updater(current)
                                    : updater;
                            setSelected(
                                Object.keys(next).filter((key) => next[key]),
                            );
                        }}
                    />
                )}
            </PageLayout>
            {openFinding ? (
                <FindingDrawer
                    finding={openFinding}
                    fix={fixes[findingKey(openFinding)]}
                    lifecycle={openFinding.lifecycle}
                    projectId={projectId}
                    canDismiss={canDismiss}
                    assignees={assignees}
                />
            ) : null}
        </Drawer>
    );
}

function detailText(row: IssueRow, key: string): string | null {
    const value = row.details?.[key];

    return typeof value === 'string' && value !== '' ? value : null;
}

function detailList(row: IssueRow, key: string): string | null {
    const value = row.details?.[key];

    return Array.isArray(value) && value.length > 0
        ? value.filter((item) => typeof item === 'string').join(', ')
        : null;
}
