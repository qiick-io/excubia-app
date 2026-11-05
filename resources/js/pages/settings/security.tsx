import { Form, Head } from '@inertiajs/react';
import { useRef } from 'react';
import { useTranslation } from 'react-i18next';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import Heading from '@/components/heading';
import type { Props as ManagePasskeysProps } from '@/components/manage-passkeys';
import ManagePasskeys from '@/components/manage-passkeys';
import type { Props as ManageTwoFactorProps } from '@/components/manage-two-factor';
import ManageTwoFactor from '@/components/manage-two-factor';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Separator } from '@/components/ui/separator';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { edit } from '@/routes/security';
import type { BreadcrumbItem } from '@/types';

// oxfmt-ignore
type Props = {
    passwordRules: string;
} & ManagePasskeysProps &
    ManageTwoFactorProps;

/**
 * Password, two-factor, and passkey security settings.
 */
export default function Security({ passwordRules, ...props }: Props) {
    const { t } = useTranslation();
    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: t('settings.security.breadcrumb'),
            href: edit(),
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('settings.security.head')} />

            <h1 className="sr-only">{t('settings.security.head')}</h1>

            <SettingsLayout>
                <div className="space-y-12">
                    <div className="space-y-6">
                        <Heading
                            variant="small"
                            title={t('settings.security.passwordTitle')}
                            description={t(
                                'settings.security.passwordDescription',
                            )}
                        />

                        <Form
                            {...SecurityController.update.form()}
                            options={{
                                preserveScroll: true,
                            }}
                            resetOnError={[
                                'password',
                                'password_confirmation',
                                'current_password',
                            ]}
                            resetOnSuccess
                            onError={(formErrors) => {
                                if (formErrors.password) {
                                    passwordInput.current?.focus();
                                }

                                if (formErrors.current_password) {
                                    currentPasswordInput.current?.focus();
                                }
                            }}
                            className="space-y-6"
                            noValidate
                        >
                            {({
                                errors: formErrors,
                                processing,
                                recentlySuccessful,
                            }) => (
                                <>
                                    <FieldGroup className="gap-6">
                                        <Field
                                            data-invalid={
                                                formErrors.current_password
                                                    ? true
                                                    : undefined
                                            }
                                        >
                                            <FieldLabel htmlFor="current_password">
                                                {t(
                                                    'settings.security.currentPassword',
                                                )}
                                            </FieldLabel>

                                            <PasswordInput
                                                id="current_password"
                                                ref={currentPasswordInput}
                                                name="current_password"
                                                className="mt-1 block w-full"
                                                autoComplete="current-password"
                                                placeholder={t(
                                                    'settings.security.currentPassword',
                                                )}
                                                aria-invalid={
                                                    formErrors.current_password
                                                        ? true
                                                        : undefined
                                                }
                                                aria-describedby={
                                                    formErrors.current_password
                                                        ? 'current-password-error'
                                                        : undefined
                                                }
                                            />

                                            <FieldError id="current-password-error">
                                                {formErrors.current_password}
                                            </FieldError>
                                        </Field>

                                        <Field
                                            data-invalid={
                                                formErrors.password
                                                    ? true
                                                    : undefined
                                            }
                                        >
                                            <FieldLabel htmlFor="password">
                                                {t(
                                                    'settings.security.newPassword',
                                                )}
                                            </FieldLabel>

                                            <PasswordInput
                                                id="password"
                                                ref={passwordInput}
                                                name="password"
                                                className="mt-1 block w-full"
                                                autoComplete="new-password"
                                                placeholder={t(
                                                    'settings.security.newPassword',
                                                )}
                                                passwordrules={passwordRules}
                                                aria-invalid={
                                                    formErrors.password
                                                        ? true
                                                        : undefined
                                                }
                                                aria-describedby={
                                                    formErrors.password
                                                        ? 'password-error'
                                                        : undefined
                                                }
                                            />

                                            <FieldError id="password-error">
                                                {formErrors.password}
                                            </FieldError>
                                        </Field>

                                        <Field
                                            data-invalid={
                                                formErrors.password_confirmation
                                                    ? true
                                                    : undefined
                                            }
                                        >
                                            <FieldLabel htmlFor="password_confirmation">
                                                {t('common.confirmPassword')}
                                            </FieldLabel>

                                            <PasswordInput
                                                id="password_confirmation"
                                                name="password_confirmation"
                                                className="mt-1 block w-full"
                                                autoComplete="new-password"
                                                placeholder={t(
                                                    'common.confirmPassword',
                                                )}
                                                passwordrules={passwordRules}
                                                aria-invalid={
                                                    formErrors.password_confirmation
                                                        ? true
                                                        : undefined
                                                }
                                                aria-describedby={
                                                    formErrors.password_confirmation
                                                        ? 'password-confirmation-error'
                                                        : undefined
                                                }
                                            />

                                            <FieldError id="password-confirmation-error">
                                                {
                                                    formErrors.password_confirmation
                                                }
                                            </FieldError>
                                        </Field>
                                    </FieldGroup>

                                    <div className="flex items-center gap-4">
                                        <Button
                                            disabled={processing}
                                            data-test="update-password-button"
                                        >
                                            {t(
                                                'settings.security.savePassword',
                                            )}
                                        </Button>

                                        {recentlySuccessful ? (
                                            <p className="animate-in text-sm text-muted-foreground duration-200 ease-in-out fade-in">
                                                {t('common.saved')}
                                            </p>
                                        ) : null}
                                    </div>
                                </>
                            )}
                        </Form>
                    </div>

                    {props.canManageTwoFactor && <Separator />}

                    <ManageTwoFactor
                        canManageTwoFactor={props.canManageTwoFactor}
                        requiresConfirmation={props.requiresConfirmation}
                        twoFactorEnabled={props.twoFactorEnabled}
                    />

                    <ManagePasskeys
                        canManagePasskeys={props.canManagePasskeys}
                        passkeys={props.passkeys}
                    />
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
