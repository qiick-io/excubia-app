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
import { DataTableToolbar } from '@/components/admin/data-table-toolbar';
import { HeaderIconButton } from '@/components/admin/header-icon-button';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { TruncatedText } from '@/components/admin/truncated-text';
import { UserFormDrawer } from '@/components/admin/user-form-drawer';
import { ConfirmDestructiveDialog } from '@/components/confirm-destructive-dialog';
import { EmptyStatus } from '@/components/empty-status';
import {
    hasTablePaginationFooter,
    PageLayout,
    TablePaginationFooter,
    TablePanel,
} from '@/components/layout/page-layout';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
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
import { useInitials } from '@/hooks/use-initials';
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
    const getInitials = useInitials();

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
            if (!can(PermissionEnum.UsersManage)) {
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

    return (
        <AppLayout
            breadcrumbs={breadcrumbs}
            headerActions={
                can(PermissionEnum.UsersManage) ? (
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
                                ) : can(PermissionEnum.UsersManage) ? (
                                    <Button type="button" onClick={openCreate}>
                                        <UserPlus className="size-4" />
                                        {t('emptyState.users.cta')}
                                    </Button>
                                ) : undefined
                            }
                        />
                    ) : (
                        <TablePanel
                            footer={
                                hasTablePaginationFooter(users) ? (
                                    <TablePaginationFooter pagination={users} />
                                ) : undefined
                            }
                        >
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Name</TableHead>
                                        <TableHead>Email</TableHead>
                                        <TableHead>Roles</TableHead>
                                        <TableHead>Groups</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead className="w-[1%] text-right">
                                            Actions
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {users.data.map((user) => (
                                        <TableRow
                                            key={user.id}
                                            className={
                                                user.can_update
                                                    ? 'cursor-pointer'
                                                    : undefined
                                            }
                                            onClick={() => openEdit(user)}
                                        >
                                            <TableCell className="max-w-[14rem] font-medium">
                                                <span className="inline-flex min-w-0 items-center gap-2">
                                                    <Avatar
                                                        userId={user.id}
                                                        className="size-7 shrink-0"
                                                    >
                                                        <AvatarFallback className="text-xs font-medium">
                                                            {getInitials(
                                                                user.name,
                                                            ) ||
                                                                user.email
                                                                    .charAt(0)
                                                                    .toUpperCase()}
                                                        </AvatarFallback>
                                                    </Avatar>
                                                    <TruncatedText
                                                        text={user.name}
                                                    />
                                                    {user.is_sso_linked && (
                                                        <Badge variant="outline">
                                                            {t(
                                                                'users.ssoBadge',
                                                            )}
                                                        </Badge>
                                                    )}
                                                </span>
                                            </TableCell>
                                            <TableCell className="max-w-[12rem] text-muted-foreground">
                                                <TruncatedText
                                                    text={user.email}
                                                />
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex flex-wrap gap-1">
                                                    {user.roles.map((r) => (
                                                        <Badge
                                                            key={r.id}
                                                            variant="secondary"
                                                        >
                                                            {r.name}
                                                        </Badge>
                                                    ))}
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex flex-wrap gap-1">
                                                    {user.groups.map((g) => (
                                                        <Badge
                                                            key={g.id}
                                                            variant="outline"
                                                        >
                                                            {g.name}
                                                        </Badge>
                                                    ))}
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                {user.is_active ? (
                                                    <Badge
                                                        variant="success"
                                                        className="gap-1.5"
                                                    >
                                                        <span
                                                            className="size-1.5 rounded-full bg-success"
                                                            aria-hidden
                                                        />
                                                        Active
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
                                                        Inactive
                                                    </Badge>
                                                )}
                                            </TableCell>
                                            <TableCell
                                                className="text-right"
                                                onClick={(e) =>
                                                    e.stopPropagation()
                                                }
                                            >
                                                <div className="inline-flex items-center justify-end gap-1">
                                                    {user.can_update && (
                                                        <HeaderIconButton
                                                            type="button"
                                                            variant="ghost"
                                                            size="icon-sm"
                                                            label={t(
                                                                'users.actions.sendResetEmail',
                                                            )}
                                                            onClick={() =>
                                                                sendPasswordReset(
                                                                    user,
                                                                )
                                                            }
                                                        >
                                                            <KeyRound className="size-3.5" />
                                                        </HeaderIconButton>
                                                    )}
                                                    {user.can_delete && (
                                                        <HeaderIconButton
                                                            type="button"
                                                            variant="destructive-ghost"
                                                            size="icon-sm"
                                                            label={t(
                                                                'common.delete',
                                                            )}
                                                            onClick={() =>
                                                                setPendingDeleteUser(
                                                                    user,
                                                                )
                                                            }
                                                        >
                                                            <Trash2 className="size-3.5" />
                                                        </HeaderIconButton>
                                                    )}
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </TablePanel>
                    )}
                </PageLayout>

                <UserFormDrawer
                    editing={editing}
                    roles={roles}
                    open={drawerOpen}
                    readOnly={
                        editing
                            ? !editing.can_update
                            : !can(PermissionEnum.UsersManage)
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
