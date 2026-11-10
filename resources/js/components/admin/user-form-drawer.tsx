import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import {
    RoleMultiSelect,
    withSelectedRoles,
} from '@/components/admin/role-multi-select';
import PasswordInput from '@/components/password-input';
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
import users from '@/routes/users';
import type { AdminRoleRef, AdminUserRow } from '@/types/admin';

const EMPTY_USER_FORM = {
    first_name: '',
    last_name: '',
    email: '',
    password: '',
    is_active: true,
    roles: [] as string[],
};

export type UserFormDrawerProps = {
    editing: AdminUserRow | null;
    /** Roles the current user may assign. */
    roles: AdminRoleRef[];
    readOnly?: boolean;
    open?: boolean;
    onCancel?: () => void;
    onSuccess?: () => void;
};

/**
 * Drawer form for creating and editing admin users.
 * @param {*} props - Component props.
 * @returns {JSX.Element}
 */
export function UserFormDrawer({
    editing,
    roles,
    readOnly = false,
    open = true,
    onCancel,
    onSuccess,
}: UserFormDrawerProps) {
    const form = useForm({ ...EMPTY_USER_FORM });
    const isCreate = editing === null;
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
                first_name: editing.first_name,
                last_name: editing.last_name ?? '',
                email: editing.email,
                password: '',
                is_active: editing.is_active,
                roles: editing.roles.map((role) => role.name),
            };
            form.setData(payload);
            form.setDefaults({ ...payload });
        } else {
            form.setDefaults({ ...EMPTY_USER_FORM });
            form.reset();
            form.clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps -- Inertia form identity is unstable
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
            form.transform((data) => ({
                ...data,
                password: data.password || undefined,
            }));
            form.put(users.update.url(editing.id), opts);
        } else {
            form.post(users.store.url(), opts);
        }
    };

    const title = editing ? 'Edit user' : 'New user';

    return (
        <DrawerContent>
            <DrawerHeader>
                <DrawerTitle>{title}</DrawerTitle>
                <DrawerDescription>
                    {editing
                        ? 'Update profile, status and direct roles. Leave the password empty to keep it.'
                        : 'Create a user and assign direct roles. The email counts as verified. Prefer groups when many users share the same role set.'}
                </DrawerDescription>
            </DrawerHeader>

            <form
                className="flex min-h-0 flex-1 flex-col overflow-hidden"
                noValidate
                onSubmit={(event) => {
                    event.preventDefault();

                    if (!readOnly) {
                        submit();
                    }
                }}
            >
                <DrawerBody>
                    <p className="mb-4 text-sm text-muted-foreground">
                        Fields marked with * are required.
                    </p>
                    <FieldGroup className="gap-4">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                data-invalid={
                                    form.errors.first_name ? true : undefined
                                }
                            >
                                <FieldLabel htmlFor="user_first_name">
                                    First name *
                                </FieldLabel>
                                <Input
                                    id="user_first_name"
                                    value={form.data.first_name}
                                    onChange={(event) =>
                                        form.setData(
                                            'first_name',
                                            event.target.value,
                                        )
                                    }
                                    required
                                    maxLength={STRING_LIMITS.NAME}
                                    disabled={readOnly}
                                    aria-invalid={
                                        form.errors.first_name
                                            ? true
                                            : undefined
                                    }
                                    aria-describedby={
                                        form.errors.first_name
                                            ? 'user-first-name-error'
                                            : undefined
                                    }
                                />
                                <FieldError id="user-first-name-error">
                                    {form.errors.first_name}
                                </FieldError>
                            </Field>
                            <Field
                                data-invalid={
                                    form.errors.last_name ? true : undefined
                                }
                            >
                                <FieldLabel htmlFor="user_last_name">
                                    Last name
                                </FieldLabel>
                                <Input
                                    id="user_last_name"
                                    value={form.data.last_name}
                                    onChange={(event) =>
                                        form.setData(
                                            'last_name',
                                            event.target.value,
                                        )
                                    }
                                    maxLength={STRING_LIMITS.NAME}
                                    disabled={readOnly}
                                    aria-invalid={
                                        form.errors.last_name ? true : undefined
                                    }
                                    aria-describedby={
                                        form.errors.last_name
                                            ? 'user-last-name-error'
                                            : undefined
                                    }
                                />
                                <FieldError id="user-last-name-error">
                                    {form.errors.last_name}
                                </FieldError>
                            </Field>
                        </div>

                        <Field
                            data-invalid={form.errors.email ? true : undefined}
                        >
                            <FieldLabel htmlFor="user_email">
                                Email *
                            </FieldLabel>
                            <Input
                                id="user_email"
                                type="email"
                                inputMode="email"
                                value={form.data.email}
                                onChange={(event) =>
                                    form.setData('email', event.target.value)
                                }
                                required
                                maxLength={STRING_LIMITS.EMAIL}
                                disabled={readOnly}
                                autoComplete="email"
                                aria-invalid={
                                    form.errors.email ? true : undefined
                                }
                                aria-describedby={
                                    form.errors.email
                                        ? 'user-email-error'
                                        : 'user_email_hint'
                                }
                            />
                            <FieldDescription id="user_email_hint">
                                Use a valid email address, for example{' '}
                                <code>name@example.com</code>.
                            </FieldDescription>
                            <FieldError id="user-email-error">
                                {form.errors.email}
                            </FieldError>
                        </Field>

                        <Field
                            data-invalid={
                                form.errors.password ? true : undefined
                            }
                        >
                            <FieldLabel htmlFor="user_password">
                                {editing
                                    ? 'New password (optional)'
                                    : 'Password *'}
                            </FieldLabel>
                            <PasswordInput
                                id="user_password"
                                value={form.data.password}
                                onChange={(event) =>
                                    form.setData('password', event.target.value)
                                }
                                required={isCreate}
                                disabled={readOnly}
                                autoComplete="new-password"
                                showGenerateButton={isCreate}
                                generateLabel="Generate secure password"
                                onGenerated={(password) =>
                                    form.setData('password', password)
                                }
                                showStrength={isCreate}
                                strengthId="user_password_strength"
                                aria-invalid={
                                    form.errors.password ? true : undefined
                                }
                                aria-describedby={
                                    form.errors.password
                                        ? 'user-password-error user_password_strength'
                                        : 'user_password_strength'
                                }
                            />
                            <FieldError id="user-password-error">
                                {form.errors.password}
                            </FieldError>
                        </Field>

                        <Field>
                            <div className="flex items-center gap-2">
                                <input
                                    id="user_is_active"
                                    type="checkbox"
                                    className="size-4 rounded border"
                                    checked={form.data.is_active}
                                    onChange={(event) =>
                                        form.setData(
                                            'is_active',
                                            event.target.checked,
                                        )
                                    }
                                    disabled={readOnly}
                                />
                                <FieldLabel
                                    htmlFor="user_is_active"
                                    className="font-normal"
                                >
                                    Active
                                </FieldLabel>
                            </div>
                            <FieldError id="user-is-active-error">
                                {form.errors.is_active}
                            </FieldError>
                        </Field>

                        <Field
                            data-invalid={
                                form.errors.roles || form.errors['roles.0']
                                    ? true
                                    : undefined
                            }
                        >
                            <FieldLabel>Roles *</FieldLabel>
                            <RoleMultiSelect
                                value={form.data.roles}
                                onChange={(selectedRoles) =>
                                    form.setData('roles', selectedRoles)
                                }
                                roles={roleOptions}
                                disabled={readOnly || isRolesLocked}
                                placeholder="Select at least one role"
                            />
                            {isRolesLocked && !readOnly && (
                                <FieldDescription id="user-roles-locked-hint">
                                    You cannot change your own roles.
                                </FieldDescription>
                            )}
                            <FieldError id="user-roles-error">
                                {form.errors.roles ?? form.errors['roles.0']}
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
