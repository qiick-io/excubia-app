import type { ReactNode } from 'react';
import AppLayout from '@/layouts/app-layout';
import { Form, Head } from '@inertiajs/react';
import UserGroupController from '@/actions/App/Http/Controllers/UserGroupController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { UserGroup } from '@/pages/groups/index';
import { index } from '@/routes/groups';

type Member = { id: number; name: string; email: string };

/**
 * Create or edit a group: name, description, roles and members.
 */
export default function GroupEdit({
    group,
    roles,
    users,
}: {
    group: UserGroup | null;
    roles: string[];
    users: Member[];
}) {
    const form = group
        ? UserGroupController.update.form(group.id)
        : UserGroupController.store.form();

    return (
        <>
            <Head title={group ? group.name : 'New group'} />

            <div className="max-w-xl space-y-6 p-4">
                <Heading
                    title={group ? group.name : 'New group'}
                    description="Members inherit the roles you pick here."
                />

                <Form {...form} className="space-y-6">
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">Name</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    defaultValue={group?.name}
                                    required
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="description">Description</Label>
                                <Input
                                    id="description"
                                    name="description"
                                    defaultValue={group?.description ?? ''}
                                />
                                <InputError message={errors.description} />
                            </div>

                            <fieldset className="grid gap-3">
                                <legend className="mb-2 text-sm font-medium">
                                    Roles
                                </legend>
                                {roles.map((role) => (
                                    <div
                                        key={role}
                                        className="flex items-center gap-3"
                                    >
                                        <Checkbox
                                            id={`role-${role}`}
                                            name="roles[]"
                                            value={role}
                                            defaultChecked={group?.roles.includes(
                                                role,
                                            )}
                                        />
                                        <Label htmlFor={`role-${role}`}>
                                            {role}
                                        </Label>
                                    </div>
                                ))}
                                <InputError message={errors.roles} />
                            </fieldset>

                            <fieldset className="grid gap-3">
                                <legend className="mb-2 text-sm font-medium">
                                    Members
                                </legend>
                                {users.map((user) => (
                                    <div
                                        key={user.id}
                                        className="flex items-center gap-3"
                                    >
                                        <Checkbox
                                            id={`user-${user.id}`}
                                            name="users[]"
                                            value={String(user.id)}
                                            defaultChecked={group?.users.includes(
                                                user.id,
                                            )}
                                        />
                                        <Label htmlFor={`user-${user.id}`}>
                                            {user.name}{' '}
                                            <span className="text-muted-foreground">
                                                {user.email}
                                            </span>
                                        </Label>
                                    </div>
                                ))}
                                <InputError message={errors.users} />
                            </fieldset>

                            <Button disabled={processing}>Save</Button>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

GroupEdit.layout = (page: ReactNode) => (
    <AppLayout breadcrumbs={[{ title: 'Groups', href: index() }]}>
        {page}
    </AppLayout>
);
