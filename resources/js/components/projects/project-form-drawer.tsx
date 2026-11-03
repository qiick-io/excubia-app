import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DrawerBody,
    DrawerContent,
    DrawerDescription,
    DrawerFooter,
    DrawerHeader,
    DrawerTitle,
} from '@/components/ui/drawer';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { STRING_LIMITS } from '@/lib/string-limits';
import projects from '@/routes/projects';
import type { ProjectRow } from '@/types';

const EMPTY_PROJECT_FORM = { name: '', is_enabled: true };

export type ProjectFormDrawerProps = {
    editing: ProjectRow | null;
    readOnly?: boolean;
    open?: boolean;
    onCancel?: () => void;
    onSuccess?: () => void;
};

/**
 * Drawer form for registering and editing a project. A new project gets its push token from
 * the server, shown by PushTokenDialog.
 */
export function ProjectFormDrawer({
    editing,
    readOnly = false,
    open = true,
    onCancel,
    onSuccess,
}: ProjectFormDrawerProps) {
    const { t } = useTranslation();
    const form = useForm({ ...EMPTY_PROJECT_FORM });

    useEffect(() => {
        if (!open) {
            return;
        }

        if (editing) {
            const payload = {
                name: editing.name,
                is_enabled: editing.is_enabled,
            };
            form.setData(payload);
            form.setDefaults({ ...payload });
        } else {
            form.setDefaults({ ...EMPTY_PROJECT_FORM });
            form.reset();
        }

        form.clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [editing, open]);

    const submit = (): void => {
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onSuccess?.();
            },
        };

        if (editing) {
            form.put(projects.update.url(editing.id), options);
        } else {
            form.post(projects.store.url(), options);
        }
    };

    const nameError =
        form.errors.name ?? (form.errors as Record<string, string>).slug;

    return (
        <DrawerContent>
            <DrawerHeader>
                <DrawerTitle>
                    {editing
                        ? t('projects.editProject')
                        : t('projects.newProject')}
                </DrawerTitle>
                <DrawerDescription>
                    {t('projects.formDescription')}
                </DrawerDescription>
            </DrawerHeader>

            <form
                className="flex min-h-0 flex-1 flex-col overflow-hidden"
                noValidate
                onSubmit={(event) => {
                    event.preventDefault();

                    if (!readOnly) {
                        submit();
                    }
                }}
            >
                <DrawerBody>
                    <FieldGroup className="gap-4">
                        <Field data-invalid={nameError ? true : undefined}>
                            <FieldLabel htmlFor="project_name">
                                {t('common.name')}
                            </FieldLabel>
                            <Input
                                id="project_name"
                                value={form.data.name}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                                required
                                maxLength={STRING_LIMITS.PROJECT_NAME}
                                disabled={readOnly}
                                aria-invalid={nameError ? true : undefined}
                                aria-describedby={
                                    nameError ? 'project-name-error' : undefined
                                }
                            />
                            {editing ? (
                                <FieldDescription>
                                    {t('projects.slugHint', {
                                        slug: editing.slug,
                                    })}
                                </FieldDescription>
                            ) : null}
                            <FieldError id="project-name-error">
                                {nameError}
                            </FieldError>
                        </Field>

                        <Field>
                            <div className="flex items-center gap-3">
                                <Checkbox
                                    id="project_is_enabled"
                                    checked={form.data.is_enabled}
                                    onCheckedChange={(checked) =>
                                        form.setData(
                                            'is_enabled',
                                            checked === true,
                                        )
                                    }
                                    disabled={readOnly}
                                />
                                <Label htmlFor="project_is_enabled">
                                    {t('projects.enabled')}
                                </Label>
                            </div>
                            <FieldDescription>
                                {t('projects.enabledHint')}
                            </FieldDescription>
                        </Field>
                    </FieldGroup>
                </DrawerBody>

                <DrawerFooter className="flex flex-row justify-end gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onCancel?.()}
                    >
                        {t('common.cancel')}
                    </Button>
                    {!readOnly && (
                        <Button type="submit" disabled={form.processing}>
                            {editing ? t('common.save') : t('projects.create')}
                        </Button>
                    )}
                </DrawerFooter>
            </form>
        </DrawerContent>
    );
}
