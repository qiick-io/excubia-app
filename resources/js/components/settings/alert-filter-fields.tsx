import { ChevronDownIcon } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

/** Mirror of `App\Data\AlertFilterData::toArray()`. */
export type AlertFilter = {
    minimum_severity: string;
    event_types: string[];
    project_ids: number[];
    project_group_ids: number[];
};

export type AlertFilterProject = { id: number; name: string };

/** A project group an alert filter may pick (#252). */
export type AlertFilterProjectGroup = { id: number; name: string };

/**
 * The group of an event by its prefix (`finding.new` → findings), so the picker reads as a few
 * categories instead of one list of every event (#205). An unknown prefix lands in `other`.
 */
const EVENT_GROUP_BY_PREFIX: Record<string, string> = {
    finding: 'findings',
    report: 'findings',
    eol: 'findings',
    project: 'projects',
    push: 'projects',
    health: 'health',
    infrastructure: 'health',
    schedule: 'schedule',
    runtime: 'runtime',
    auth: 'runtime',
    scout: 'runtime',
    ai: 'ai',
    billing: 'billing',
};

const EVENT_GROUPS = [
    'findings',
    'projects',
    'health',
    'schedule',
    'runtime',
    'ai',
    'billing',
    'other',
];

/** The events of each group, in group order; groups without events are left out. */
function groupEvents(
    events: string[],
): { group: string; groupEvents: string[] }[] {
    return EVENT_GROUPS.map((group) => ({
        group,
        groupEvents: events.filter(
            (event) =>
                (EVENT_GROUP_BY_PREFIX[event.split('.')[0]] ?? 'other') ===
                group,
        ),
    })).filter(({ groupEvents }) => groupEvents.length > 0);
}

/** Adds or removes one item of a list. */
function toggle<T>(items: T[], item: T, isChecked: boolean): T[] {
    return isChecked
        ? [...items, item]
        : items.filter((current) => current !== item);
}

/**
 * A checkbox grid of named records (projects, project groups) posted as `<name>[]` hidden
 * inputs, for the project scope of an alert filter.
 */
function IdCheckboxGrid({
    id,
    name,
    label,
    hint,
    options,
    selected,
    onChange,
    error,
}: {
    id: string;
    name: string;
    label: string;
    hint: string;
    options: { id: number; name: string }[];
    selected: number[];
    onChange: (selected: number[]) => void;
    error?: string;
}) {
    return (
        <Field data-invalid={error ? true : undefined}>
            <div className="space-y-1">
                <FieldLabel>{label}</FieldLabel>
                <FieldDescription>{hint}</FieldDescription>
            </div>
            {selected.map((selectedId) => (
                <input
                    key={selectedId}
                    type="hidden"
                    name={`${name}[]`}
                    value={selectedId}
                />
            ))}
            <div className="grid gap-2 sm:grid-cols-2">
                {options.map((option) => (
                    <label
                        key={option.id}
                        htmlFor={`${id}-${option.id}`}
                        className="flex cursor-pointer items-center gap-3 rounded-md border border-border/60 p-3 text-sm"
                    >
                        <Checkbox
                            id={`${id}-${option.id}`}
                            checked={selected.includes(option.id)}
                            onCheckedChange={(isChecked) =>
                                onChange(
                                    toggle(
                                        selected,
                                        option.id,
                                        isChecked === true,
                                    ),
                                )
                            }
                        />
                        <span className="min-w-0 truncate">{option.name}</span>
                    </label>
                ))}
            </div>
            <FieldError>{error}</FieldError>
        </Field>
    );
}

/**
 * The filters of one alert channel or of a user's alert emails (#22): minimum severity, event
 * types, and projects and project groups (#252; none checked: every project). Controlled; the
 * values are posted through hidden inputs named `<name>[minimum_severity]`,
 * `<name>[event_types][]`, `<name>[project_group_ids][]` and `<name>[project_ids][]`, and the
 * errors read under the dotted `errorKey`.
 */
