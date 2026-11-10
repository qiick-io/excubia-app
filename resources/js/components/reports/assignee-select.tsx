import { router, usePage } from '@inertiajs/react';
import { UserRound } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Field, FieldDescription, FieldLabel } from '@/components/ui/field';
import { Toggle } from '@/components/ui/toggle';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { Auth } from '@/types';

/** A user an exception group or a finding may be assigned to (#254). */
export type AssigneeOption = { id: number; name: string };

/** Select value of "nobody"; user ids are numeric strings. */
const UNASSIGNED = 'none';

/** The `?assigned=me` deep link of the dashboard's "Assigned to you" card (#254). */
export function isAssignedToMeLinked(): boolean {
    return (
        typeof window !== 'undefined' &&
        new URLSearchParams(window.location.search).get('assigned') === 'me'
    );
}

/** The signed-in user's id, to filter what is assigned to them. */
export function useCurrentUserId(): number | null {
    const { auth } = usePage<{ auth: Auth }>().props;

    return auth.user?.id ?? null;
}

/**
 * The "Assigned to me" toggle of the Exceptions and Findings toolbars (#254), next to the
 * status filter.
 */
export function AssignedToMeToggle({
    isPressed,
    onPressedChange,
}: {
    isPressed: boolean;
    onPressedChange: (isPressed: boolean) => void;
}) {
    const { t } = useTranslation();

    return (
        <Toggle
            variant="outline"
            className="shrink-0"
            pressed={isPressed}
            onPressedChange={onPressedChange}
        >
            <UserRound />
            {t('assignee.mine')}
        </Toggle>
    );
}

/**
 * Who is on an exception group or a finding (#254), in its drawer: a select of the users who can
 * see the project, plus "Unassigned". Saving is immediate (PUT to `url`); without the right to
 * handle the item it is read-only.
 */
export function AssigneeSelect({
    id,
    url,
    assignee,
    options,
    canAssign,
}: {
    id: string;
    url: string;
    assignee: AssigneeOption | null;
    options: AssigneeOption[];
    canAssign: boolean;
}) {
    const { t } = useTranslation();
    // The current assignee stays selectable even if they lost access since.
    const choices =
        assignee !== null &&
        !options.some((option) => option.id === assignee.id)
            ? [assignee, ...options]
            : options;

    return (
        <Field>
            <FieldLabel htmlFor={id}>{t('assignee.label')}</FieldLabel>
            <Select
                value={assignee === null ? UNASSIGNED : String(assignee.id)}
                disabled={!canAssign}
                onValueChange={(value) =>
                    router.put(
                        url,
                        {
                            assigned_user_id:
                                value === UNASSIGNED ? null : Number(value),
                        },
                        { preserveScroll: true, preserveState: true },
                    )
                }
            >
                <SelectTrigger id={id} className="w-full sm:w-72">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value={UNASSIGNED}>
                        {t('assignee.none')}
                    </SelectItem>
                    {choices.map((option) => (
                        <SelectItem key={option.id} value={String(option.id)}>
                            {option.name}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            <FieldDescription>{t('assignee.hint')}</FieldDescription>
        </Field>
    );
}
