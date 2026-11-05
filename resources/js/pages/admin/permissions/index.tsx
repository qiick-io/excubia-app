import { Head, router } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';
import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { HeaderIconButton } from '@/components/admin/header-icon-button';
import {
    hasTablePaginationFooter,
    PageLayout,
    TablePaginationFooter,
    TablePanel,
} from '@/components/layout/page-layout';
import { Badge } from '@/components/ui/badge';
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

    return (
        <AppLayout
            breadcrumbs={breadcrumbs}
            headerActions={
                can(PermissionEnum.RolesManage) ? (
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
                    <TablePanel
                        footer={
                            hasTablePaginationFooter(permissions) ? (
                                <TablePaginationFooter
                                    pagination={permissions}
                                />
                            ) : undefined
                        }
                    >
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>
                                        {t('permissions.columns.name')}
                                    </TableHead>
                                    <TableHead>
                                        {t('permissions.columns.roles')}
                                    </TableHead>
                                    <TableHead>
                                        {t('permissions.columns.guard')}
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {(permissions.data ?? []).length === 0 ? (
                                    <TableRow>
                                        <TableCell
                                            colSpan={3}
                                            className="text-muted-foreground"
                                        >
                                            {t('permissions.empty')}
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    (permissions.data ?? []).map((perm) => (
                                        <TableRow key={perm.id}>
                                            <TableCell className="font-mono text-sm">
                                                {perm.name}
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex flex-wrap gap-1">
                                                    {perm.roles.map((role) => (
                                                        <Badge
                                                            key={role}
                                                            variant="secondary"
                                                        >
                                                            {t(
                                                                `roles.names.${role}`,
                                                                role,
                                                            )}
                                                        </Badge>
                                                    ))}
                                                </div>
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {perm.guard_name}
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </TablePanel>
                </PageLayout>
            </SettingsLayout>
        </AppLayout>
    );
}
