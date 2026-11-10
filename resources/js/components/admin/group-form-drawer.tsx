import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import {
    RoleMultiSelect,
    withSelectedRoles,
} from '@/components/admin/role-multi-select';
import { UserMultiSelect } from '@/components/admin/user-multi-select';
import { IdCheckboxGrid } from '@/components/settings/alert-filter-fields';
import { Button } from '@/components/ui/button';
import {
    DrawerBody,
    DrawerContent,
    DrawerDescription,
    DrawerFooter,
    DrawerHeader,
    DrawerTitle,
} from '@/components/ui/drawer';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { STRING_LIMITS } from '@/lib/string-limits';
import groups from '@/routes/groups';
import type { AdminGroupRow, AdminRoleRef } from '@/types/admin';

const EMPTY_GROUP_FORM = {
    name: '',
    description: '',
    roles: [] as string[],
    users: [] as number[],
    project_groups: [] as number[],
};

export type GroupFormDrawerProps = {
    editing: AdminGroupRow | null;
    /** Roles the current user may assign. */
    roles: AdminRoleRef[];
    /** Project groups the group may be scoped to (#253). */
    projectGroups?: { id: number; name: string }[];
    /** Only admins change the project scope; the server enforces it too. */
    canChangeProjectScope?: boolean;
    readOnly?: boolean;
    open?: boolean;
    onCancel?: () => void;
    onSuccess?: () => void;
};

/**
 * Drawer form for creating and editing user groups.
 * @param {*} props - Component props.
 * @returns {JSX.Element}
 */
export function GroupFormDrawer({
    editing,
    roles,
    projectGroups = [],
    canChangeProjectScope = false,
    readOnly = false,
    open = true,
    onCancel,
    onSuccess,
}: GroupFormDrawerProps) {
    const { t } = useTranslation();
    const form = useForm({ ...EMPTY_GROUP_FORM });
    // Only a super-admin changes their own access (#151); the server enforces it too.
    const isRolesLocked = editing !== null && !editing.can_change_roles;
    const roleOptions = withSelectedRoles(roles, editing?.roles ?? []);

    useEffect(() => {
        if (!open) {
            return;
        }

        if (editing) {
            // Fresh object for setDefaults — avoid sharing the setData reference.
            const payload = {
                name: editing.name,
                description: editing.description ?? '',
                roles: editing.roles.map((role) => role.name),
                users: editing.users?.map((user) => user.id) ?? [],
                project_groups:
                    editing.project_groups?.map((group) => group.id) ?? [],
            };
            form.setData(payload);
            form.setDefaults({ ...payload });
        } else {
            form.setDefaults({ ...EMPTY_GROUP_FORM });
            form.reset();
            form.clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [editing, open]);

    const submit = (): void => {
        const opts = {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onSuccess?.();
            },
        };

        if (editing) {
            form.put(groups.update.url(editing.id), opts);
        } else {
            form.post(groups.store.url(), opts);
        }
    };

    const title = editing ? t('groups.form.editTitle') : t('groups.newGroup');

    return (
        <DrawerContent>
            <DrawerHeader>
                <DrawerTitle>{title}</DrawerTitle>
                <DrawerDescription>
                    {t('groups.form.description')}
                </DrawerDescription>
            </DrawerHeader>

            <form
                className="flex min-h-0 flex-1 flex-col overflow-hidden"
                noValidate
                onSubmit={(e) => {
                    e.preventDefault();

                    if (!readOnly) {
                        submit();
                    }
                }}
            >
                <DrawerBody>
                    <FieldGroup className="gap-4">
                        <Field
                            data-invalid={form.errors.name ? true : undefined}
                        >
                            <FieldLabel htmlFor="group_name">
                                {t('common.name')}
                            </FieldLabel>
                            <Input
                                id="group_name"
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                                required
                                maxLength={STRING_LIMITS.GROUP_NAME}
                                disabled={readOnly}
                                aria-invalid={
                                    form.errors.name ? true : undefined
                                }
                                aria-describedby={
                                    form.errors.name
                                        ? 'group-name-error'
                                        : undefined
                                }
                            />
                            <FieldError id="group-name-error">
                                {form.errors.name}
                            </FieldError>
                        </Field>

                        <Field
                            data-invalid={
                                form.errors.description ? true : undefined
                            }
                        >
                            <FieldLabel htmlFor="group_description">
                                {t('common.description')}
                            </FieldLabel>
                            <Input
                                id="group_description"
                                value={form.data.description}
                                onChange={(e) =>
                                    form.setData('description', e.target.value)
                                }
                                maxLength={STRING_LIMITS.DESCRIPTION}
                                disabled={readOnly}
                                aria-invalid={
                                    form.errors.description ? true : undefined
                                }
                                aria-describedby={
                                    form.errors.description
                                        ? 'group-description-error'
                                        : undefined
                                }
                            />
                            <FieldError id="group-description-error">
                                {form.errors.description}
                            </FieldError>
                        </Field>

                        <Field
                            data-invalid={
                                form.errors.roles || form.errors['roles.0']
                                    ? true
                                    : undefined
                            }
                        >
                            <FieldLabel>{t('groups.columns.roles')}</FieldLabel>
                            <RoleMultiSelect
                                value={form.data.roles}
                                onChange={(selectedRoles) =>
                                    form.setData('roles', selectedRoles)
                                }
                                roles={roleOptions}
                                disabled={readOnly || isRolesLocked}
                            />
                            <FieldDescription id="group-roles-hint">
                                {t('groups.form.rolesHint')}
                            </FieldDescription>
                            {isRolesLocked && !readOnly && (
                                <FieldDescription id="group-roles-locked-hint">
                                    {t('groups.form.rolesLocked')}
                                </FieldDescription>
                            )}
                            <FieldError id="group-roles-error">
                                {form.errors.roles ?? form.errors['roles.0']}
                            </FieldError>
                        </Field>

                        <Field
                            data-invalid={form.errors.users ? true : undefined}
                        >
                            <FieldLabel>{t('groups.form.users')}</FieldLabel>
                            <UserMultiSelect
                                value={form.data.users}
                                onChange={(userIds) =>
                                    form.setData('users', userIds)
                                }
                                initialUsers={editing?.users}
                                disabled={readOnly}
                            />
                            <FieldError id="group-users-error">
                                {form.errors.users}
                            </FieldError>
                        </Field>

                        {projectGroups.length > 0 && (
                            <IdCheckboxGrid
                                id="group_project_groups"
                                name="project_groups"
                                label={t('groups.projectScope.label')}
                                hint={
                                    canChangeProjectScope
                                        ? t('groups.projectScope.hint')
                                        : t('groups.projectScope.adminsOnly')
                                }
                                options={projectGroups}
                                selected={form.data.project_groups}
                                onChange={(selected) =>
                                    form.setData('project_groups', selected)
                                }
                                error={
                                    form.errors.project_groups ??
                                    form.errors['project_groups.0']
                                }
                                disabled={readOnly || !canChangeProjectScope}
                            />
                        )}
                    </FieldGroup>
                </DrawerBody>

                <DrawerFooter className="flex flex-row justify-end gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onCancel?.()}
                    >
                        {t('common.cancel')}
                    </Button>
                    {!readOnly && (
                        <Button type="submit" disabled={form.processing}>
                            {editing ? t('common.save') : t('common.create')}
                        </Button>
                    )}
                </DrawerFooter>
            </form>
        </DrawerContent>
    );
}
