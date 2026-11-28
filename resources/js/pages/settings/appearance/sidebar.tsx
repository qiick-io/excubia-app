import { Form, Head } from '@inertiajs/react';
import { ArrowDown, ArrowUp, GripVertical, Lock } from 'lucide-react';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import AppearanceSidebarSettingsController from '@/actions/App/Http/Controllers/Settings/AppearanceSidebarSettingsController';
import Heading from '@/components/heading';
import { SettingsFormActions } from '@/components/settings-form-actions';
import { Button } from '@/components/ui/button';
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

/** A sortable module also exposes single-pointer controls for the same order. */
function ModuleRow({
    module,
    label,
    onToggle,
    canMoveUp,
    canMoveDown,
    onMove,
}: {
    module: SidebarModuleSetting;
    label: string;
    onToggle: (enabled: boolean) => void;
    canMoveUp: boolean;
    canMoveDown: boolean;
    onMove: (offset: -1 | 1, button: HTMLButtonElement) => void;
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
            <div className="flex shrink-0 gap-1">
                <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    aria-label={t('settings.project.moveModuleUp', {
                        module: label,
                    })}
                    aria-disabled={!canMoveUp}
                    aria-controls="sidebar-module-order-status"
                    className="aria-disabled:cursor-default aria-disabled:opacity-50"
                    onClick={(event) => {
                        if (canMoveUp) {
                            onMove(-1, event.currentTarget);
                        }
                    }}
                >
                    <ArrowUp aria-hidden="true" />
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    aria-label={t('settings.project.moveModuleDown', {
                        module: label,
                    })}
                    aria-disabled={!canMoveDown}
                    aria-controls="sidebar-module-order-status"
                    className="aria-disabled:cursor-default aria-disabled:opacity-50"
                    onClick={(event) => {
                        if (canMoveDown) {
                            onMove(1, event.currentTarget);
                        }
                    }}
                >
                    <ArrowDown aria-hidden="true" />
                </Button>
            </div>
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
    const [movedModuleId, setMovedModuleId] = useState<string | null>(null);

    const sortableModulesRef = useRef<HTMLDivElement>(null);
    const moveButtonRef = useRef<HTMLButtonElement>(null);
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
    const movedModulePosition =
        sortableModules.findIndex((module) => module.id === movedModuleId) + 1;

    useLayoutEffect(() => {
        // Moving a keyed row can detach it briefly. Restore focus to the same button.
        moveButtonRef.current?.focus();
        moveButtonRef.current = null;
    }, [sortableModuleKey]);

    useEffect(() => {
        const el = sortableModulesRef.current;

        if (!el || sortableModuleKey === '') {
            return;
        }

        const sortable = createSortableList(el, {
            handle: '.drag-handle',
            onEnd: (event) => {
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
                setMovedModuleId(event.item.dataset.id ?? null);
            },
        });

        return () => sortable.destroy();
    }, [sortableModuleKey]);

    const moveModule = (
        moduleId: string,
        offset: -1 | 1,
        button: HTMLButtonElement,
    ): void => {
        const modules = [...sortableModules];
        const position = modules.findIndex((module) => module.id === moduleId);
        const destination = position + offset;

        if (position < 0 || destination < 0 || destination >= modules.length) {
            return;
        }

        if (button === document.activeElement) {
            moveButtonRef.current = button;
        }

        const [module] = modules.splice(position, 1);
        modules.splice(destination, 0, module);

        setForm((current) => ({ ...current, sidebar_modules: modules }));
        setMovedModuleId(moduleId);
    };

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
                                            {sortableModules.map(
                                                (module, position) => (
                                                    <ModuleRow
                                                        key={module.id}
                                                        module={module}
                                                        label={moduleLabel(
                                                            module.id,
                                                        )}
                                                        canMoveUp={position > 0}
                                                        canMoveDown={
                                                            position <
                                                            sortableModules.length -
                                                                1
                                                        }
                                                        onMove={(
                                                            offset,
                                                            button,
                                                        ) =>
                                                            moveModule(
                                                                module.id,
                                                                offset,
                                                                button,
                                                            )
                                                        }
                                                        onToggle={(enabled) =>
                                                            toggleModule(
                                                                module.id,
                                                                enabled,
                                                            )
                                                        }
                                                    />
                                                ),
                                            )}
                                        </div>
                                    </div>
                                    <p
                                        id="sidebar-module-order-status"
                                        role="status"
                                        aria-live="polite"
                                        aria-atomic="true"
                                        className="sr-only"
                                    >
                                        {movedModuleId !== null &&
                                        movedModulePosition > 0
                                            ? t(
                                                  'settings.project.moduleMoved',
                                                  {
                                                      module: moduleLabel(
                                                          movedModuleId,
                                                      ),
                                                      position:
                                                          movedModulePosition,
                                                      total: sortableModules.length,
                                                  },
                                              )
                                            : null}
                                    </p>
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
