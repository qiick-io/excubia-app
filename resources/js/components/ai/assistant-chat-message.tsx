import { useTranslation } from 'react-i18next';
import { Bubble, BubbleContent } from '@/components/ui/bubble';
import { Message, MessageContent } from '@/components/ui/message';
import type { AssistantMessage } from '@/types';

/**
 * One message: user bubbles muted and aligned to the end, assistant answers ghost and full
 * width, failed answers destructive (app-ui `docs/chat-and-assistant.md`).
 */
export function AssistantChatMessage({
    message,
}: {
    message: AssistantMessage;
}) {
    const { t } = useTranslation();
    const isUser = message.role === 'user';

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
                        {message.is_failed && message.content === ''
                            ? t('assistant.failed')
                            : message.content}
                    </BubbleContent>
                </Bubble>
            </MessageContent>
        </Message>
    );
}
