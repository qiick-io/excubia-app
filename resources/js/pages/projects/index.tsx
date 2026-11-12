import type { ColumnDef } from '@tanstack/react-table';
import { Head, Link, router } from '@inertiajs/react';
import {
    FolderKanban,
    KeyRound,
    Pencil,
    Plus,
    SearchX,
    ShieldOff,
    Trash2,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { DataTable } from '@/components/admin/data-table';
import type { DataTableSort } from '@/components/admin/data-table';
import { DataTableColumnHeader } from '@/components/admin/data-table-column-header';
import { ProjectPinButton } from '@/components/project-pin-button';
import { ButtonGroup } from '@/components/ui/button-group';
import { DataTableToolbar } from '@/components/admin/data-table-toolbar';
import { HeaderIconButton } from '@/components/admin/header-icon-button';
import { NameSlugCell } from '@/components/admin/name-slug-cell';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { ConfirmDestructiveDialog } from '@/components/confirm-destructive-dialog';
import { EmptyStatus } from '@/components/empty-status';
import { PageLayout } from '@/components/layout/page-layout';
import { ProjectFormDrawer } from '@/components/projects/project-form-drawer';
import { PushTokenDialog } from '@/components/projects/push-token-dialog';
import { CHECK_STATUS_VARIANTS } from '@/components/reports/check-details';
import { SeverityCounts } from '@/components/reports/severity-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Drawer } from '@/components/ui/drawer';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { PermissionEnum } from '@/enums/permission-enum';
import { useCan } from '@/hooks/use-can';
import { useDebouncedServerSearch } from '@/hooks/use-debounced-server-search';
import AppLayout from '@/layouts/app-layout';
import { listPerPageQuery } from '@/lib/list-per-page';
import { normalizePaginated } from '@/lib/pagination';
import type { LaravelPaginated } from '@/lib/pagination';
import { relativeTime } from '@/lib/report-format';
import { toast } from '@/lib/toast';
import { cn } from '@/lib/utils';
import projectsRoutes from '@/routes/projects';
import type { BreadcrumbItem, Paginated, ProjectRow } from '@/types';

/** Server sort fields of `ProjectListService::SORTABLE_COLUMNS` shown as column headers. */
const PROJECT_SORT_FIELDS = [
    'name',
    'health',
    'open_findings',
    'last_report_at',
] as const;

type ProjectSortField = (typeof PROJECT_SORT_FIELDS)[number];

type SortDirection = 'asc' | 'desc';

type Filters = {
    search?: string;
    sort?: string;
    direction?: SortDirection;
};

function isProjectSortField(value: string): value is ProjectSortField {
    return (PROJECT_SORT_FIELDS as readonly string[]).includes(value);
}

type PendingAction = {
    kind: 'delete' | 'rotate' | 'revoke';
    project: ProjectRow;
};

/**
 * Registered projects with search, pagination, a create / edit drawer and push token
 * actions. Rotating, revoking and deleting ask for confirmation first. Each row shows the
 * project's health, open findings and failed checks (#293), so the list says which project
 * to open first; health, open findings, name and last report sort on the server.
 */
export default function ProjectsIndex({
    projects: projectsProp,
    filters = {},
    canChangeVisibility = false,
}: {
    projects: LaravelPaginated<ProjectRow> | Paginated<ProjectRow>;
    filters?: Filters;
    canChangeVisibility?: boolean;
}) {
    const projects = normalizePaginated(projectsProp);
    const { t, i18n } = useTranslation();
    const { can } = useCan();
    const canCreate = can(PermissionEnum.CanCreateProjects);
    const canEdit = can(PermissionEnum.CanEditProjects);
    const canDelete = can(PermissionEnum.CanDeleteProjects);
    const [search, setSearch] = useState(filters.search ?? '');
    const sort: ProjectSortField =
        filters.sort !== undefined && isProjectSortField(filters.sort)
            ? filters.sort
            : 'name';
    const direction: SortDirection = filters.direction ?? 'asc';
    const [drawerOpen, setDrawerOpen] = useState(false);
    const [editing, setEditing] = useState<ProjectRow | null>(null);
    const [pending, setPending] = useState<PendingAction | null>(null);
    const [confirming, setConfirming] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = useMemo(
        () => [
            { title: t('projects.title'), href: projectsRoutes.index.url() },
        ],
        [t],
    );

    useEffect(() => {
        setSearch(filters.search ?? '');
    }, [filters.search]);

    const visit = useCallback(
        (
            overrides: {
                search?: string;
                sort?: ProjectSortField;
                direction?: SortDirection;
            } = {},
        ): void => {
            const nextSearch =
                overrides.search !== undefined ? overrides.search : search;

            router.get(
                projectsRoutes.index.url({
                    query: {
                        search: nextSearch || undefined,
                        sort: overrides.sort ?? sort,
                        direction: overrides.direction ?? direction,
                        per_page: listPerPageQuery(projects.per_page),
                    },
                }),
                {},
                { preserveState: true, preserveScroll: true },
            );
        },
        [direction, projects.per_page, search, sort],
    );

    const onDebouncedSearch = useCallback(
        (value: string): void => visit({ search: value }),
        [visit],
    );

    const changeSort = (nextSort: DataTableSort): void => {
        if (isProjectSortField(nextSort.id)) {
            visit({ sort: nextSort.id, direction: nextSort.direction });
        }
    };

    useDebouncedServerSearch(search, filters.search ?? '', onDebouncedSearch);

    const openDrawer = (project: ProjectRow | null): void => {
        if (project ? !canEdit : !canCreate) {
            return;
        }

        setEditing(project);
        setDrawerOpen(true);
    };

    const closeDrawer = (): void => {
        setDrawerOpen(false);
        setEditing(null);
    };

    const confirmPending = (): void => {
        if (pending === null) {
            return;
        }

        const options = {
            preserveScroll: true,
            onFinish: () => setConfirming(false),
            onSuccess: () => setPending(null),
            onError: (errors: Record<string, string>) => {
                setPending(null);
                toast.error(Object.values(errors)[0]);
            },
        };

        setConfirming(true);

        if (pending.kind === 'delete') {
            router.delete(
                projectsRoutes.destroy.url(pending.project.id),
                options,
            );
        } else if (pending.kind === 'rotate') {
            router.post(
                projectsRoutes.token.rotate.url(pending.project.id),
                {},
                options,
            );
        } else {
            router.delete(
                projectsRoutes.token.revoke.url(pending.project.id),
                options,
            );
        }
    };

    const hasActiveFilters = search.trim() !== '';
    const isListEmpty = projects.data.length === 0;
    const hideListChrome = isListEmpty && !hasActiveFilters;

    const columns: ColumnDef<ProjectRow>[] = [
        {
            accessorKey: 'name',
            enableSorting: true,
            header: ({ column }) => (
                <DataTableColumnHeader
                    column={column}
                    title={t('projects.columns.project')}
                />
            ),
            meta: { cellClassName: 'max-w-[16rem]' },
            cell: ({ row }) => {
                const project = row.original;

                return (
                    // A real link too, for the keyboard and "open in new tab".
                    <Link
                        href={projectsRoutes.show.url(project.slug)}
                        className="block rounded-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        onClick={(event) => event.stopPropagation()}
                    >
                        {/* ponytail: the avatar / logo joins this cell with #287. */}
                        <NameSlugCell
                            name={project.name}
                            slug={project.environment ?? project.slug}
                            badge={<ProjectStateBadges project={project} />}
                        />
                    </Link>
                );
            },
        },
        {
            accessorKey: 'health',
            enableSorting: true,
            header: ({ column }) => (
                <DataTableColumnHeader
                    column={column}
                    title={t('projects.columns.health')}
                />
            ),
            cell: ({ row }) =>
                row.original.health ? (
                    <Badge variant={CHECK_STATUS_VARIANTS[row.original.health]}>
                        {t(`report.status.${row.original.health}`)}
                    </Badge>
                ) : (
                    <span className="text-sm text-muted-foreground">—</span>
                ),
        },
        {
            accessorKey: 'open_findings',
            enableSorting: true,
            header: ({ column }) => (
                <DataTableColumnHeader
                    column={column}
                    title={t('projects.columns.openFindings')}
                />
            ),
            cell: ({ row }) => {
                const openFindings = row.original.open_findings;

                return openFindings &&
                    Object.values(openFindings).some((count) => count > 0) ? (
                    <SeverityCounts counts={openFindings} />
                ) : (
                    <span className="text-sm text-muted-foreground">—</span>
                );
            },
        },
        {
            id: 'failed_checks',
            header: t('projects.columns.failedChecks'),
            cell: ({ row }) => {
                const checks = row.original.checks;

                return checks ? (
                    <span
                        className={cn(
                            'text-sm tabular-nums',
                            checks.failed > 0
                                ? 'font-medium text-destructive'
                                : 'text-muted-foreground',
                        )}
                        aria-label={t('projects.failedChecksLabel', checks)}
                    >
                        {t('projects.checksRatio', checks)}
                    </span>
                ) : (
                    <span className="text-sm text-muted-foreground">—</span>
                );
            },
        },
        {
            accessorKey: 'last_report_at',
            enableSorting: true,
            header: ({ column }) => (
                <DataTableColumnHeader
                    column={column}
                    title={t('projects.lastReport')}
                />
            ),
            cell: ({ row }) => {
                const project = row.original;

                return (
                    <div className="flex items-center gap-1.5">
                        {project.last_report_at ? (
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <time
                                        dateTime={project.last_report_at}
                                        tabIndex={0}
                                        className="rounded-sm text-sm whitespace-nowrap tabular-nums focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    >
                                        {relativeTime(
                                            project.last_report_at,
                                            i18n.language,
                                        )}
                                    </time>
                                </TooltipTrigger>
                                <TooltipContent>
                                    {new Date(
                                        project.last_report_at,
                                    ).toLocaleString(i18n.language)}
                                </TooltipContent>
                            </Tooltip>
                        ) : (
                            <span className="text-sm text-muted-foreground">
                                {t('projects.never')}
                            </span>
                        )}
                        {project.is_stale ? (
                            <Badge variant="muted">{t('projects.stale')}</Badge>
                        ) : null}
                    </div>
                );
            },
        },
        {
            id: 'actions',
            header: t('projects.actions'),
            meta: {
                headerClassName: 'w-[1%] text-right',
                cellClassName: 'text-right',
                isRowClickIgnored: true,
            },
            cell: ({ row }) => {
                const project = row.original;

                return (
                    <ButtonGroup
                        aria-label={t('projects.actions')}
                        className="ml-auto"
                    >
                        {/* Everyone may pin a project they see (#273). */}
                        <ProjectPinButton project={project} />
                        {canEdit ? (
                            <HeaderIconButton
                                type="button"
                                variant="outline"
                                size="icon-sm"
                                label={t('projects.editProject')}
                                onClick={() => openDrawer(project)}
                            >
                                <Pencil className="size-3.5" />
                            </HeaderIconButton>
                        ) : null}
                        {canEdit && !project.is_system ? (
                            <HeaderIconButton
                                type="button"
                                variant="outline"
                                size="icon-sm"
                                label={t('projects.token.rotate')}
                                onClick={() =>
                                    setPending({
                                        kind: 'rotate',
                                        project,
                                    })
                                }
                            >
                                <KeyRound className="size-3.5" />
                            </HeaderIconButton>
                        ) : null}
                        {canEdit &&
                        !project.is_system &&
                        project.has_push_token ? (
                            <HeaderIconButton
                                type="button"
                                variant="outline"
                                size="icon-sm"
                                label={t('projects.token.revoke')}
                                onClick={() =>
                                    setPending({
                                        kind: 'revoke',
                                        project,
                                    })
                                }
                            >
                                <ShieldOff className="size-3.5" />
                            </HeaderIconButton>
                        ) : null}
                        {canDelete && !project.is_system ? (
                            <HeaderIconButton
                                type="button"
                                variant="outline"
                                size="icon-sm"
                                className="text-destructive hover:text-destructive"
                                label={t('common.delete')}
                                onClick={() =>
                                    setPending({
                                        kind: 'delete',
                                        project,
                                    })
                                }
                            >
                                <Trash2 className="size-3.5" />
                            </HeaderIconButton>
                        ) : null}
                    </ButtonGroup>
                );
            },
        } satisfies ColumnDef<ProjectRow>,
    ];

    return (
        <AppLayout
            breadcrumbs={breadcrumbs}
            headerActions={
                canCreate ? (
                    <Button type="button" onClick={() => openDrawer(null)}>
                        <Plus className="mr-1 size-4" />
                        {t('projects.newProject')}
                    </Button>
                ) : undefined
            }
        >
            <Head title={t('projects.title')} />

            <Drawer
                direction="right"
                open={drawerOpen}
                onOpenChange={(open) =>
                    open ? setDrawerOpen(true) : closeDrawer()
                }
            >
                <PageLayout
                    filters={
                        hideListChrome ? undefined : (
                            <DataTableToolbar
                                search={search}
                                onSearchChange={setSearch}
                                searchPlaceholder={t(
                                    'projects.searchPlaceholder',
                                )}
                            />
                        )
                    }
                >
                    {isListEmpty ? (
                        <PageEmptyState
                            icon={hasActiveFilters ? SearchX : FolderKanban}
                            title={
                                <EmptyStatus>
                                    {hasActiveFilters
                                        ? t('emptyState.projects.filteredTitle')
                                        : t('emptyState.projects.title')}
                                </EmptyStatus>
                            }
                            description={
                                hasActiveFilters
                                    ? t(
                                          'emptyState.projects.filteredDescription',
                                      )
                                    : t('emptyState.projects.description')
                            }
                            action={
                                hasActiveFilters ? (
                                    <Button
                                        type="button"
                                        onClick={() => {
                                            setSearch('');
                                            visit({ search: '' });
                                        }}
                                    >
                                        {t('common.clearFilters')}
                                    </Button>
                                ) : canCreate ? (
                                    <Button
                                        type="button"
                                        onClick={() => openDrawer(null)}
                                    >
                                        <Plus className="size-4" />
                                        {t('emptyState.projects.cta')}
                                    </Button>
                                ) : undefined
                            }
                        />
                    ) : (
                        <DataTable
                            columns={columns}
                            data={projects.data}
                            getRowId={(project) => String(project.id)}
                            sort={{ id: sort, direction }}
                            onSortChange={changeSort}
                            pagination={projects}
                            onRowClick={(project) =>
                                router.visit(
                                    projectsRoutes.show.url(project.slug),
                                )
                            }
                            getRowProps={() => ({
                                className: 'cursor-pointer',
                            })}
                        />
                    )}
                </PageLayout>

                <ProjectFormDrawer
                    editing={editing}
                    open={drawerOpen}
                    readOnly={editing ? !canEdit : !canCreate}
                    canChangeVisibility={canChangeVisibility}
                    onCancel={closeDrawer}
                    onSuccess={closeDrawer}
                />
            </Drawer>

            <ConfirmDestructiveDialog
                open={pending !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setPending(null);
                    }
                }}
                title={t(`projects.confirm.${pending?.kind ?? 'delete'}Title`, {
                    name: pending?.project.name ?? '',
                })}
                description={t(
                    `projects.confirm.${pending?.kind ?? 'delete'}Description`,
                )}
                confirmLabel={t(
                    `projects.confirm.${pending?.kind ?? 'delete'}Confirm`,
                )}
                confirming={confirming}
                onConfirm={confirmPending}
            />

            <PushTokenDialog />
        </AppLayout>
    );
}

/**
 * What sets a project apart from an enabled, everyone-visible one with a push token: disabled,
 * the system project, admins only, no token. Next to the name, so the list needs no status
 * column.
 */
function ProjectStateBadges({ project }: { project: ProjectRow }) {
    const { t } = useTranslation();

    return (
        <>
            {project.is_enabled ? null : (
                <Badge variant="muted">{t('projects.disabled')}</Badge>
            )}
            {project.is_system ? (
                <Badge variant="info">{t('projects.system')}</Badge>
            ) : null}
            {project.visibility === 'admins' ? (
                <Badge variant="outline">
                    {t('projects.visibility.admins')}
                </Badge>
            ) : null}
            {project.has_push_token || project.is_system ? null : (
                <Badge variant="warning">{t('projects.noToken')}</Badge>
            )}
        </>
    );
}
