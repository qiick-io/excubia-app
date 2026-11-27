import { Check, Copy } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import type { CopiedValue, CopyFn } from '@/hooks/use-clipboard';

/** The scheduler line a project adds to `routes/console.php` to push its reports. */
export const SCHEDULE_LINE = "Schedule::command('excubia:push')->daily();";

type Props = {
    label: string;
    text: string;
    copiedText: CopiedValue;
    copy: CopyFn;
};

/**
 * A labelled block of setup text (environment lines, commands, config) with a copy button.
 * The clipboard state comes from the parent so only the last copied block shows "Copied".
 */
export function CopySnippet({ label, text, copiedText, copy }: Props) {
    const { t } = useTranslation();
    const isCopied = copiedText === text;

    return (
        <div className="space-y-1.5">
            <div className="flex items-center justify-between gap-2">
                <span className="text-sm font-medium">{label}</span>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() => void copy(text)}
                >
                    {isCopied ? (
                        <Check className="size-3.5" />
                    ) : (
                        <Copy className="size-3.5" />
                    )}
                    {isCopied
                        ? t('projects.token.copied')
                        : t('projects.token.copy')}
                </Button>
            </div>
            <pre className="overflow-x-auto rounded-md bg-muted p-3 font-mono text-xs break-all whitespace-pre-wrap">
                {text}
            </pre>
        </div>
    );
}
