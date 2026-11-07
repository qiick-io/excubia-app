import { useTranslation } from 'react-i18next';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
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
};

export type AlertFilterProject = { id: number; name: string };

/** Adds or removes one item of a list. */
function toggle<T>(items: T[], item: T, isChecked: boolean): T[] {
    return isChecked
        ? [...items, item]
        : items.filter((current) => current !== item);
}

/**
 * The filters of one alert channel or of a user's alert emails (#22): minimum severity, event
 * types and projects (none checked: every project). Controlled; the values are posted through
 * hidden inputs named `<name>[minimum_severity]`, `<name>[event_types][]` and
 * `<name>[project_ids][]`, and the errors read under the dotted `errorKey`.
 */
export default function AlertFilterFields({
    name,
    errorKey,
    value,
    onChange,
    events,
    severities,
    projects,
    errors,
}: {
    name: string;
    errorKey: string;
    value: AlertFilter;
    onChange: (value: AlertFilter) => void;
    events: string[];
    severities: string[];
    projects: AlertFilterProject[];
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

            <Field data-invalid={errorOf('event_types') ? true : undefined}>
                <div className="space-y-1">
                    <FieldLabel>
                        {t('settings.integrations.eventTypes')}
                    </FieldLabel>
                    <FieldDescription>
                        {t('settings.integrations.eventTypesHint')}
                    </FieldDescription>
                </div>
                {value.event_types.map((event) => (
                    <input
                        key={event}
                        type="hidden"
                        name={`${name}[event_types][]`}
                        value={event}
                    />
                ))}
                <div className="grid gap-2 sm:grid-cols-2">
                    {events.map((event) => (
                        <label
                            key={event}
                            htmlFor={`${id}-event-${event}`}
                            className="flex cursor-pointer items-start gap-3 rounded-md border border-border/60 p-3"
                        >
                            <Checkbox
                                id={`${id}-event-${event}`}
                                className="mt-0.5"
                                checked={value.event_types.includes(event)}
                                onCheckedChange={(isChecked) =>
                                    onChange({
                                        ...value,
                                        event_types: toggle(
                                            value.event_types,
                                            event,
                                            isChecked === true,
                                        ),
                                    })
                                }
                            />
                            <span className="min-w-0 space-y-0.5">
                                <span className="block font-mono text-sm">
                                    {event}
                                </span>
                                <span className="block text-sm text-muted-foreground">
                                    {t(`settings.integrations.events.${event}`)}
                                </span>
                            </span>
                        </label>
                    ))}
                </div>
                <FieldError>{errorOf('event_types')}</FieldError>
            </Field>

            {projects.length > 0 ? (
                <Field data-invalid={errorOf('project_ids') ? true : undefined}>
                    <div className="space-y-1">
                        <FieldLabel>
                            {t('settings.integrations.projects')}
                        </FieldLabel>
                        <FieldDescription>
                            {t('settings.integrations.projectsHint')}
                        </FieldDescription>
                    </div>
                    {value.project_ids.map((projectId) => (
                        <input
                            key={projectId}
                            type="hidden"
                            name={`${name}[project_ids][]`}
                            value={projectId}
                        />
                    ))}
                    <div className="grid gap-2 sm:grid-cols-2">
                        {projects.map((project) => (
                            <label
                                key={project.id}
                                htmlFor={`${id}-project-${project.id}`}
                                className="flex cursor-pointer items-center gap-3 rounded-md border border-border/60 p-3 text-sm"
                            >
                                <Checkbox
                                    id={`${id}-project-${project.id}`}
                                    checked={value.project_ids.includes(
                                        project.id,
                                    )}
                                    onCheckedChange={(isChecked) =>
                                        onChange({
                                            ...value,
                                            project_ids: toggle(
                                                value.project_ids,
                                                project.id,
                                                isChecked === true,
                                            ),
                                        })
                                    }
                                />
                                <span className="min-w-0 truncate">
                                    {project.name}
                                </span>
                            </label>
                        ))}
                    </div>
                    <FieldError>{errorOf('project_ids')}</FieldError>
                </Field>
            ) : null}
        </div>
    );
}
