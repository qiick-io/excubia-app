import { usePage } from '@inertiajs/react';

/**
 * Returns a checker for the permissions shared by HandleInertiaRequests.
 * Only hides UI: the server authorizes every request on its own.
 */
export function useCan(): (permission: string) => boolean {
    const { permissions } = usePage().props.auth;

    return (permission) => permissions.includes(permission);
}
