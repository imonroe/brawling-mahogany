<script setup lang="ts">
/**
 * S13 — the deals index (PRD §4.9 F9.1 · issue #78).
 *
 * ## Built from the kit
 *
 * Design System §7.3 specifies `DealRow` to the pixel and §8.8 specifies the
 * table around it. Both read the same `columns` array, which is what keeps the
 * header and the body aligned — #78 is explicit that misaligning them by even
 * 2px is visible, and one array is a stronger guarantee than two lists that
 * agree today.
 *
 * ## Every filter is in the URL
 *
 * A filtered view is a link somebody can send. Search debounces to the server
 * rather than filtering in the browser, because PRD §9 sizes a team at several
 * hundred closed deals and filtering them here would mean shipping them here.
 *
 * ## Two of the seven cells are honestly absent
 *
 * `owner` is hidden: `deals` has no owning-agent column, and Screen Inventory
 * already records the same departure for S15's header. #78's assignee filter
 * goes with it.
 *
 * The `date` cell is the soonest **open task** due date. `key_dates` is S18 in
 * Slice 4; a task due date is a real date somebody has to act by, which is the
 * nearest true answer available now.
 */
import { Head, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import AppButton from '@/components/app/AppButton.vue';
import AppSelect from '@/components/app/AppSelect.vue';
import { dealRowColumns } from '@/components/app/dealRow';
import DealRow from '@/components/app/DealRow.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SegmentedControl from '@/components/app/SegmentedControl.vue';
import Table from '@/components/app/Table.vue';
import AppInput from '@/components/ui/input/Input.vue';
import { formatCount } from '@/lib/formatters';
import type { Paginated } from '@/types/people';

type DealRowData = {
    id: string;
    name: string;
    url: string;
    client: string | null;
    stage: string | null;
    state: string;
    dealTypeName: string | null;
    nextDate: string | null;
};

const props = defineProps<{
    segment: string;
    segmentCounts: { value: string; label: string; count: number }[];
    search: string;
    dealType: string;
    dealTypeOptions: { value: string; label: string }[];
    sort: string;
    direction: string;
    deals: Paginated<DealRowData>;
}>();

/*
 * `owner` hidden, and the select cell absent because bulk select is not built.
 * #78 asks for bulk select and for it to be "hidden where it does not apply" —
 * with no bulk action to perform, everywhere is where it does not apply, so
 * the honest shape is no checkbox rather than a checkbox that selects into
 * nothing.
 */
const columns = dealRowColumns({ hide: ['owner'] });

const search = ref(props.search);

watch(
    () => props.search,
    (value) => {
        search.value = value;
    },
);

let debounce: ReturnType<typeof setTimeout> | undefined;

watch(search, (value) => {
    clearTimeout(debounce);

    debounce = setTimeout(() => {
        visit({ search: value || undefined });
    }, 250);
});

/**
 * One place that builds the query string, so a filter can never drop another.
 *
 * Every control passes only what it changes; everything else is read from the
 * props, which are what the server last resolved. Changing the segment while a
 * search is typed used to be how a screen quietly clears its own filters.
 */
function visit(changes: Record<string, string | undefined>): void {
    router.get(
        '/deals',
        {
            segment: props.segment === 'open' ? undefined : props.segment,
            search: props.search || undefined,
            dealType: props.dealType === 'all' ? undefined : props.dealType,
            sort: props.sort || undefined,
            direction: props.direction === 'asc' ? undefined : props.direction,
            ...changes,
        },
        {
            preserveState: true,
            replace: true,
            only: [
                'deals',
                'segmentCounts',
                'segment',
                'search',
                'dealType',
                'sort',
                'direction',
            ],
        },
    );
}

/**
 * A sortable header toggles direction on the column already sorted, and starts
 * ascending on one that is not.
 */
function sortBy(key: string): void {
    visit({
        sort: key,
        direction:
            props.sort === key && props.direction === 'asc' ? 'desc' : 'asc',
    });
}

/** `AppSelect` takes value → label, the shape every `Enum::options()` returns. */
const dealTypeChoices = computed<Record<string, string>>(() =>
    Object.fromEntries([
        ['all', 'All deal types'],
        ...props.dealTypeOptions.map((option) => [option.value, option.label]),
    ]),
);

const isFiltered = computed(
    () =>
        props.search.trim().length > 0 ||
        props.dealType !== 'all' ||
        props.segment !== 'open',
);

function clearFilters(): void {
    router.get('/deals', {}, { preserveState: true, replace: true });
}
</script>

<template>
    <Head title="Deals" />

    <div class="flex flex-col gap-4 p-4 md:p-6">
        <PageHeader
            title="Deals"
            :subtitle="formatCount(deals.total, 'deal', 'deals')"
        >
            <template #actions>
                <AppButton href="/deals/create">
                    <Plus class="size-4" aria-hidden="true" />
                    New deal
                </AppButton>
            </template>
        </PageHeader>

        <!-- §8.6: one h-8 row, search first, then the filters. -->
        <div class="flex flex-wrap items-center gap-2">
            <AppInput
                v-model="search"
                type="search"
                class="h-8 w-[260px]"
                placeholder="Search deals"
                aria-label="Search deals"
            />

            <SegmentedControl
                :model-value="segment"
                :segments="segmentCounts"
                @update:model-value="(value) => visit({ segment: value })"
            />

            <AppSelect
                v-if="dealTypeOptions.length > 0"
                :model-value="dealType"
                :options="dealTypeChoices"
                aria-label="Filter by deal type"
                @update:model-value="
                    (value) => visit({ dealType: value ?? 'all' })
                "
            />
        </div>

        <Table
            :columns="columns"
            caption="Deals"
            :sort="sort || null"
            :direction="direction === 'desc' ? 'desc' : 'asc'"
            @sort="sortBy"
            :footer-note="`Page ${deals.current_page} of ${deals.last_page}`"
            class="flex-1"
        >
            <DealRow
                v-for="deal in deals.data"
                :key="deal.id"
                :columns="columns"
                :primary="deal.name"
                :href="deal.url"
                :meta1="deal.client"
                :meta2="deal.stage"
                :state="deal.state"
                :date="deal.nextDate"
            />

            <template #empty>
                <EmptyState
                    v-if="deals.data.length === 0"
                    :variant="isFiltered ? 'filtered' : 'empty'"
                    :title="
                        isFiltered
                            ? 'No deals match those filters'
                            : 'No deals yet'
                    "
                    :description="
                        isFiltered
                            ? 'Try a different search, or clear the filters to see everything.'
                            : 'Create your first deal.'
                    "
                >
                    <template #action>
                        <AppButton
                            v-if="isFiltered"
                            variant="ghost"
                            @click="clearFilters"
                            >Clear filters</AppButton
                        >
                        <AppButton v-else href="/deals/create"
                            >Create your first deal</AppButton
                        >
                    </template>
                </EmptyState>
            </template>

            <template #footer>
                <AppButton
                    variant="ghost"
                    :disabled="!deals.prev_page_url"
                    @click="
                        deals.prev_page_url && router.get(deals.prev_page_url)
                    "
                    >Previous</AppButton
                >
                <AppButton
                    variant="ghost"
                    :disabled="!deals.next_page_url"
                    @click="
                        deals.next_page_url && router.get(deals.next_page_url)
                    "
                    >Next</AppButton
                >
            </template>
        </Table>
    </div>
</template>
