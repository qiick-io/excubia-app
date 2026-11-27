import { Head, Link, router } from '@inertiajs/react';
import { ArrowUpRight, Coins } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import Heading from '@/components/heading';
import { AI_GUIDE_URL } from '@/components/reports/ai-usage-view';
import { SettingsFormActions } from '@/components/settings-form-actions';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldLabel,
} from '@/components/ui/field';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import aiPricesRoutes from '@/routes/ai-prices';
import aiSettings from '@/routes/ai-settings';
import type { BreadcrumbItem } from '@/types';
import { edit as editProfile } from '@/routes/profile';

type ToolRow = {
    name: string;
    kind: 'read' | 'write';
    description: string;
};

/**
 * Settings → AI, laid out as externa-core's AI tools page: what the assistant runs on and
 * whether its write tools are on (from `.env`, #320), and the read and write tools it and the MCP
 * server may use, one checkbox each. Then the Prices section (#331): what the AI price table feeds
 * and a link to it, or, until a project sends AI usage, an empty state that still opens it. Each
 * part shows only to who may change it (`canManageTools`, `canManagePrices`).
 */
export default function AiSettingsPage({
    canManageTools,
    canManagePrices,
    hasAiUsage,
    isEnabled,
    isWriteEnabled,
    isMcpWriteEnabled,
    provider,
    model,
    enabledTools,
    tools,
}: {
    canManageTools: boolean;
    canManagePrices: boolean;
    hasAiUsage: boolean;
    isEnabled: boolean;
    isWriteEnabled: boolean;
    isMcpWriteEnabled: boolean;
    provider: string;
    model: string;
    enabledTools: string[];
    tools: ToolRow[];
}) {
    const { t } = useTranslation();
    const [selected, setSelected] = useState<string[]>(enabledTools);
    const [saving, setSaving] = useState(false);
    const [recentlySuccessful, setRecentlySuccessful] = useState(false);

    useEffect(() => {
        if (!recentlySuccessful) {
            return;
        }

        const timeoutId = window.setTimeout(
            () => setRecentlySuccessful(false),
            2000,
        );

        return () => window.clearTimeout(timeoutId);
    }, [recentlySuccessful]);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('settings.layout.title'), href: editProfile() },
        {
            title: t('settings.ai.breadcrumb'),
            href: aiSettings.edit.url(),
        },
    ];

    const selectedSet = useMemo(() => new Set(selected), [selected]);

    const toggle = (name: string, isChecked: boolean): void => {
        setSelected((previous) => {
            if (isChecked) {
                return previous.includes(name) ? previous : [...previous, name];
            }

            return previous.filter((item) => item !== name);
        });
    };

    const save = (): void => {
        setSaving(true);
        router.put(
            aiSettings.update.url(),
            { tools: selected },
            {
                preserveScroll: true,
                onSuccess: () => setRecentlySuccessful(true),
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('settings.ai.head')} />
            <SettingsLayout>
                {canManageTools ? (
                    <form
                        className="space-y-6"
                        onSubmit={(event) => {
                            event.preventDefault();
                            save();
                        }}
                    >
                        <Heading
                            variant="small"
                            title={t('settings.ai.title')}
                            description={t('settings.ai.description')}
                        />

                        <div className="rounded-md border border-border p-4 text-sm">
                            <p>
                                {t('settings.ai.statusLabel')}:{' '}
                                <span className="font-medium">
                                    {isEnabled
                                        ? t('settings.ai.statusOn')
                                        : t('settings.ai.statusOff')}
                                </span>
                            </p>
                            <p className="mt-2">
                                {t('settings.ai.writesLabel')}:{' '}
                                <span className="font-medium">
                                    {isWriteEnabled
                                        ? t('settings.ai.writesOn')
                                        : t('settings.ai.writesOff')}
                                </span>
                                {isWriteEnabled && !isMcpWriteEnabled ? (
                                    <span className="text-muted-foreground">
                                        {' · '}
                                        {t('settings.ai.mcpWritesOff')}
                                    </span>
                                ) : null}
                            </p>
                            <p className="mt-2">
                                {t('settings.ai.providerLabel')}:{' '}
                                <span className="font-mono text-xs">
                                    {provider}
                                </span>
                                {' · '}
                                {t('settings.ai.modelLabel')}:{' '}
                                <span className="font-mono text-xs">
                                    {model || '—'}
                                </span>
                            </p>
                            <p className="mt-2 text-muted-foreground">
                                {t('settings.ai.envHint')}
                            </p>
                        </div>

                        <div className="space-y-3">
                            {tools.map((tool) => (
                                <Field
                                    key={tool.name}
                                    orientation="horizontal"
                                    className="rounded-md border border-border p-3"
                                    data-test={`ai-tool-${tool.name}`}
                                >
                                    <Checkbox
                                        id={`tool-${tool.name}`}
                                        checked={selectedSet.has(tool.name)}
                                        onCheckedChange={(value) =>
                                            toggle(tool.name, value === true)
                                        }
                                    />
                                    <FieldContent>
                                        <FieldLabel
                                            htmlFor={`tool-${tool.name}`}
                                        >
                                            {tool.name}
                                            <span className="text-xs font-normal text-muted-foreground">
                                                (
                                                {t(
                                                    `settings.ai.kind.${tool.kind}`,
                                                )}
                                                )
                                            </span>
                                        </FieldLabel>
                                        <FieldDescription>
                                            {tool.description}
                                        </FieldDescription>
                                    </FieldContent>
                                </Field>
                            ))}
                        </div>

                        <SettingsFormActions
                            processing={saving}
                            recentlySuccessful={recentlySuccessful}
                            data-test="ai-settings-save"
                        />
                    </form>
                ) : null}

                {canManagePrices ? (
                    <section
                        className="space-y-6"
                        data-test="ai-prices-section"
                    >
                        <Heading
                            variant="small"
                            title={t('settings.ai.pricesTitle')}
                            description={t('settings.ai.pricesDescription')}
                        />

                        {hasAiUsage ? (
                            <div className="flex flex-wrap items-center gap-2">
                                <Button variant="outline" asChild>
                                    <Link href={aiPricesRoutes.edit.url()}>
                                        {t('settings.ai.pricesEdit')}
                                    </Link>
                                </Button>
                                <Button
                                    variant="link"
                                    asChild
                                    className="text-muted-foreground"
                                >
                                    <a
                                        href={AI_GUIDE_URL}
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        {t('settings.ai.pricesDocs')}
                                        <ArrowUpRight className="size-3.5" />
                                    </a>
                                </Button>
                            </div>
                        ) : (
                            <PageEmptyState
                                compact
                                icon={Coins}
                                title={t('settings.ai.pricesEmptyTitle')}
                                description={t(
                                    'settings.ai.pricesEmptyDescription',
                                )}
                                action={
                                    <Button variant="outline" asChild>
                                        <Link href={aiPricesRoutes.edit.url()}>
                                            {t('settings.ai.pricesOpenAnyway')}
                                        </Link>
                                    </Button>
                                }
                                docsHref={AI_GUIDE_URL}
                                docsLabel={t('settings.ai.pricesDocs')}
                            />
                        )}
                    </section>
                ) : null}
            </SettingsLayout>
        </AppLayout>
    );
}
