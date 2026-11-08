import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import ProjectSettingsController from '@/actions/App/Http/Controllers/Settings/ProjectSettingsController';
import Heading from '@/components/heading';
import { SettingsFormActions } from '@/components/settings-form-actions';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Separator } from '@/components/ui/separator';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { edit as editProject } from '@/routes/project';
import type { BreadcrumbItem, ProjectSettingsForm } from '@/types';

type GeneralProjectSettings = Pick<
    ProjectSettingsForm,
    | 'app_name'
    | 'description'
    | 'url'
    | 'report_issue_url'
    | 'report_bug_url'
    | 'report_error_url'
    | 'stale_after_hours'
    | 'pennant_stale_after_days'
    | 'reports_kept_per_project'
    | 'runtime_retention_days'
    | 'exceptions_retention_days'
    | 'activity_retention_days'
>;

type NumberField = {
    field:
        | 'stale_after_hours'
        | 'pennant_stale_after_days'
        | 'reports_kept_per_project'
        | 'runtime_retention_days'
        | 'exceptions_retention_days'
        | 'activity_retention_days';
    labelKey: string;
    hintKey: string;
    max: number;
};

type Props = {
    project: GeneralProjectSettings;
};

/** Number fields of the Checks section, with their bounds (UpdateProjectSettingsRequest). */
const CHECK_FIELDS: NumberField[] = [
    {
        field: 'stale_after_hours',
        labelKey: 'settings.project.staleAfterHours',
        hintKey: 'settings.project.staleAfterHoursHint',
        max: 8760,
    },
    {
        field: 'pennant_stale_after_days',
        labelKey: 'settings.project.pennantStaleAfterDays',
        hintKey: 'settings.project.pennantStaleAfterDaysHint',
        max: 3650,
    },
];

/** Number fields of the Data retention section, with their bounds. */
const RETENTION_FIELDS: NumberField[] = [
    {
        field: 'reports_kept_per_project',
        labelKey: 'settings.project.reportsKeptPerProject',
        hintKey: 'settings.project.reportsKeptPerProjectHint',
        max: 1000,
    },
    {
        field: 'runtime_retention_days',
        labelKey: 'settings.project.runtimeRetentionDays',
        hintKey: 'settings.project.runtimeRetentionDaysHint',
        max: 3650,
    },
    {
        field: 'exceptions_retention_days',
        labelKey: 'settings.project.exceptionsRetentionDays',
        hintKey: 'settings.project.exceptionsRetentionDaysHint',
        max: 3650,
    },
    {
        field: 'activity_retention_days',
        labelKey: 'settings.project.activityRetentionDays',
        hintKey: 'settings.project.activityRetentionDaysHint',
        max: 3650,
    },
];

/** URL fields of the Reporting section (externa-core). */
const REPORTING_FIELDS = [
    ['report_issue_url', 'settings.project.reportIssueUrl'],
    ['report_bug_url', 'settings.project.reportBugUrl'],
    ['report_error_url', 'settings.project.reportErrorUrl'],
] as const;

function buildFormState(project: GeneralProjectSettings) {
    return {
        app_name: project.app_name ?? '',
        description: project.description ?? '',
        url: project.url ?? '',
        report_issue_url: project.report_issue_url ?? '',
        report_bug_url: project.report_bug_url ?? '',
        report_error_url: project.report_error_url ?? '',
        stale_after_hours: project.stale_after_hours,
        pennant_stale_after_days: project.pennant_stale_after_days,
        reports_kept_per_project: project.reports_kept_per_project,
        runtime_retention_days: project.runtime_retention_days,
        exceptions_retention_days: project.exceptions_retention_days,
        activity_retention_days: project.activity_retention_days,
    };
}

/**
 * General project settings, as in externa-core (identity, reporting links), plus Checks and
 * Data retention.
 */
