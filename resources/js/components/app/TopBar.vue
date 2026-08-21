<script setup lang="ts">
/**
 * Design System §8.3. 56px: breadcrumb, spacer, search, notifications, help.
 * The top bar carries no primary action — one primary button per screen, and
 * it belongs to the page header.
 */
import { Link } from '@inertiajs/vue3';
import { Bell, ChevronRight, CircleQuestionMark, PanelLeft, Search } from '@lucide/vue';
import IconButton from '@/components/app/IconButton.vue';
import { toUrl } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
        unreadNotifications?: boolean;
    }>(),
    { breadcrumbs: () => [], unreadNotifications: false },
);

defineEmits<{ 'toggle-sidebar': [] }>();
</script>

<template>
    <header
        class="flex h-14 shrink-0 items-center gap-3 border-b bg-background px-4 md:px-6"
        data-slot="top-bar"
    >
        <IconButton
            :icon="PanelLeft"
            label="Toggle sidebar"
            class="hidden md:inline-flex"
            @click="$emit('toggle-sidebar')"
        />

        <nav aria-label="Breadcrumb" class="flex min-w-0 items-center gap-1.5">
            <template v-for="(crumb, index) in breadcrumbs" :key="toUrl(crumb.href)">
                <ChevronRight
                    v-if="index > 0"
                    class="size-3.5 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
                <Link
                    :href="crumb.href"
                    :aria-current="index === breadcrumbs.length - 1 ? 'page' : undefined"
                    :class="
                        index === breadcrumbs.length - 1 && index > 0
                            ? 'truncate text-sm font-medium text-muted-foreground'
                            : 'truncate text-sm font-semibold text-foreground'
                    "
                    >{{ crumb.title }}</Link
                >
            </template>
        </nav>

        <div class="flex-1"></div>

        <button
            type="button"
            class="hidden h-8 w-[300px] items-center gap-2 rounded-md border px-2.5 text-left md:flex"
            aria-label="Search"
        >
            <Search class="size-3.5 text-muted-foreground" aria-hidden="true" />
            <span class="flex-1 text-13 text-muted-foreground">Search</span>
            <kbd
                class="rounded-sm bg-muted px-[5px] py-0.5 text-[11px] font-medium text-muted-foreground"
                >⌘K</kbd
            >
        </button>

        <IconButton :icon="Bell" label="Notifications" :unread="unreadNotifications" />
        <IconButton :icon="CircleQuestionMark" label="Help" />
    </header>
</template>
