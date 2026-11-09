import { useForm } from '@inertiajs/react';
import { useEffect, useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { PaginatedMultiSelect } from '@/components/admin/paginated-multi-select';
import { Button } from '@/components/ui/button';
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
import { STRING_LIMITS } from '@/lib/string-limits';
import projectGroupsRoutes from '@/routes/project-groups';
import projectsRoutes from '@/routes/projects';
import type { AdminProjectGroupRow } from '@/types/admin';

const EMPTY_PROJECT_GROUP_FORM = {
    name: '',
    description: '',
    projects: [] as number[],
};

export type ProjectGroupFormDrawerProps = {
    editing: AdminProjectGroupRow | null;
    readOnly?: boolean;
    open?: boolean;
    onCancel?: () => void;
    onSuccess?: () => void;
};

/**
 * Drawer form for creating and editing a project group (#252): name, description and the
 * projects, picked from the projects the current user can see (`projects.index` as JSON).
 * @param {*} props - Component props.
 * @returns {JSX.Element}
 */
export function ProjectGroupFormDrawer({
    editing,
    readOnly = false,
    open = true,
    onCancel,
    onSuccess,
}: ProjectGroupFormDrawerProps) {
    const { t } = useTranslation();
    const form = useForm({ ...EMPTY_PROJECT_GROUP_FORM });
    const initialProjectOptions = useMemo(
        () =>
            (editing?.projects ?? []).map((project) => ({
                id: project.id,
                label: project.name,
            })),
        [editing],
    );

    useEffect(() => {
        if (!open) {
            return;
        }

        if (editing) {
            const payload = {
                name: editing.name,
                description: editing.description ?? '',
                projects: editing.projects.map((project) => project.id),
            };
            form.setData(payload);
            form.setDefaults({ ...payload });
        } else {
            form.setDefaults({ ...EMPTY_PROJECT_GROUP_FORM });
            form.reset();
            form.clearErrors();
        }
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
            form.put(projectGroupsRoutes.update.url(editing.id), options);
        } else {
            form.post(projectGroupsRoutes.store.url(), options);
        }
    };

    const projectsError = form.errors.projects ?? form.errors['projects.0'];

    return (
        <DrawerContent>
            <DrawerHeader>
                <DrawerTitle>
                    {editing
                        ? t('projectGroups.editGroup')
                        : t('projectGroups.newGroup')}
                </DrawerTitle>
                <DrawerDescription>
                    {t('projectGroups.formDescription')}
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
                        <Field
                            data-invalid={form.errors.name ? true : undefined}
                        >
                            <FieldLabel htmlFor="project_group_name">
                                {t('projectGroups.name')}
                            </FieldLabel>
                            <Input
                                id="project_group_name"
                                value={form.data.name}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                                required
                                maxLength={STRING_LIMITS.GROUP_NAME}
                                disabled={readOnly}
                                aria-invalid={
                                    form.errors.name ? true : undefined
                                }
                                aria-describedby={
                                    form.errors.name
                                        ? 'project-group-name-error'
                                        : undefined
                                }
                            />
                            <FieldError id="project-group-name-error">
                                {form.errors.name}
                            </FieldError>
                        </Field>

                        <Field
                            data-invalid={
                                form.errors.description ? true : undefined
                            }
                        >
                            <FieldLabel htmlFor="project_group_description">
                                {t('projectGroups.description')}
                            </FieldLabel>
                            <Input
                                id="project_group_description"
                                value={form.data.description}
                                onChange={(event) =>
                                    form.setData(
                                        'description',
                                        event.target.value,
                                    )
                                }
                                maxLength={STRING_LIMITS.DESCRIPTION}
                                disabled={readOnly}
                                aria-invalid={
                                    form.errors.description ? true : undefined
                                }
                                aria-describedby={
                                    form.errors.description
                                        ? 'project-group-description-error'
                                        : undefined
                                }
                            />
                            <FieldError id="project-group-description-error">
                                {form.errors.description}
                            </FieldError>
                        </Field>

                        <Field data-invalid={projectsError ? true : undefined}>
                            <FieldLabel>
                                {t('projectGroups.projects')}
                            </FieldLabel>
                            <PaginatedMultiSelect
                                fetchUrl={projectsRoutes.index.url()}
                                initialOptions={initialProjectOptions}
                                placeholder={t(
                                    'projectGroups.projectsPlaceholder',
                                )}
                                aria-label={t('projectGroups.projects')}
                                value={form.data.projects}
                                onChange={(projectIds) =>
                                    form.setData('projects', projectIds)
                                }
                                disabled={readOnly}
                            />
                            <FieldDescription>
                                {t('projectGroups.projectsHint')}
                            </FieldDescription>
                            <FieldError>{projectsError}</FieldError>
                        </Field>
                    </FieldGroup>
                </DrawerBody>

                <DrawerFooter className="flex flex-row justify-end gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onCancel?.()}
                    >
                        {t('projectGroups.cancel')}
                    </Button>
                    {!readOnly && (
                        <Button type="submit" disabled={form.processing}>
                            {editing
                                ? t('projectGroups.save')
                                : t('projectGroups.create')}
                        </Button>
                    )}
                </DrawerFooter>
            </form>
        </DrawerContent>
    );
}
