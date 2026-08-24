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
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import AppButton from '@/components/app/AppButton.vue';
import AppInput from '@/components/app/AppInput.vue';
import AppSelect from '@/components/app/AppSelect.vue';
import { dealRowColumns } from '@/components/app/dealRow';
import DealRow from '@/components/app/DealRow.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SegmentedControl from '@/components/app/SegmentedControl.vue';
import Table from '@/components/app/Table.vue';
import { formatCount } from '@/lib/formatters';
import type { Paginated } from '@/types';

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

let debounce: ReturnType<typeof setTimeout> | undefined;

/**
 * Whether the pending change to `search` was written by this page rather than
 * typed into the box.
 *
 * The debounce watcher cannot tell the two apart, and the difference is the
 * whole question: a keystroke should schedule a visit, and a value the page
 * assigns itself should not — it is either echoing what the server just
 * resolved, or part of a visit already going out.
 *
 * `clearFilters()` is where the absence of this showed. It cancels the
 * pending debounce and then clears the box, and clearing the box **re-armed
 * the timer the line above had just cancelled**. So the clear navigated to
 * `segment=all`, and 250ms later a second visit went out reading the props
 * the server had not answered yet — `/deals` bare, which *is* `segment=open`.
 * The button widened the list and then put it straight back, which is the
 * exact defect the widening was added to fix, arriving by the fix for it.
 */
let assigned = false;

function setSearch(value: string): void {
    /*
     * A no-op assignment fires no watcher, so arming the flag for one would
     * leave it armed and swallow the reader's next real keystroke instead.
     */
    if (search.value === value) {
        return;
    }

    assigned = true;
    search.value = value;
}

// The server's answer, echoed into the box. Assigned, so it does not ask again.
watch(
    () => props.search,
    (value) => {
        setSearch(value);
    },
);

watch(search, (value) => {
    clearTimeout(debounce);

    if (assigned) {
        assigned = false;

        return;
    }

    debounce = setTimeout(() => {
        visit({ search: value || undefined });
    }, 250);
});

/*
 * The timer does not outlive the page.
 *
 * Typing and then clicking straight through to a deal used to yank the reader
 * back to `/deals` 250ms later, because the pending visit fired after the
 * component was gone.
 */
onBeforeUnmount(() => {
    clearTimeout(debounce);
});

/**
 * One place that builds the query string, so that a filter dropping another is
 * one bug rather than six.
 *
 * Every control passes only what it changes; everything else is read from the
 * props, which are what the server last resolved.
 *
 * **`search` is the only axis that survives a race, and the other three are a
 * known gap — issue to follow.** `props` are stale for the whole in-flight
 * round trip, so two controls used inside one are read through the *first*
 * one's un-updated props: click **All** and then a sort header before the
 * response lands, and the second visit goes out as
 * `{sort: 'primary', direction: 'asc'}` with no segment at all, putting the
 * reader back on open deals without saying so. Same for the deal-type select.
 * `search` escapes it only because it has a local ref and this function reads
 * that ref rather than the prop.
 *
 * The fix is the same shape for all four — the page has to remember what it
 * last asked for until the server answers — and it is deliberately **not**
 * being made here: this screen has had four consecutive rounds in which the
 * round's fix opened the hole it closed, and restructuring the navigation
 * path is not something to land unreviewed. It is recoverable in one click
 * and the UI comes back truthful, which is why it is a gap and not a stop.
 *
 * **Cancelling the pending search is part of that guarantee, not tidiness.**
 * The debounce closure reads `props.search` and `props.segment` — the values
 * the *server* last resolved — so a search typed at t=0 and a segment clicked
 * at t=100ms would fire at t=250ms still reading the old segment, because the
 * segment's own response had not landed. It would then navigate back to the
 * segment the reader had just left. Without this line the sentence above is
 * a claim the code does not make good on.
 */
