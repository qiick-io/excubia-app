import { Head, Link } from '@inertiajs/react';
import UserController from '@/actions/App/Http/Controllers/UserController';
import ConfirmDeleteButton from '@/components/confirm-delete-button';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/users';

export type ManagedUser = {
    id: number;
    name: string;
    email: string;
    is_active: boolean;
    is_sso_linked: boolean;
    roles: string[];
    can_update: boolean;
    can_delete: boolean;
};

/**
 * Lists dashboard users with their roles and status.
 */
export default function UsersIndex({ users }: { users: ManagedUser[] }) {
    return (
        <>
            <Head title="Users" />

            <div className="space-y-6 p-4">
                <div className="flex items-start justify-between gap-4">
                    <Heading
                        title="Users"
                        description="People who can sign in to this dashboard."
                    />
                    <Button asChild>
                        <Link href={UserController.create()}>New user</Link>
                    </Button>
                </div>

                <div className="divide-y rounded-lg border">
                    {users.map((user) => (
                        <div
                            key={user.id}
                            className="flex flex-wrap items-center justify-between gap-4 p-4"
                        >
                            <div className="space-y-1">
                                <div className="flex flex-wrap items-center gap-2 font-medium">
                                    {user.name}
                                    {!user.is_active && (
                                        <Badge variant="destructive">
                                            Inactive
                                        </Badge>
                                    )}
                                    {user.is_sso_linked && (
                                        <Badge variant="outline">SSO</Badge>
                                    )}
                                    {user.roles.map((role) => (
                                        <Badge key={role} variant="secondary">
                                            {role}
                                        </Badge>
                                    ))}
                                </div>
                                <p className="text-sm text-muted-foreground">
                                    {user.email}
                                </p>
                            </div>
                            <div className="flex gap-2">
                                {user.can_update && (
                                    <>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            asChild
                                        >
                                            <Link
                                                href={UserController.sendPasswordReset(
                                                    user.id,
                                                )}
                                                as="button"
                                                preserveScroll
                                            >
                                                Send reset email
                                            </Link>
                                        </Button>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            asChild
                                        >
                                            <Link
                                                href={UserController.edit(
                                                    user.id,
                                                )}
                                            >
                                                Edit
                                            </Link>
                                        </Button>
                                    </>
                                )}
                                {user.can_delete && (
                                    <ConfirmDeleteButton
                                        href={UserController.destroy(user.id)}
                                        title={`Delete ${user.name}?`}
                                        description="The account is removed. Deactivate it instead to keep it."
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

UsersIndex.layout = {
    breadcrumbs: [{ title: 'Users', href: index() }],
};
