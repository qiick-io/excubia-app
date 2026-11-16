import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Confirmation } from '@/components/ai-elements/confirmation';
import { Bubble, BubbleContent } from '@/components/ui/bubble';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldLabel } from '@/components/ui/field';
import { Message, MessageContent } from '@/components/ui/message';
import type { AssistantMessage, AssistantPendingApproval } from '@/types';

/** Approve or reject every call the turn waits on; `isAlways` stops asking for those tools in the chat. */
export type ResolveApprovals = (isApproved: boolean, isAlways: boolean) => void;

/**
 * One message: user bubbles muted and aligned to the end, assistant answers ghost and full
 * width, failed answers destructive (app-ui `docs/chat-and-assistant.md`). A turn waiting on
 * write calls (#320) shows them under the answer in the registry `Confirmation`, with the
 * "always for this chat" choice of the qiick AI rule beside it.
 */
export function AssistantChatMessage({
    message,
    onResolveApprovals,
    isResolving = false,
}: {
    message: AssistantMessage;
    /** Given for the latest message only: its pending calls can still be decided. */
    onResolveApprovals?: ResolveApprovals;
    isResolving?: boolean;
}) {
    const { t } = useTranslation();
    const isUser = message.role === 'user';
    const pendingApprovals = message.pending_approvals ?? [];
    const isAwaitingApproval =
        onResolveApprovals !== undefined && pendingApprovals.length > 0;

    const content =
        message.is_failed && message.content === ''
            ? t('assistant.failed')
            : isAwaitingApproval && message.content === ''
              ? t('assistant.approval.awaiting')
              : message.content;

    return (
        <Message align={isUser ? 'end' : 'start'}>
            <MessageContent>
                <Bubble
                    variant={
                        message.is_failed
                            ? 'destructive'
                            : isUser
                              ? 'muted'
                              : 'ghost'
                    }
                    align={isUser ? 'end' : 'start'}
                >
                    <BubbleContent
                        className={
                            isUser
                                ? 'whitespace-pre-wrap text-foreground'
                                : 'w-full max-w-full whitespace-pre-wrap'
                        }
                    >
                        <span className="sr-only">
                            {isUser ? t('assistant.you') : t('assistant.title')}
                            :{' '}
                        </span>
                        {content}
                    </BubbleContent>
                </Bubble>
                {isAwaitingApproval ? (
                    <ToolApprovals
                        approvals={pendingApprovals}
                        isResolving={isResolving}
                        onResolve={onResolveApprovals}
                    />
                ) : null}
            </MessageContent>
        </Message>
    );
}

/**
 * The calls a paused turn waits on, as one Confirmation: the question, each call in words with
 * its arguments, Reject / Approve, and the "don't ask again in this chat" checkbox.
 */
function ToolApprovals({
    approvals,
    isResolving,
    onResolve,
}: {
    approvals: AssistantPendingApproval[];
    isResolving: boolean;
    onResolve: ResolveApprovals;
}) {
    const { t } = useTranslation();
    const alwaysId = useId();
    const [isAlways, setAlways] = useState(false);

    return (
        <div className="grid w-full gap-2" data-test="assistant-approvals">
            <Confirmation
                title={t('assistant.approval.question', {
                    count: approvals.length,
                })}
                description={
                    <span className="grid gap-1">
                        {approvals.map((approval) => (
                            <span key={approval.id} className="grid">
                                <span className="text-foreground">
                                    {t(
                                        `assistant.approval.tools.${approval.tool}`,
                                        {
                                            defaultValue: approval.tool,
                                        },
                                    )}
                                </span>
                                <span className="font-mono text-xs break-all">
                                    {describeArguments(approval.arguments)}
                                </span>
                            </span>
                        ))}
                    </span>
                }
                disabled={isResolving}
                onApprove={() => onResolve(true, isAlways)}
                onDeny={() => onResolve(false, false)}
            />
            <Field orientation="horizontal">
                <Checkbox
                    id={alwaysId}
                    checked={isAlways}
                    disabled={isResolving}
                    onCheckedChange={(value) => setAlways(value === true)}
                />
                <FieldLabel htmlFor={alwaysId} className="font-normal">
                    {t('assistant.approval.always')}
                </FieldLabel>
            </Field>
        </div>
    );
}

/** `project: shop · ids: 12, 13 · status: resolved`: the arguments as the model sent them. */
function describeArguments(toolArguments: Record<string, unknown>): string {
    return Object.entries(toolArguments)
        .map(
            ([name, value]) =>
                `${name}: ${Array.isArray(value) ? value.join(', ') : String(value)}`,
        )
        .join(' · ');
}
