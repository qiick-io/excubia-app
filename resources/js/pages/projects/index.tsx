import { Head, router } from '@inertiajs/react';
import {
    FolderKanban,
    KeyRound,
    Plus,
    SearchX,
    ShieldOff,
    Trash2,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { DataTableToolbar } from '@/components/admin/data-table-toolbar';
import { HeaderIconButton } from '@/components/admin/header-icon-button';
import { NameSlugCell } from '@/components/admin/name-slug-cell';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { ConfirmDestructiveDialog } from '@/components/confirm-destructive-dialog';
import { EmptyStatus } from '@/components/empty-status';
import {
    hasTablePaginationFooter,
    PageLayout,
    TablePaginationFooter,
    TablePanel,
} from '@/components/layout/page-layout';
import { ProjectFormDrawer } from '@/components/projects/project-form-drawer';
import { PushTokenDialog } from '@/components/projects/push-token-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Drawer } from '@/components/ui/drawer';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
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
}: {
    projects: LaravelPaginated<ProjectRow> | Paginated<ProjectRow>;
    filters?: { search?: string };
}) {
    const projects = normalizePaginated(projectsProp);
    const { t } = useTranslation();
    const { can } = useCan();
    const canManage = can(PermissionEnum.ProjectsManage);
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
        if (!canManage) {
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

    return (
        <AppLayout
            breadcrumbs={breadcrumbs}
            headerActions={
                canManage ? (
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
                                ) : canManage ? (
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
                        <TablePanel
                            footer={
                                hasTablePaginationFooter(projects) ? (
                                    <TablePaginationFooter
                                        pagination={projects}
                                    />
                                ) : undefined
                            }
                        >
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>
                                            {t('common.name')}
                                        </TableHead>
                                        <TableHead>
                                            {t('projects.status')}
                                        </TableHead>
                                        <TableHead>
                                            {t('projects.lastReport')}
                                        </TableHead>
                                        {canManage ? (
                                            <TableHead className="w-[1%] text-right">
                                                {t('projects.actions')}
                                            </TableHead>
                                        ) : null}
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {projects.data.map((project) => (
                                        <TableRow
                                            key={project.id}
                                            className={
                                                canManage
                                                    ? 'cursor-pointer'
                                                    : undefined
                                            }
                                            onClick={() => openDrawer(project)}
                                        >
                                            <TableCell className="max-w-[16rem]">
                                                <NameSlugCell
                                                    name={project.name}
                                                    slug={project.slug}
                                                />
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex flex-wrap gap-1">
                                                    {project.is_enabled ? (
                                                        <Badge
                                                            variant="success"
                                                            className="gap-1.5"
                                                        >
                                                            <span
                                                                className="size-1.5 rounded-full bg-success"
                                                                aria-hidden
                                                            />
                                                            {t(
                                                                'projects.enabled',
                                                            )}
                                                        </Badge>
                                                    ) : (
                                                        <Badge
                                                            variant="muted"
                                                            className="gap-1.5"
                                                        >
                                                            <span
                                                                className="size-1.5 rounded-full bg-muted-foreground"
                                                                aria-hidden
                                                            />
                                                            {t(
                                                                'projects.disabled',
                                                            )}
                                                        </Badge>
                                                    )}
                                                    {project.has_push_token ? null : (
                                                        <Badge variant="warning">
                                                            {t(
                                                                'projects.noToken',
                                                            )}
                                                        </Badge>
                                                    )}
                                                </div>
                                            </TableCell>
                                            <TableCell
                                                className={
                                                    project.is_stale
                                                        ? 'text-muted-foreground tabular-nums'
                                                        : 'tabular-nums'
                                                }
                                            >
                                                {project.last_report_at
                                                    ? new Date(
                                                          project.last_report_at,
                                                      ).toLocaleString()
                                                    : t('projects.never')}
                                            </TableCell>
                                            {canManage ? (
                                                <TableCell
                                                    className="text-right whitespace-nowrap"
                                                    onClick={(event) =>
                                                        event.stopPropagation()
                                                    }
                                                >
                                                    <HeaderIconButton
                                                        type="button"
                                                        size="icon-sm"
                                                        label={t(
                                                            'projects.token.rotate',
                                                        )}
                                                        onClick={() =>
                                                            setPending({
                                                                kind: 'rotate',
                                                                project,
                                                            })
                                                        }
                                                    >
                                                        <KeyRound className="size-3.5" />
                                                    </HeaderIconButton>
                                                    {project.has_push_token ? (
                                                        <HeaderIconButton
                                                            type="button"
                                                            size="icon-sm"
                                                            label={t(
                                                                'projects.token.revoke',
                                                            )}
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
                                                    <HeaderIconButton
                                                        type="button"
                                                        variant="destructive-ghost"
                                                        size="icon-sm"
                                                        label={t(
                                                            'common.delete',
                                                        )}
                                                        onClick={() =>
                                                            setPending({
                                                                kind: 'delete',
                                                                project,
                                                            })
                                                        }
                                                    >
                                                        <Trash2 className="size-3.5" />
                                                    </HeaderIconButton>
                                                </TableCell>
                                            ) : null}
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </TablePanel>
                    )}
                </PageLayout>

                <ProjectFormDrawer
                    editing={editing}
                    open={drawerOpen}
                    readOnly={!canManage}
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