export default function AlertFilterFields({
    name,
    errorKey,
    value,
    onChange,
    events,
    severities,
    projects,
    projectGroups = [],
    errors,
}: {
    name: string;
    errorKey: string;
    value: AlertFilter;
    onChange: (value: AlertFilter) => void;
    events: string[];
    severities: string[];
    projects: AlertFilterProject[];
    projectGroups?: AlertFilterProjectGroup[];
    errors: Record<string, string | undefined>;
}) {
    const { t } = useTranslation();
    const id = name.replace(/[^a-z0-9]+/gi, '-');
    const errorOf = (key: string): string | undefined =>
        Object.entries(errors).find(
            ([field]) =>
                field === `${errorKey}.${key}` ||
                field.startsWith(`${errorKey}.${key}.`),
        )?.[1];

    return (
        <div className="grid gap-6">
            <Field
                data-invalid={errorOf('minimum_severity') ? true : undefined}
            >
                <FieldLabel htmlFor={`${id}-severity`}>
                    {t('settings.integrations.minimumSeverity')}
                </FieldLabel>
                <input
                    type="hidden"
                    name={`${name}[minimum_severity]`}
                    value={value.minimum_severity}
                />
                <Select
                    value={value.minimum_severity}
                    onValueChange={(severity) =>
                        onChange({ ...value, minimum_severity: severity })
                    }
                >
                    <SelectTrigger
                        id={`${id}-severity`}
                        className="w-full sm:w-64"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {severities.map((severity) => (
                            <SelectItem key={severity} value={severity}>
                                {t(`report.severity.${severity}`)}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <FieldDescription>
                    {t('settings.integrations.minimumSeverityHint')}
                </FieldDescription>
                <FieldError>{errorOf('minimum_severity')}</FieldError>
            </Field>

            <EventTypesField
                id={id}
                name={name}
                events={events}
                selected={value.event_types}
                onChange={(eventTypes) =>
                    onChange({ ...value, event_types: eventTypes })
                }
                error={errorOf('event_types')}
            />

            {projectGroups.length > 0 ? (
                <IdCheckboxGrid
                    id={`${id}-project-group`}
                    name={`${name}[project_group_ids]`}
                    label={t('settings.integrations.projectGroups')}
                    hint={t('settings.integrations.projectGroupsHint')}
                    options={projectGroups}
                    selected={value.project_group_ids}
                    onChange={(projectGroupIds) =>
                        onChange({
                            ...value,
                            project_group_ids: projectGroupIds,
                        })
                    }
                    error={errorOf('project_group_ids')}
                />
            ) : null}

            {projects.length > 0 ? (
                <IdCheckboxGrid
                    id={`${id}-project`}
                    name={`${name}[project_ids]`}
                    label={t('settings.integrations.projects')}
                    hint={t('settings.integrations.projectsHint')}
                    options={projects}
                    selected={value.project_ids}
                    onChange={(projectIds) =>
                        onChange({ ...value, project_ids: projectIds })
                    }
                    error={errorOf('project_ids')}
                />
            ) : null}
        </div>
    );
}

/**
 * The event types of one filter, short by default (#205): "All events" posts every event, while
 * "Selected events" shows how many are on and opens a picker grouped by category, each group
 * with a checkbox that turns all of its events on or off. The stored shape does not change: the
 * checked events are posted as `<name>[event_types][]`, and an empty list still means no event.
 */
function EventTypesField({
    id,
    name,
    events,
    selected,
    onChange,
    error,
}: {
    id: string;
    name: string;
    events: string[];
    selected: string[];
    onChange: (eventTypes: string[]) => void;
    error?: string;
}) {
    const { t } = useTranslation();
    const [isSelecting, setIsSelecting] = useState(
        events.some((event) => !selected.includes(event)),
    );
    const [isPickerOpen, setIsPickerOpen] = useState(error !== undefined);

    const selectMode = (mode: string): void => {
        if (mode === 'all') {
            setIsSelecting(false);
            onChange(events);

            return;
        }

        setIsSelecting(true);
        setIsPickerOpen(true);
    };

    const toggleGroup = (groupEvents: string[], isChecked: boolean): void =>
        onChange(
            isChecked
                ? [
                      ...selected,
                      ...groupEvents.filter(
                          (event) => !selected.includes(event),
                      ),
                  ]
                : selected.filter((event) => !groupEvents.includes(event)),
        );

    return (
        <Field data-invalid={error ? true : undefined}>
            <div className="space-y-1">
                <FieldLabel id={`${id}-events-label`}>
                    {t('settings.integrations.eventTypes')}
                </FieldLabel>
                <FieldDescription>
                    {t('settings.integrations.eventTypesHint')}
                </FieldDescription>
            </div>
            {selected.map((event) => (
                <input
                    key={event}
                    type="hidden"
                    name={`${name}[event_types][]`}
                    value={event}
                />
            ))}
            <RadioGroup
                value={isSelecting ? 'selected' : 'all'}
                onValueChange={selectMode}
                aria-labelledby={`${id}-events-label`}
                className="flex flex-col gap-3 sm:flex-row sm:gap-6"
            >
                {(['all', 'selected'] as const).map((mode) => (
                    <div key={mode} className="flex items-center gap-2">
                        <RadioGroupItem
                            id={`${id}-events-${mode}`}
                            value={mode}
                        />
                        <Label
                            htmlFor={`${id}-events-${mode}`}
                            className="font-normal"
                        >
                            {t(
                                mode === 'all'
                                    ? 'settings.integrations.eventsAll'
                                    : 'settings.integrations.eventsSelected',
                            )}
                        </Label>
                    </div>
                ))}
            </RadioGroup>

            {isSelecting ? (
                <Collapsible open={isPickerOpen} onOpenChange={setIsPickerOpen}>
                    <CollapsibleTrigger asChild>
                        <Button type="button" variant="outline">
                            {t('settings.integrations.eventsCount', {
                                selected: selected.length,
                                total: events.length,
                            })}
                            <ChevronDownIcon
                                aria-hidden="true"
                                className={
                                    isPickerOpen
                                        ? 'rotate-180 transition-transform'
                                        : 'transition-transform'
                                }
                            />
                        </Button>
                    </CollapsibleTrigger>
                    <CollapsibleContent>
                        <div className="grid gap-6 pt-4 lg:grid-cols-2">
                            {groupEvents(events).map(
                                ({ group, groupEvents }) => {
                                    const checkedCount = groupEvents.filter(
                                        (event) => selected.includes(event),
                                    ).length;

                                    return (
                                        <div key={group} className="space-y-2">
                                            <label
                                                htmlFor={`${id}-group-${group}`}
                                                className="flex cursor-pointer items-center gap-3 border-b pb-2 text-sm font-medium"
                                            >
                                                <Checkbox
                                                    id={`${id}-group-${group}`}
                                                    checked={
                                                        checkedCount ===
                                                        groupEvents.length
                                                            ? true
                                                            : checkedCount > 0
                                                              ? 'indeterminate'
                                                              : false
                                                    }
                                                    onCheckedChange={(
                                                        isChecked,
                                                    ) =>
                                                        toggleGroup(
                                                            groupEvents,
                                                            isChecked === true,
                                                        )
                                                    }
                                                />
                                                <span className="flex-1">
                                                    {t(
                                                        `settings.integrations.eventGroups.${group}`,
                                                    )}
                                                </span>
                                                <span className="text-muted-foreground tabular-nums">
                                                    {`${checkedCount}/${groupEvents.length}`}
                                                </span>
                                            </label>
                                            {groupEvents.map((event) => (
                                                <label
                                                    key={event}
                                                    htmlFor={`${id}-event-${event}`}
                                                    className="flex cursor-pointer items-start gap-3 py-1"
                                                >
                                                    <Checkbox
                                                        id={`${id}-event-${event}`}
                                                        className="mt-0.5"
                                                        checked={selected.includes(
                                                            event,
                                                        )}
                                                        onCheckedChange={(
                                                            isChecked,
                                                        ) =>
                                                            onChange(
                                                                toggle(
                                                                    selected,
                                                                    event,
                                                                    isChecked ===
                                                                        true,
                                                                ),
                                                            )
                                                        }
                                                    />
                                                    <span className="min-w-0 space-y-0.5">
                                                        <span className="block font-mono text-sm">
                                                            {event}
                                                        </span>
                                                        <span className="block text-sm text-muted-foreground">
                                                            {t(
                                                                `settings.integrations.events.${event}`,
                                                            )}
                                                        </span>
                                                    </span>
                                                </label>
                                            ))}
                                        </div>
                                    );
                                },
                            )}
                        </div>
                    </CollapsibleContent>
                </Collapsible>
            ) : null}

            {isSelecting && selected.length === 0 ? (
                <FieldDescription>
                    {t('settings.integrations.eventsNone')}
                </FieldDescription>
            ) : null}
            <FieldError>{error}</FieldError>
        </Field>
    );
}
