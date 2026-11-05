import { Head, Link, router, useForm } from '@inertiajs/react';
import { Plus, Sparkles } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { AssistantChatMessage } from '@/components/ai/assistant-chat-message';
import { ThinkingDots } from '@/components/ai/thinking-dots';
import InputError from '@/components/input-error';
import {
    ChatComposer,
    ChatLayout,
    ChatListItem,
    ChatListSection,
    ChatThread,
} from '@/components/layout/chat-layout';
import { FilterSearch } from '@/components/layout/page-header';
import { PageLayout } from '@/components/layout/page-layout';
import { Button } from '@/components/ui/button';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import AppLayout from '@/layouts/app-layout';
import assistantRoutes from '@/routes/assistant';
import type {
    AssistantConversation,
    AssistantMessage,
    BreadcrumbItem,
} from '@/types';

const EXAMPLES = [
    'assistant.examples.critical',
    'assistant.examples.package',
    'assistant.examples.upgradeFirst',
    'assistant.examples.lastWeek',
];

type ConversationSection = {
    key: string;
    labelKey: string;
    order: number;
    conversations: AssistantConversation[];
};

/**
 * Assistant chat, laid out as externa-core `/ai`: the searchable conversation list on the left,
 * the thread on the right with the composer always at the bottom. The answer arrives when the
 * request finishes (no streaming).
 * ponytail: answers render as plain text with line breaks; add a Markdown renderer if needed.
 */
