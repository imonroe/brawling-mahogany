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
import MobileTabBar from '@/components/app/MobileTabBar.vue';
import TopBar from '@/components/app/TopBar.vue';
import { Toaster } from '@/components/ui/sonner';
import { setTeamTimeZone } from '@/lib/formatters';
import type { BreadcrumbItem } from '@/types';

const props = withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
        counts?: { myWork?: number | null };
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

        <div class="flex min-h-0 flex-1">
            <div class="hidden md:flex">
                <AppSidebar :collapsed="collapsed" :counts="props.counts" />
            </div>

            <div class="flex min-w-0 flex-1 flex-col">
                <TopBar
                    :breadcrumbs="breadcrumbs"
                    @toggle-sidebar="collapsed = !collapsed"
                />
                <main class="min-h-0 flex-1 overflow-y-auto">
                    <slot />
                </main>
            </div>
        </div>

        <MobileTabBar />
        <Toaster />
    </div>
</template>
