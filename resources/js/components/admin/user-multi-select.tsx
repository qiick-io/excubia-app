import users from '@/routes/users';
import type { AdminSelectOption, AdminUserRef } from '@/types/admin';
import { PaginatedMultiSelect } from './paginated-multi-select';
import type { PaginatedMultiSelectProps } from './paginated-multi-select';

function userOption(user: AdminUserRef): AdminSelectOption {
    return {
        id: user.id,
        label: user.name ? `${user.name} (${user.email})` : user.email,
        name: user.name,
        email: user.email,
    };
}

export type UserMultiSelectProps = Omit<
    PaginatedMultiSelectProps,
    'fetchUrl' | 'options' | 'placeholder' | 'showUserDetails'
> & {
    placeholder?: string;
    initialUsers?: AdminUserRef[] | null;
};

/**
 * User picker backed by paginated search (avatar + name / email).
 * @param {*} props - Component props.
 * @returns {JSX.Element}
 */
export function UserMultiSelect({
    placeholder = 'Select users…',
    initialUsers,
    initialOptions,
    ...props
}: UserMultiSelectProps) {
    const resolvedInitial: AdminSelectOption[] =
        initialOptions ?? initialUsers?.map(userOption) ?? [];

    return (
        <PaginatedMultiSelect
            fetchUrl={users.index.url()}
            placeholder={placeholder}
            initialOptions={resolvedInitial}
            showUserDetails
            {...props}
        />
    );
}
