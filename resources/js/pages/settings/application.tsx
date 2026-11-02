import type { ReactNode } from 'react';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { Form, Head } from '@inertiajs/react';
import ApplicationSettingsController from '@/actions/App/Http/Controllers/ApplicationSettingsController';
import BooleanField from '@/components/boolean-field';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { edit } from '@/routes/application-settings';

type ApplicationSettings = {
    app_name: string | null;
    password_policy: string;
    login_max_attempts: number;
    two_factor_required: boolean;
    reports_kept_per_project: number;
    stale_after_hours: number;
    oidc_enabled: boolean;
    oidc_issuer: string | null;
    oidc_client_id: string | null;
    has_oidc_client_secret: boolean;
    oidc_button_label: string | null;
    oidc_jit_provisioning: boolean;
};

/**
 * Application-wide settings (General, Security, Checks), behind `settings.manage`.
 */
export default function ApplicationSettingsPage({
    settings,
    passwordPolicies,
    oidcCallbackUrl,
}: {
    settings: ApplicationSettings;
    passwordPolicies: string[];
    oidcCallbackUrl: string;
}) {
    return (
        <>
            <Head title="Application settings" />

            <h1 className="sr-only">Application settings</h1>

            <Form
                {...ApplicationSettingsController.update.form()}
                options={{ preserveScroll: true }}
                className="space-y-12"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="space-y-6">
                            <Heading
                                variant="small"
                                title="General"
                                description="Applies to every user of this instance"
                            />
                            <div className="grid gap-2">
                                <Label htmlFor="app_name">
                                    Application name
                                </Label>
                                <Input
                                    id="app_name"
                                    name="app_name"
                                    defaultValue={settings.app_name ?? ''}
                                    placeholder="Excubia"
                                />
                                <InputError message={errors.app_name} />
                            </div>
                        </div>

                        <div className="space-y-6">
                            <Heading
                                variant="small"
                                title="Security"
                                description="Password rules and login protection"
                            />
                            <div className="grid gap-2">
                                <Label htmlFor="password_policy">
                                    Password policy
                                </Label>
                                <Select
                                    name="password_policy"
                                    defaultValue={settings.password_policy}
                                >
                                    <SelectTrigger id="password_policy">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {passwordPolicies.map((policy) => (
                                            <SelectItem
                                                key={policy}
                                                value={policy}
                                            >
                                                {policy}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <p className="text-sm text-muted-foreground">
                                    Weak: 6 characters. Medium: 8 with mixed
                                    case and numbers. Strong: 12 with symbols,
                                    not found in known breaches.
                                </p>
                                <InputError message={errors.password_policy} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="login_max_attempts">
                                    Login attempts per minute
                                </Label>
                                <Input
                                    id="login_max_attempts"
                                    name="login_max_attempts"
                                    type="number"
                                    min={1}
                                    max={100}
                                    defaultValue={settings.login_max_attempts}
                                    required
                                />
                                <InputError
                                    message={errors.login_max_attempts}
                                />
                            </div>
                            <BooleanField
                                name="two_factor_required"
                                label="Require two-factor authentication or a passkey"
                                defaultChecked={settings.two_factor_required}
                            />
                            <InputError message={errors.two_factor_required} />
                        </div>

                        <div className="space-y-6">
                            <Heading
                                variant="small"
                                title="Checks"
                                description="Report history and stale projects"
                            />
                            <div className="grid gap-2">
                                <Label htmlFor="reports_kept_per_project">
                                    Reports kept per project
                                </Label>
                                <Input
                                    id="reports_kept_per_project"
                                    name="reports_kept_per_project"
                                    type="number"
                                    min={1}
                                    max={1000}
                                    defaultValue={
                                        settings.reports_kept_per_project
                                    }
                                    required
                                />
                                <InputError
                                    message={errors.reports_kept_per_project}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="stale_after_hours">
                                    Stale after (hours without a report)
                                </Label>
                                <Input
                                    id="stale_after_hours"
                                    name="stale_after_hours"
                                    type="number"
                                    min={1}
                                    max={8760}
                                    defaultValue={settings.stale_after_hours}
                                    required
                                />
                                <InputError
                                    message={errors.stale_after_hours}
                                />
                            </div>
                        </div>

                        <div className="space-y-6">
                            <Heading
                                variant="small"
                                title="SSO"
                                description={`Optional OIDC login with any standard provider. Redirect URI: ${oidcCallbackUrl}`}
                            />
                            <BooleanField
                                name="oidc_enabled"
                                label="Enable SSO login"
                                defaultChecked={settings.oidc_enabled}
                            />
                            <InputError message={errors.oidc_enabled} />
                            <div className="grid gap-2">
                                <Label htmlFor="oidc_issuer">Issuer URL</Label>
                                <Input
                                    id="oidc_issuer"
                                    name="oidc_issuer"
                                    type="url"
                                    defaultValue={settings.oidc_issuer ?? ''}
                                    placeholder="https://idp.example.com/realms/acme"
                                />
                                <InputError message={errors.oidc_issuer} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="oidc_client_id">
                                    Client ID
                                </Label>
                                <Input
                                    id="oidc_client_id"
                                    name="oidc_client_id"
                                    defaultValue={settings.oidc_client_id ?? ''}
                                />
                                <InputError message={errors.oidc_client_id} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="oidc_client_secret">
                                    Client secret
                                </Label>
                                <Input
                                    id="oidc_client_secret"
                                    name="oidc_client_secret"
                                    type="password"
                                    autoComplete="off"
                                    placeholder={
                                        settings.has_oidc_client_secret
                                            ? 'Saved. Leave empty to keep it.'
                                            : ''
                                    }
                                />
                                <InputError
                                    message={errors.oidc_client_secret}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="oidc_button_label">
                                    Button label
                                </Label>
                                <Input
                                    id="oidc_button_label"
                                    name="oidc_button_label"
                                    defaultValue={
                                        settings.oidc_button_label ?? ''
                                    }
                                    placeholder="Sign in with SSO"
                                />
                                <InputError
                                    message={errors.oidc_button_label}
                                />
                            </div>
                            <BooleanField
                                name="oidc_jit_provisioning"
                                label="Create unknown users on first SSO login (role reader)"
                                defaultChecked={settings.oidc_jit_provisioning}
                            />
                            <InputError
                                message={errors.oidc_jit_provisioning}
                            />
                        </div>

                        <Button disabled={processing}>Save</Button>
                    </>
                )}
            </Form>
        </>
    );
}

ApplicationSettingsPage.layout = (page: ReactNode) => (
    <AppLayout breadcrumbs={[{ title: 'Application settings', href: edit() }]}>
        <SettingsLayout>{page}</SettingsLayout>
    </AppLayout>
);
