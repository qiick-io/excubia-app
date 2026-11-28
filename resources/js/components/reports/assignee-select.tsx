import { usePage, router } from '@inertiajs/react';
import type { ColumnDef } from '@tanstack/react-table';
import { UserCheck, UserPlus, UserRound } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { DataTableSort } from '@/components/admin/data-table';
import { DataTableColumnHeader } from '@/components/admin/data-table-column-header';
import { DataTableFacetedFilter } from '@/components/admin/data-table-faceted-filter';
import { HeaderIconButton } from '@/components/admin/header-icon-button';
import { TruncatedText } from '@/components/admin/truncated-text';
import { UserAvatar } from '@/components/admin/user-avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Field, FieldDescription, FieldLabel } from '@/components/ui/field';
import { Toggle } from '@/components/ui/toggle';
import {
    getQueryParam,
    locationPageUrl,
    patchLocationQuery,
} from '@/lib/admin-query-params';
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

/**
 * Saves who is on one exception group or finding (`null` unassigns), through its single assign
 * route: the drawer select, the row actions and the `a` shortcut all go through here.
 */
export function saveAssignee(url: string, assigneeId: number | null): void {
    router.put(
        url,
        { assigned_user_id: assigneeId },
        { preserveScroll: true, preserveState: true },
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
            {/* Wrapped so the icon is not the toggle's only element child: the registry Toggle
                drops its horizontal padding for icon-only toggles (`has-[>svg:only-child]:px-0`),
                and a bare text node does not count as a child element. */}
            <span>{t('assignee.mine')}</span>
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
    const choices = withCurrentAssignee(assignee, options);

    return (
        <Field>
            <FieldLabel htmlFor={id}>{t('assignee.label')}</FieldLabel>
            <Select
                value={assignee === null ? UNASSIGNED : String(assignee.id)}
                disabled={!canAssign}
                onValueChange={(value) =>
                    saveAssignee(
                        url,
                        value === UNASSIGNED ? null : Number(value),
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

/** The options a select or menu offers: the current assignee stays even if they lost access since. */
function withCurrentAssignee(
    assignee: AssigneeOption | null,
    options: AssigneeOption[],
): AssigneeOption[] {
    return assignee !== null &&
        !options.some((option) => option.id === assignee.id)
        ? [assignee, ...options]
        : options;
}

/**
 * The assignee column of the Exceptions and Findings lists (#332): the user's avatar and name, or
 * a muted "Unassigned". Sortable by name in the browser (the lists are loaded whole); unassigned
 * rows go last when ascending.
 */
export function assigneeColumn<Row>(
    title: string,
    getAssignee: (row: Row) => AssigneeOption | null,
): ColumnDef<Row> {
    return {
        id: 'assignee',
        accessorFn: (row) => getAssignee(row)?.name ?? '',
        enableSorting: true,
        meta: { cellClassName: 'max-w-[12rem]' },
        header: ({ column }) => (
            <DataTableColumnHeader column={column} title={title} />
        ),
        cell: ({ row }) => (
            <AssigneeCell assignee={getAssignee(row.original)} />
        ),
    };
}

function AssigneeCell({ assignee }: { assignee: AssigneeOption | null }) {
    const { t } = useTranslation();

    if (assignee === null) {
        return (
            <span className="text-muted-foreground">{t('assignee.none')}</span>
        );
    }

    return (
        <span
            className="inline-flex max-w-full min-w-0 items-center gap-2"
            data-test="assignee-cell"
        >
            <UserAvatar userId={assignee.id} name={assignee.name} size="sm" />
            <TruncatedText text={assignee.name} />
        </span>
    );
}

/**
 * The list's sort, kept in the URL like its filters (`?sort=assignee&direction=desc`). Only the
 * assignee column sorts; without a sort the rows keep the server's order.
 */
export function useAssigneeSort(): [
    DataTableSort | undefined,
    (sort: DataTableSort) => void,
] {
    const [sort, setSortState] = useState<DataTableSort | undefined>(() => {
        const pageUrl = locationPageUrl();

        return getQueryParam(pageUrl, 'sort') === 'assignee'
            ? {
                  id: 'assignee',
                  direction:
                      getQueryParam(pageUrl, 'direction') === 'desc'
                          ? 'desc'
                          : 'asc',
              }
            : undefined;
    });

    const setSort = (next: DataTableSort): void => {
        setSortState(next);
        patchLocationQuery({ sort: next.id, direction: next.direction });
    };

    return [sort, setSort];
}

/** The rows in the order of `sort`, by assignee name; unassigned rows last when ascending. */
export function sortByAssignee<Row>(
    rows: Row[],
    sort: DataTableSort | undefined,
    getAssignee: (row: Row) => AssigneeOption | null,
): Row[] {
    if (sort?.id !== 'assignee') {
        return rows;
    }

    const sign = sort.direction === 'desc' ? -1 : 1;

    return [...rows].sort((first, second) => {
        const firstName = getAssignee(first)?.name ?? null;
        const secondName = getAssignee(second)?.name ?? null;

        if (firstName === secondName) {
            return 0;
        }

        if (firstName === null) {
            return sign;
        }

        if (secondName === null) {
            return -sign;
        }

        return sign * firstName.localeCompare(secondName);
    });
}

/**
 * The assignee facet of the list's filter row (`?assignee=none,12`): "Unassigned" and each
 * user, several at once. Empty means everyone.
 */
export function useAssigneeFilter(): [string[], (values: string[]) => void] {
    const [values, setValuesState] = useState<string[]>(() =>
        (getQueryParam(locationPageUrl(), 'assignee') ?? '')
            .split(',')
            .filter((value) => value !== ''),
    );

    const setValues = (next: string[]): void => {
        setValuesState(next);
        patchLocationQuery({
            assignee: next.length > 0 ? next.join(',') : null,
        });
    };

    return [values, setValues];
}

/** Whether an item passes the assignee facet. */
export function matchesAssigneeFilter(
    assignee: AssigneeOption | null,
    values: string[],
): boolean {
    return (
        values.length === 0 ||
        values.includes(assignee === null ? UNASSIGNED : String(assignee.id))
    );
}

export function AssigneeFacetFilter({
    options,
    rowAssignees,
    selectedValues,
    onSelectedValuesChange,
}: {
    options: AssigneeOption[];
    /** The assignees of the rows, so someone who lost access can still be filtered on. */
    rowAssignees: (AssigneeOption | null)[];
    selectedValues: string[];
    onSelectedValuesChange: (values: string[]) => void;
}) {
    const { t } = useTranslation();
    const users = rowAssignees.reduce<AssigneeOption[]>(
        (all, assignee) => withCurrentAssignee(assignee, all),
        options,
    );

    return (
        <DataTableFacetedFilter
            title={t('assignee.column')}
            options={[
                { value: UNASSIGNED, label: t('assignee.none') },
                ...users.map((user) => ({
                    value: String(user.id),
                    label: user.name,
                })),
            ]}
            selectedValues={selectedValues}
            onSelectedValuesChange={onSelectedValuesChange}
        />
    );
}

/**
 * The assign actions of one row (#332), first in its actions column: "Assign to me" and
 * "Assign…" (a menu of the users who can see the project, plus "Unassigned"). Only for users who
 * may handle the item; the server checks again.
 */
export function AssigneeRowActions({
    url,
    itemLabel,
    assignee,
    options,
}: {
    url: string;
    /** What the row is, for the accessible names ("Assign Payment failed to me"). */
    itemLabel: string;
    assignee: AssigneeOption | null;
    options: AssigneeOption[];
}) {
    const { t } = useTranslation();
    const currentUserId = useCurrentUserId();
    const canAssignToMe =
        currentUserId !== null &&
        options.some((option) => option.id === currentUserId);

    return (
        <>
            {canAssignToMe ? (
                <HeaderIconButton
                    type="button"
                    size="icon-sm"
                    label={t('assignee.assignToMe', { item: itemLabel })}
                    disabled={assignee?.id === currentUserId}
                    onClick={() => saveAssignee(url, currentUserId)}
                    data-test="row-assign-me"
                >
                    <UserCheck className="size-3.5" />
                </HeaderIconButton>
            ) : null}
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <HeaderIconButton
                        type="button"
                        size="icon-sm"
                        label={t('assignee.assignTo', { item: itemLabel })}
                        data-test="row-assign"
                    >
                        <UserPlus className="size-3.5" />
                    </HeaderIconButton>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuLabel>{t('assignee.label')}</DropdownMenuLabel>
                    <DropdownMenuSeparator />
                    <DropdownMenuRadioGroup
                        value={
                            assignee === null ? UNASSIGNED : String(assignee.id)
                        }
                        onValueChange={(value) =>
                            saveAssignee(
                                url,
                                value === UNASSIGNED ? null : Number(value),
                            )
                        }
                    >
                        <DropdownMenuRadioItem value={UNASSIGNED}>
                            {t('assignee.none')}
                        </DropdownMenuRadioItem>
                        {withCurrentAssignee(assignee, options).map(
                            (option) => (
                                <DropdownMenuRadioItem
                                    key={option.id}
                                    value={String(option.id)}
                                >
                                    <UserAvatar
                                        userId={option.id}
                                        name={option.name}
                                        size="sm"
                                    />
                                    {option.name}
                                </DropdownMenuRadioItem>
                            ),
                        )}
                    </DropdownMenuRadioGroup>
                </DropdownMenuContent>
            </DropdownMenu>
        </>
    );
}
