import { Head, router } from '@inertiajs/react';
import { ArrowDownAZ, ArrowUpAZ, Plus, Trash2 } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { DataTableToolbar } from '@/components/admin/data-table-toolbar';
import { GroupFormDrawer } from '@/components/admin/group-form-drawer';
import { HeaderIconButton } from '@/components/admin/header-icon-button';
import { NameSlugCell } from '@/components/admin/name-slug-cell';
import { ConfirmDestructiveDialog } from '@/components/confirm-destructive-dialog';
import {
    PageLayout,
    TablePagination,
    TablePanel,
} from '@/components/layout/page-layout';
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
import { useDrawerDeepLink } from '@/hooks/use-drawer-deep-link';
import AppLayout from '@/layouts/app-layout';
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
}: {
    groups: LaravelPaginated<AdminGroupRow> | Paginated<AdminGroupRow>;
    filters?: Filters;
    roles: AdminRoleRef[];
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
            const row = groups.data.find((g) => String(g.id) === id);

            if (!row?.can_update) {
                return;
            }

            setEditing(row);
            setDrawerOpen(true);
        },
        onNew: () => {
            if (!can(PermissionEnum.GroupsManage)) {
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
                    },
                }),
                {},
                { preserveState: true, preserveScroll: true },
            );
        },
        [direction, search, sort],
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

    return (
        <AppLayout
            breadcrumbs={breadcrumbs}
            headerActions={
                can(PermissionEnum.GroupsManage) ? (
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
                                    size="sm"
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
                                size="icon-sm"
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
                    <TablePanel
                        footer={
                            groups.last_page > 1 ? (
                                <TablePagination links={groups.links ?? []} />
                            ) : undefined
                        }
                    >
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Roles</TableHead>
                                    <TableHead className="text-right">
                                        Members
                                    </TableHead>
                                    <TableHead className="w-[1%] text-right">
                                        Actions
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {groups.data.length === 0 ? (
                                    <TableRow>
                                        <TableCell
                                            colSpan={4}
                                            className="text-muted-foreground"
                                        >
                                            No groups yet.
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    groups.data.map((group) => (
                                        <TableRow
                                            key={group.id}
                                            className={
                                                group.can_update
                                                    ? 'cursor-pointer'
                                                    : undefined
                                            }
                                            onClick={() => openEdit(group)}
                                        >
                                            <TableCell className="max-w-[16rem]">
                                                <NameSlugCell
                                                    name={group.name}
                                                    slug={group.slug}
                                                />
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex flex-wrap gap-1">
                                                    {group.roles.map((r) => (
                                                        <Badge
                                                            key={r.id}
                                                            variant="secondary"
                                                        >
                                                            {r.name}
                                                        </Badge>
                                                    ))}
                                                </div>
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {group.users_count ?? '—'}
                                            </TableCell>
                                            <TableCell
                                                className="text-right"
                                                onClick={(e) =>
                                                    e.stopPropagation()
                                                }
                                            >
                                                {group.can_update && (
                                                    <HeaderIconButton
                                                        type="button"
                                                        variant="destructive-ghost"
                                                        size="icon-sm"
                                                        label={t(
                                                            'common.delete',
                                                        )}
                                                        onClick={() =>
                                                            setPendingDeleteGroup(
                                                                group,
                                                            )
                                                        }
                                                    >
                                                        <Trash2 className="size-3.5" />
                                                    </HeaderIconButton>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </TablePanel>
                </PageLayout>

                <GroupFormDrawer
                    editing={editing}
                    roles={roles}
                    open={drawerOpen}
                    readOnly={
                        editing
                            ? !editing.can_update
                            : !can(PermissionEnum.GroupsManage)
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
