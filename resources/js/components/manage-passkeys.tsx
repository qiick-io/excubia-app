import { router } from '@inertiajs/react';
import { UserCancelledError } from '@laravel/passkeys';
import { usePasskeyRegister } from '@laravel/passkeys/react';
import { KeyRound } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { destroy } from '@/actions/Laravel/Passkeys/Http/Controllers/PasskeyRegistrationController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import type { Passkey } from '@/types/auth';

export type Props = {
    canManagePasskeys?: boolean;
    passkeys?: Passkey[];
};

/**
 * The passkeys section of the Security page, copied from qiick-io/externa-core: the list with
 * a delete button per passkey, then a name field and "Add passkey" on one row. Registration
 * goes through `@laravel/passkeys` (Fortify).
 */
export default function ManagePasskeys({
    canManagePasskeys = false,
    passkeys = [],
}: Props) {
    const { t } = useTranslation();
    const [passkeyName, setPasskeyName] = useState('');
    const [deletingPasskeyId, setDeletingPasskeyId] = useState<number | null>(
        null,
    );

    const {
        register: registerPasskey,
        isLoading: registeringPasskey,
        error: registerPasskeyError,
        errorInstance: registerPasskeyErrorInstance,
        isSupported: passkeysSupported,
    } = usePasskeyRegister({
        onSuccess: () => {
            setPasskeyName('');
            router.reload({ only: ['passkeys'] });
        },
    });

    // The user dismissed the platform prompt: not an error to show.
    const registerErrorMessage =
        registerPasskeyErrorInstance instanceof UserCancelledError
            ? null
            : registerPasskeyError;

    if (!canManagePasskeys) {
        return null;
    }

    return (
        <>
            <Separator />

            <div className="space-y-6" data-test="passkeys-section">
                <Heading
                    variant="small"
                    title={t('settings.security.passkeysTitle')}
                    description={t('settings.security.passkeysDescription')}
                />

                {passkeys.length > 0 ? (
                    <ul className="divide-y divide-border rounded-md border">
                        {passkeys.map((passkey) => (
                            <li
                                key={passkey.id}
                                className="flex items-center justify-between gap-4 px-4 py-3"
                                data-test="passkey-item"
                            >
                                <div className="min-w-0">
                                    <p className="truncate text-sm font-medium">
                                        {passkey.name}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {t('settings.security.passkeyCreated', {
                                            date: passkey.created_at_diff,
                                        })}
                                    </p>
                                </div>
                                <Button
                                    type="button"
                                    variant="destructive"
                                    disabled={deletingPasskeyId === passkey.id}
                                    data-test="delete-passkey-button"
                                    onClick={() => {
                                        setDeletingPasskeyId(passkey.id);
                                        router.delete(destroy.url(passkey.id), {
                                            preserveScroll: true,
                                            onFinish: () =>
                                                setDeletingPasskeyId(null),
                                        });
                                    }}
                                >
                                    {deletingPasskeyId === passkey.id && (
                                        <Spinner />
                                    )}
                                    {t('settings.security.deletePasskey')}
                                </Button>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        {t('settings.security.passkeysEmpty')}
                    </p>
                )}

                <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                    <Field className="min-w-0 flex-1">
                        <FieldLabel htmlFor="passkey_name">
                            {t('settings.security.passkeyName')}
                        </FieldLabel>
                        <Input
                            id="passkey_name"
                            value={passkeyName}
                            onChange={(event) =>
                                setPasskeyName(event.target.value)
                            }
                            placeholder={t(
                                'settings.security.passkeyNamePlaceholder',
                            )}
                            disabled={!passkeysSupported || registeringPasskey}
                            data-test="passkey-name-input"
                            aria-describedby={
                                registerErrorMessage
                                    ? 'passkey-register-error'
                                    : undefined
                            }
                        />
                    </Field>
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <span className="inline-flex">
                                <Button
                                    type="button"
                                    disabled={
                                        !passkeysSupported ||
                                        registeringPasskey ||
                                        passkeyName.trim() === ''
                                    }
                                    data-test="add-passkey-button"
                                    onClick={() =>
                                        void registerPasskey(passkeyName.trim())
                                    }
                                >
                                    {registeringPasskey ? (
                                        <Spinner />
                                    ) : (
                                        <KeyRound />
                                    )}
                                    {t('settings.security.addPasskey')}
                                </Button>
                            </span>
                        </TooltipTrigger>
                        {!passkeysSupported && (
                            <TooltipContent>
                                {typeof window !== 'undefined' &&
                                !window.isSecureContext
                                    ? t('settings.security.passkeysNeedHttps')
                                    : t(
                                          'settings.security.passkeysUnsupported',
                                      )}
                            </TooltipContent>
                        )}
                    </Tooltip>
                </div>

                <FieldError id="passkey-register-error">
                    {registerErrorMessage}
                </FieldError>
            </div>
        </>
    );
}
