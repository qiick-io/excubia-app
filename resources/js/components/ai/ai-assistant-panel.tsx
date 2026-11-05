import { router, useHttp, usePage } from '@inertiajs/react';
import { Maximize2, Sparkles } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { HeaderIconButton } from '@/components/admin/header-icon-button';
import { AssistantChatMessage } from '@/components/ai/assistant-chat-message';
import { ThinkingDots } from '@/components/ai/thinking-dots';
import InputError from '@/components/input-error';
import { ChatComposer } from '@/components/layout/chat-layout';
import {
    Drawer,
    DrawerBody,
    DrawerContent,
    DrawerDescription,
    DrawerFooter,
    DrawerHeader,
    DrawerTitle,
} from '@/components/ui/drawer';
import {
    Empty,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { PermissionEnum } from '@/enums/permission-enum';
import { useCan } from '@/hooks/use-can';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { AI_TOGGLE_EVENT } from '@/lib/ai-open';
import { toast } from '@/lib/toast';
import aiRoutes from '@/routes/ai';
import type { AssistantMessage } from '@/types';

type PanelResponse = {
    conversationId: string;
    messages: AssistantMessage[];
};

/**
 * Assistant side panel, as externa-core `components/ai/ai-assistant-panel.tsx`: a right drawer
 * with the same thread and composer as `/assistant`, toggled by ⌘J / Ctrl+J or the sidebar
 * item's hover action. It keeps its conversation while open across pages and is not mounted on
 * the Assistant page itself. No floating button.
 */
export function AiAssistantPanel() {
    const { t } = useTranslation();
    const { can } = useCan();
    const { isAssistantEnabled } = usePage().props;
    const { isCurrentUrl } = useCurrentUrl();
    const endRef = useRef<HTMLDivElement>(null);
    const [open, setOpen] = useState(false);
    const [messages, setMessages] = useState<AssistantMessage[]>([]);
    const http = useHttp<
        { message: string; conversation_id: string },
        PanelResponse
    >({ message: '', conversation_id: '' });

    const isAvailable = isAssistantEnabled && can(PermissionEnum.CanUseAi);
    const isAssistantRoute = isCurrentUrl(
        aiRoutes.index.url(),
        undefined,
        true,
    );

    useEffect(() => {
        if (!isAvailable || isAssistantRoute) {
            return;
        }

        const onToggle = (): void => setOpen((current) => !current);
        const onKeyDown = (event: KeyboardEvent): void => {
            if (
                (event.metaKey || event.ctrlKey) &&
                !event.altKey &&
                !event.shiftKey &&
                event.key.toLowerCase() === 'j'
            ) {
                event.preventDefault();
                onToggle();
            }
        };

        window.addEventListener(AI_TOGGLE_EVENT, onToggle);
        window.addEventListener('keydown', onKeyDown);

        return () => {
            window.removeEventListener(AI_TOGGLE_EVENT, onToggle);
            window.removeEventListener('keydown', onKeyDown);
        };
    }, [isAvailable, isAssistantRoute]);

    useEffect(() => {
        endRef.current?.scrollIntoView({ block: 'end' });
    }, [messages.length, http.processing]);

    if (!isAvailable || isAssistantRoute) {
        return null;
    }

    const send = async (): Promise<void> => {
        try {
            const response = await http.post(aiRoutes.store.url());
            setMessages(response.messages);
            http.setData({
                message: '',
                conversation_id: response.conversationId,
            });
        } catch {
            // Validation errors land in http.errors; anything else is the provider.
            if (!http.hasErrors) {
                toast.error(t('assistant.unreachable'));
            }
        }
    };

    return (
        <Drawer open={open} onOpenChange={setOpen} direction="right">
            <DrawerContent
                onOpenAutoFocus={(event) => {
                    // Land in the composer (⌘J then type), never on the header button.
                    const panel = event.currentTarget;

                    if (!(panel instanceof HTMLElement)) {
                        return;
                    }

                    event.preventDefault();
                    (
                        panel.querySelector<HTMLElement>(
                            'textarea:not([disabled])',
                        ) ?? panel
                    ).focus();
                }}
            >
                <DrawerHeader className="flex-row items-start justify-between gap-2">
                    <div className="grid gap-1">
                        <DrawerTitle>
                            {t('assistant.newConversation')}
                        </DrawerTitle>
                        <DrawerDescription>
                            {t('assistant.panelDescription')}
                        </DrawerDescription>
                    </div>
                    <HeaderIconButton
                        type="button"
                        variant="ghost"
                        label={t('assistant.openFullPage')}
                        onClick={() => {
                            setOpen(false);
                            router.visit(
                                http.data.conversation_id
                                    ? aiRoutes.show.url(
                                          http.data.conversation_id,
                                      )
                                    : aiRoutes.index.url(),
                            );
                        }}
                    >
                        <Maximize2 className="size-4" />
                    </HeaderIconButton>
                </DrawerHeader>

                <DrawerBody className="gap-4" aria-live="polite">
                    {messages.length === 0 && !http.processing ? (
                        <Empty className="m-auto">
                            <EmptyHeader>
                                <EmptyMedia
                                    variant="icon"
                                    className="bg-ai/10 text-ai"
                                >
                                    <Sparkles />
                                </EmptyMedia>
                                <EmptyTitle>
                                    {t('assistant.emptyTitle')}
                                </EmptyTitle>
                            </EmptyHeader>
                        </Empty>
                    ) : null}

                    {messages.map((message) => (
                        <AssistantChatMessage
                            key={message.id}
                            message={message}
                        />
                    ))}

                    {http.processing ? (
                        <ThinkingDots label={t('assistant.thinking')} />
                    ) : null}
                    <div ref={endRef} />
                </DrawerBody>

                <DrawerFooter className="gap-2">
                    <ChatComposer
                        value={http.data.message}
                        onChange={(value) => http.setData('message', value)}
                        onSubmit={() => void send()}
                        label={t('assistant.placeholder')}
                        sendLabel={t('assistant.send')}
                        placeholder={t('assistant.placeholder')}
                        disabled={http.processing}
                    />
                    <InputError
                        message={
                            http.errors.message ?? http.errors.conversation_id
                        }
                    />
                </DrawerFooter>
            </DrawerContent>
        </Drawer>
    );
}
