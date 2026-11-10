import type { ColumnDef } from '@tanstack/react-table';
import { Head, router } from '@inertiajs/react';
import { ArrowDownAZ, ArrowUpAZ, Plus, Trash2 } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { DataTable } from '@/components/admin/data-table';
import type { DataTableSort } from '@/components/admin/data-table';
import { DataTableColumnHeader } from '@/components/admin/data-table-column-header';
import { ButtonGroup } from '@/components/ui/button-group';
import { DataTableToolbar } from '@/components/admin/data-table-toolbar';
import { GroupFormDrawer } from '@/components/admin/group-form-drawer';
import { HeaderIconButton } from '@/components/admin/header-icon-button';
import { NameSlugCell } from '@/components/admin/name-slug-cell';
import { ConfirmDestructiveDialog } from '@/components/confirm-destructive-dialog';
import { PageLayout } from '@/components/layout/page-layout';
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
import { PermissionEnum } from '@/enums/permission-enum';
import { useCan } from '@/hooks/use-can';
import { useDebouncedServerSearch } from '@/hooks/use-debounced-server-search';
import { useDrawerDeepLink } from '@/hooks/use-drawer-deep-link';
import AppLayout from '@/layouts/app-layout';
import { listPerPageQuery } from '@/lib/list-per-page';
import { normalizePaginated } from '@/lib/pagination';
import type { LaravelPaginated } from '@/lib/pagination';
import { toast } from '@/lib/toast';
import groupsRoutes from '@/routes/groups';
import type {
    AdminGroupRow,
    AdminRoleRef,
    BreadcrumbItem,
    Paginated,
} from '@/types';

type GroupSortField = 'name' | 'created_at' | 'updated_at';
type GroupSortDirection = 'asc' | 'desc';

const GROUP_SORT_FIELDS: GroupSortField[] = [
    'name',
    'created_at',
    'updated_at',
];

function isGroupSortField(value: string): value is GroupSortField {
    return (GROUP_SORT_FIELDS as string[]).includes(value);
}

type Filters = {
    search?: string;
    sort?: GroupSortField;
    direction?: GroupSortDirection;
};

/**
 * Admin groups list with search, sort, pagination and a create/edit drawer.
 */
