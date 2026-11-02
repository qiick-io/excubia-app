import { Form, Head } from '@inertiajs/react';
import RoleController from '@/actions/App/Http/Controllers/RoleController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Role } from '@/pages/roles/index';
import { index } from '@/routes/roles';

/**
 * Create or edit a custom role. A built-in role (`can_edit` false) is shown read-only.
 */
export default function RoleEdit({
    role,
    permissions,
}: {
    role: Role | null;
    permissions: string[];
}) {
    const isReadOnly = role !== null && !role.can_edit;
    const form = role
        ? RoleController.update.form(String(role.id))
        : RoleController.store.form();

    return (
        <>
            <Head title={role ? role.name : 'New role'} />

            <div className="max-w-xl space-y-6 p-4">
                <Heading
                    title={role ? role.name : 'New role'}
                    description={
                        isReadOnly
                            ? 'Built-in role, read-only.'
                            : 'Name the role and pick its permissions.'
                    }
                />

                <Form {...form} className="space-y-6">
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">Name</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    defaultValue={role?.name}
                                    required
                                    disabled={isReadOnly}
                                />
                                <InputError message={errors.name} />
                            </div>

                            <fieldset className="grid gap-3">
                                <legend className="mb-2 text-sm font-medium">
                                    Permissions
                                </legend>
                                {permissions.map((permission) => (
                                    <div
                                        key={permission}
                                        className="flex items-center gap-3"
                                    >
                                        <Checkbox
                                            id={permission}
                                            name="permissions[]"
                                            value={permission}
                                            defaultChecked={role?.permissions.includes(
                                                permission,
                                            )}
                                            disabled={isReadOnly}
                                        />
                                        <Label htmlFor={permission}>
                                            {permission}
                                        </Label>
                                    </div>
                                ))}
                                <InputError message={errors.permissions} />
                            </fieldset>

                            {!isReadOnly && (
                                <Button disabled={processing}>Save</Button>
                            )}
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

RoleEdit.layout = {
    breadcrumbs: [{ title: 'Roles', href: index() }],
};
