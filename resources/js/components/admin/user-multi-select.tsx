import users from '@/routes/users';
import type { AdminUserRef } from '@/types/admin';
import { PaginatedMultiSelect } from './paginated-multi-select';
import type {
    PaginatedMultiSelectProps,
    PickerOption,
} from './paginated-multi-select';

function userOption(user: AdminUserRef): PickerOption {
    return {
        id: user.id,
        label: user.name ? `${user.name} (${user.email})` : user.email,
        first_name: user.name,
        email: user.email,
    };
}

export type UserMultiSelectProps = Omit<
    PaginatedMultiSelectProps,
    'fetchUrl' | 'placeholder' | 'showUserDetails'
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
    const resolvedInitial: PickerOption[] =
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
