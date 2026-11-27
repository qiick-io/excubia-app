import { Form, Head } from '@inertiajs/react';
import { GripVertical, Lock } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import AppearanceSidebarSettingsController from '@/actions/App/Http/Controllers/Settings/AppearanceSidebarSettingsController';
import Heading from '@/components/heading';
import { SettingsFormActions } from '@/components/settings-form-actions';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Switch } from '@/components/ui/switch';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { createSortableList } from '@/lib/create-sortable-list';
import { cn } from '@/lib/utils';
import { edit as editSidebar } from '@/routes/appearance/sidebar';
import type {
    BreadcrumbItem,
    ProjectSettingsForm,
    SidebarModuleSetting,
} from '@/types';
import { edit as editProfile } from '@/routes/profile';

type SidebarProjectSettings = Pick<ProjectSettingsForm, 'sidebar_modules'>;

type Props = {
    project: SidebarProjectSettings;
};

function ModuleRowContent({
    module,
    label,
    onToggle,
    dragHandle,
}: {
    module: SidebarModuleSetting;
    label: string;
    onToggle: (enabled: boolean) => void;
    dragHandle?: ReactNode;
}) {
    return (
        <>
            {dragHandle ?? (
                <span
                    className="flex size-4 items-center justify-center text-muted-foreground/40"
                    aria-hidden
                >
                    <Lock className="size-3.5" />
                </span>
            )}
            <Switch
                id={`module-${module.id}`}
                checked={module.enabled}
                disabled={module.locked}
                onCheckedChange={(checked) => onToggle(checked)}
            />
            <FieldLabel
                htmlFor={`module-${module.id}`}
                className={cn(
                    'flex-1 font-normal',
                    module.locked && 'text-muted-foreground',
                )}
            >
                {label}
                {module.locked ? ' *' : ''}
            </FieldLabel>
        </>
    );
}

function ModuleRow({
    module,
    label,
    onToggle,
}: {
    module: SidebarModuleSetting;
    label: string;
    onToggle: (enabled: boolean) => void;
}) {
    const { t } = useTranslation();

    return (
        <div
            data-id={module.id}
            className="flex items-center gap-3 rounded-md border px-3 py-2"
        >
            <ModuleRowContent
                module={module}
                label={label}
                onToggle={onToggle}
                dragHandle={
                    <button
                        type="button"
                        className="drag-handle cursor-grab touch-none text-muted-foreground hover:text-foreground"
                        aria-label={t('a11y.reorder')}
                    >
                        <GripVertical className="size-4" />
                    </button>
                }
            />
        </div>
    );
}

/**
 * Sidebar module order / visibility settings.
 */
export default function AppearanceSidebarSettingsPage({ project }: Props) {
    const { t } = useTranslation();
    const [form, setForm] = useState<{
        sidebar_modules: SidebarModuleSetting[];
    }>(() => ({ sidebar_modules: project.sidebar_modules }));

    const sortableModulesRef = useRef<HTMLDivElement>(null);
    const formRef = useRef(form);
    const setFormRef = useRef(setForm);

    useEffect(() => {
        formRef.current = form;
        setFormRef.current = setForm;
    });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('settings.layout.title'), href: editProfile() },
        {
            title: t('settings.layout.sidebar'),
            href: editSidebar(),
        },
    ];

    const moduleLabel = (id: string): string => {
        const key = `settings.project.modules.${id}`;
        const translated = t(key);

        return translated === key ? id : translated;
    };

    const sortableModules = form.sidebar_modules;
    const sortableModuleKey = sortableModules
        .map((module) => module.id)
        .join('\0');

    useEffect(() => {
        const el = sortableModulesRef.current;

        if (!el || sortableModuleKey === '') {
            return;
        }

        const sortable = createSortableList(el, {
            handle: '.drag-handle',
            onEnd: () => {
                const order = sortable.toArray();
                const prev = formRef.current.sidebar_modules.map(
                    (module) => module.id,
                );

                if (
                    order.length === 0 ||
                    order.join('\0') === prev.join('\0')
                ) {
                    return;
                }

                setFormRef.current((current) => {
                    const byId = new Map(
                        current.sidebar_modules.map((module) => [
                            module.id,
                            module,
                        ]),
                    );
                    const reordered = order
                        .map((id) => byId.get(id))
                        .filter(
                            (module): module is SidebarModuleSetting =>
                                !!module,
                        );

                    return {
                        ...current,
                        sidebar_modules: reordered,
                    };
                });
            },
        });

        return () => sortable.destroy();
    }, [sortableModuleKey]);

    const toggleModule = (moduleId: string, enabled: boolean): void => {
        setForm((current) => ({
            ...current,
            sidebar_modules: current.sidebar_modules.map((entry) =>
                entry.id === moduleId ? { ...entry, enabled } : entry,
            ),
        }));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('settings.appearance.sidebar.head')} />

            <h1 className="sr-only">{t('settings.appearance.sidebar.head')}</h1>

            <SettingsLayout>
                <Form
                    {...AppearanceSidebarSettingsController.update.form()}
                    options={{ preserveScroll: true }}
                    className="space-y-12"
                    noValidate
                >
                    {({ processing, recentlySuccessful, errors }) => (
                        <>
                            <input
                                type="hidden"
                                name="sidebar_modules"
                                value={JSON.stringify(form.sidebar_modules)}
                            />

                            <div className="space-y-6">
                                <Heading
                                    variant="small"
                                    title={t('settings.project.sidebarTitle')}
                                    description={t(
                                        'settings.project.sidebarDescription',
                                    )}
                                />
                                <Field
                                    data-invalid={
                                        errors.sidebar_modules
                                            ? true
                                            : undefined
                                    }
                                >
                                    <div className="space-y-2">
                                        <div
                                            ref={sortableModulesRef}
                                            className="space-y-2"
                                        >
                                            {sortableModules.map((module) => (
                                                <ModuleRow
                                                    key={module.id}
                                                    module={module}
                                                    label={moduleLabel(
                                                        module.id,
                                                    )}
                                                    onToggle={(enabled) =>
                                                        toggleModule(
                                                            module.id,
                                                            enabled,
                                                        )
                                                    }
                                                />
                                            ))}
                                        </div>
                                    </div>
                                    <FieldError id="sidebar-modules-error">
                                        {errors.sidebar_modules}
                                    </FieldError>
                                </Field>
                            </div>

                            <SettingsFormActions
                                processing={processing}
                                recentlySuccessful={recentlySuccessful}
                                data-test="appearance-sidebar-settings-save"
                            />
                        </>
                    )}
                </Form>
            </SettingsLayout>
        </AppLayout>
    );
}
