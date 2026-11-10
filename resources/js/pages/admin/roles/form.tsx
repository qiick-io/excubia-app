import { Head, Link, useForm } from '@inertiajs/react';
import { useEffect, useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { edit as editProfile } from '@/routes/profile';
import rolesRoutes from '@/routes/roles';
import type { AdminRoleRow, BreadcrumbItem, PermissionGroup } from '@/types';

/**
 * Create or edit a role and its permissions. Built-in roles render read-only
 * (`can_edit` false): PermissionSeeder owns their permissions.
 */
export default function AdminRoleForm({
    role,
    permissionGroups,
}: {
    role?: AdminRoleRow | null;
    permissionGroups: PermissionGroup[];
}) {
    const { t } = useTranslation();
    const isEdit = Boolean(role?.id);
    const isReadOnly = role ? !role.can_edit : false;

    const form = useForm({
        name: role?.name ?? '',
        permissions: [] as string[],
    });

    useEffect(() => {
        const permissions = role?.permissions ?? [];
        // Fresh object for setDefaults — avoid sharing the setData reference.
        const payload = { name: role?.name ?? '', permissions };
        form.setData(payload);
        form.setDefaults({ ...payload, permissions: [...permissions] });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [role?.id]);

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
            {
                title: isEdit
                    ? t('roles.form.editTitle')
                    : t('roles.actions.newRole'),
                href: isEdit
                    ? rolesRoutes.edit.url(role!.id)
                    : rolesRoutes.create.url(),
            },
        ],
        [isEdit, role, t],
    );

    const togglePermission = (name: string, checked: boolean): void => {
        const current = form.data.permissions;
        form.setData(
            'permissions',
            checked
                ? [...current, name]
                : current.filter((permission) => permission !== name),
        );
    };

    const toggleGroup = (group: PermissionGroup, checked: boolean): void => {
        const current = new Set(form.data.permissions);

        if (checked) {
            group.permissions.forEach((name) => current.add(name));
        } else {
            group.permissions.forEach((name) => current.delete(name));
        }

        form.setData('permissions', [...current]);
    };

    const submit = (): void => {
        const opts = { preserveScroll: true };

        if (isEdit) {
            form.put(rolesRoutes.update.url(role!.id), opts);
        } else {
            form.post(rolesRoutes.store.url(), opts);
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head
                title={
                    isEdit
                        ? t('roles.form.editHead', { name: role?.name })
                        : t('roles.actions.newRole')
                }
            />

            <SettingsLayout wide>
                <div className="flex min-h-0 flex-1 flex-col gap-6">
                    <form
                        className="flex w-full flex-col gap-6"
                        noValidate
                        onSubmit={(event) => {
                            event.preventDefault();

                            if (!isReadOnly) {
                                submit();
                            }
                        }}
                    >
                        <div className="flex flex-wrap items-center justify-between gap-4">
                            <div>
                                <h1 className="text-xl font-semibold tracking-tight">
                                    {isEdit
                                        ? t('roles.form.editTitle')
                                        : t('roles.actions.newRole')}
                                </h1>
                                {isReadOnly && (
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        {t('roles.form.readOnlyHint')}
                                    </p>
                                )}
                            </div>
                            <div className="flex gap-2">
                                <Button variant="outline" asChild>
                                    <Link href={rolesRoutes.index.url()}>
                                        {t('common.cancel')}
                                    </Link>
                                </Button>
                                {!isReadOnly && (
                                    <Button
                                        type="submit"
                                        disabled={form.processing}
                                    >
                                        {isEdit
                                            ? t('common.save')
                                            : t('common.create')}
                                    </Button>
                                )}
                            </div>
                        </div>

                        <FieldGroup className="max-w-md">
                            <Field
                                data-invalid={
                                    form.errors.name ? true : undefined
                                }
                            >
                                <FieldLabel htmlFor="role_name">
                                    {t('common.name')}
                                </FieldLabel>
                                <Input
                                    id="role_name"
                                    value={form.data.name}
                                    onChange={(event) =>
                                        form.setData('name', event.target.value)
                                    }
                                    required
                                    maxLength={100}
                                    disabled={isReadOnly}
                                    aria-invalid={
                                        form.errors.name ? true : undefined
                                    }
                                    aria-describedby={
                                        form.errors.name
                                            ? 'role-name-error'
                                            : undefined
                                    }
                                />
                                <FieldError id="role-name-error">
                                    {form.errors.name}
                                </FieldError>
                            </Field>
                        </FieldGroup>

                        <div className="space-y-6">
                            <h2 className="text-lg font-medium">
                                {t('roles.form.adminPermissions')}
                            </h2>
                            {permissionGroups.map((group) => {
                                const allChecked =
                                    group.permissions.length > 0 &&
                                    group.permissions.every((name) =>
                                        form.data.permissions.includes(name),
                                    );

                                return (
                                    <FieldSet
                                        key={group.section}
                                        className="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
                                    >
                                        <div className="mb-3 flex flex-wrap items-center gap-2">
                                            <Checkbox
                                                id={`section-${group.section}`}
                                                checked={allChecked}
                                                disabled={isReadOnly}
                                                onCheckedChange={(
                                                    checkedState,
                                                ) =>
                                                    toggleGroup(
                                                        group,
                                                        checkedState === true,
                                                    )
                                                }
                                            />
                                            <FieldLegend
                                                variant="label"
                                                className="mb-0"
                                            >
                                                <Label
                                                    htmlFor={`section-${group.section}`}
                                                    className="text-base font-semibold"
                                                >
                                                    {group.label}
                                                </Label>
                                            </FieldLegend>
                                            <FieldDescription className="font-mono text-xs">
                                                {group.section}
                                            </FieldDescription>
                                        </div>
                                        <div className="grid gap-2 sm:grid-cols-2">
                                            {group.permissions.map((name) => (
                                                <label
                                                    key={name}
                                                    className="flex items-center gap-2 text-sm"
                                                >
                                                    <Checkbox
                                                        checked={form.data.permissions.includes(
                                                            name,
                                                        )}
                                                        disabled={isReadOnly}
                                                        onCheckedChange={(
                                                            checkedState,
                                                        ) =>
                                                            togglePermission(
                                                                name,
                                                                checkedState ===
                                                                    true,
                                                            )
                                                        }
                                                    />
                                                    <span>{name}</span>
                                                </label>
                                            ))}
                                        </div>
                                    </FieldSet>
                                );
                            })}
                            <FieldError id="role-permissions-error">
                                {form.errors.permissions}
                            </FieldError>
                        </div>
                    </form>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
