import { Form, Head, Link } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import PasswordInput from '@/components/password-input';
import { CopySnippet, SCHEDULE_LINE } from '@/components/projects/copy-snippet';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useClipboard } from '@/hooks/use-clipboard';
import AuthLayout from '@/layouts/auth-layout';
import { dashboard } from '@/routes';
import { store as storeAdmin } from '@/routes/setup/admin';
import { store as storeProject } from '@/routes/setup/project';
import type { PushTokenFlash } from '@/types';

type Props =
    | { step: 'admin'; passwordRules: string }
    | { step: 'project' }
    | { step: 'done'; pushToken: PushTokenFlash };

/** Constraint of the first excubia-core version with `excubia push` (see docs/compatibility.md). */
const CORE_CONSTRAINT = '^1.0@beta';

const EXCUBIA_JSON = `{
    "$schema": "https://raw.githubusercontent.com/qiick-io/excubia-core/main/excubia.schema.json",
    "runtime": { "enabled": true },
    "exceptions": { "enabled": true }
}`;

const CRON_LINE =
    '* * * * * cd /path/to/your-project && php artisan schedule:run >> /dev/null 2>&1';

/**
 * First-run wizard (#116), one page per step: create the first admin, create the first
 * project, then show its push token once with the excubia-core install snippet. The server
 * picks the step and locks the wizard once it is done.
 */
export default function Setup(props: Props) {
    const { t } = useTranslation();
    const stepNumber = { admin: 1, project: 2, done: 3 }[props.step];

    return (
        <AuthLayout
            title={t(`auth.setup.${props.step}.title`)}
            description={t('auth.setup.stepOf', { step: stepNumber, total: 3 })}
        >
            <Head title={t('auth.setup.head')} />
            {props.step === 'admin' && (
                <AdminStep passwordRules={props.passwordRules} />
            )}
            {props.step === 'project' && <ProjectStep />}
            {props.step === 'done' && <DoneStep pushToken={props.pushToken} />}
        </AuthLayout>
    );
}

/**
 * Step 1: the first account, created as super-admin and logged in.
 */
function AdminStep({ passwordRules }: { passwordRules: string }) {
    const { t } = useTranslation();

    return (
        <Form
            {...storeAdmin.form()}
            resetOnSuccess={['password', 'password_confirmation']}
            noValidate
        >
            {({ processing, errors }) => (
                <FieldGroup className="gap-6">
                    <p className="text-sm text-muted-foreground">
                        {t('auth.setup.admin.description')}
                    </p>
                    <TextField
                        name="first_name"
                        label={t('common.firstName')}
                        autoComplete="given-name"
                        error={errors.first_name}
                        isRequired
                        hasAutoFocus
                    />
                    <TextField
                        name="last_name"
                        label={t('common.lastName')}
                        autoComplete="family-name"
                        error={errors.last_name}
                    />
                    <TextField
                        name="email"
                        type="email"
                        label={t('common.emailAddress')}
                        autoComplete="email"
                        error={errors.email}
                        isRequired
                    />
                    <Field data-invalid={errors.password ? true : undefined}>
                        <FieldLabel htmlFor="password">
                            {t('common.password')}
                        </FieldLabel>
                        <PasswordInput
                            id="password"
                            name="password"
                            required
                            autoComplete="new-password"
                            passwordrules={passwordRules}
                            aria-invalid={errors.password ? true : undefined}
                            aria-describedby={
                                errors.password ? 'password-error' : undefined
                            }
                        />
                        <FieldError id="password-error">
                            {errors.password}
                        </FieldError>
                    </Field>
                    <Field>
                        <FieldLabel htmlFor="password_confirmation">
                            {t('common.confirmPassword')}
                        </FieldLabel>
                        <PasswordInput
                            id="password_confirmation"
                            name="password_confirmation"
                            required
                            autoComplete="new-password"
                        />
                    </Field>
                    <SubmitButton
                        label={t('auth.setup.admin.submit')}
                        isProcessing={processing}
                    />
                </FieldGroup>
            )}
        </Form>
    );
}

/**
 * Step 2: the first monitored project. Skippable: projects can be added later.
 */
