import { useMemo } from 'react';
import type { AdminRoleRef, AdminSelectOption } from '@/types/admin';
import { PaginatedMultiSelect } from './paginated-multi-select';
import type { PaginatedMultiSelectProps } from './paginated-multi-select';

export type RoleMultiSelectProps = Omit<
    PaginatedMultiSelectProps,
    'fetchUrl' | 'options' | 'placeholder' | 'value' | 'onChange'
> & {
    placeholder?: string;
    /** Selected role names (Excubia validates roles by name). */
    value: string[];
    onChange: (value: string[]) => void;
    /** Roles the current user may assign (`StoreUserRequest::assignableRolesFor`). */
    roles: AdminRoleRef[];
};

/**
 * Role picker over the assignable roles shared by the page; maps names to ids for the select.
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
    const options: AdminSelectOption[] = useMemo(
        () => roles.map((role) => ({ id: role.id, label: role.name })),
        [roles],
    );

    return (
        <PaginatedMultiSelect
            options={options}
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
