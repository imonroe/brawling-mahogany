<script setup lang="ts">
/**
 * S06 — the application shell. Design System §8.1.
 *
 * Sidebar 240px fixed, top bar 56px, content region filling the rest. Every
 * internal screen inherits this, so the density, the type scale, and the
 * mobile collapse are decided here and nowhere else.
 *
 * On a phone the sidebar is replaced by the bottom tab bar (IA §5.3).
 */
import { usePage } from '@inertiajs/vue3';
import { computed, ref, watch, watchEffect } from 'vue';
import AppSidebar from '@/components/app/AppSidebar.vue';
import ImpersonationBanner from '@/components/app/ImpersonationBanner.vue';
import LogContactDialog from '@/components/app/LogContactDialog.vue';
import MobileTabBar from '@/components/app/MobileTabBar.vue';
import PendingInvitationBanner from '@/components/app/PendingInvitationBanner.vue';
import SearchOverlay from '@/components/app/SearchOverlay.vue';
import TopBar from '@/components/app/TopBar.vue';
import { Toaster } from '@/components/ui/sonner';
import { setTeamTimeZone } from '@/lib/formatters';
import type { BreadcrumbItem, PendingInvitation } from '@/types';

const props = withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
        counts?: { myWork?: number | null; pendingMessages?: number | null };
    }>(),
    { breadcrumbs: () => [] },
);

const page = usePage();

/*
 * Dates are stored in UTC and displayed in the team's timezone (PRD §9).
 *
 * This has to run where a page exists: `usePage()` at module scope resolves
 * before `createInertiaApp` has mounted anything, so it would read nothing
 * and never run again. A watcher here also survives a team switch.
 */
watchEffect(() => {
    const timeZone = (page.props as { team?: { timezone?: string } }).team
        ?.timezone;

    if (timeZone) {
        setTeamTimeZone(timeZone);
    }
});

const impersonating = computed(() => page.props.auth?.impersonating ?? null);

/*
 * ADR 0003: no flow depends on email alone. Somebody already in a team never
 * sees S09's "no access" state, so the shell is the only place a second
 * invitation can reach them without one.
 *
 * Suppressed on `Teams/None` itself, which renders the same invitations as a
 * card with room to say what they are. That page resolves to this layout like
 * any other, so without the check the same invitation appears twice on the one
 * screen built to show it.
 */
const invitations = computed<PendingInvitation[]>(() =>
    page.component === 'Teams/None'
        ? []
        : ((page.props.invitations as PendingInvitation[] | undefined) ?? []),
);

/*
 * S26 is reachable from the global shell (issue #81), so the modal is mounted
 * here — once, beside the top bar that opens it, rather than on every page
 * that might want it.
 *
 * With no person preselected it asks who first, which is the one entry point
 * that has to. The other two hand it a person and keep the two-click target.
 */
const logging = ref(false);

/** S07's overlay (#82). Mounted by the shell, because ⌘K works anywhere. */
const searching = ref(false);

const collapsed = ref(page.props.sidebarOpen === false);

watch(collapsed, (value) => {
    // Unencrypted on purpose (see bootstrap/app.php) so the server can render
    // the first paint in the same state and avoid a visible reflow.
    document.cookie = `sidebar_state=${value ? 'false' : 'true'}; path=/; max-age=31536000; SameSite=Lax`;
});
</script>

<template>
    <div class="flex h-svh flex-col overflow-hidden bg-background">
        <ImpersonationBanner
            v-if="impersonating"
            :person-name="impersonating.name"
            :team-name="impersonating.teamName"
            :reason="impersonating.reason"
            :ends-at="impersonating.endsAt"
        />

        <PendingInvitationBanner
            v-if="invitations.length > 0"
            :invitations="invitations"
        />

        <div class="flex min-h-0 flex-1">
            <div class="hidden md:flex">
                <AppSidebar :collapsed="collapsed" :counts="props.counts" />
            </div>

            <div class="flex min-w-0 flex-1 flex-col">
                <TopBar
                    :breadcrumbs="breadcrumbs"
                    @toggle-sidebar="collapsed = !collapsed"
                    @log-contact="logging = true"
                    @search="searching = true"
                />
                <main class="min-h-0 flex-1 overflow-y-auto">
                    <slot />
                </main>
            </div>
        </div>

        <MobileTabBar />
        <LogContactDialog v-model:open="logging" />
        <SearchOverlay v-model:open="searching" />
        <Toaster />
    </div>
</template>
