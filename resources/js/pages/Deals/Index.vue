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

/** The five filters, as they travel in the query string. */
type Query = {
    segment?: string;
    search?: string;
    dealType?: string;
    sort?: string;
    direction?: string;
};

const search = ref(props.search);

let debounce: ReturnType<typeof setTimeout> | undefined;

/** Cancel the pending search, and *record* that none is pending. */
function cancelSearch(): void {
    clearTimeout(debounce);
    debounce = undefined;
}

/**
 * One filter set, in the single spelling everything else compares against.
 *
 * Defaults drop out, so `/deals` bare and `?segment=open&direction=asc` are
 * the same view *and the same object* — which is what lets a record of what
 * was asked be compared to what came back.
 *
 * **Both the props and an outgoing visit go through here, and that is the
 * point.** They did not: `resolved()` normalised while `visit()` spread its
 * caller's `changes` verbatim, so the first press of a sort header stored
 * `direction: 'asc'` against a `resolved()` that says `undefined`. Those never
 * compare equal, so `asked` was never released and the props could never take
 * over again — the release mechanism was dead on the commonest path on the
 * screen. Pressing **Open**, choosing **All deal types**, or leaving a
 * trailing space in the search box did the same thing.
 *
 * Two spellings of one value is the defect this screen keeps producing. There
 * is one spelling now, and one function that produces it.
 */
function canonical(query: Query): Query {
    return {
        segment: query.segment === 'open' ? undefined : query.segment,
        search: query.search?.trim() || undefined,
        dealType: query.dealType === 'all' ? undefined : query.dealType,
        sort: query.sort || undefined,
        direction: query.direction === 'asc' ? undefined : query.direction,
    };
}

/** What the server last resolved. */
function resolved(): Query {
    return canonical({
        segment: props.segment,
        search: props.search,
        dealType: props.dealType,
        sort: props.sort,
        direction: props.direction,
    });
}

const QUERY_KEYS = [
    'segment',
    'search',
    'dealType',
    'sort',
    'direction',
] as const;

function sameQuery(a: Query, b: Query): boolean {
    return QUERY_KEYS.every((key) => a[key] === b[key]);
}

/**
 * **What this page last asked the server for, until the server answers.**
 *
 * This is the fix for the whole family of races on this screen, and it exists
 * because `props` are not "the current filters" — they are *the filters of the
 * last response that landed*, and they stay stale for the entire in-flight
 * round trip.
 *
 * Every control passes only what it changes and inherits the rest, so during
 * that window the inheritance read the wrong thing. Click **All**, then press
 * a sort header before the response arrives: the sort's visit inherited
 * `props.segment`, still `open`, and went out as
 * `{sort: 'primary', direction: 'asc'}` with **no segment at all** — silently
 * putting the reader back on the deals they had just navigated away from. The
 * deal-type select did the same. `search` escaped it only because it happened
 * to have a local ref that `visit()` read instead.
 *
 * So `visit()` inherits from `asked` when there is one, and the fix is uniform
 * across all five filters rather than one exception that happens to work.
 *
 * **Cleared by agreement, not by arrival.** A response landing does not mean
 * *this* request was answered: with two visits in flight, the first one's
 * props would otherwise discard the second one's record and reintroduce the
 * bug one visit later. So `asked` is dropped only once `resolved()` matches
 * it — which is exactly the moment props stop being stale.
 */
let asked: Query | null = null;

watch(
    () => QUERY_KEYS.map((key) => props[key]),
    () => {
        if (asked !== null && sameQuery(resolved(), asked)) {
            asked = null;
        }
    },
);

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

watch(
    () => props.search,
    (value) => {
        /*
         * **The reader's box outranks the server's echo.**
         *
         * A pending debounce means they have typed since the request being
         * answered went out. Type `a`, let it fire, type `b` while it is in
         * flight: the answer for `a` arrived and overwrote the box back to
         * `a`, cancelling `ab`'s timer on the way — so the character was lost
         * and *no request was ever made for it*. The box silently undid a
         * keystroke, which is the one thing a search box must not do.
         *
         * With nothing pending there is nothing to lose, and echoing is right:
         * it is how a back button or a hand-edited URL reaches the box.
         */
        if (debounce !== undefined) {
            return;
        }

        setSearch(value);
    },
);

watch(search, (value) => {
    cancelSearch();

    if (assigned) {
        assigned = false;

        return;
    }

    debounce = setTimeout(() => {
        // No `debounce = undefined` here: `visit()` opens with `cancelSearch()`,
        // which does it. A second assignment read as though it were load-bearing
        // and was not — the one place this is cleared is the one function that
        // clears it.
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
    cancelSearch();
});

/**
 * One place that builds the query string, so a filter can never drop another.
 *
 * Every control passes only what it changes and inherits the rest — from
 * `asked` while a request is outstanding, and from the props once the server
 * has caught up. Inheriting from the props alone is what dropped a filter: see
 * `asked` above for the sequence.
 *
 * **Cancelling the pending search is part of that guarantee, not tidiness.**
 * A search typed at t=0 and a segment clicked at t=100ms would otherwise fire
 * at t=250ms as a second, redundant visit — and before `asked` existed it read
 * the old segment and navigated back to the one the reader had just left.
 */
function visit(changes: Query): void {
    cancelSearch();

    const query: Query = canonical({
        ...(asked ?? resolved()),
        /*
         * The **ref**, not either of them.
         *
         * The box can hold a search that has been typed and not yet sent —
         * that is what the debounce is — so neither the props nor `asked` know
         * about it. Type `smith`, click a segment 100ms later, and cancelling
         * the pending debounce meant the segment's own visit went out with no
         * search at all: it was not carried, it was thrown away, and the box
         * went on showing `smith` over a list not filtered by it.
         *
         * `changes` still wins, so the debounce's own visit sets it normally.
         */
        search: search.value.trim() || undefined,
        ...changes,
    });

    asked = query;

    router.get('/deals', query, {
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
    });
}

/**
 * A sortable header toggles direction on the column already sorted, and starts
 * ascending on one that is not.
 */
function sortBy(key: string): void {
    /*
     * Read through `asked` for the same reason `visit()` does. Pressing the
     * same header twice inside one round trip read `props.sort`, still the
     * *previous* column, so the second press restarted at ascending instead of
     * toggling — the arrow refusing to flip.
     */
    const current = asked ?? resolved();

    visit({
        sort: key,
        direction:
            current.sort === key && (current.direction ?? 'asc') === 'asc'
                ? 'desc'
                : 'asc',
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
    cancelSearch();
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
     * enough and the segment is not widened.
     *
     * **Not widened is not the same as preserved**, and an earlier version of
     * this comment claimed the latter. The other branch sends a bare `{}`,
     * which resets *every* filter including the segment — so a reader on
     * **All** with a search that matches nothing is returned to **Open**. That
     * is the button doing what it says (clear the filters, land on the default
     * view) rather than a bug, but it is not the segment staying where they
     * left it, and the two readings differ.
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

    /*
     * `canonical()` here changes nothing today — both literals are already in
     * that spelling — and it is kept so that *everything* writing `asked`
     * writes it the same way, with no exception to remember. A mutation
     * confirms it is currently unobservable; it is a rule, not a fix.
     */
    const query: Query = canonical(widen ? { segment: 'all' } : {});

    /*
     * Recorded like any other visit. This is the second thing on the page that
     * navigates, and `asked` is only true if **both** of them keep it — a
     * control pressed before this clear's response landed would otherwise
     * inherit the filters the clear had just dropped and put them back.
     */
    asked = query;

    router.get('/deals', query, {
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
