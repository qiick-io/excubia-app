import { Check, Copy } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldLabel } from '@/components/ui/field';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupButton,
    InputGroupInput,
} from '@/components/ui/input-group';
import { copyTextToClipboard } from '@/lib/clipboard';
import { announce } from '@/lib/live-announcer';

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
 * The show-once secret of an API key (docs/api-keys.md, behaviour 1). The secret is never
 * stored, so this is the only time anyone sees it: the dialog cannot be dismissed by Esc or
 * the overlay, and Done stays disabled until the user ticks that they stored it.
 *
 * The secret sits in a read-only input that takes focus on open and selects its text, so it
 * can be read, selected and copied by keyboard and screen reader alike; the opening is also
 * announced in the polite live region, and so is the result of Copy.
 */
export function ApiKeySecretDialog({
    secret,
    keyName,
    onAcknowledge,
    reason = 'created',
    previousSecretValidUntil,
}: ApiKeySecretDialogProps) {
    const { t } = useTranslation();
    const dialogId = useId();
    const secretInputRef = useRef<HTMLInputElement>(null);
    // Keyed by the secret itself, so a second key issued in the same session starts unacknowledged.
    const [acknowledgedSecret, setAcknowledgedSecret] = useState<string | null>(
        null,
    );
    const [copiedSecret, setCopiedSecret] = useState<string | null>(null);
    const isOpen = secret !== null;
    const isAcknowledged = isOpen && acknowledgedSecret === secret;
    const isCopied = isOpen && copiedSecret === secret;

    useEffect(() => {
        if (secret !== null) {
            announce(t('apiKeys.secret.announcement', { name: keyName }));
        }
    }, [secret, keyName, t]);

    const copySecret = async () => {
        if (secret === null) {
            return;
        }

        const hasCopied = await copyTextToClipboard(secret);

        if (hasCopied) {
            setCopiedSecret(secret);
            announce(t('apiKeys.secret.copied'));

            return;
        }

        // Leave the text selected, so the keyboard copy works where the clipboard API does not.
        secretInputRef.current?.select();
        announce(t('apiKeys.secret.copyFailed'), { politeness: 'assertive' });
    };

    return (
        <AlertDialog
            open={isOpen}
            onOpenChange={(isNextOpen) => {
                if (!isNextOpen && isAcknowledged) {
                    onAcknowledge();
                }
            }}
        >
            <AlertDialogContent
                data-test="api-key-secret-dialog"
                onEscapeKeyDown={(event) => {
                    if (!isAcknowledged) {
                        event.preventDefault();
                    }
                }}
                onOpenAutoFocus={(event) => {
                    event.preventDefault();
                    secretInputRef.current?.focus();
                    secretInputRef.current?.select();
                }}
            >
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        {reason === 'rotated'
                            ? t('apiKeys.secret.rotatedTitle', {
                                  name: keyName,
                              })
                            : t('apiKeys.secret.createdTitle', {
                                  name: keyName,
                              })}
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        {t('apiKeys.secret.description')}
                        {reason === 'rotated' && previousSecretValidUntil
                            ? ` ${t('apiKeys.secret.previousSecretValidUntil', {
                                  date: previousSecretValidUntil,
                              })}`
                            : null}
                    </AlertDialogDescription>
                </AlertDialogHeader>

                <Field>
                    <FieldLabel htmlFor={`${dialogId}-secret`}>
                        {t('apiKeys.secret.label')}
                    </FieldLabel>
                    <InputGroup>
                        <InputGroupInput
                            ref={secretInputRef}
                            id={`${dialogId}-secret`}
                            readOnly
                            value={secret ?? ''}
                            autoComplete="off"
                            spellCheck={false}
                            className="font-mono"
                            data-test="api-key-secret-value"
                            onFocus={(event) => event.currentTarget.select()}
                        />
                        <InputGroupAddon align="inline-end">
                            <InputGroupButton
                                size="icon-xs"
                                aria-label={
                                    isCopied
                                        ? t('apiKeys.secret.copied')
                                        : t('apiKeys.secret.copy')
                                }
                                onClick={() => void copySecret()}
                            >
                                {isCopied ? <Check /> : <Copy />}
                            </InputGroupButton>
                        </InputGroupAddon>
                    </InputGroup>
                </Field>

                <Field orientation="horizontal">
                    <Checkbox
                        id={`${dialogId}-acknowledge`}
                        checked={isAcknowledged}
                        data-test="api-key-secret-acknowledge"
                        onCheckedChange={(checked) =>
                            setAcknowledgedSecret(
                                checked === true ? secret : null,
                            )
                        }
                    />
                    <FieldLabel
                        htmlFor={`${dialogId}-acknowledge`}
                        className="font-normal"
                    >
                        {t('apiKeys.secret.acknowledge')}
                    </FieldLabel>
                </Field>

                <AlertDialogFooter>
                    <AlertDialogAction
                        disabled={!isAcknowledged}
                        data-test="api-key-secret-done"
                    >
                        {t('apiKeys.secret.done')}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
