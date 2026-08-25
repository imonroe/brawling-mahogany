<script setup lang="ts">
/**
 * S30 — the people directory.
 *
 * One segmented screen, not four (IA §5.2): the segment is a query parameter,
 * which is how the vendor directory (S34) works too rather than being a
 * second screen that drifts.
 *
 * PRD §3.4 puts hundreds of past clients in a team, so this paginates and
 * never renders the whole directory. The search is debounced and goes to the
 * server, because filtering 500 rows in the browser means shipping 500 rows
 * to the browser.
 */
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus, Upload, Users } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import AppButton from '@/components/app/AppButton.vue';
import AppInput from '@/components/app/AppInput.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import PersonAvatar from '@/components/app/PersonAvatar.vue';
import PersonFormDialog from '@/components/app/PersonFormDialog.vue';
import SegmentedControl from '@/components/app/SegmentedControl.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { usePermissions } from '@/composables/usePermissions';
import { formatCount, formatPersonName } from '@/lib/formatters';
import type { PersonRow, Paginated } from '@/types';

const props = defineProps<{
    segment: string;
    segmentCounts: { value: string; label: string; count: number }[];
    emptyMessage: string;
    search: string;
    people: Paginated<PersonRow>;
    lifecycleStates: Record<string, string>;
}>();

const { can } = usePermissions();

const search = ref(props.search);
const creating = ref(false);

let debounce: ReturnType<typeof setTimeout> | undefined;

watch(search, (value) => {
    clearTimeout(debounce);

    debounce = setTimeout(() => {
        router.get(
            '/people',
            { segment: props.segment, search: value || undefined },
            { preserveState: true, replace: true, only: ['people', 'search'] },
        );
    }, 250);
});

function selectSegment(segment: string): void {
    router.get(
        '/people',
        { segment, search: search.value || undefined },
        { preserveState: true },
    );
}

const subtitle = computed(() => formatCount(props.people.total, 'person'));

const isFiltered = computed(() => search.value.trim().length > 0);
</script>

<template>
    <Head title="People" />

    <div class="flex flex-col gap-4 p-4 md:p-6">
        <PageHeader title="People" :subtitle="subtitle">
            <template #actions>
                <AppButton
                    v-if="can('people.import')"
                    variant="ghost"
                    href="/people/import"
                >
                    <Upload class="size-4" aria-hidden="true" />
                    Import
                </AppButton>
                <AppButton v-if="can('people.manage')" @click="creating = true">
                    <Plus class="size-4" aria-hidden="true" />
                    Add person
                </AppButton>
            </template>
        </PageHeader>

        <div class="flex flex-wrap items-center gap-2">
            <SegmentedControl
                :model-value="segment"
                :segments="segmentCounts"
                @update:model-value="selectSegment"
            />
            <AppInput
                v-model="search"
                size="filter"
                type="search"
                placeholder="Search by name, email, or phone"
                aria-label="Search people"
                class="w-full sm:w-72"
            />
        </div>

        <div class="flex flex-col overflow-hidden rounded-lg border bg-card">
            <EmptyState
                v-if="people.data.length === 0"
                :icon="Users"
                :variant="isFiltered ? 'filtered' : 'empty'"
                :title="isFiltered ? 'Nobody matches that' : 'Nothing here yet'"
                :description="
                    isFiltered
                        ? 'Try a shorter search, or clear it to see everybody in this segment.'
                        : emptyMessage
                "
            >
                <template #action>
                    <AppButton
                        v-if="isFiltered"
                        variant="ghost"
                        @click="search = ''"
                        >Clear search</AppButton
                    >
                    <AppButton
                        v-else-if="can('people.manage')"
                        @click="creating = true"
                        >Add person</AppButton
                    >
                </template>
            </EmptyState>

            <ul v-else class="flex flex-col">
                <li
                    v-for="person in people.data"
                    :key="person.id"
                    class="border-b last:border-b-0"
                >
                    <Link
                        :href="`/people/${person.id}`"
                        class="flex min-h-11 flex-wrap items-center gap-3 px-4 py-2.5 transition-colors duration-150 ease-out hover:bg-accent/60"
                    >
                        <PersonAvatar :person="person" :size="30" />
                        <span class="flex min-w-0 flex-1 flex-col">
                            <span
                                class="truncate text-13 font-medium text-foreground"
                                >{{ formatPersonName(person) }}</span
                            >
                            <span
                                class="truncate text-[11px] text-muted-foreground"
                                >{{
                                    person.email ??
                                    person.phone ??
                                    'No contact details'
                                }}</span
                            >
                        </span>
                        <StatusBadge
                            v-if="person.isVendor"
                            tone="neutral"
                            label="Vendor"
                            dotless
                        />
                        <!--
                            Three facts, each drawn when it is true (#162).

                            The first fix made these one badge with an
                            `v-else`, and the fallback branch drew a lifecycle
                            value nobody had chosen: a revoked colleague came
                            back as a green *Client*, which is the bug this
                            issue reported. `status` is nullable now, so a
                            person the lifecycle does not describe simply has
                            no lifecycle badge.

                            The roles hang on `carriesAccess`, so this row
                            says what `/settings/members` and the console say
                            about the same person, revoked or not.
                        -->
                        <template v-if="person.carriesAccess">
                            <StatusBadge
                                v-for="role in person.roles"
                                :key="role"
                                tone="neutral"
                                :label="role"
                                dotless
                            />
                        </template>
                        <StatusBadge
                            v-if="person.status && !person.isColleague"
                            domain="person"
                            :state="person.status"
                        />
                        <StatusBadge
                            v-if="person.isRevoked"
                            tone="danger"
                            label="Revoked"
                            dotless
                        />
                    </Link>
                </li>
            </ul>

            <nav
                v-if="people.last_page > 1"
                class="flex items-center justify-between gap-2 border-t px-4 py-2.5"
                aria-label="Pagination"
            >
                <p class="text-[11px] text-muted-foreground">
                    Page {{ people.current_page }} of {{ people.last_page }}
                </p>
                <div class="flex items-center gap-2">
                    <AppButton
                        variant="ghost"
                        :href="people.prev_page_url ?? undefined"
                        :disabled="!people.prev_page_url"
                        >Previous</AppButton
                    >
                    <AppButton
                        variant="ghost"
                        :href="people.next_page_url ?? undefined"
                        :disabled="!people.next_page_url"
                        >Next</AppButton
                    >
                </div>
            </nav>
        </div>
    </div>

    <PersonFormDialog
        v-model:open="creating"
        :lifecycle-states="lifecycleStates"
    />
</template>
