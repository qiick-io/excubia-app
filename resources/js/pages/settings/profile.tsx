import { Form, Head, Link, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/delete-user';
import Heading from '@/components/heading';
import { SettingsFormActions } from '@/components/settings-form-actions';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Separator } from '@/components/ui/separator';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';
import type { BreadcrumbItem } from '@/types';

/**
 * User profile settings page.
 */
export default function Profile({
    mustVerifyEmail,
    status,
}: {
    mustVerifyEmail: boolean;
    status?: string;
}) {
    const { t } = useTranslation();
    const { auth } = usePage().props;
    const user = auth.user;

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: t('settings.profile.breadcrumb'),
            href: edit(),
        },
    ];

    if (!user) {
        return null;
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('settings.profile.head')} />

            <h1 className="sr-only">{t('settings.profile.head')}</h1>

            <SettingsLayout>
                <div className="space-y-12">
                    <div className="space-y-6">
                        <Heading
                            variant="small"
                            title={t('settings.profile.title')}
                            description={t('settings.profile.description')}
                        />

                        <Form
                            {...ProfileController.update.form()}
                            options={{
                                preserveScroll: true,
                            }}
                            className="space-y-6"
                            noValidate
                        >
                            {({ processing, recentlySuccessful, errors }) => (
                                <>
                                    <FieldGroup className="gap-6">
                                        <Field
                                            data-invalid={
                                                errors.name ? true : undefined
                                            }
                                        >
                                            <FieldLabel htmlFor="name">
                                                {t('common.name')}
                                            </FieldLabel>

                                            <Input
                                                id="name"
                                                className="mt-1 block w-full"
                                                defaultValue={user.name}
                                                name="name"
                                                required
                                                autoComplete="name"
                                                placeholder={t('common.name')}
                                                aria-invalid={
                                                    errors.name
                                                        ? true
                                                        : undefined
                                                }
                                                aria-describedby={
                                                    errors.name
                                                        ? 'name-error'
                                                        : undefined
                                                }
                                            />

                                            <FieldError id="name-error">
                                                {errors.name}
                                            </FieldError>
                                        </Field>

                                        <Field
                                            data-invalid={
                                                errors.email ? true : undefined
                                            }
                                        >
                                            <FieldLabel htmlFor="email">
                                                {t('common.emailAddress')}
                                            </FieldLabel>

                                            <Input
                                                id="email"
                                                type="email"
                                                className="mt-1 block w-full"
                                                defaultValue={user.email}
                                                name="email"
                                                required
                                                autoComplete="username"
                                                placeholder={t(
                                                    'common.emailAddress',
                                                )}
                                                aria-invalid={
                                                    errors.email
                                                        ? true
                                                        : undefined
                                                }
                                                aria-describedby={
                                                    errors.email
                                                        ? 'email-error'
                                                        : undefined
                                                }
                                            />

                                            <FieldError id="email-error">
                                                {errors.email}
                                            </FieldError>
                                        </Field>
                                    </FieldGroup>

                                    {mustVerifyEmail &&
                                        user.email_verified_at === null && (
                                            <div>
                                                <p className="-mt-4 text-sm text-muted-foreground">
                                                    {t(
                                                        'settings.profile.unverified',
                                                    )}{' '}
                                                    <Link
                                                        href={send()}
                                                        as="button"
                                                        className="text-foreground underline decoration-muted-foreground underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current!"
                                                    >
                                                        {t(
                                                            'settings.profile.resend',
                                                        )}
                                                    </Link>
                                                </p>

                                                {status ===
                                                    'verification-link-sent' && (
                                                    <div className="mt-2 text-sm font-medium text-success">
                                                        {t(
                                                            'settings.profile.linkSent',
                                                        )}
                                                    </div>
                                                )}
                                            </div>
                                        )}

                                    <SettingsFormActions
                                        processing={processing}
                                        recentlySuccessful={recentlySuccessful}
                                        data-test="update-profile-button"
                                    />
                                </>
                            )}
                        </Form>
                    </div>

                    <Separator />

                    <DeleteUser />
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
