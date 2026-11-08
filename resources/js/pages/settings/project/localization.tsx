import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import ProjectLocalizationSettingsController from '@/actions/App/Http/Controllers/Settings/ProjectLocalizationSettingsController';
import Heading from '@/components/heading';
import { SettingsFormActions } from '@/components/settings-form-actions';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { edit as editLocalization } from '@/routes/project/localization';
import type { BreadcrumbItem, ProjectSettingsForm } from '@/types';
import { edit as editProfile } from '@/routes/profile';

type LocalizationProjectSettings = Pick<
    ProjectSettingsForm,
    'default_language'
>;

type Props = {
    project: LocalizationProjectSettings;
    availableLocales: Record<string, string>;
};

/**
 * Localization project settings (default admin UI language).
 */
export default function ProjectLocalizationSettingsPage({
    project,
    availableLocales,
}: Props) {
    const { t } = useTranslation();
    const [defaultLanguage, setDefaultLanguage] = useState(
        project.default_language,
    );

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('settings.layout.title'), href: editProfile() },
        {
            title: t('settings.layout.localization'),
            href: editLocalization(),
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('settings.project.localization.head')} />

            <h1 className="sr-only">
                {t('settings.project.localization.head')}
            </h1>

            <SettingsLayout>
                <Form
                    {...ProjectLocalizationSettingsController.update.form()}
                    options={{ preserveScroll: true }}
                    className="space-y-12"
                    noValidate
                >
                    {({ processing, recentlySuccessful, errors }) => (
                        <>
                            <input
                                type="hidden"
                                name="default_language"
                                value={defaultLanguage}
                            />

                            <div className="space-y-6">
                                <Heading
                                    variant="small"
                                    title={t(
                                        'settings.project.localization.title',
                                    )}
                                    description={t(
                                        'settings.project.localization.description',
                                    )}
                                />

                                <FieldGroup>
                                    <Field
                                        data-invalid={
                                            errors.default_language
                                                ? true
                                                : undefined
                                        }
                                    >
                                        <FieldLabel htmlFor="default_language">
                                            {t(
                                                'settings.project.defaultLanguage',
                                            )}
                                        </FieldLabel>
                                        <Select
                                            value={defaultLanguage}
                                            onValueChange={setDefaultLanguage}
                                        >
                                            <SelectTrigger
                                                id="default_language"
                                                className="w-full"
                                                aria-invalid={
                                                    errors.default_language
                                                        ? true
                                                        : undefined
                                                }
                                                aria-describedby={
                                                    errors.default_language
                                                        ? 'default-language-error'
                                                        : undefined
                                                }
                                            >
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {Object.entries(
                                                    availableLocales,
                                                ).map(([code, label]) => (
                                                    <SelectItem
                                                        key={code}
                                                        value={code}
                                                    >
                                                        {label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <FieldError id="default-language-error">
                                            {errors.default_language}
                                        </FieldError>
                                    </Field>
                                </FieldGroup>
                            </div>

                            <SettingsFormActions
                                processing={processing}
                                recentlySuccessful={recentlySuccessful}
                                data-test="project-localization-settings-save"
                            />
                        </>
                    )}
                </Form>
            </SettingsLayout>
        </AppLayout>
    );
}
