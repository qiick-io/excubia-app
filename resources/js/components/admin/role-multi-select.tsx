import { useMemo } from 'react';
import users from '@/routes/users';
import type { AdminRoleRef } from '@/types/admin';
import { PaginatedMultiSelect } from './paginated-multi-select';
import type { PaginatedMultiSelectProps } from './paginated-multi-select';

export type RoleMultiSelectProps = Omit<
    PaginatedMultiSelectProps,
    'fetchUrl' | 'placeholder' | 'value' | 'onChange'
> & {
    placeholder?: string;
    /** Selected role names (Excubia validates roles by name). */
    value: string[];
    onChange: (value: string[]) => void;
    /** Roles the current user may assign (`StoreUserRequest::assignableRolesFor`). */
    roles: AdminRoleRef[];
};

/**
 * The assignable roles plus the ones already selected, so a read-only or locked picker still
 * shows roles the current user cannot grant (#151).
 * @param {AdminRoleRef[]} roles - Roles the current user may assign.
 * @param {AdminRoleRef[]} selected - Roles the edited record holds.
 * @returns {AdminRoleRef[]}
 */
export function withSelectedRoles(
    roles: AdminRoleRef[],
    selected: AdminRoleRef[],
): AdminRoleRef[] {
    return [
        ...roles,
        ...selected.filter(
            (role) => !roles.some((option) => option.id === role.id),
        ),
    ];
}

/**
 * Role picker on the registry picker, searching the roles the user may grant
 * (`users.assignable-roles`); maps names to ids. `roles` labels the selected ones.
 * @param {*} props - Component props.
 * @returns {JSX.Element}
 */
export function RoleMultiSelect({
    placeholder = 'Select roles…',
    value,
    onChange,
    roles,
    ...props
}: RoleMultiSelectProps) {
    const options = useMemo(
        () => roles.map((role) => ({ id: role.id, label: role.name })),
        [roles],
    );

    return (
        <PaginatedMultiSelect
            fetchUrl={users.assignableRoles.url()}
            initialOptions={options}
            placeholder={placeholder}
            value={roles
                .filter((role) => value.includes(role.name))
                .map((role) => role.id)}
            onChange={(ids) =>
                onChange(
                    roles
                        .filter((role) => ids.includes(role.id))
                        .map((role) => role.name),
                )
            }
            {...props}
        />
    );
}
