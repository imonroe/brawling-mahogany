<script setup lang="ts">
/**
 * The super-admin surface (IA §5.5). Visually distinct from the tenant app so
 * nobody ever confuses the two: inverted chrome, its own nav, and a marker
 * that is always on screen.
 */
import { Link } from '@inertiajs/vue3';
import { ShieldAlert } from '@lucide/vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { cn } from '@/lib/utils';

const sections = [
    { label: 'Teams', href: '/admin/teams' },
    { label: 'People', href: '/admin/people' },
    { label: 'System Health', href: '/admin/health' },
    { label: 'Audit Log', href: '/admin/audit' },
];

const { isCurrentOrParentUrl } = useCurrentUrl();
</script>

<template>
    <div class="flex min-h-svh flex-col bg-background">
        <header
            class="flex h-14 items-center gap-4 bg-foreground px-6 text-background"
            data-slot="admin-top-bar"
        >
            <span class="flex items-center gap-2 text-sm font-semibold">
                <ShieldAlert class="size-4" :stroke-width="2" aria-hidden="true" />
                Super admin
            </span>
            <nav class="flex items-center gap-1" aria-label="Admin">
                <Link
                    v-for="section in sections"
                    :key="section.href"
                    :href="section.href"
                    :aria-current="isCurrentOrParentUrl(section.href) ? 'page' : undefined"
                    :class="
                        cn(
                            'rounded-md px-2.5 py-1.5 text-sm font-medium',
                            isCurrentOrParentUrl(section.href)
                                ? 'bg-background/15 text-background'
                                : 'text-background/70 hover:text-background',
                        )
                    "
                    >{{ section.label }}</Link
                >
            </nav>
            <div class="flex-1"></div>
            <Link href="/dashboard" class="text-13 font-medium text-background/70 hover:text-background"
                >Back to the app</Link
            >
        </header>

        <main class="flex-1">
            <slot />
        </main>
    </div>
</template>
