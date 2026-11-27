import type { ColumnDef } from '@tanstack/react-table';
import { Head, router } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';
import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { DataTable } from '@/components/admin/data-table';
import { HeaderIconButton } from '@/components/admin/header-icon-button';
import { PageLayout } from '@/components/layout/page-layout';
import { Badge } from '@/components/ui/badge';
import { PermissionEnum } from '@/enums/permission-enum';
import { useCan } from '@/hooks/use-can';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { normalizePaginated } from '@/lib/pagination';
import type { LaravelPaginated } from '@/lib/pagination';
import permissionsRoutes from '@/routes/permissions';
import { edit as editProfile } from '@/routes/profile';
import type { AdminPermissionRow, BreadcrumbItem, Paginated } from '@/types';

/**
 * Read-only permissions reference with the roles holding each permission.
 * Permissions come from `PermissionEnum`; the header button re-syncs them.
 * @param {*} props.permissions - paginated permissions.
 * @returns {JSX.Element}
 */
export default function AdminPermissionsIndex({
    permissions: permissionsProp,
}: {
    permissions:
        | LaravelPaginated<AdminPermissionRow>
        | Paginated<AdminPermissionRow>;
}) {
    const { t } = useTranslation();
    const permissions = normalizePaginated(permissionsProp);
    const { can } = useCan();

    const breadcrumbs: BreadcrumbItem[] = useMemo(
        () => [
            {
                title: t('settings.layout.title'),
                href: editProfile(),
            },
            {
                title: t('settings.layout.permissions'),
                href: permissionsRoutes.index.url(),
            },
        ],
        [t],
    );

    const sync = (): void => {
        router.post(permissionsRoutes.sync.url(), {}, { preserveScroll: true });
    };

    const columns: ColumnDef<(typeof permissions.data)[number]>[] = [
        {
            id: 'name',
            header: t('permissions.columns.name'),
            meta: { cellClassName: 'font-mono text-sm' },
            cell: ({ row }) => row.original.name,
        },
        {
            id: 'roles',
            header: t('permissions.columns.roles'),
            cell: ({ row }) => (
                <div className="flex flex-wrap gap-1">
                    {row.original.roles.map((role) => (
                        <Badge key={role} variant="secondary">
                            {t(`roles.names.${role}`, role)}
                        </Badge>
                    ))}
                </div>
            ),
        },
        {
            id: 'guard',
            header: t('permissions.columns.guard'),
            meta: { cellClassName: 'text-muted-foreground' },
            cell: ({ row }) => row.original.guard_name,
        },
    ];

    return (
        <AppLayout
            breadcrumbs={breadcrumbs}
            headerActions={
                can(PermissionEnum.CanEditPermissions) ? (
                    <HeaderIconButton
                        type="button"
                        variant="outline"
                        label={t('permissions.sync')}
                        onClick={sync}
                    >
                        <RefreshCw className="size-4" />
                    </HeaderIconButton>
                ) : undefined
            }
        >
            <Head title={t('settings.layout.permissions')} />

            <SettingsLayout wide>
                <PageLayout
                    className="p-0"
                    description={t('permissions.description')}
                >
                    <DataTable
                        columns={columns}
                        data={permissions.data ?? []}
                        getRowId={(permission) => String(permission.id)}
                        pagination={permissions}
                        emptyState={
                            <span className="text-muted-foreground">
                                {t('permissions.empty')}
                            </span>
                        }
                    />
                </PageLayout>
            </SettingsLayout>
        </AppLayout>
    );
}
