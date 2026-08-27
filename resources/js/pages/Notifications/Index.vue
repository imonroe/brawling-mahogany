<script setup lang="ts">
/**
 * S08's full list — the same rows the shell's popover shows, unabridged.
 *
 * One screen rather than two components rendering the same thing differently:
 * the popover is a preview of this, both read `NotificationFeed`, and the
 * grouping rule lives on the server so the two cannot come to disagree about
 * what counts as one line.
 *
 * ## No layout import
 *
 * `app.ts` wraps every page in `AppLayout` and keeps it **persistent**. A page
 * that imports it as well draws the shell twice — two sidebars, two bells —
 * and the inner copy is remounted on every visit while the outer one is not,
 * so the two disagree about what is open. Held by
 * `tests/js/persistentLayouts.test.ts`.
 */
import { Head, Link, router } from '@inertiajs/vue3';
import { Check, X } from '@lucide/vue';
import { computed } from 'vue';
import AppButton from '@/components/app/AppButton.vue';
import Card from '@/components/app/Card.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import { formatRelativeDate } from '@/lib/formatters';

type Group = {
    id: string;
    type: string;
    summary: string;
    dealId: string | null;
    dealName: string | null;
    teamId: string;
    teamName: string | null;
    url: string | null;
    occurredAt: string | null;
    count: number;
    unread: number;
    ids: string[];
};

const props = defineProps<{ groups: Group[] }>();

const showsTeams = computed(
    () => new Set(props.groups.map((group) => group.teamId)).size > 1,
);

const unread = computed(() =>
    props.groups.reduce((total, group) => total + group.unread, 0),
);

/**
 * Dismiss one line, meaning every row it folded.
 *
 * The list is the surface where somebody works through what they have been
 * told, so it needs a per-line action and not only *"mark all read"* — a
 * button that clears the lot is the one nobody presses while there is still
 * something in the list they have not dealt with.
 *
 * `async`, like the popover's, so it lands on Inertia's other request stream
 * and cannot interrupt a navigation the same click may have started.
 */
function markRead(group: Group): void {
    if (group.unread === 0) {
        return;
    }

    router.post(
        '/notifications/read',
        { notifications: group.ids },
        { async: true, preserveScroll: true },
    );
}

function markAllRead(): void {
    router.post(
        '/notifications/read',
        {},
        { async: true, preserveScroll: true },
    );
}
</script>

<template>
    <Head title="Notifications" />

    <PageHeader title="Notifications">
        <template #actions>
            <AppButton
                v-if="unread > 0"
                variant="secondary"
                :icon="Check"
                @click="markAllRead"
            >
                Mark all read
            </AppButton>
        </template>
    </PageHeader>

    <EmptyState
        v-if="groups.length === 0"
        title="Nothing yet"
        description="You will hear about tasks assigned to you, deadlines coming up, and anything on a deal that needs a look."
    />

    <Card v-else>
        <ul class="divide-y divide-border">
            <li
                v-for="group in groups"
                :key="group.id"
                class="flex items-start gap-3 px-4 py-3"
            >
                <span
                    :class="[
                        'mt-1.5',
                        'size-1.5',
                        'shrink-0',
                        'rounded-full',
                        group.unread > 0 ? 'bg-state-info' : 'bg-transparent',
                    ]"
                />
                <span class="min-w-0 flex-1">
                    <component
                        :is="group.url ? Link : 'span'"
                        :href="group.url ?? undefined"
                        :class="[
                            'block',
                            'text-13',
                            group.url ? 'hover:underline' : '',
                        ]"
                        @click="markRead(group)"
                    >
                        {{ group.summary }}
                    </component>
                    <span
                        :class="[
                            'block',
                            'text-[11px]',
                            'text-muted-foreground',
                        ]"
                    >
                        <template v-if="group.dealName">
                            {{ group.dealName }} ·
                        </template>
                        <template v-if="showsTeams && group.teamName">
                            {{ group.teamName }} ·
                        </template>
                        {{
                            group.occurredAt
                                ? formatRelativeDate(group.occurredAt)
                                : ''
                        }}
                    </span>
                </span>

                <button
                    v-if="group.unread > 0"
                    type="button"
                    class="shrink-0 rounded-md p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground"
                    :aria-label="'Mark read: ' + group.summary"
                    @click="markRead(group)"
                >
                    <X class="size-3.5" />
                </button>
            </li>
        </ul>
    </Card>
</template>
