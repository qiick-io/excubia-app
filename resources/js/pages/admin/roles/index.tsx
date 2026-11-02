import { Head, Link, router } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { HeaderIconButton } from '@/components/admin/header-icon-button';
import { ConfirmDestructiveDialog } from '@/components/confirm-destructive-dialog';
import {
    PageLayout,
    TablePagination,
    TablePanel,
} from '@/components/layout/page-layout';
import { Button } from '@/components/ui/button';
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

    return (
        <AppLayout
            breadcrumbs={breadcrumbs}
            headerActions={
                can(PermissionEnum.RolesManage) ? (
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
                    <TablePanel
                        footer={
                            roles.last_page > 1 ? (
                                <TablePagination links={roles.links ?? []} />
                            ) : undefined
                        }
                    >
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>
                                        {t('roles.columns.name')}
                                    </TableHead>
                                    <TableHead className="text-right">
                                        {t('roles.columns.permissions')}
                                    </TableHead>
                                    <TableHead className="text-right">
                                        {t('roles.columns.actions')}
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {rows.length === 0 ? (
                                    <TableRow>
                                        <TableCell
                                            colSpan={3}
                                            className="text-muted-foreground"
                                        >
                                            {t('roles.empty')}
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    rows.map((role) => (
                                        <TableRow key={role.id}>
                                            <TableCell className="font-medium">
                                                <span className="inline-flex items-center gap-2">
                                                    {role.name}
                                                    {role.is_built_in && (
                                                        <span className="rounded bg-muted px-2 py-0.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                                            {t(
                                                                'roles.systemBadge',
                                                            )}
                                                        </span>
                                                    )}
                                                </span>
                                            </TableCell>
                                            <TableCell className="text-right text-muted-foreground tabular-nums">
                                                {role.permissions_count ?? '—'}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <div className="inline-flex items-center justify-end gap-1">
                                                    <HeaderIconButton
                                                        variant="outline"
                                                        size="icon-sm"
                                                        label={t(
                                                            'roles.actions.edit',
                                                        )}
                                                        asChild
                                                    >
                                                        <Link
                                                            href={rolesRoutes.edit.url(
                                                                role.id,
                                                            )}
                                                        >
                                                            <Pencil className="size-3.5" />
                                                        </Link>
                                                    </HeaderIconButton>
                                                    {role.can_edit && (
                                                        <HeaderIconButton
                                                            type="button"
                                                            variant="destructive-ghost"
                                                            size="icon-sm"
                                                            label={t(
                                                                'roles.actions.delete',
                                                            )}
                                                            onClick={() =>
                                                                setPendingRoleId(
                                                                    role.id,
                                                                )
                                                            }
                                                        >
                                                            <Trash2 className="size-3.5" />
                                                        </HeaderIconButton>
                                                    )}
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </TablePanel>
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
