import { Sparkles, SquareTerminal } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { HeaderIconButton } from '@/components/admin/header-icon-button';
import { useClipboard } from '@/hooks/use-clipboard';
import { toast } from '@/lib/toast';

/** A ready fix for a finding (FixSuggestionService, #78). */
export type FindingFix = {
    command: string | null;
    prompt: string;
};

/**
 * The prompt for several findings at once: one header, then each finding's prompt.
 */
export function combinedPrompt(fixes: FindingFix[]): string {
    if (fixes.length === 1) {
        return fixes[0].prompt;
    }

    return [
        `Fix these ${fixes.length} findings from an Excubia health report, one at a time, in this Laravel project. Keep each change minimal and run the test suite after each one.`,
        ...fixes.map(
            (fix, index) =>
                `--- Finding ${index + 1} of ${fixes.length} ---\n${fix.prompt}`,
        ),
    ].join('\n\n');
}

/**
 * Copies text and confirms with a toast (polite live region).
 */
export function useCopyWithToast(): (text: string) => Promise<void> {
    const { t } = useTranslation();
    const [, copy] = useClipboard();

    return async (text: string): Promise<void> => {
        if (await copy(text)) {
            toast.success(t('fix.copied'));
        } else {
            toast.error(t('fix.copyFailed'));
        }
    };
}

/**
 * Row actions for a finding: copy the command (when there is one) and copy a prompt for the
 * user's own coding assistant. The app sends nothing anywhere; the prompt button wears the AI
 * accent, never the brand primary.
 */
export function FixActions({ fix }: { fix: FindingFix | undefined }) {
    const { t } = useTranslation();
    const copyWithToast = useCopyWithToast();

    if (fix === undefined) {
        return null;
    }

    return (
        <div className="inline-flex items-center justify-end gap-1">
            {fix.command ? (
                <HeaderIconButton
                    type="button"
                    size="icon-sm"
                    label={t('fix.copyCommand', { command: fix.command })}
                    onClick={() => void copyWithToast(fix.command ?? '')}
                >
                    <SquareTerminal className="size-3.5" />
                </HeaderIconButton>
            ) : null}
            <HeaderIconButton
                type="button"
                size="icon-sm"
                label={t('fix.copyPrompt')}
                className="text-ai-text hover:bg-ai-bg"
                onClick={() => void copyWithToast(fix.prompt)}
            >
                <Sparkles className="size-3.5" />
            </HeaderIconButton>
        </div>
    );
}
