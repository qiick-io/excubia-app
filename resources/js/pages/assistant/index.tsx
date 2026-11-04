import { Head, Link, useForm } from '@inertiajs/react';
import { Plus, Send } from 'lucide-react';
import { useEffect, useMemo, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
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

/**
 * Assistant chat: the user's conversations on the left, the selected one on the right.
 * The answer arrives when the request finishes (no streaming).
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
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('assistant.title')} />
            <div className="flex min-h-0 flex-1 gap-4 p-4">
                <aside className="hidden w-64 shrink-0 flex-col gap-2 md:flex">
                    <Button asChild variant="outline" className="justify-start">
                        <Link href={assistantRoutes.index.url()}>
                            <Plus className="mr-1 size-4" />
                            {t('assistant.newConversation')}
                        </Link>
                    </Button>
                    <nav
                        aria-label={t('assistant.conversations')}
                        className="flex flex-col gap-0.5 overflow-y-auto"
                    >
                        {conversations.map((conversation) => (
                            <Link
                                key={conversation.id}
                                href={assistantRoutes.show.url(conversation.id)}
                                className={cn(
                                    'truncate rounded-md px-2 py-1.5 text-sm hover:bg-accent',
                                    conversation.id === conversationId &&
                                        'bg-accent font-medium',
                                )}
                                aria-current={
                                    conversation.id === conversationId
                                        ? 'page'
                                        : undefined
                                }
                            >
                                {conversation.title}
                            </Link>
                        ))}
                    </nav>
                </aside>

                <section className="flex min-h-0 flex-1 flex-col rounded-xl border">
                    <div
                        className="flex-1 space-y-4 overflow-y-auto p-4"
                        aria-live="polite"
                    >
                        {messages.length === 0 && !form.processing ? (
                            <div className="mx-auto max-w-xl space-y-3 py-8 text-center">
                                <p className="font-medium">
                                    {t('assistant.emptyTitle')}
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    {t('assistant.emptyDescription')}
                                </p>
                                <div className="flex flex-wrap justify-center gap-2 pt-2">
                                    {EXAMPLES.map((key) => (
                                        <Button
                                            key={key}
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                form.setData('message', t(key))
                                            }
                                        >
                                            {t(key)}
                                        </Button>
                                    ))}
                                </div>
                            </div>
                        ) : null}

                        {messages.map((message) => (
                            <div
                                key={message.id}
                                className={cn(
                                    'flex',
                                    message.role === 'user'
                                        ? 'justify-end'
                                        : 'justify-start',
                                )}
                            >
                                <div
                                    className={cn(
                                        'max-w-[85%] rounded-lg px-3 py-2 text-sm whitespace-pre-wrap',
                                        message.role === 'user'
                                            ? 'bg-primary text-primary-foreground'
                                            : 'bg-muted',
                                        message.is_failed &&
                                            'border border-destructive',
                                    )}
                                >
                                    <span className="sr-only">
                                        {message.role === 'user'
                                            ? t('assistant.you')
                                            : t('assistant.title')}
                                        :{' '}
                                    </span>
                                    {message.is_failed && message.content === ''
                                        ? t('assistant.failed')
                                        : message.content}
                                </div>
                            </div>
                        ))}

                        {form.processing ? (
                            <div className="flex items-center gap-2 text-sm text-muted-foreground">
                                <Spinner />
                                {t('assistant.thinking')}
                            </div>
                        ) : null}
                        <div ref={endRef} />
                    </div>

                    <form
                        className="border-t p-3"
                        onSubmit={(event) => {
                            event.preventDefault();
                            submit();
                        }}
                    >
                        <label htmlFor="assistant_message" className="sr-only">
                            {t('assistant.placeholder')}
                        </label>
                        <div className="flex items-end gap-2">
                            <Textarea
                                id="assistant_message"
                                value={form.data.message}
                                onChange={(event) =>
                                    form.setData('message', event.target.value)
                                }
                                onKeyDown={(event) => {
                                    if (
                                        event.key === 'Enter' &&
                                        !event.shiftKey
                                    ) {
                                        event.preventDefault();
                                        submit();
                                    }
                                }}
                                placeholder={t('assistant.placeholder')}
                                maxLength={4000}
                                rows={2}
                                disabled={form.processing}
                                className="min-h-0 resize-none"
                            />
                            <Button
                                type="submit"
                                size="icon"
                                disabled={
                                    form.processing ||
                                    form.data.message.trim() === ''
                                }
                                aria-label={t('assistant.send')}
                            >
                                <Send className="size-4" />
                            </Button>
                        </div>
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
                    </form>
                </section>
            </div>
        </AppLayout>
    );
}
