import { usePage } from '@inertiajs/react';
import { Check, Copy } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
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

const SCHEDULE_LINE = "Schedule::command('excubia:push')->daily();";

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

    const snippet = (label: string, text: string) => (
        <div className="space-y-1.5">
            <div className="flex items-center justify-between">
                <span className="text-sm font-medium">{label}</span>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() => void copy(text)}
                >
                    {copiedText === text ? (
                        <Check className="size-3.5" />
                    ) : (
                        <Copy className="size-3.5" />
                    )}
                    {copiedText === text
                        ? t('projects.token.copied')
                        : t('projects.token.copy')}
                </Button>
            </div>
            <pre className="overflow-x-auto rounded-md bg-muted p-3 font-mono text-xs break-all whitespace-pre-wrap">
                {text}
            </pre>
        </div>
    );

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

                {snippet(t('projects.token.environment'), environment)}
                {snippet(t('projects.token.schedule'), SCHEDULE_LINE)}

                <DialogFooter>
                    <Button type="button" onClick={() => setPushToken(null)}>
                        {t('projects.token.done')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
