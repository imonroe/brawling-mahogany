<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/app/Heading.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { usePermissions } from '@/composables/usePermissions';
import { toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editNotifications } from '@/routes/notification-preferences';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { NavItem } from '@/types';

const { can } = usePermissions();
const page = usePage();

/*
 * A person's own account first, then the team's. IA §5.1: a section somebody
 * lacks the permission for is **hidden**, never shown disabled — so an
 * ordinary Team Member sees the three personal items and a Team Owner sees
 * those plus every team section they hold the permission for. Deliberately
 * not a count: it went stale the first time a section was added.
 */
const sidebarNavItems = computed<NavItem[]>(() => {
    const items: NavItem[] = [
        { title: 'Profile', href: editProfile() },
        { title: 'Security', href: editSecurity() },
        { title: 'Appearance', href: editAppearance() },
        /*
         * S78 (#101). Beside the other personal settings and above the
         * team-wide ones, because how somebody is told about work is theirs —
         * every member has this row whatever permissions they hold.
         */
        { title: 'Notifications', href: editNotifications() },
    ];

    if (page.props.team && can('settings.manage')) {
        items.push({ title: 'Team', href: '/settings/team' });
    }

    if (page.props.team && can('team.members.manage')) {
        items.push({ title: 'Members', href: '/settings/members' });
    }

    if (page.props.team && can('settings.manage')) {
        items.push({ title: 'Deal types', href: '/settings/deal-types' });
    }

    /*
     * F5.9's rails (#96). Directly under Team rather than at the bottom: this
     * is the item somebody looks for in a hurry because a client has just
     * phoned, and a list is scanned from the top.
     */
    if (page.props.team && can('settings.manage')) {
        items.push({ title: 'Sending', href: '/settings/sending' });
    }

    if (page.props.team && can('team.export')) {
        items.push({ title: 'Export', href: '/settings/export' });
    }

    return items;
});

const { isCurrentOrParentUrl } = useCurrentUrl();
</script>

<template>
    <div class="px-4 py-6">
        <Heading
            title="Settings"
            description="Manage your profile and account settings"
        />

        <div class="flex flex-col lg:flex-row lg:space-x-12">
            <aside class="w-full max-w-xl lg:w-48">
                <nav
                    class="flex flex-col space-y-1 space-x-0"
                    aria-label="Settings"
                >
                    <Button
                        v-for="item in sidebarNavItems"
                        :key="toUrl(item.href)"
                        variant="ghost"
                        :class="[
                            'w-full justify-start',
                            { 'bg-muted': isCurrentOrParentUrl(item.href) },
                        ]"
                        as-child
                    >
                        <Link :href="item.href">
                            <component :is="item.icon" class="h-4 w-4" />
                            {{ item.title }}
                        </Link>
                    </Button>
                </nav>
            </aside>

            <Separator class="my-6 lg:hidden" />

            <div class="flex-1 md:max-w-2xl">
                <section class="max-w-xl space-y-12">
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>
