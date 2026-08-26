<script setup lang="ts">
/**
 * Design System §8.3. 56px: breadcrumb, spacer, search, then the shell's own
 * controls — Report a bug, Log contact, notifications, help.
 * The top bar carries no primary action — one primary button per screen, and
 * it belongs to the page header.
 */
import { Link } from '@inertiajs/vue3';
import {
    Bell,
    Bug,
    ChevronRight,
    CircleQuestionMark,
    MessageSquarePlus,
    PanelLeft,
    Search,
} from '@lucide/vue';
import AppButton from '@/components/app/AppButton.vue';
import IconButton from '@/components/app/IconButton.vue';
import { usePermissions } from '@/composables/usePermissions';
import { toUrl } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
        unreadNotifications?: boolean;
        /** Whether the n8n bug-report form is configured (issue #176). */
        bugReport?: boolean;
    }>(),
    { breadcrumbs: () => [], unreadNotifications: false, bugReport: false },
);

defineEmits<{
    'toggle-sidebar': [];
    'log-contact': [];
    search: [];
    'report-bug': [];
}>();

const { can } = usePermissions();
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
            <template
                v-for="(crumb, index) in breadcrumbs"
                :key="toUrl(crumb.href)"
            >
                <ChevronRight
                    v-if="index > 0"
                    class="size-[13px] shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
                <Link
                    :href="crumb.href"
                    :aria-current="
                        index === breadcrumbs.length - 1 ? 'page' : undefined
                    "
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
            class="hidden h-8 w-[300px] items-center gap-2 rounded-md border px-2.5 text-left transition-colors duration-150 ease-out hover:bg-accent/60 md:flex"
            aria-label="Search"
            @click="$emit('search')"
        >
            <Search class="size-3.5 text-muted-foreground" aria-hidden="true" />
            <span class="flex-1 text-13 text-muted-foreground">Search</span>
            <kbd
                class="rounded-sm bg-muted px-[5px] py-0.5 text-[11px] font-medium text-muted-foreground"
                >⌘K</kbd
            >
        </button>

        <!--
            Issue #176. The one labelled control in a bar of icons, and
            deliberately so: it is aimed at somebody who has just hit
            something broken and is not going to recognise a bug glyph. §8.3
            says the top bar carries no *primary* action — this is a ghost
            button, and the screen's primary action is still its own.

            Text from `lg` up, icon alone below it, where the bar is competing
            with a breadcrumb for room. `aria-label` rather than a second
            visually-hidden span, so the accessible name is the same sentence
            at every width. `w-11` restores §11's 44px target once the label
            is gone.
        -->
        <AppButton
            v-if="bugReport"
            variant="ghost"
            aria-label="Report a bug"
            title="Report a bug"
            class="w-11 px-0 lg:w-auto lg:px-2.5"
            @click="$emit('report-bug')"
        >
            <Bug aria-hidden="true" />
            <span class="hidden lg:inline">Report a bug</span>
        </AppButton>

        <!--
            S26's third entry point (issue #81). The other two already know
            who the contact was with; this one is here because the call
            Heather needs to log happens while she is looking at something
            else entirely.

            An icon button rather than a labelled one: §8.3 gives the top bar
            no primary action, because one primary button per screen belongs
            to the page header.
        -->
        <IconButton
            v-if="can('people.manage')"
            :icon="MessageSquarePlus"
            label="Log contact"
            @click="$emit('log-contact')"
        />

        <IconButton
            :icon="Bell"
            label="Notifications"
            :unread="unreadNotifications"
        />
        <!--
            S92 (#170). An anchor rather than a button, so it can be
            middle-clicked into a tab — which is what people do to a help icon
            when they want to keep the thing they were reading.
        -->
        <IconButton :icon="CircleQuestionMark" label="Help" href="/help" />
    </header>
</template>
