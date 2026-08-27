<script setup lang="ts">
/**
 * S08's full list — the same rows the shell's popover shows, unabridged.
 *
 * One screen rather than two components rendering the same thing differently:
 * the popover is a preview of this, both read `NotificationFeed`, and the
 * grouping rule lives on the server so the two cannot come to disagree about
 * what counts as one line.
 */
import { Head, Link, router } from '@inertiajs/vue3';
import { Check } from '@lucide/vue';
import { computed } from 'vue';
import AppButton from '@/components/app/AppButton.vue';
import Card from '@/components/app/Card.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
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

function markAllRead(): void {
    router.post('/notifications/read', {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Notifications" />

    <AppLayout
        :breadcrumbs="[{ title: 'Notifications', href: '/notifications' }]"
    >
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
                            group.unread > 0
                                ? 'bg-state-info'
                                : 'bg-transparent',
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
                </li>
            </ul>
        </Card>
    </AppLayout>
</template>
