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

export type SecretOnceDialogProps = {
    /** The plain-text secret, straight from the response that issued it; the dialog is open while it is set. */
    secret: string | null;
    /** Called once the user has confirmed they stored the secret; the app drops it from state. */
    onAcknowledge: () => void;
    /** Translated title, naming what the secret belongs to. */
    title: string;
    /** Translated explanation; the default says the secret is shown only this once. */
    description?: string;
    /** Translated label of the secret field. */
    label?: string;
    /** Translated text announced in the live region when the dialog opens. */
    announcement: string;
    /** Translated texts of the Copy button, its result and the confirmation; generic defaults. */
    copyLabel?: string;
    copiedLabel?: string;
    copyFailedLabel?: string;
    acknowledgeLabel?: string;
    /** Prefix of the `data-test` hooks: `{testId}-dialog`, `-value`, `-acknowledge`, `-done`. */
    testId?: string;
};

/**
 * A secret shown once (an API key, a webhook signing secret, a recovery code set): it is never
 * stored in plain text, so this is the only time anyone sees it. The dialog cannot be dismissed
 * by Esc or the overlay, and Done stays disabled until the user ticks that they stored it.
 *
 * The secret sits in a read-only input that takes focus on open and selects its text, so it
 * can be read, selected and copied by keyboard and screen reader alike; the opening is also
 * announced in the polite live region, and so is the result of Copy. If the clipboard is
 * unavailable the text stays selected and the failure is announced assertively.
 */
export function SecretOnceDialog({
    secret,
    onAcknowledge,
    title,
    description,
    label,
    announcement,
    copyLabel,
    copiedLabel,
    copyFailedLabel,
    acknowledgeLabel,
    testId = 'secret-once',
}: SecretOnceDialogProps) {
    const { t } = useTranslation();
    const dialogId = useId();
    const secretInputRef = useRef<HTMLInputElement>(null);
    // Keyed by the secret itself, so a second secret issued in the same session starts unacknowledged.
    const [acknowledgedSecret, setAcknowledgedSecret] = useState<string | null>(
        null,
    );
    const [copiedSecret, setCopiedSecret] = useState<string | null>(null);
    const isOpen = secret !== null;
    const isAcknowledged = isOpen && acknowledgedSecret === secret;
    const isCopied = isOpen && copiedSecret === secret;
    const copiedText = copiedLabel ?? t('secretOnce.copied');

    // Once the app has dropped the secret, drop this component's copies of it too (adjusting
    // state during render, so no stale copy survives even one frame).
    if (
        secret === null &&
        (acknowledgedSecret !== null || copiedSecret !== null)
    ) {
        setAcknowledgedSecret(null);
        setCopiedSecret(null);
    }

    useEffect(() => {
        if (secret !== null) {
            announce(announcement);
        }
    }, [secret, announcement]);

    const copySecret = async () => {
        if (secret === null) {
            return;
        }

        const hasCopied = await copyTextToClipboard(
            secret,
            secretInputRef.current,
        );

        if (hasCopied) {
            setCopiedSecret(secret);
            announce(copiedText);

            return;
        }

        // Leave the text selected, so the keyboard copy works where the clipboard API does not.
        secretInputRef.current?.select();
        announce(copyFailedLabel ?? t('secretOnce.copyFailed'), {
            politeness: 'assertive',
        });
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
                data-test={`${testId}-dialog`}
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
                    <AlertDialogTitle>{title}</AlertDialogTitle>
                    <AlertDialogDescription>
                        {description ?? t('secretOnce.description')}
                    </AlertDialogDescription>
                </AlertDialogHeader>

                <Field>
                    <FieldLabel htmlFor={`${dialogId}-secret`}>
                        {label ?? t('secretOnce.label')}
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
                            data-test={`${testId}-value`}
                            onFocus={(event) => event.currentTarget.select()}
                        />
                        <InputGroupAddon align="inline-end">
                            <InputGroupButton
                                size="icon-xs"
                                aria-label={
                                    isCopied
                                        ? copiedText
                                        : (copyLabel ?? t('secretOnce.copy'))
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
                        data-test={`${testId}-acknowledge`}
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
                        {acknowledgeLabel ?? t('secretOnce.acknowledge')}
                    </FieldLabel>
                </Field>

                <AlertDialogFooter>
                    <AlertDialogAction
                        disabled={!isAcknowledged}
                        data-test={`${testId}-done`}
                    >
                        {t('secretOnce.done')}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
