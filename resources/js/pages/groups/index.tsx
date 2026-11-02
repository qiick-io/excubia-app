import { Head, Link } from '@inertiajs/react';
import UserGroupController from '@/actions/App/Http/Controllers/UserGroupController';
import ConfirmDeleteButton from '@/components/confirm-delete-button';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/groups';

export type UserGroup = {
    id: number;
    name: string;
    description: string | null;
    roles: string[];
    users: number[];
    can_update: boolean;
};

/**
 * Lists user groups; members inherit the roles of their groups.
 */
export default function GroupsIndex({ groups }: { groups: UserGroup[] }) {
    return (
        <>
            <Head title="Groups" />

            <div className="space-y-6 p-4">
                <div className="flex items-start justify-between gap-4">
                    <Heading
                        title="Groups"
                        description="Members inherit the roles of their groups."
                    />
                    <Button asChild>
                        <Link href={UserGroupController.create()}>
                            New group
                        </Link>
                    </Button>
                </div>

                <div className="divide-y rounded-lg border">
                    {groups.length === 0 && (
                        <p className="p-4 text-sm text-muted-foreground">
                            No groups yet.
                        </p>
                    )}
                    {groups.map((group) => (
                        <div
                            key={group.id}
                            className="flex flex-wrap items-center justify-between gap-4 p-4"
                        >
                            <div className="space-y-1">
                                <div className="flex flex-wrap items-center gap-2 font-medium">
                                    {group.name}
                                    {group.roles.map((role) => (
                                        <Badge key={role} variant="secondary">
                                            {role}
                                        </Badge>
                                    ))}
                                </div>
                                <p className="text-sm text-muted-foreground">
                                    {group.users.length} members
                                    {group.description &&
                                        ` · ${group.description}`}
                                </p>
                            </div>
                            {group.can_update && (
                                <div className="flex gap-2">
                                    <Button variant="outline" size="sm" asChild>
                                        <Link
                                            href={UserGroupController.edit(
                                                group.id,
                                            )}
                                        >
                                            Edit
                                        </Link>
                                    </Button>
                                    <ConfirmDeleteButton
                                        href={UserGroupController.destroy(
                                            group.id,
                                        )}
                                        title={`Delete the group ${group.name}?`}
                                        description="Members lose the roles of this group."
                                    />
                                </div>
                            )}
                        </div>
                    ))}
                </div>
            </div>
        </>
    );
}

GroupsIndex.layout = {
    breadcrumbs: [{ title: 'Groups', href: index() }],
};