export default function AdminGroupsIndex({
    groups: groupsProp,
    filters = {},
    roles,
    projectGroups = [],
    canChangeProjectScope = false,
}: {
    groups: LaravelPaginated<AdminGroupRow> | Paginated<AdminGroupRow>;
    filters?: Filters;
    roles: AdminRoleRef[];
    /** Project groups a group may be scoped to (#253). */
    projectGroups?: { id: number; name: string }[];
    canChangeProjectScope?: boolean;
}) {
    const groups = normalizePaginated(groupsProp);
    const { t } = useTranslation();

    const groupSortFields: { value: GroupSortField; label: string }[] = [
        { value: 'name', label: t('common.name') },
        { value: 'created_at', label: t('common.created') },
        { value: 'updated_at', label: t('common.updated') },
    ];
    const { can } = useCan();
    const [search, setSearch] = useState(filters.search ?? '');
    const [sort, setSort] = useState<GroupSortField>(filters.sort ?? 'name');
    const [direction, setDirection] = useState<GroupSortDirection>(
        filters.direction ?? 'asc',
    );
    const [drawerOpen, setDrawerOpen] = useState(false);
    const [editing, setEditing] = useState<AdminGroupRow | null>(null);
    const [pendingDeleteGroup, setPendingDeleteGroup] =
        useState<AdminGroupRow | null>(null);
    const [deleting, setDeleting] = useState(false);

    const deepLink = useDrawerDeepLink({
        onEdit: (id) => {
            const row = groups.data.find((group) => String(group.id) === id);

            if (!row?.can_update) {
                return;
            }

            setEditing(row);
            setDrawerOpen(true);
        },
        onNew: () => {
            if (!can(PermissionEnum.CanCreateGroups)) {
                return;
            }

            setEditing(null);
            setDrawerOpen(true);
        },
    });

    const breadcrumbs: BreadcrumbItem[] = useMemo(
        () => [{ title: t('groups.title'), href: groupsRoutes.index.url() }],
        [t],
    );

    const visit = useCallback(
        (overrides: Partial<Filters> = {}) => {
            const nextSearch =
                overrides.search !== undefined ? overrides.search : search;
            const nextSort = overrides.sort ?? sort;
            const nextDirection = overrides.direction ?? direction;

            router.get(
                groupsRoutes.index.url({
                    query: {
                        search: nextSearch || undefined,
                        sort: nextSort,
                        direction: nextDirection,
                        per_page: listPerPageQuery(groups.per_page),
                    },
                }),
                {},
                { preserveState: true, preserveScroll: true },
            );
        },
        [direction, groups.per_page, search, sort],
    );

    useEffect(() => {
        setSearch(filters.search ?? '');
        setSort(filters.sort ?? 'name');
        setDirection(filters.direction ?? 'asc');
    }, [filters.search, filters.sort, filters.direction]);

    const onDebouncedSearch = useCallback(
        (value: string): void => {
            visit({ search: value || undefined });
        },
        [visit],
    );

    useDebouncedServerSearch(search, filters.search ?? '', onDebouncedSearch);

    const openCreate = (): void => {
        setEditing(null);
        setDrawerOpen(true);
        deepLink.syncNew();
    };

    const openEdit = (group: AdminGroupRow): void => {
        if (!group.can_update) {
            return;
        }

        setEditing(group);
        setDrawerOpen(true);
        deepLink.syncEdit(group.id);
    };

    const handleDrawerOpenChange = (open: boolean): void => {
        if (open) {
            setDrawerOpen(true);

            return;
        }

        setDrawerOpen(false);
        setEditing(null);
        deepLink.syncClosed();
    };

    const changeSort = (nextSort: DataTableSort): void => {
        if (!isGroupSortField(nextSort.id)) {
            return;
        }

        setSort(nextSort.id);
        setDirection(nextSort.direction);
        visit({ sort: nextSort.id, direction: nextSort.direction });
    };

    const columns: ColumnDef<AdminGroupRow>[] = [
        {
            accessorKey: 'name',
            enableSorting: true,
            meta: { cellClassName: 'max-w-[16rem]' },
            header: ({ column }) => (
                <DataTableColumnHeader
                    column={column}
                    title={t('groups.columns.name')}
                />
            ),
            cell: ({ row }) => (
                <NameSlugCell
                    name={row.original.name}
                    slug={row.original.slug}
                />
            ),
        },
        {
            id: 'roles',
            header: t('groups.columns.roles'),
            cell: ({ row }) => (
                <div className="flex flex-wrap gap-1">
                    {row.original.roles.map((role) => (
                        <Badge key={role.id} variant="secondary">
                            {role.name}
                        </Badge>
                    ))}
                </div>
            ),
        },
        {
            id: 'members',
            header: t('groups.columns.members'),
            meta: {
                headerClassName: 'text-right',
                cellClassName: 'text-right tabular-nums',
            },
            cell: ({ row }) => row.original.users_count ?? '—',
        },
        {
            id: 'actions',
            header: t('groups.columns.actions'),
            meta: {
                headerClassName: 'w-[1%] text-right',
                cellClassName: 'text-right',
                isRowClickIgnored: true,
            },
            cell: ({ row }) =>
                row.original.can_update ? (
                    <ButtonGroup
                        aria-label={t('groups.columns.actions')}
                        className="ml-auto"
                    >
                        <HeaderIconButton
                            type="button"
                            variant="outline"
                            size="icon-sm"
                            className="text-destructive hover:text-destructive"
                            label={t('common.delete')}
                            onClick={() => setPendingDeleteGroup(row.original)}
                        >
                            <Trash2 className="size-3.5" />
                        </HeaderIconButton>
                    </ButtonGroup>
                ) : null,
        },
    ];

    return (
        <AppLayout
            breadcrumbs={breadcrumbs}
            headerActions={
                can(PermissionEnum.CanCreateGroups) ? (
                    <Button type="button" onClick={openCreate}>
                        <Plus className="mr-1 size-4" />
                        {t('groups.newGroup')}
                    </Button>
                ) : undefined
            }
        >
            <Head title={t('groups.title')} />

            <Drawer
                direction="right"
                open={drawerOpen}
                onOpenChange={handleDrawerOpenChange}
            >
                <PageLayout
                    filters={
                        <DataTableToolbar
                            search={search}
                            onSearchChange={setSearch}
                            searchPlaceholder={t('groups.searchPlaceholder')}
                        />
                    }
                    filtersRight={
                        <div className="flex items-center gap-1.5">
                            <Select
                                value={sort}
                                onValueChange={(value) => {
                                    if (
                                        value === 'name' ||
                                        value === 'created_at' ||
                                        value === 'updated_at'
                                    ) {
                                        setSort(value);
                                        visit({ sort: value });
                                    }
                                }}
                            >
                                <SelectTrigger
                                    aria-label={t('groups.sortBy')}
                                    className="w-[7.5rem]"
                                >
                                    <SelectValue placeholder="Sort" />
                                </SelectTrigger>
                                <SelectContent align="end">
                                    {groupSortFields.map((field) => (
                                        <SelectItem
                                            key={field.value}
                                            value={field.value}
                                        >
                                            {field.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                aria-label={
                                    direction === 'asc'
                                        ? t('common.sortAscending')
                                        : t('common.sortDescending')
                                }
                                onClick={() => {
                                    const nextDirection =
                                        direction === 'asc' ? 'desc' : 'asc';
                                    setDirection(nextDirection);
                                    visit({ direction: nextDirection });
                                }}
                            >
                                {direction === 'asc' ? (
                                    <ArrowUpAZ className="size-4" />
                                ) : (
                                    <ArrowDownAZ className="size-4" />
                                )}
                            </Button>
                        </div>
                    }
                >
                    <DataTable
                        columns={columns}
                        data={groups.data}
                        getRowId={(group) => String(group.id)}
                        sort={{ id: sort, direction }}
                        onSortChange={changeSort}
                        pagination={groups}
                        onRowClick={openEdit}
                        getRowProps={(group) => ({
                            className: group.can_update
                                ? 'cursor-pointer'
                                : undefined,
                        })}
                        emptyState={
                            <span className="text-muted-foreground">
                                {t('groups.empty')}
                            </span>
                        }
                    />
                </PageLayout>

                <GroupFormDrawer
                    editing={editing}
                    roles={roles}
                    projectGroups={projectGroups}
                    canChangeProjectScope={canChangeProjectScope}
                    open={drawerOpen}
                    readOnly={
                        editing
                            ? !editing.can_update
                            : !can(PermissionEnum.CanCreateGroups)
                    }
                    onCancel={() => handleDrawerOpenChange(false)}
                    onSuccess={() => {
                        setDrawerOpen(false);
                        setEditing(null);
                        deepLink.syncClosed();
                    }}
                />
            </Drawer>

            <ConfirmDestructiveDialog
                open={pendingDeleteGroup !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setPendingDeleteGroup(null);
                    }
                }}
                title={t('groups.confirm.deleteTitle', {
                    name: pendingDeleteGroup?.name ?? '',
                })}
                description={t('groups.confirm.deleteDescription')}
                confirming={deleting}
                onConfirm={() => {
                    if (pendingDeleteGroup === null) {
                        return;
                    }

                    setDeleting(true);
                    router.delete(
                        groupsRoutes.destroy.url(pendingDeleteGroup.id),
                        {
                            preserveScroll: true,
                            onFinish: () => setDeleting(false),
                            onSuccess: () => setPendingDeleteGroup(null),
                            onError: (errors) => {
                                setPendingDeleteGroup(null);
                                toast.error(Object.values(errors)[0]);
                            },
                        },
                    );
                }}
            />
        </AppLayout>
    );
}
