import { Form, Head } from '@inertiajs/react';
import UserController from '@/actions/App/Http/Controllers/UserController';
import BooleanField from '@/components/boolean-field';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { ManagedUser } from '@/pages/users/index';
import { index } from '@/routes/users';

/**
 * Create or edit a dashboard user: profile, password, roles and active flag.
 */
export default function UserEdit({
    user,
    roles,
}: {
    user: ManagedUser | null;
    roles: string[];
}) {
    const form = user
        ? UserController.update.form(user.id)
        : UserController.store.form();

    return (
        <>
            <Head title={user ? user.name : 'New user'} />

            <div className="max-w-xl space-y-6 p-4">
                <Heading
                    title={user ? user.name : 'New user'}
                    description={
                        user
                            ? 'Leave the password empty to keep it.'
                            : 'The email counts as verified.'
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
                                    defaultValue={user?.name}
                                    required
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="email">Email address</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    defaultValue={user?.email}
                                    required
                                />
                                <InputError message={errors.email} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password">Password</Label>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    autoComplete="new-password"
                                    required={user === null}
                                />
                                <InputError message={errors.password} />
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
                                            defaultChecked={user?.roles.includes(
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

                            <BooleanField
                                name="is_active"
                                label="Active (can sign in)"
                                defaultChecked={user?.is_active ?? true}
                            />
                            <InputError message={errors.is_active} />

                            <Button disabled={processing}>Save</Button>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

UserEdit.layout = {
    breadcrumbs: [{ title: 'Users', href: index() }],
};