export default function AssistantIndex({
    conversations,
    conversationId,
    messages,
}: {
    conversations: AssistantConversation[];
    conversationId: string | null;
    messages: AssistantMessage[];
}) {
    const { t } = useTranslation();
    const endRef = useRef<HTMLDivElement>(null);
    const [query, setQuery] = useState('');
    const form = useForm({
        message: '',
        conversation_id: conversationId ?? '',
    });

    const breadcrumbs: BreadcrumbItem[] = useMemo(
        () => [
            { title: t('assistant.title'), href: assistantRoutes.index.url() },
        ],
        [t],
    );

    const sections = useMemo(() => {
        const needle = query.trim().toLowerCase();

        return groupConversations(
            needle === ''
                ? conversations
                : conversations.filter((conversation) =>
                      conversation.title.toLowerCase().includes(needle),
                  ),
        );
    }, [conversations, query]);

    useEffect(() => {
        form.setData('conversation_id', conversationId ?? '');
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [conversationId]);

    useEffect(() => {
        endRef.current?.scrollIntoView({ block: 'end' });
    }, [messages.length, form.processing]);

    const submit = (): void => {
        if (form.data.message.trim() === '' || form.processing) {
            return;
        }

        form.post(assistantRoutes.store.url(), {
            preserveScroll: true,
            // Keep the question when the provider failed, so it can be sent again.
            onSuccess: (page) => {
                if (page.flash.toast?.type !== 'error') {
                    form.reset('message');
                }
            },
        });
    };

    return (
        <AppLayout
            breadcrumbs={breadcrumbs}
            headerActions={
                <Button asChild>
                    <Link href={assistantRoutes.index.url()}>
                        <Plus className="size-4" />
                        {t('assistant.newConversation')}
                    </Link>
                </Button>
            }
        >
            <Head title={t('assistant.title')} />
            <PageLayout
                filters={
                    <FilterSearch
                        className="w-64 flex-none sm:max-w-none lg:w-80"
                        value={query}
                        placeholder={t('assistant.searchConversations')}
                        onChange={setQuery}
                    />
                }
            >
                <ChatLayout
                    listLabel={t('assistant.conversations')}
                    isListCentered={sections.length === 0}
                    list={
                        sections.length === 0 ? (
                            <p className="px-3 text-center text-sm text-muted-foreground">
                                {t('assistant.noConversations')}
                            </p>
                        ) : (
                            sections.map((section) => (
                                <ChatListSection
                                    key={section.key}
                                    label={t(section.labelKey)}
                                >
                                    {section.conversations.map(
                                        (conversation) => (
                                            <ChatListItem
                                                key={conversation.id}
                                                isSelected={
                                                    conversation.id ===
                                                    conversationId
                                                }
                                                onSelect={() =>
                                                    router.visit(
                                                        assistantRoutes.show.url(
                                                            conversation.id,
                                                        ),
                                                    )
                                                }
                                            >
                                                <span className="min-w-0 flex-1 truncate">
                                                    {conversation.title}
                                                </span>
                                            </ChatListItem>
                                        ),
                                    )}
                                </ChatListSection>
                            ))
                        )
                    }
                >
                    <ChatThread
                        composer={
                            <>
                                <ChatComposer
                                    value={form.data.message}
                                    onChange={(value) =>
                                        form.setData('message', value)
                                    }
                                    onSubmit={submit}
                                    label={t('assistant.placeholder')}
                                    sendLabel={t('assistant.send')}
                                    placeholder={t('assistant.placeholder')}
                                    disabled={form.processing}
                                />
                                <InputError
                                    message={
                                        form.errors.message ??
                                        form.errors.conversation_id
                                    }
                                    className="mt-1"
                                />
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {t('assistant.disclaimer')}
                                </p>
                            </>
                        }
                    >
                        <div aria-live="polite" className="contents">
                            {messages.length === 0 && !form.processing ? (
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
                                        <EmptyDescription>
                                            {t('assistant.emptyDescription')}
                                        </EmptyDescription>
                                    </EmptyHeader>
                                    <div className="flex flex-wrap justify-center gap-2">
                                        {EXAMPLES.map((key) => (
                                            <Button
                                                key={key}
                                                type="button"
                                                variant="outline"
                                                onClick={() =>
                                                    form.setData(
                                                        'message',
                                                        t(key),
                                                    )
                                                }
                                            >
                                                {t(key)}
                                            </Button>
                                        ))}
                                    </div>
                                </Empty>
                            ) : null}

                            {messages.map((message) => (
                                <AssistantChatMessage
                                    key={message.id}
                                    message={message}
                                />
                            ))}

                            {form.processing ? (
                                <ThinkingDots label={t('assistant.thinking')} />
                            ) : null}
                            <div ref={endRef} />
                        </div>
                    </ChatThread>
                </ChatLayout>
            </PageLayout>
        </AppLayout>
    );
}

function startOfLocalDay(date: Date): Date {
    return new Date(date.getFullYear(), date.getMonth(), date.getDate());
}

/**
 * Date bucket of a conversation, as externa-core `pages/ai/index.tsx`.
 */
function conversationDateGroup(
    updatedAt: string | null,
): Omit<ConversationSection, 'conversations'> {
    const now = new Date();
    const updated = updatedAt ? new Date(updatedAt) : now;
    const dayDelta = Math.round(
        (startOfLocalDay(now).getTime() - startOfLocalDay(updated).getTime()) /
            86_400_000,
    );

    if (dayDelta <= 0) {
        return { key: 'today', labelKey: 'assistant.today', order: 0 };
    }

    if (dayDelta === 1) {
        return { key: 'yesterday', labelKey: 'assistant.yesterday', order: 1 };
    }

    if (dayDelta === 2) {
        return { key: '2-days', labelKey: 'assistant.twoDaysAgo', order: 2 };
    }

    if (dayDelta < 7) {
        return { key: 'week', labelKey: 'assistant.thisWeek', order: 3 };
    }

    if (dayDelta < 14) {
        return { key: 'last-week', labelKey: 'assistant.aWeekAgo', order: 4 };
    }

    if (dayDelta < 30) {
        return { key: 'month', labelKey: 'assistant.thisMonth', order: 5 };
    }

    return { key: 'older', labelKey: 'assistant.older', order: 6 };
}

function groupConversations(
    conversations: AssistantConversation[],
): ConversationSection[] {
    const buckets = new Map<string, ConversationSection>();

    for (const conversation of conversations) {
        const group = conversationDateGroup(conversation.updated_at);
        const bucket = buckets.get(group.key);

        if (bucket) {
            bucket.conversations.push(conversation);
        } else {
            buckets.set(group.key, { ...group, conversations: [conversation] });
        }
    }

    return [...buckets.values()].sort(
        (first, second) => first.order - second.order,
    );
}
