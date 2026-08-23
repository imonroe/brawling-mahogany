<script setup lang="ts">
/**
 * S12 — the team activity feed (PRD §4.9 F9.4 · issue #81).
 *
 * IA §11 names what this shows **Activity**, never History, Log, Feed, or
 * Audit. The security log is `audit_log`, is append-only, has its own
 * permission and its own retention, and is read on S72 by somebody asking a
 * different question. The two must never converge on one screen.
 *
 * ## The three states the inventory names
 *
 * - **Empty** says what belongs here rather than "No results" (IA §10), and
 *   the copy is the category's own — a filtered-to-nothing Properties tab and
 *   a brand new team are not the same emptiness.
 * - **Filtered** keeps the way back visible: the empty state offers "Show
 *   everything" rather than leaving somebody to work out which chip did it.
 * - **Loading more** appends. `events` is an Inertia merge prop keyed on `id`,
 *   so the button issues a partial reload with the next cursor and the rows
 *   arrive on the end rather than replacing what is on screen. Changing the
 *   filter is an ordinary visit rather than a partial one, so the same prop
 *   resets — which is what changing a filter should do.
 */
import { Head, Link, router } from '@inertiajs/vue3';
import { Activity } from '@lucide/vue';
import { computed, ref } from 'vue';
import ActivityItem from '@/components/app/ActivityItem.vue';
import AppButton from '@/components/app/AppButton.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SegmentedControl from '@/components/app/SegmentedControl.vue';
import TextLink from '@/components/app/TextLink.vue';
import { activityDescriptor } from '@/lib/activity';
import { formatDateTime } from '@/lib/formatters';
import type { ActivityFeedRow } from '@/types';

const props = defineProps<{
    category: string;
    categories: Record<string, string>;
    emptyMessage: string;
    events: ActivityFeedRow[];
    nextCursor: string | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Activity', href: '/activity' }],
    },
});

const loadingMore = ref(false);

const segments = computed(() =>
    Object.entries(props.categories).map(([value, label]) => ({
        value,
        label,
    })),
);

/**
 * Decorated once per row rather than three times in the template — the icon,
 * the tone, and the timestamp are each a function call, and `v-for` would run
 * every one of them on every re-render of a list that only ever grows.
 */
const rows = computed(() =>
    props.events.map((event) => ({
        event,
        ...activityDescriptor(event),
        time: formatDateTime(event.occurredAt),
        meta: [event.actorName].filter((part): part is string => Boolean(part)),
    })),
);

const isFiltered = computed(() => props.category !== 'all');

function selectCategory(category: string): void {
    /*
     * A visit, not a partial reload — which is exactly what resets `events`.
     * Inertia merges a merge prop only on a partial request, so the ordinary
     * visit a filter change makes replaces the list rather than appending a
     * different filter's rows to the last one's.
     */
    router.get(
        '/activity',
        { category },
        { preserveState: true, preserveScroll: true },
    );
}

function loadMore(): void {
    if (props.nextCursor === null || loadingMore.value) {
        return;
    }

    loadingMore.value = true;

    router.reload({
        only: ['events', 'nextCursor'],
        data: { category: props.category, cursor: props.nextCursor },
        /*
         * The cursor stays out of the address bar. It is a position inside one
         * reading session, not a place — and a refresh of `?cursor=…` would
         * render the middle of the feed with no way back to the top.
         */
        preserveUrl: true,
        onFinish: () => {
            loadingMore.value = false;
        },
    });
}
</script>

<template>
    <Head title="Activity" />

    <div class="flex h-full flex-col gap-4 p-4 md:p-6">
        <PageHeader
            title="Activity"
            subtitle="What the team has been doing, newest first"
        />

        <!--
            Five segments do not fit across a 390px viewport, so the row
            scrolls rather than wrapping into a second line that pushes the
            feed down the screen.
        -->
        <div class="-mx-4 overflow-x-auto px-4 md:mx-0 md:px-0">
            <SegmentedControl
                :model-value="category"
                :segments="segments"
                class="w-max"
                @update:model-value="selectCategory"
            />
        </div>

        <div
            class="flex flex-1 flex-col overflow-hidden rounded-lg border bg-card"
        >
            <EmptyState
                v-if="events.length === 0"
                :icon="Activity"
                :variant="isFiltered ? 'filtered' : 'empty'"
                :title="isFiltered ? 'Nothing in this filter' : 'Nothing yet'"
                :description="emptyMessage"
            >
                <template v-if="isFiltered" #action>
                    <AppButton variant="ghost" @click="selectCategory('all')"
                        >Show everything</AppButton
                    >
                </template>
            </EmptyState>

            <ol v-else class="flex flex-col divide-y overflow-y-auto">
                <li v-for="row in rows" :key="row.event.id" class="px-4">
                    <ActivityItem
                        :icon="row.icon"
                        :tone="row.tone"
                        :text="row.event.summary"
                        :time="row.time"
                    >
                        <p
                            v-if="row.event.note"
                            class="mt-0.5 text-13 text-muted-foreground"
                        >
                            {{ row.event.note }}
                        </p>
                        <p
                            v-if="row.meta.length > 0 || row.event.subject"
                            class="mt-0.5 flex flex-wrap items-center gap-x-2 text-[11px] text-muted-foreground"
                        >
                            <Link
                                v-if="row.event.subject?.url"
                                :href="row.event.subject.url"
                                class="text-primary hover:underline"
                                >{{ row.event.subject.label }}</Link
                            >
                            <span v-else-if="row.event.subject">{{
                                row.event.subject.label
                            }}</span>
                            <!--
                                The deal is linked now that S15 (#75) exists.
                                This said it was not, and landing S15 and the
                                feed together falsified the comment without
                                touching the line beneath it.
                            -->
                            <TextLink
                                v-if="row.event.deal"
                                :href="row.event.deal.url"
                                >{{ row.event.deal.label }}</TextLink
                            >
                            <span v-for="part in row.meta" :key="part">{{
                                part
                            }}</span>
                        </p>
                    </ActivityItem>
                </li>
            </ol>

            <div
                v-if="nextCursor !== null"
                class="flex justify-center border-t px-4 py-3"
            >
                <AppButton
                    variant="ghost"
                    :disabled="loadingMore"
                    @click="loadMore"
                >
                    {{ loadingMore ? 'Loading…' : 'Load more' }}
                </AppButton>
            </div>
        </div>
    </div>
</template>