function visit(changes: Record<string, string | undefined>): void {
    clearTimeout(debounce);

    router.get(
        '/deals',
        {
            segment: props.segment === 'open' ? undefined : props.segment,
            /*
             * The **ref**, not the prop.
             *
             * `props.search` is what the server last resolved, and during a
             * race it is stale: type `smith`, click a segment 100ms later, and
             * cancelling the pending debounce meant the segment's own visit
             * went out reading `''`. The search was not carried, it was thrown
             * away — and because `props.search` came back empty, the watcher
             * below never fired, so the box went on showing `smith` over a
             * list that was not filtered by it.
             *
             * The window is wider than the debounce: `props.search` stays
             * stale for the whole in-flight round trip.
             */
            search: search.value.trim() || undefined,
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

/**
 * Whether this team has any deals at all, as opposed to none *here*.
 *
 * The default segment is `open`, so a team whose deals are all closed lands on
 * an empty list — and calling that "No deals yet. Create your first deal." is
 * telling somebody with eight hundred deals that they have none. The `all`
 * count is already on the page and knows the difference.
 */
const hasAnyDeals = computed(
    () =>
        (props.segmentCounts.find((segment) => segment.value === 'all')
            ?.count ?? 0) > 0,
);

/**
 * Whether anything is narrowing the list — including the default segment,
 * once we know deals exist outside it.
 */
const isFiltered = computed(
    () =>
        props.search.trim().length > 0 ||
        props.dealType !== 'all' ||
        props.segment !== 'open' ||
        hasAnyDeals.value,
);

/**
 * "See everything", which is what the sentence beside this button promises.
 *
 * `/deals` with no query string **is** `segment=open`, so clearing to it left
 * a closed-only team on the identical empty screen — the right sentence
 * attached to a button that did nothing. `segment=all` is the only clearing
 * that shows a team all of its deals.
 */
function clearFilters(): void {
    /*
     * The pending search goes too. `visit()` says why it cancels the debounce,
     * and this is the second thing that builds a query string — a rule written
     * into one of two callers is the defect this screen keeps producing.
     * Without it the clear undoes itself 250ms later.
     *
     * `setSearch()` rather than a bare assignment, because a bare one re-armed
     * the timer the line above cancels. See the flag it reads.
     */
    clearTimeout(debounce);
    setSearch('');

    /*
     * Widen the segment only when the segment is the **only** thing filtering.
     *
     * `/deals` with no query string *is* `segment=open`, so clearing to it
     * left a closed-only team on the identical empty screen — that is what
     * `segment: 'all'` is for.
     *
     * But a team with open deals whose search happens to match none of them
     * also lands here, and they want their search dropped, not closed deals
     * they never asked for. So the test is whether anything *else* is
     * narrowing the list: if a search or a deal type is set, clearing those is
     * enough, and the segment stays where the reader left it.
     *
     * An earlier version tested `deals.data.length === 0` instead, which is
     * always true wherever this button renders — the button only exists inside
     * the empty state. It read like a narrowing and was a no-op.
     */
    const widen =
        hasAnyDeals.value &&
        props.segment === 'open' &&
        props.search.trim() === '' &&
        props.dealType === 'all';

    router.get('/deals', widen ? { segment: 'all' } : {}, {
        preserveState: true,
        replace: true,
    });
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
            <!--
                The house component at the filter size, not the raw shadcn
                `Input` with an `h-8` class on it. §4.2 puts the filter size at
                32px on a pointer device and §11 floors a touch target at 44 —
                `AppInput` carries both, and a hand-set height carries neither.
                It is also what §4.3's row-count arithmetic assumes.
            -->
            <AppInput
                v-model="search"
                size="filter"
                type="search"
                class="w-[260px]"
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
            sortable
            :sort="sort || null"
            :direction="direction === 'desc' ? 'desc' : 'asc'"
            @sort="sortBy"
            :footer-note="
                deals.total > 0
                    ? `Page ${deals.current_page} of ${deals.last_page}`
                    : null
            "
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
