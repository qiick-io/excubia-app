import { useTranslation } from 'react-i18next';
import { SecretOnceDialog } from '@/components/secrets/secret-once-dialog';

export type ApiKeySecretDialogProps = {
    /** The plain-text key, straight from the create or rotate response; the dialog is open while it is set. */
    secret: string | null;
    /** The key's name, so the user knows which integration the secret belongs to. */
    keyName: string;
    /** Called once the user has confirmed they stored the secret; the app drops it from state. */
    onAcknowledge: () => void;
    /** `rotated` explains that the old secret is being replaced. */
    reason?: 'created' | 'rotated';
    /** For a rotation: when the previous secret stops working, formatted by the app. */
    previousSecretValidUntil?: string;
};

/**
 * The show-once secret of an API key (docs/api-keys.md, behaviour 1): `SecretOnceDialog`
 * with the API key wording. See `SecretOnceDialog` for the behaviour (not dismissible until
 * confirmed, keyboard and screen-reader copy).
 */
export function ApiKeySecretDialog({
    secret,
    keyName,
    onAcknowledge,
    reason = 'created',
    previousSecretValidUntil,
}: ApiKeySecretDialogProps) {
    const { t } = useTranslation();
    const previousSecretNote =
        reason === 'rotated' && previousSecretValidUntil
            ? ` ${t('apiKeys.secret.previousSecretValidUntil', {
                  date: previousSecretValidUntil,
              })}`
            : '';

    return (
        <SecretOnceDialog
            secret={secret}
            onAcknowledge={onAcknowledge}
            title={
                reason === 'rotated'
                    ? t('apiKeys.secret.rotatedTitle', { name: keyName })
                    : t('apiKeys.secret.createdTitle', { name: keyName })
            }
            description={`${t('apiKeys.secret.description')}${previousSecretNote}`}
            label={t('apiKeys.secret.label')}
            announcement={t('apiKeys.secret.announcement', { name: keyName })}
            copyLabel={t('apiKeys.secret.copy')}
            copiedLabel={t('apiKeys.secret.copied')}
            copyFailedLabel={t('apiKeys.secret.copyFailed')}
            acknowledgeLabel={t('apiKeys.secret.acknowledge')}
            testId="api-key-secret"
        />
    );
}
