<script setup lang="ts">
/**
 * Design System §8.3. 56px: breadcrumb, spacer, search, then the shell's own
 * controls — Report a bug, Log contact, notifications, help.
 * The top bar carries no primary action — one primary button per screen, and
 * it belongs to the page header.
 */
import { Link } from '@inertiajs/vue3';
import {
    Bug,
    ChevronRight,
    CircleQuestionMark,
    MessageSquarePlus,
    PanelLeft,
    Search,
} from '@lucide/vue';
import AppButton from '@/components/app/AppButton.vue';
import IconButton from '@/components/app/IconButton.vue';
import NotificationsMenu from '@/components/app/NotificationsMenu.vue';
import { usePermissions } from '@/composables/usePermissions';
import { toUrl } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
        /** Whether the n8n bug-report form is configured (issue #176). */
        bugReport?: boolean;
    }>(),
    { breadcrumbs: () => [], bugReport: false },
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
    <!--
        `gap-1.5` below `md`, where four 44px controls and a breadcrumb compete
        for a 375px bar. The sidebar toggle and the search box are
        `display: none` there and so are not flex items at all, which leaves
        six children and five gaps: **up to** 30px back to the breadcrumb — five
        children and four gaps, so 24px, for somebody without `people.manage`
        and therefore without Log contact. Not the 42px a first count of seven
        gaps claimed. §8.3's `gap-3` is the desktop
        measurement and holds from `md`, the same way `px-6` does.
    -->
    <header
        class="flex h-14 shrink-0 items-center gap-1.5 border-b bg-background px-4 md:gap-3 md:px-6"
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
            at every width.

            Three widths, and the middle one is why `md:w-8` is here: below
            `md` it is §11's 44px square, from `md` to `lg` it is the same
            32×32 as the icon buttons beside it, and above `lg` it grows to
            fit its words. Without the middle step it is a 44px-wide button in
            a row of 32px ones at exactly the width where they sit together.
        -->
        <AppButton
            v-if="bugReport"
            variant="ghost"
            aria-label="Report a bug"
            title="Report a bug"
            class="w-11 px-0 md:w-8 lg:w-auto lg:px-2.5"
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

        <!--
            S08 (#101). The bell was a dead control until now — no href, no
            handler, and a prop nothing fed. `CLAUDE.md`'s *"a reader with no
            writer is as dead as a row nothing can reach"*, on the shell.
        -->
        <NotificationsMenu />
        <!--
            S92 (#170). An anchor rather than a button, so it can be
            middle-clicked into a tab — which is what people do to a help icon
            when they want to keep the thing they were reading.
        -->
        <IconButton :icon="CircleQuestionMark" label="Help" href="/help" />
    </header>
</template>
