import type { ColumnDef } from '@tanstack/react-table';
import { Head, Link, router } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { DataTable } from '@/components/admin/data-table';
import { Badge } from '@/components/ui/badge';
import { ButtonGroup } from '@/components/ui/button-group';
import { HeaderIconButton } from '@/components/admin/header-icon-button';
import { ConfirmDestructiveDialog } from '@/components/confirm-destructive-dialog';
import { PageLayout } from '@/components/layout/page-layout';
import { Button } from '@/components/ui/button';
import { PermissionEnum } from '@/enums/permission-enum';
import { useCan } from '@/hooks/use-can';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { normalizePaginated } from '@/lib/pagination';
import type { LaravelPaginated } from '@/lib/pagination';
import { edit as editProfile } from '@/routes/profile';
import rolesRoutes from '@/routes/roles';
import type { AdminRoleRow, BreadcrumbItem, Paginated } from '@/types';

/**
 * Admin roles list with permissions overview and icon row actions.
 * Built-in roles (RoleEnum) open read-only and cannot be deleted.
 */
export default function AdminRolesIndex({
    roles: rolesProp,
}: {
    roles: LaravelPaginated<AdminRoleRow> | Paginated<AdminRoleRow>;
}) {
    const { t } = useTranslation();
    const { can } = useCan();
    const roles = normalizePaginated(rolesProp);
    const rows = roles.data;
    const [pendingRoleId, setPendingRoleId] = useState<number | null>(null);
    const [deleting, setDeleting] = useState(false);

    const pendingRole = rows.find((role) => role.id === pendingRoleId);

    const breadcrumbs: BreadcrumbItem[] = useMemo(
        () => [
            {
                title: t('settings.layout.title'),
                href: editProfile(),
            },
            {
                title: t('settings.layout.roles'),
                href: rolesRoutes.index.url(),
            },
        ],
        [t],
    );

    const columns: ColumnDef<(typeof rows)[number]>[] = [
        {
            id: 'name',
            header: t('roles.columns.name'),
            meta: { cellClassName: 'font-medium' },
            cell: ({ row }) => (
                <span className="inline-flex items-center gap-2">
                    {row.original.name}
                    {row.original.is_built_in ? (
                        <Badge variant="muted">{t('roles.systemBadge')}</Badge>
                    ) : null}
                </span>
            ),
        },
        {
            id: 'permissions',
            header: t('roles.columns.permissions'),
            meta: {
                headerClassName: 'text-right',
                cellClassName: 'text-right text-muted-foreground tabular-nums',
            },
            cell: ({ row }) => row.original.permissions_count ?? '—',
        },
        {
            id: 'actions',
            header: t('roles.columns.actions'),
            meta: {
                headerClassName: 'w-[1%] text-right',
                cellClassName: 'text-right',
                isRowClickIgnored: true,
            },
            cell: ({ row }) => (
                <ButtonGroup
                    aria-label={t('roles.columns.actions')}
                    className="ml-auto"
                >
                    <HeaderIconButton
                        variant="outline"
                        size="icon-sm"
                        label={t('roles.actions.edit')}
                        asChild
                    >
                        <Link href={rolesRoutes.edit.url(row.original.id)}>
                            <Pencil className="size-3.5" />
                        </Link>
                    </HeaderIconButton>
                    {row.original.can_edit ? (
                        <HeaderIconButton
                            type="button"
                            variant="outline"
                            size="icon-sm"
                            className="text-destructive hover:text-destructive"
                            label={t('roles.actions.delete')}
                            onClick={() => setPendingRoleId(row.original.id)}
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
                can(PermissionEnum.CanCreateRoles) ? (
                    <Button asChild>
                        <Link href={rolesRoutes.create.url()}>
                            <Plus className="mr-1 size-4" />
                            {t('roles.actions.newRole')}
                        </Link>
                    </Button>
                ) : undefined
            }
        >
            <Head title={t('settings.layout.roles')} />

            <SettingsLayout wide>
                <PageLayout className="p-0">
                    <DataTable
                        columns={columns}
                        data={rows}
                        getRowId={(role) => String(role.id)}
                        pagination={roles}
                        onRowClick={(role) =>
                            router.visit(rolesRoutes.edit.url(role.id))
                        }
                        getRowProps={() => ({ className: 'cursor-pointer' })}
                        emptyState={
                            <span className="text-muted-foreground">
                                {t('roles.empty')}
                            </span>
                        }
                    />
                </PageLayout>
            </SettingsLayout>

            <ConfirmDestructiveDialog
                open={pendingRoleId !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setPendingRoleId(null);
                    }
                }}
                title={
                    pendingRole
                        ? t('roles.deleteTitle', { name: pendingRole.name })
                        : t('roles.deleteFallback')
                }
                description={t('roles.deleteDescription')}
                confirming={deleting}
                onConfirm={() => {
                    if (pendingRoleId === null) {
                        return;
                    }

                    setDeleting(true);
                    router.delete(rolesRoutes.destroy.url(pendingRoleId), {
                        preserveScroll: true,
                        onFinish: () => setDeleting(false),
                        onSuccess: () => setPendingRoleId(null),
                        onError: () => setPendingRoleId(null),
                    });
                }}
            />
        </AppLayout>
    );
}
