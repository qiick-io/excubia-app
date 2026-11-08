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
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Drawer } from '@/components/ui/drawer';
import { PermissionEnum } from '@/enums/permission-enum';
import { useCan } from '@/hooks/use-can';
import { useDebouncedServerSearch } from '@/hooks/use-debounced-server-search';
import AppLayout from '@/layouts/app-layout';
import { listPerPageQuery } from '@/lib/list-per-page';
import { normalizePaginated } from '@/lib/pagination';
import type { LaravelPaginated } from '@/lib/pagination';
import { toast } from '@/lib/toast';
import projectsRoutes from '@/routes/projects';
import type { BreadcrumbItem, Paginated, ProjectRow } from '@/types';

type PendingAction = {
    kind: 'delete' | 'rotate' | 'revoke';
    project: ProjectRow;
};

/**
 * Registered projects with search, pagination, a create / edit drawer and push token
 * actions. Rotating, revoking and deleting ask for confirmation first.
 */
export default function ProjectsIndex({
    projects: projectsProp,
    filters = {},
    canChangeVisibility = false,
}: {
    projects: LaravelPaginated<ProjectRow> | Paginated<ProjectRow>;
    filters?: { search?: string };
    canChangeVisibility?: boolean;
}) {
    const projects = normalizePaginated(projectsProp);
    const { t } = useTranslation();
    const { can } = useCan();
    const canCreate = can(PermissionEnum.CanCreateProjects);
    const canEdit = can(PermissionEnum.CanEditProjects);
    const canDelete = can(PermissionEnum.CanDeleteProjects);
    const hasRowActions = canEdit || canDelete;
    const [search, setSearch] = useState(filters.search ?? '');
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

    const onDebouncedSearch = useCallback(
        (value: string): void => {
            router.get(
                projectsRoutes.index.url({
                    query: {
                        search: value || undefined,
                        per_page: listPerPageQuery(projects.per_page),
                    },
                }),
                {},
                { preserveState: true, preserveScroll: true },
            );
        },
        [projects.per_page],
    );

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
            id: 'name',
            header: t('common.name'),
            meta: { cellClassName: 'max-w-[16rem]' },
            cell: ({ row }) => (
                // A real link too, for the keyboard and "open in new tab".
                <Link
                    href={projectsRoutes.show.url(row.original.slug)}
                    className="block rounded-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    onClick={(event) => event.stopPropagation()}
                >
                    <NameSlugCell
                        name={row.original.name}
                        slug={row.original.slug}
                    />
                </Link>
            ),
        },
        {
            id: 'status',
            header: t('projects.status'),
            cell: ({ row }) => {
                const project = row.original;

                return (
                    <div className="flex flex-wrap gap-1">
                        {project.is_enabled ? (
                            <Badge variant="success" className="gap-1.5">
                                <span
                                    className="size-1.5 rounded-full bg-success"
                                    aria-hidden
                                />
                                {t('projects.enabled')}
                            </Badge>
                        ) : (
                            <Badge variant="muted" className="gap-1.5">
                                <span
                                    className="size-1.5 rounded-full bg-muted-foreground"
                                    aria-hidden
                                />
                                {t('projects.disabled')}
                            </Badge>
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
                            <Badge variant="warning">
                                {t('projects.noToken')}
                            </Badge>
                        )}
                    </div>
                );
            },
        },
        {
            id: 'last_report_at',
            header: t('projects.lastReport'),
            cell: ({ row }) => (
                <span
                    className={
                        row.original.is_stale
                            ? 'text-muted-foreground tabular-nums'
                            : 'tabular-nums'
                    }
                >
                    {row.original.last_report_at
                        ? new Date(row.original.last_report_at).toLocaleString()
                        : t('projects.never')}
                </span>
            ),
        },
        ...(hasRowActions
            ? [
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
              ]
            : []),
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
                                            onDebouncedSearch('');
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
