import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { CopySnippet, SCHEDULE_LINE } from '@/components/projects/copy-snippet';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useClipboard } from '@/hooks/use-clipboard';
import type { PushTokenFlash } from '@/types';

/**
 * Shows a push token flashed by the server once, with the setup the project needs to push
 * its reports. The token is kept only in component state and is gone once closed.
 */
export function PushTokenDialog() {
    const { t } = useTranslation();
    const { flash } = usePage();
    const [pushToken, setPushToken] = useState<PushTokenFlash | null>(null);
    const [copiedText, copy] = useClipboard();

    useEffect(() => {
        if (flash.pushToken) {
            setPushToken(flash.pushToken);
        }
    }, [flash.pushToken]);

    const environment = pushToken
        ? `EXCUBIA_APP_URL=${pushToken.url}\nEXCUBIA_APP_TOKEN=${pushToken.token}`
        : '';

    return (
        <Dialog
            open={pushToken !== null}
            onOpenChange={(open) => {
                if (!open) {
                    setPushToken(null);
                }
            }}
        >
            <DialogContent className="sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>
                        {t('projects.token.title', {
                            name: pushToken?.project ?? '',
                        })}
                    </DialogTitle>
                    <DialogDescription>
                        {t('projects.token.description')}
                    </DialogDescription>
                </DialogHeader>

                <CopySnippet
                    label={t('projects.token.environment')}
                    text={environment}
                    copiedText={copiedText}
                    copy={copy}
                />
                <CopySnippet
                    label={t('projects.token.schedule')}
                    text={SCHEDULE_LINE}
                    copiedText={copiedText}
                    copy={copy}
                />

                <DialogFooter>
                    <Button type="button" onClick={() => setPushToken(null)}>
                        {t('projects.token.done')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