export default function ProjectSettingsPage({ project }: Props) {
    const { t } = useTranslation();
    const [form, setForm] = useState(() => buildFormState(project));

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: t('settings.project.breadcrumb'),
            href: editProject(),
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('settings.project.head')} />

            <h1 className="sr-only">{t('settings.project.head')}</h1>

            <SettingsLayout>
                <Form
                    {...ProjectSettingsController.update.form()}
                    options={{ preserveScroll: true }}
                    className="space-y-12"
                    noValidate
                >
                    {({ processing, recentlySuccessful, errors }) => (
                        <>
                            <div className="space-y-6">
                                <Heading
                                    variant="small"
                                    title={t('settings.project.generalTitle')}
                                    description={t(
                                        'settings.project.generalDescription',
                                    )}
                                />

                                <FieldGroup className="gap-6">
                                    <Field
                                        data-invalid={
                                            errors.app_name ? true : undefined
                                        }
                                    >
                                        <FieldLabel htmlFor="app_name">
                                            {t('settings.project.name')}
                                        </FieldLabel>
                                        <Input
                                            id="app_name"
                                            name="app_name"
                                            value={form.app_name}
                                            onChange={(event) =>
                                                setForm((current) => ({
                                                    ...current,
                                                    app_name:
                                                        event.target.value,
                                                }))
                                            }
                                            aria-invalid={
                                                errors.app_name
                                                    ? true
                                                    : undefined
                                            }
                                            aria-describedby={
                                                errors.app_name
                                                    ? 'project-name-error'
                                                    : undefined
                                            }
                                        />
                                        <FieldError id="project-name-error">
                                            {errors.app_name}
                                        </FieldError>
                                    </Field>

                                    <Field
                                        data-invalid={
                                            errors.description
                                                ? true
                                                : undefined
                                        }
                                    >
                                        <FieldLabel htmlFor="description">
                                            {t('settings.project.description')}
                                        </FieldLabel>
                                        <Textarea
                                            id="description"
                                            name="description"
                                            value={form.description}
                                            onChange={(event) =>
                                                setForm((current) => ({
                                                    ...current,
                                                    description:
                                                        event.target.value,
                                                }))
                                            }
                                            aria-invalid={
                                                errors.description
                                                    ? true
                                                    : undefined
                                            }
                                            aria-describedby={
                                                errors.description
                                                    ? 'project-description-error'
                                                    : undefined
                                            }
                                        />
                                        <FieldError id="project-description-error">
                                            {errors.description}
                                        </FieldError>
                                    </Field>

                                    <Field
                                        data-invalid={
                                            errors.url ? true : undefined
                                        }
                                    >
                                        <FieldLabel htmlFor="url">
                                            {t('settings.project.url')}
                                        </FieldLabel>
                                        <Input
                                            id="url"
                                            name="url"
                                            type="url"
                                            value={form.url}
                                            onChange={(event) =>
                                                setForm((current) => ({
                                                    ...current,
                                                    url: event.target.value,
                                                }))
                                            }
                                            placeholder="https://example.com"
                                            aria-invalid={
                                                errors.url ? true : undefined
                                            }
                                            aria-describedby={
                                                errors.url
                                                    ? 'project-url-error'
                                                    : undefined
                                            }
                                        />
                                        <FieldError id="project-url-error">
                                            {errors.url}
                                        </FieldError>
                                    </Field>
                                </FieldGroup>
                            </div>

                            <Separator />

                            <div className="space-y-6">
                                <Heading
                                    variant="small"
                                    title={t('settings.project.reportingTitle')}
                                    description={t(
                                        'settings.project.reportingDescription',
                                    )}
                                />

                                <FieldGroup className="gap-6">
                                    {REPORTING_FIELDS.map(
                                        ([field, labelKey]) => (
                                            <Field
                                                key={field}
                                                data-invalid={
                                                    errors[field]
                                                        ? true
                                                        : undefined
                                                }
                                            >
                                                <FieldLabel htmlFor={field}>
                                                    {t(labelKey)}
                                                </FieldLabel>
                                                <Input
                                                    id={field}
                                                    name={field}
                                                    type="url"
                                                    value={form[field]}
                                                    onChange={(event) =>
                                                        setForm((current) => ({
                                                            ...current,
                                                            [field]:
                                                                event.target
                                                                    .value,
                                                        }))
                                                    }
                                                    aria-invalid={
                                                        errors[field]
                                                            ? true
                                                            : undefined
                                                    }
                                                    aria-describedby={
                                                        errors[field]
                                                            ? `project-${field}-error`
                                                            : undefined
                                                    }
                                                />
                                                <FieldError
                                                    id={`project-${field}-error`}
                                                >
                                                    {errors[field]}
                                                </FieldError>
                                            </Field>
                                        ),
                                    )}
                                </FieldGroup>
                            </div>

                            <Separator />

                            <div className="space-y-6">
                                <Heading
                                    variant="small"
                                    title={t('settings.project.checksTitle')}
                                    description={t(
                                        'settings.project.checksDescription',
                                    )}
                                />

                                <FieldGroup className="gap-6">
                                    {CHECK_FIELDS.map(
                                        ({ field, labelKey, hintKey, max }) => (
                                            <Field
                                                key={field}
                                                data-invalid={
                                                    errors[field]
                                                        ? true
                                                        : undefined
                                                }
                                            >
                                                <FieldLabel htmlFor={field}>
                                                    {t(labelKey)}
                                                </FieldLabel>
                                                <Input
                                                    id={field}
                                                    name={field}
                                                    type="number"
                                                    min={1}
                                                    max={max}
                                                    required
                                                    value={form[field]}
                                                    onChange={(event) =>
                                                        setForm((current) => ({
                                                            ...current,
                                                            [field]: Number(
                                                                event.target
                                                                    .value,
                                                            ),
                                                        }))
                                                    }
                                                    aria-invalid={
                                                        errors[field]
                                                            ? true
                                                            : undefined
                                                    }
                                                    aria-describedby={
                                                        errors[field]
                                                            ? `${field}-error`
                                                            : `${field}-hint`
                                                    }
                                                />
                                                <FieldDescription
                                                    id={`${field}-hint`}
                                                >
                                                    {t(hintKey)}
                                                </FieldDescription>
                                                <FieldError
                                                    id={`${field}-error`}
                                                >
                                                    {errors[field]}
                                                </FieldError>
                                            </Field>
                                        ),
                                    )}
                                </FieldGroup>
                            </div>

                            <Separator />

                            <div className="space-y-6">
                                <Heading
                                    variant="small"
                                    title={t('settings.project.retentionTitle')}
                                    description={t(
                                        'settings.project.retentionDescription',
                                    )}
                                />

                                <FieldGroup className="gap-6">
                                    {RETENTION_FIELDS.map(
                                        ({ field, labelKey, hintKey, max }) => (
                                            <Field
                                                key={field}
                                                data-invalid={
                                                    errors[field]
                                                        ? true
                                                        : undefined
                                                }
                                            >
                                                <FieldLabel htmlFor={field}>
                                                    {t(labelKey)}
                                                </FieldLabel>
                                                <Input
                                                    id={field}
                                                    name={field}
                                                    type="number"
                                                    min={1}
                                                    max={max}
                                                    required
                                                    value={form[field]}
                                                    onChange={(event) =>
                                                        setForm((current) => ({
                                                            ...current,
                                                            [field]: Number(
                                                                event.target
                                                                    .value,
                                                            ),
                                                        }))
                                                    }
                                                    aria-invalid={
                                                        errors[field]
                                                            ? true
                                                            : undefined
                                                    }
                                                    aria-describedby={
                                                        errors[field]
                                                            ? `${field}-error`
                                                            : `${field}-hint`
                                                    }
                                                />
                                                <FieldDescription
                                                    id={`${field}-hint`}
                                                >
                                                    {t(hintKey)}
                                                </FieldDescription>
                                                <FieldError
                                                    id={`${field}-error`}
                                                >
                                                    {errors[field]}
                                                </FieldError>
                                            </Field>
                                        ),
                                    )}
                                </FieldGroup>
                            </div>

                            <SettingsFormActions
                                processing={processing}
                                recentlySuccessful={recentlySuccessful}
                                data-test="project-settings-save"
                            />
                        </>
                    )}
                </Form>
            </SettingsLayout>
        </AppLayout>
    );
}
