<script setup lang="ts">
/**
 * The platform-wide audit log (IA §5.5).
 *
 * Read-only, and read-only by construction: there is no write path here, the
 * model refuses updates and deletes, and the table's triggers refuse them
 * again. What is shown is the fact of each action and who took it — the
 * before/after payloads are redacted at write time (PRD §9: no PII in logs),
 * so there is nothing here to leak by rendering.
 */
import { Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppButton from '@/components/app/AppButton.vue';
import AppSelect from '@/components/app/AppSelect.vue';
import Card from '@/components/app/Card.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import { formatDate, formatTime } from '@/lib/formatters';
import type { Paginated } from '@/types';

const props = defineProps<{
    action: string;
    actions: string[];
    entries: Paginated<{
        id: string;
        action: string;
        teamId: string | null;
        actorName: string | null;
        auditableType: string | null;
        auditableId: string | null;
        reason: string | null;
        createdAt: string;
    }>;
}>();

const action = ref(props.action);

/**
 * `AppSelect` takes value → label, the shape every `Enum::options()` returns.
 * An audit action key is its own label — `deal_type.archived` is what the
 * operator is looking for, and prettifying it would make it unsearchable.
 */
const actionOptions = computed(() =>
    Object.fromEntries(props.actions.map((value) => [value, value])),
);

watch(action, (value) => {
    router.get(
        '/admin/audit',
        { action: value || undefined },
        { preserveState: true, replace: true },
    );
});
</script>

<template>
    <Head title="Audit log" />

    <div class="flex flex-col gap-4 p-6">
        <PageHeader
            title="Audit log"
            :subtitle="`${entries.total} entries, newest first`"
        />

        <AppSelect
            :model-value="action || null"
            :options="actionOptions"
            placeholder="Every action"
            class="w-full sm:w-72"
            aria-label="Filter by action"
            @update:model-value="(value) => (action = value ?? '')"
        />

        <Card>
            <EmptyState
                v-if="entries.data.length === 0"
                title="Nothing recorded"
                :variant="action ? 'filtered' : 'empty'"
                description="Sign-ins, permission changes, gate overrides, and impersonation all land here."
            />
            <ul v-else class="flex flex-col">
                <li
                    v-for="entry in entries.data"
                    :key="entry.id"
                    class="flex flex-col gap-0.5 border-b px-4 py-2.5 last:border-b-0"
                >
                    <div class="flex flex-wrap items-baseline gap-2">
                        <span class="text-13 font-medium">{{
                            entry.action
                        }}</span>
                        <span class="tabular text-[11px] text-muted-foreground"
                            >{{ formatDate(entry.createdAt) }},
                            {{ formatTime(entry.createdAt) }}</span
                        >
                        <span
                            v-if="entry.actorName"
                            class="text-[11px] text-muted-foreground"
                            >by {{ entry.actorName }}</span
                        >
                    </div>
                    <p
                        v-if="entry.reason"
                        class="text-13 text-muted-foreground"
                    >
                        {{ entry.reason }}
                    </p>
                </li>
            </ul>

            <nav
                v-if="entries.last_page > 1"
                class="flex items-center justify-between gap-2 border-t px-4 py-2.5"
                aria-label="Pagination"
            >
                <p class="text-[11px] text-muted-foreground">
                    Page {{ entries.current_page }} of {{ entries.last_page }}
                </p>
                <div class="flex items-center gap-2">
                    <AppButton
                        variant="ghost"
                        :href="entries.prev_page_url ?? undefined"
                        :disabled="!entries.prev_page_url"
                        >Previous</AppButton
                    >
                    <AppButton
                        variant="ghost"
                        :href="entries.next_page_url ?? undefined"
                        :disabled="!entries.next_page_url"
                        >Next</AppButton
                    >
                </div>
            </nav>
        </Card>
    </div>
</template>
