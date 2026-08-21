import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Permission checks for the interface.
 *
 * IA §5.1 and Design System §7.3: a section the user cannot use is hidden,
 * never shown disabled. Hiding is the default because a disabled control
 * still advertises a capability and still invites a support question.
 *
 * This is a display concern only. Authorisation is enforced by policies on
 * the server; nothing here is a security boundary.
 */
export function usePermissions() {
    const page = usePage();

    const permissions = computed<string[]>(
        () => (page.props.auth?.permissions as string[] | undefined) ?? [],
    );

    function can(permission?: string): boolean {
        if (!permission) {
            return true;
        }

        return permissions.value.includes(permission);
    }

    return { permissions, can };
}
