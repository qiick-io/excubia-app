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
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { edit as editProject } from '@/routes/project';
import type { BreadcrumbItem, ProjectSettingsForm } from '@/types';

type GeneralProjectSettings = Pick<
    ProjectSettingsForm,
    | 'app_name'
    | 'report_bug_url'
    | 'reports_kept_per_project'
    | 'stale_after_hours'
>;

type Props = {
    project: GeneralProjectSettings;
};

/** Number fields of the Checks section, with their bounds (UpdateProjectSettingsRequest). */
const CHECK_FIELDS = [
    {
        field: 'reports_kept_per_project',
        labelKey: 'settings.project.reportsKeptPerProject',
        hintKey: 'settings.project.reportsKeptPerProjectHint',
        max: 1000,
    },
    {
        field: 'stale_after_hours',
        labelKey: 'settings.project.staleAfterHours',
        hintKey: 'settings.project.staleAfterHoursHint',
        max: 8760,
    },
] as const;

function buildFormState(project: GeneralProjectSettings) {
    return {
        app_name: project.app_name ?? '',
        report_bug_url: project.report_bug_url ?? '',
        reports_kept_per_project: project.reports_kept_per_project,
        stale_after_hours: project.stale_after_hours,
    };
}

/**
 * General project settings (identity, bug report link, checks).
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
                                    <Field
                                        data-invalid={
                                            errors.report_bug_url
                                                ? true
                                                : undefined
                                        }
                                    >
                                        <FieldLabel htmlFor="report_bug_url">
                                            {t('settings.project.reportBugUrl')}
                                        </FieldLabel>
                                        <Input
                                            id="report_bug_url"
                                            name="report_bug_url"
                                            type="url"
                                            value={form.report_bug_url}
                                            onChange={(event) =>
                                                setForm((current) => ({
                                                    ...current,
                                                    report_bug_url:
                                                        event.target.value,
                                                }))
                                            }
                                            aria-invalid={
                                                errors.report_bug_url
                                                    ? true
                                                    : undefined
                                            }
                                            aria-describedby={
                                                errors.report_bug_url
                                                    ? 'project-report-bug-url-error'
                                                    : undefined
                                            }
                                        />
                                        <FieldError id="project-report-bug-url-error">
                                            {errors.report_bug_url}
                                        </FieldError>
                                    </Field>
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
