import type { ReactNode } from 'react';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import RoleController from '@/actions/App/Http/Controllers/RoleController';
import ConfirmDeleteButton from '@/components/confirm-delete-button';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/roles';

export type Role = {
    id: number;
    name: string;
    permissions: string[];
    users_count: number | null;
    is_built_in: boolean;
    can_edit: boolean;
};

/**
 * Lists built-in and custom roles. Built-in roles open read-only.
 */
export default function RolesIndex({ roles }: { roles: Role[] }) {
    return (
        <>
            <Head title="Roles" />

            <div className="space-y-6 p-4">
                <div className="flex items-start justify-between gap-4">
                    <Heading
                        title="Roles"
                        description="Built-in roles are read-only. Create a custom role for anything else."
                    />
                    <Button asChild>
                        <Link href={RoleController.create()}>New role</Link>
                    </Button>
                </div>

                <div className="divide-y rounded-lg border">
                    {roles.map((role) => (
                        <div
                            key={role.id}
                            className="flex items-center justify-between gap-4 p-4"
                        >
                            <div className="space-y-1">
                                <div className="flex items-center gap-2 font-medium">
                                    {role.name}
                                    {role.is_built_in && (
                                        <Badge variant="secondary">
                                            Built-in
                                        </Badge>
                                    )}
                                </div>
                                <p className="text-sm text-muted-foreground">
                                    {role.permissions.length} permissions ·{' '}
                                    {role.users_count ?? 0} users
                                </p>
                            </div>
                            <div className="flex gap-2">
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={RoleController.edit(role.id)}>
                                        {role.can_edit ? 'Edit' : 'View'}
                                    </Link>
                                </Button>
                                {role.can_edit && (
                                    <ConfirmDeleteButton
                                        href={RoleController.destroy(role.id)}
                                        title={`Delete the role ${role.name}?`}
                                        description="Users and groups that hold this role lose its permissions."
                                    />
                                )}
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        </>
    );
}

RolesIndex.layout = (page: ReactNode) => (
    <AppLayout breadcrumbs={[{ title: 'Roles', href: index() }]}>
        {page}
    </AppLayout>
);
