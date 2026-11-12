import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import AlertSubscriptionController from '@/actions/App/Http/Controllers/Settings/AlertSubscriptionController';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import BooleanField from '@/components/boolean-field';
import AlertFilterFields from '@/components/settings/alert-filter-fields';
import type {
    AlertFilter,
    AlertFilterProject,
    AlertFilterProjectGroup,
} from '@/components/settings/alert-filter-fields';
import { Button } from '@/components/ui/button';
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

/** The signed-in user's alert emails (#22) and in-app alerts (#296), from ProfileController::edit(). */
type AlertSubscription = {
    has_alert_emails: boolean;
    has_alert_in_app: boolean;
    filter: AlertFilter;
    events: string[];
    severities: string[];
    projects: AlertFilterProject[];
    projectGroups: AlertFilterProjectGroup[];
};

/**
 * User profile settings page, with the user's alert emails (#22) when they can see projects.
 */
export default function Profile({
    mustVerifyEmail,
    status,
    alertSubscription,
}: {
    mustVerifyEmail: boolean;
    status?: string;
    alertSubscription: AlertSubscription | null;
}) {
    const { t } = useTranslation();
    const { auth } = usePage().props;
    const user = auth.user;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('settings.layout.title'), href: edit() },
        {
            title: t('settings.layout.profile'),
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
                                                errors.first_name
                                                    ? true
                                                    : undefined
                                            }
                                        >
                                            <FieldLabel htmlFor="first_name">
                                                {t('common.firstName')}
                                            </FieldLabel>

                                            <Input
                                                id="first_name"
                                                className="mt-1 block w-full"
                                                defaultValue={user.first_name}
                                                name="first_name"
                                                required
                                                autoComplete="given-name"
                                                placeholder={t(
                                                    'common.firstName',
                                                )}
                                                aria-invalid={
                                                    errors.first_name
                                                        ? true
                                                        : undefined
                                                }
                                                aria-describedby={
                                                    errors.first_name
                                                        ? 'first-name-error'
                                                        : undefined
                                                }
                                            />

                                            <FieldError id="first-name-error">
                                                {errors.first_name}
                                            </FieldError>
                                        </Field>

                                        <Field
                                            data-invalid={
                                                errors.last_name
                                                    ? true
                                                    : undefined
                                            }
                                        >
                                            <FieldLabel htmlFor="last_name">
                                                {t('common.lastName')}
                                            </FieldLabel>

                                            <Input
                                                id="last_name"
                                                className="mt-1 block w-full"
                                                defaultValue={
                                                    user.last_name ?? ''
                                                }
                                                name="last_name"
                                                autoComplete="family-name"
                                                placeholder={t(
                                                    'common.lastName',
                                                )}
                                                aria-invalid={
                                                    errors.last_name
                                                        ? true
                                                        : undefined
                                                }
                                                aria-describedby={
                                                    errors.last_name
                                                        ? 'last-name-error'
                                                        : undefined
                                                }
                                            />

                                            <FieldError id="last-name-error">
                                                {errors.last_name}
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

                                        <BooleanField
                                            name="has_weekly_digest"
                                            label={t(
                                                'settings.profile.weeklyDigest',
                                            )}
                                            defaultChecked={Boolean(
                                                user.has_weekly_digest,
                                            )}
                                        />
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

                    {alertSubscription !== null ? (
                        <>
                            <Separator />
                            <AlertEmails subscription={alertSubscription} />
                        </>
                    ) : null}

                    <Separator />

                    <DeleteUser />
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}

/**
 * Alerts of the signed-in user: emails (#22) and in-app notifications (#296) on or off, the
 * filters both read, and a test mail.
 */
function AlertEmails({ subscription }: { subscription: AlertSubscription }) {
    const { t } = useTranslation();
    const [filter, setFilter] = useState(subscription.filter);
    const [isTesting, setIsTesting] = useState(false);

    const sendTest = (): void => {
        setIsTesting(true);
        router.post(
            AlertSubscriptionController.sendTest.url(),
            {},
            { preserveScroll: true, onFinish: () => setIsTesting(false) },
        );
    };

    return (
        <div className="space-y-6">
            <Heading
                variant="small"
                title={t('settings.profile.alertsTitle')}
                description={t('settings.profile.alertsDescription')}
            />

            <Form
                {...AlertSubscriptionController.update.form()}
                options={{ preserveScroll: true }}
                className="space-y-6"
                noValidate
            >
                {({ processing, recentlySuccessful, errors }) => (
                    <>
                        <BooleanField
                            name="has_alert_emails"
                            label={t('settings.profile.alertsEnabled')}
                            defaultChecked={subscription.has_alert_emails}
                        />

                        <BooleanField
                            name="has_alert_in_app"
                            label={t('settings.profile.alertsInApp')}
                            defaultChecked={subscription.has_alert_in_app}
                        />

                        <AlertFilterFields
                            name="alerts"
                            errorKey="alerts"
                            value={filter}
                            onChange={setFilter}
                            events={subscription.events}
                            severities={subscription.severities}
                            projects={subscription.projects}
                            projectGroups={subscription.projectGroups}
                            errors={errors}
                        />

                        <SettingsFormActions
                            processing={processing}
                            recentlySuccessful={recentlySuccessful}
                            data-test="update-alert-emails-button"
                        />
                    </>
                )}
            </Form>

            <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                <Button
                    type="button"
                    variant="secondary"
                    disabled={isTesting || !subscription.has_alert_emails}
                    onClick={sendTest}
                >
                    {t('settings.profile.alertsTest')}
                </Button>
                <p className="text-sm text-muted-foreground">
                    {t('settings.profile.alertsTestHint')}
                </p>
            </div>
        </div>
    );
}