function ProjectStep() {
    const { t } = useTranslation();

    return (
        <Form {...storeProject.form()} noValidate>
            {({ processing, errors }) => (
                <FieldGroup className="gap-6">
                    <TextField
                        name="name"
                        label={t('auth.setup.project.name')}
                        description={t('auth.setup.project.description')}
                        error={errors.name ?? errors.slug}
                        isRequired
                        hasAutoFocus
                    />
                    <SubmitButton
                        label={t('auth.setup.project.submit')}
                        isProcessing={processing}
                    />
                    <Button variant="ghost" className="w-full" asChild>
                        <Link href={dashboard()}>
                            {t('auth.setup.project.skip')}
                        </Link>
                    </Button>
                </FieldGroup>
            )}
        </Form>
    );
}

/**
 * Step 3: the push token (shown once) and what the project needs to start pushing.
 */
function DoneStep({ pushToken }: { pushToken: PushTokenFlash }) {
    const { t } = useTranslation();
    const [copiedText, copy] = useClipboard();
    const snippets = [
        {
            label: t('auth.setup.done.install'),
            text: `composer require qiick/excubia-core:${CORE_CONSTRAINT}`,
        },
        {
            label: t('projects.token.environment'),
            text: `EXCUBIA_APP_URL=${pushToken.url}\nEXCUBIA_APP_TOKEN=${pushToken.token}`,
        },
        { label: t('auth.setup.done.config'), text: EXCUBIA_JSON },
        {
            label: t('projects.token.schedule'),
            text: `use Illuminate\\Support\\Facades\\Schedule;\n\n${SCHEDULE_LINE}`,
        },
        { label: t('auth.setup.done.cron'), text: CRON_LINE },
    ];

    return (
        <div className="flex flex-col gap-6">
            <p className="text-sm text-muted-foreground">
                {t('auth.setup.done.description', { name: pushToken.project })}
            </p>
            {snippets.map((snippet) => (
                <CopySnippet
                    key={snippet.label}
                    label={snippet.label}
                    text={snippet.text}
                    copiedText={copiedText}
                    copy={copy}
                />
            ))}
            <p className="text-sm text-muted-foreground">
                {t('auth.setup.done.firstPush')}
            </p>
            <Button className="w-full" asChild>
                <Link href={dashboard()}>{t('auth.setup.done.submit')}</Link>
            </Button>
        </div>
    );
}

type TextFieldProps = {
    name: string;
    label: string;
    type?: 'text' | 'email';
    autoComplete?: string;
    description?: string;
    error?: string;
    isRequired?: boolean;
    hasAutoFocus?: boolean;
};

/**
 * One labelled text input with its validation error, wired for screen readers.
 */
function TextField({
    name,
    label,
    type = 'text',
    autoComplete,
    description,
    error,
    isRequired = false,
    hasAutoFocus = false,
}: TextFieldProps) {
    const describedBy = [
        description ? `${name}-description` : null,
        error ? `${name}-error` : null,
    ]
        .filter(Boolean)
        .join(' ');

    return (
        <Field data-invalid={error ? true : undefined}>
            <FieldLabel htmlFor={name}>{label}</FieldLabel>
            <Input
                id={name}
                name={name}
                type={type}
                required={isRequired}
                // eslint-disable-next-line jsx-a11y/no-autofocus -- focus primary field when the step opens
                autoFocus={hasAutoFocus}
                autoComplete={autoComplete}
                aria-invalid={error ? true : undefined}
                aria-describedby={describedBy || undefined}
            />
            {description && (
                <FieldDescription id={`${name}-description`}>
                    {description}
                </FieldDescription>
            )}
            <FieldError id={`${name}-error`}>{error}</FieldError>
        </Field>
    );
}

/**
 * Full-width submit button with the busy state of the auth forms.
 */
function SubmitButton({
    label,
    isProcessing,
}: {
    label: string;
    isProcessing: boolean;
}) {
    const { t } = useTranslation();

    return (
        <Button
            type="submit"
            className="w-full"
            disabled={isProcessing}
            aria-busy={isProcessing || undefined}
        >
            {isProcessing && <Spinner />}
            {isProcessing ? (
                <span className="sr-only" role="status">
                    {t('a11y.loading')}
                </span>
            ) : null}
            {label}
        </Button>
    );
}
