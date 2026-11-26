import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { SecretOnceDialog } from '@/components/secrets/secret-once-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Field, FieldDescription, FieldLabel } from '@/components/ui/field';
import { Label } from '@/components/ui/label';
import integrationsRoutes from '@/routes/project/integrations';

export type AlertWebhookStatus = {
    is_active: boolean;
    disabled_reason: string | null;
    last_success_at: string | null;
    last_failure_at: string | null;
    consecutive_failures: number;
} | null;

type IssuedWebhookSecret = { secret: string; reason: 'created' | 'rotated' };

/**
 * The alert webhook's signing secret and delivery status (#22, qiick/laravel-outbound). Excubia
 * generates the secret (Standard Webhooks: the sender does) and shows it once in
 * `SecretOnceDialog`; Rotate keeps the previous one signing for 24 hours, so the receiver can
 * switch without a gap. A webhook the package disabled (410 Gone, or days of failures) is
 * turned back on here.
 *
 * The secret arrives once as Inertia flash (`issuedWebhookSecret`, stripped from
 * history.state) and is dropped from state when the user confirms they stored it.
 */
export default function AlertWebhookSigning({
    hasSavedUrl,
    hasSecret,
}: {
    hasSavedUrl: boolean;
    hasSecret: boolean;
}) {
    const { t } = useTranslation();
    const { flash } = usePage();
    const [issued, setIssued] = useState<IssuedWebhookSecret | null>(null);
    const [isIssuing, setIsIssuing] = useState(false);

    useEffect(() => {
        if (flash.issuedWebhookSecret) {
            setIssued(flash.issuedWebhookSecret);
        }
    }, [flash.issuedWebhookSecret]);

    const issueSecret = (): void => {
        setIsIssuing(true);
        router.post(
            integrationsRoutes.webhookSecret.url(),
            {},
            { preserveScroll: true, onFinish: () => setIsIssuing(false) },
        );
    };

    return (
        <>
            <Field>
                <FieldLabel>
                    {t('settings.integrations.webhookSecret')}
                </FieldLabel>
                <div className="flex flex-wrap items-center gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        disabled={!hasSavedUrl || isIssuing}
                        onClick={issueSecret}
                    >
                        {hasSecret
                            ? t('settings.integrations.rotateSecret')
                            : t('settings.integrations.generateSecret')}
                    </Button>
                    {hasSecret ? (
                        <Badge variant="secondary">
                            {t('settings.integrations.secretSet')}
                        </Badge>
                    ) : null}
                </div>
                <FieldDescription>
                    {hasSavedUrl
                        ? t('settings.integrations.webhookSecretHint')
                        : t('settings.integrations.webhookSecretNeedsUrl')}
                </FieldDescription>
            </Field>

            <SecretOnceDialog
                secret={issued?.secret ?? null}
                onAcknowledge={() => setIssued(null)}
                title={
                    issued?.reason === 'rotated'
                        ? t('settings.integrations.secretRotatedTitle')
                        : t('settings.integrations.secretCreatedTitle')
                }
                description={
                    issued?.reason === 'rotated'
                        ? `${t('secretOnce.description')} ${t('settings.integrations.secretRotatedNote')}`
                        : undefined
                }
                announcement={t('settings.integrations.secretAnnouncement')}
                testId="webhook-secret"
            />
        </>
    );
}

/**
 * The alert webhook's delivery status from qiick/laravel-outbound's endpoint: last success,
 * last failure and the failure streak, and Re-enable when the package disabled it.
 */
export function AlertWebhookDeliveryStatus({
    status,
}: {
    status: AlertWebhookStatus;
}) {
    const { t } = useTranslation();

    if (status === null) {
        return null;
    }

    const enableWebhook = (): void => {
        router.post(
            integrationsRoutes.webhookEnable.url(),
            {},
            { preserveScroll: true },
        );
    };

    const formatTime = (value: string | null): string =>
        value === null
            ? t('settings.integrations.deliveryNone')
            : new Date(value).toLocaleString();

    return (
        <div className="grid gap-3">
            <Label>{t('settings.integrations.deliveryTitle')}</Label>
            {status.is_active ? null : (
                <div className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-destructive/40 p-3">
                    <p className="text-sm text-destructive">
                        {t('settings.integrations.webhookDisabled', {
                            reason:
                                status.disabled_reason ??
                                t('settings.integrations.deliveryNone'),
                        })}
                    </p>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={enableWebhook}
                    >
                        {t('settings.integrations.reenableWebhook')}
                    </Button>
                </div>
            )}
            <dl className="grid gap-3 sm:grid-cols-3">
                <div className="space-y-1 rounded-md border p-3">
                    <dt className="text-sm font-medium">
                        {t('settings.integrations.lastSuccess')}
                    </dt>
                    <dd className="text-sm text-muted-foreground">
                        {formatTime(status.last_success_at)}
                    </dd>
                </div>
                <div className="space-y-1 rounded-md border p-3">
                    <dt className="text-sm font-medium">
                        {t('settings.integrations.lastError')}
                    </dt>
                    <dd className="text-sm text-muted-foreground">
                        {formatTime(status.last_failure_at)}
                    </dd>
                </div>
                <div className="space-y-1 rounded-md border p-3">
                    <dt className="text-sm font-medium">
                        {t('settings.integrations.consecutiveFailures')}
                    </dt>
                    <dd className="text-sm text-muted-foreground">
                        {status.consecutive_failures}
                    </dd>
                </div>
            </dl>
        </div>
    );
}
