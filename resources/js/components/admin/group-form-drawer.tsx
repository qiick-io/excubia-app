import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import { RoleMultiSelect } from '@/components/admin/role-multi-select';
import { UserMultiSelect } from '@/components/admin/user-multi-select';
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
};

export type GroupFormDrawerProps = {
    editing: AdminGroupRow | null;
    /** Roles the current user may assign. */
    roles: AdminRoleRef[];
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
    readOnly = false,
    open = true,
    onCancel,
    onSuccess,
}: GroupFormDrawerProps) {
    const form = useForm({ ...EMPTY_GROUP_FORM });

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

    const title = editing ? 'Edit group' : 'New group';

    return (
        <DrawerContent>
            <DrawerHeader>
                <DrawerTitle>{title}</DrawerTitle>
                <DrawerDescription>
                    Members inherit the roles you pick here. Prefer groups for
                    large memberships instead of assigning roles user-by-user.
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
                            <FieldLabel htmlFor="group_name">Name</FieldLabel>
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
                                Description
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
                            <FieldLabel>Roles</FieldLabel>
                            <RoleMultiSelect
                                value={form.data.roles}
                                onChange={(selectedRoles) =>
                                    form.setData('roles', selectedRoles)
                                }
                                roles={roles}
                                disabled={readOnly}
                            />
                            <FieldDescription id="group-roles-hint">
                                Members inherit these roles’ permissions.
                            </FieldDescription>
                            <FieldError id="group-roles-error">
                                {form.errors.roles ?? form.errors['roles.0']}
                            </FieldError>
                        </Field>

                        <Field
                            data-invalid={form.errors.users ? true : undefined}
                        >
                            <FieldLabel>Users</FieldLabel>
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
                    </FieldGroup>
                </DrawerBody>

                <DrawerFooter className="flex flex-row justify-end gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onCancel?.()}
                    >
                        Cancel
                    </Button>
                    {!readOnly && (
                        <Button type="submit" disabled={form.processing}>
                            {editing ? 'Save' : 'Create'}
                        </Button>
                    )}
                </DrawerFooter>
            </form>
        </DrawerContent>
    );
}
