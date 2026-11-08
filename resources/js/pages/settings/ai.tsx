import { Head, router } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import Heading from '@/components/heading';
import { SettingsFormActions } from '@/components/settings-form-actions';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldLabel,
} from '@/components/ui/field';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import aiSettings from '@/routes/ai-settings';
import type { BreadcrumbItem } from '@/types';
import { edit as editProfile } from '@/routes/profile';

type ToolRow = {
    name: string;
    description: string;
};

/**
 * Settings → AI, laid out as externa-core's AI tools page: what the assistant runs on (from
 * `.env`) and the read-only tools it may use, one checkbox each.
 */
export default function AiSettingsPage({
    isEnabled,
    provider,
    model,
    enabledTools,
    tools,
}: {
    isEnabled: boolean;
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
                                    <FieldLabel htmlFor={`tool-${tool.name}`}>
                                        {tool.name}
                                        <span className="text-xs font-normal text-muted-foreground">
                                            ({t('settings.ai.readOnly')})
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
            </SettingsLayout>
        </AppLayout>
    );
}
