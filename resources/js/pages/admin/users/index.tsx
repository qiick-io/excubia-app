import type { ColumnDef } from '@tanstack/react-table';
import { Head, router } from '@inertiajs/react';
import {
    ArrowDownAZ,
    ArrowUpAZ,
    KeyRound,
    SearchX,
    Trash2,
    UserPlus,
    Users,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { DataTable } from '@/components/admin/data-table';
import type { DataTableSort } from '@/components/admin/data-table';
import { DataTableColumnHeader } from '@/components/admin/data-table-column-header';
import { DataTableToolbar } from '@/components/admin/data-table-toolbar';
import { HeaderIconButton } from '@/components/admin/header-icon-button';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { TruncatedText } from '@/components/admin/truncated-text';
import { UserAvatar } from '@/components/admin/user-avatar';
import { UserFormDrawer } from '@/components/admin/user-form-drawer';
import { ConfirmDestructiveDialog } from '@/components/confirm-destructive-dialog';
import { EmptyStatus } from '@/components/empty-status';
import { PageLayout } from '@/components/layout/page-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ButtonGroup } from '@/components/ui/button-group';
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
import usersRoutes from '@/routes/users';
import type {
    AdminRoleRef,
    AdminUserRow,
    BreadcrumbItem,
    Paginated,
} from '@/types';

type UserSortField = 'name' | 'email' | 'created_at' | 'updated_at';
type UserSortDirection = 'asc' | 'desc';

const USER_SORT_FIELDS: UserSortField[] = [
    'name',
    'email',
    'created_at',
    'updated_at',
];

function isUserSortField(value: string): value is UserSortField {
    return (USER_SORT_FIELDS as string[]).includes(value);
}

type Filters = {
    search?: string;
    sort?: UserSortField;
    direction?: UserSortDirection;
};

/**
 * Admin users list with search, sort, pagination and a create/edit drawer.
 * Row actions send Fortify's reset email and delete users the policy allows.
 */
export default function AdminUsersIndex({
    users: usersProp,
    filters = {},
    roles,
}: {
    users: LaravelPaginated<AdminUserRow> | Paginated<AdminUserRow>;
    filters?: Filters;
    roles: AdminRoleRef[];
}) {
    const users = normalizePaginated(usersProp);
    const { t } = useTranslation();

    const userSortFields: { value: UserSortField; label: string }[] = [
        { value: 'name', label: t('common.name') },
        { value: 'email', label: t('common.email') },
        { value: 'created_at', label: t('users.columns.created') },
        { value: 'updated_at', label: t('users.columns.updated') },
    ];
    const { can } = useCan();
    const [search, setSearch] = useState(filters.search ?? '');
    const [sort, setSort] = useState<UserSortField>(
        filters.sort ?? 'created_at',
    );
    const [direction, setDirection] = useState<UserSortDirection>(
        filters.direction ?? 'desc',
    );
    const [drawerOpen, setDrawerOpen] = useState(false);
    const [editing, setEditing] = useState<AdminUserRow | null>(null);
    const [pendingDeleteUser, setPendingDeleteUser] =
        useState<AdminUserRow | null>(null);
    const [deleting, setDeleting] = useState(false);

    const deepLink = useDrawerDeepLink({
        onEdit: (id) => {
            const row = users.data.find((u) => String(u.id) === id);

            if (!row?.can_update) {
                return;
            }

            setEditing(row);
            setDrawerOpen(true);
        },
        onNew: () => {
            if (!can(PermissionEnum.CanCreateUsers)) {
                return;
            }

            setEditing(null);
            setDrawerOpen(true);
        },
    });

    const breadcrumbs: BreadcrumbItem[] = useMemo(
        () => [{ title: t('users.title'), href: usersRoutes.index.url() }],
        [t],
    );

    const visit = useCallback(
        (overrides: Partial<Filters> = {}) => {
            const nextSearch =
                overrides.search !== undefined ? overrides.search : search;
            const nextSort = overrides.sort ?? sort;
            const nextDirection = overrides.direction ?? direction;

            router.get(
                usersRoutes.index.url({
                    query: {
                        search: nextSearch || undefined,
                        sort: nextSort,
                        direction: nextDirection,
                        per_page: listPerPageQuery(users.per_page),
                    },
                }),
                {},
                { preserveState: true, preserveScroll: true },
            );
        },
        [direction, users.per_page, search, sort],
    );

    useEffect(() => {
        setSearch(filters.search ?? '');
        setSort(filters.sort ?? 'created_at');
        setDirection(filters.direction ?? 'desc');
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

    const openEdit = (user: AdminUserRow): void => {
        if (!user.can_update) {
            return;
        }

        setEditing(user);
        setDrawerOpen(true);
        deepLink.syncEdit(user.id);
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

    const hasActiveFilters = search.trim() !== '';
    const isListEmpty = users.data.length === 0;
    const hideListChrome = isListEmpty && !hasActiveFilters;

    const sendPasswordReset = (user: AdminUserRow): void => {
        router.post(
            usersRoutes.passwordReset.url(user.id),
            {},
            { preserveScroll: true },
        );
    };

    const changeSort = (nextSort: DataTableSort): void => {
        if (!isUserSortField(nextSort.id)) {
            return;
        }

        setSort(nextSort.id);
        setDirection(nextSort.direction);
        visit({ sort: nextSort.id, direction: nextSort.direction });
    };

    const columns: ColumnDef<AdminUserRow>[] = [
        {
            accessorKey: 'name',
            enableSorting: true,
            meta: { cellClassName: 'max-w-[14rem] font-medium' },
            header: ({ column }) => (
                <DataTableColumnHeader
                    column={column}
                    title={t('users.columns.name')}
                />
            ),
            cell: ({ row }) => (
                <span className="inline-flex min-w-0 items-center gap-2">
                    <UserAvatar
                        userId={row.original.id}
                        name={row.original.name}
                        email={row.original.email}
                        size="sm"
                    />
                    <TruncatedText text={row.original.name} />
                    {row.original.is_sso_linked ? (
                        <Badge variant="outline">{t('users.ssoBadge')}</Badge>
                    ) : null}
                </span>
            ),
        },
        {
            accessorKey: 'email',
            enableSorting: true,
            meta: { cellClassName: 'max-w-[12rem] text-muted-foreground' },
            header: ({ column }) => (
                <DataTableColumnHeader
                    column={column}
                    title={t('users.columns.email')}
                />
            ),
            cell: ({ row }) => <TruncatedText text={row.original.email} />,
        },
        {
            id: 'roles',
            header: t('users.columns.roles'),
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
            id: 'groups',
            header: t('users.columns.groups'),
            cell: ({ row }) => (
                <div className="flex flex-wrap gap-1">
                    {row.original.groups.map((group) => (
                        <Badge key={group.id} variant="outline">
                            {group.name}
                        </Badge>
                    ))}
                </div>
            ),
        },
        {
            id: 'status',
            header: t('users.columns.status'),
            cell: ({ row }) =>
                row.original.is_active ? (
                    <Badge variant="success" className="gap-1.5">
                        <span
                            className="size-1.5 rounded-full bg-success"
                            aria-hidden
                        />
                        {t('users.status.active')}
                    </Badge>
                ) : (
                    <Badge variant="muted" className="gap-1.5">
                        <span
                            className="size-1.5 rounded-full bg-muted-foreground"
                            aria-hidden
                        />
                        {t('users.status.inactive')}
                    </Badge>
                ),
        },
        {
            id: 'actions',
            header: t('users.columns.actions'),
            meta: {
                headerClassName: 'w-[1%] text-right',
                cellClassName: 'text-right',
                isRowClickIgnored: true,
            },
            cell: ({ row }) => (
                <ButtonGroup
                    aria-label={t('users.columns.actions')}
                    className="ml-auto"
                >
                    {row.original.can_update ? (
                        <HeaderIconButton
                            type="button"
                            variant="outline"
                            size="icon-sm"
                            label={t('users.actions.sendResetEmail')}
                            onClick={() => sendPasswordReset(row.original)}
                        >
                            <KeyRound className="size-3.5" />
                        </HeaderIconButton>
                    ) : null}
                    {row.original.can_delete ? (
                        <HeaderIconButton
                            type="button"
                            variant="outline"
                            size="icon-sm"
                            className="text-destructive hover:text-destructive"
                            label={t('common.delete')}
                            onClick={() => setPendingDeleteUser(row.original)}
                        >
                            <Trash2 className="size-3.5" />
                        </HeaderIconButton>
                    ) : null}
                </ButtonGroup>
            ),
        },
    ];

    return (
        <AppLayout
            breadcrumbs={breadcrumbs}
            headerActions={
                can(PermissionEnum.CanCreateUsers) ? (
                    <Button type="button" onClick={openCreate}>
                        <UserPlus className="mr-1 size-4" />
                        {t('users.newUser')}
                    </Button>
                ) : undefined
            }
        >
            <Head title={t('users.title')} />

            <Drawer
                direction="right"
                open={drawerOpen}
                onOpenChange={handleDrawerOpenChange}
            >
                <PageLayout
                    filters={
                        hideListChrome ? undefined : (
                            <DataTableToolbar
                                search={search}
                                onSearchChange={setSearch}
                                searchPlaceholder={t('users.searchPlaceholder')}
                            />
                        )
                    }
                    filtersRight={
                        hideListChrome ? null : (
                            <div className="flex items-center gap-1.5">
                                <Select
                                    value={sort}
                                    onValueChange={(value) => {
                                        if (
                                            value === 'name' ||
                                            value === 'email' ||
                                            value === 'created_at' ||
                                            value === 'updated_at'
                                        ) {
                                            setSort(value);
                                            visit({ sort: value });
                                        }
                                    }}
                                >
                                    <SelectTrigger
                                        aria-label={t('users.sortBy')}
                                        className="w-[7.5rem]"
                                    >
                                        <SelectValue placeholder="Sort" />
                                    </SelectTrigger>
                                    <SelectContent align="end">
                                        {userSortFields.map((field) => (
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
                                            direction === 'asc'
                                                ? 'desc'
                                                : 'asc';
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
                        )
                    }
                >
                    {isListEmpty ? (
                        <PageEmptyState
                            icon={hasActiveFilters ? SearchX : Users}
                            title={
                                <EmptyStatus>
                                    {hasActiveFilters
                                        ? t('emptyState.users.filteredTitle')
                                        : t('emptyState.users.title')}
                                </EmptyStatus>
                            }
                            description={
                                hasActiveFilters
                                    ? t('emptyState.users.filteredDescription')
                                    : t('emptyState.users.description')
                            }
                            action={
                                hasActiveFilters ? (
                                    <Button
                                        type="button"
                                        onClick={() => {
                                            setSearch('');
                                            visit({ search: undefined });
                                        }}
                                    >
                                        {t('common.clearFilters')}
                                    </Button>
                                ) : can(PermissionEnum.CanCreateUsers) ? (
                                    <Button type="button" onClick={openCreate}>
                                        <UserPlus className="size-4" />
                                        {t('emptyState.users.cta')}
                                    </Button>
                                ) : undefined
                            }
                        />
                    ) : (
                        <DataTable
                            columns={columns}
                            data={users.data}
                            getRowId={(user) => String(user.id)}
                            sort={{ id: sort, direction }}
                            onSortChange={changeSort}
                            pagination={users}
                            onRowClick={openEdit}
                            getRowProps={(user) => ({
                                className: user.can_update
                                    ? 'cursor-pointer'
                                    : undefined,
                            })}
                        />
                    )}
                </PageLayout>

                <UserFormDrawer
                    editing={editing}
                    roles={roles}
                    open={drawerOpen}
                    readOnly={
                        editing
                            ? !editing.can_update
                            : !can(PermissionEnum.CanCreateUsers)
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
                open={pendingDeleteUser !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setPendingDeleteUser(null);
                    }
                }}
                title={t('users.confirm.deleteTitle', {
                    name: pendingDeleteUser?.name ?? '',
                })}
                description={t('users.confirm.deleteDescription')}
                confirming={deleting}
                onConfirm={() => {
                    if (pendingDeleteUser === null) {
                        return;
                    }

                    setDeleting(true);
                    router.delete(
                        usersRoutes.destroy.url(pendingDeleteUser.id),
                        {
                            preserveScroll: true,
                            onFinish: () => setDeleting(false),
                            onSuccess: () => setPendingDeleteUser(null),
                            onError: (errors) => {
                                setPendingDeleteUser(null);
                                toast.error(Object.values(errors)[0]);
                            },
                        },
                    );
                }}
            />
        </AppLayout>
    );
}
