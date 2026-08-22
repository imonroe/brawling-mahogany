<script setup lang="ts">
/**
 * S35 — the properties directory.
 *
 * Grid and list are the same rows in two layouts. The toggle is client-side
 * and remembered, because a view switch that hits the server feels broken —
 * the server sends one shape either way (`PropertyDirectory::row`).
 *
 * The status filter is a query parameter like the people directory's segment,
 * so a filtered view is a URL somebody can send to a colleague. Search goes
 * to the server on a debounce: PRD §3.4 puts every house a team ever listed
 * in here, and filtering 500 rows in the browser means shipping 500 rows to
 * the browser.
 */
import { Head, Link, router } from '@inertiajs/vue3';
import { Home, LayoutGrid, List, Plus } from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';
import AppButton from '@/components/app/AppButton.vue';
import AppInput from '@/components/app/AppInput.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import PropertyFormDialog from '@/components/app/PropertyFormDialog.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { usePermissions } from '@/composables/usePermissions';
import { formatAddress, formatCount, formatNumber } from '@/lib/formatters';
import type { Paginated, PropertyRow } from '@/types';

const props = defineProps<{
    status: string;
    statusCounts: { value: string; label: string; count: number }[];
    search: string;
    properties: Paginated<PropertyRow>;
    propertyTypes: Record<string, string>;
    propertyStatuses: Record<string, string>;
}>();

const { can } = usePermissions();

const search = ref(props.search);
const creating = ref(false);
const layout = ref<'grid' | 'list'>('grid');

const VIEW_KEY = 'goldieflow.properties.layout';

/*
 * Read after mount, not during setup. This component renders on the server
 * for the initial page, and `localStorage` does not exist there.
 */
onMounted(() => {
    const stored = window.localStorage.getItem(VIEW_KEY);

    if (stored === 'grid' || stored === 'list') {
        layout.value = stored;
    }
});

watch(layout, (value) => window.localStorage.setItem(VIEW_KEY, value));

let debounce: ReturnType<typeof setTimeout> | undefined;

watch(search, (value) => {
    clearTimeout(debounce);

    debounce = setTimeout(() => {
        router.get(
            '/properties',
            { status: props.status, search: value || undefined },
            {
                preserveState: true,
                replace: true,
                only: ['properties', 'statusCounts', 'search'],
            },
        );
    }, 250);
});

function selectStatus(status: string): void {
    router.get(
        '/properties',
        { status, search: search.value || undefined },
        { preserveState: true },
    );
}

const subtitle = computed(() =>
    formatCount(props.properties.total, 'property', 'properties'),
);
const isFiltered = computed(
    () => search.value.trim().length > 0 || props.status !== 'all',
);

/** "3 bd · 2 ba · 1,840 sqft", with whichever parts are known. */
function facts(property: PropertyRow): string {
    return [
        property.beds === null ? null : `${property.beds} bd`,
        property.baths === null ? null : `${Number(property.baths)} ba`,
        property.sqft === null ? null : `${formatNumber(property.sqft)} sqft`,
    ]
        .filter(Boolean)
        .join(' · ');
}
</script>

<template>
    <Head title="Properties" />

    <div class="flex flex-col gap-4 p-4 md:p-6">
        <PageHeader title="Properties" :subtitle="subtitle">
            <template #actions>
                <AppButton
                    v-if="can('properties.manage')"
                    @click="creating = true"
                >
                    <Plus class="size-4" aria-hidden="true" />
                    Add property
                </AppButton>
            </template>
        </PageHeader>

        <div class="flex flex-wrap items-center gap-2">
            <AppInput
                v-model="search"
                size="filter"
                type="search"
                placeholder="Search by address or parcel number"
                aria-label="Search properties"
                class="w-full sm:w-72"
            />
            <label class="sr-only" for="status-filter">Filter by status</label>
            <select
                id="status-filter"
                :value="status"
                class="h-8 rounded-md border bg-background px-2.5 text-xs"
                @change="
                    selectStatus(($event.target as HTMLSelectElement).value)
                "
            >
                <option
                    v-for="option in statusCounts"
                    :key="option.value"
                    :value="option.value"
                >
                    {{ option.label }} ({{ option.count }})
                </option>
            </select>

            <div class="flex-1"></div>

            <div class="flex overflow-hidden rounded-md border">
                <button
                    type="button"
                    class="flex size-8 items-center justify-center"
                    :class="
                        layout === 'grid'
                            ? 'bg-accent text-primary'
                            : 'text-muted-foreground'
                    "
                    :aria-pressed="layout === 'grid'"
                    aria-label="Grid view"
                    @click="layout = 'grid'"
                >
                    <LayoutGrid class="size-4" aria-hidden="true" />
                </button>
                <button
                    type="button"
                    class="flex size-8 items-center justify-center border-l"
                    :class="
                        layout === 'list'
                            ? 'bg-accent text-primary'
                            : 'text-muted-foreground'
                    "
                    :aria-pressed="layout === 'list'"
                    aria-label="List view"
                    @click="layout = 'list'"
                >
                    <List class="size-4" aria-hidden="true" />
                </button>
            </div>
        </div>

        <EmptyState
            v-if="properties.data.length === 0"
            :icon="Home"
            :variant="isFiltered ? 'filtered' : 'empty'"
            :title="isFiltered ? 'Nothing matches that' : 'No properties yet'"
            :description="
                isFiltered
                    ? 'Try a shorter search, or clear the filter to see everything.'
                    : 'Properties live here once you add one. A property can be on more than one deal, so add it once and link it as often as you need.'
            "
            class="rounded-lg border bg-card"
        >
            <template #action>
                <AppButton
                    v-if="isFiltered"
                    variant="ghost"
                    @click="
                        search = '';
                        selectStatus('all');
                    "
                    >Clear filters</AppButton
                >
                <AppButton
                    v-else-if="can('properties.manage')"
                    @click="creating = true"
                    >Add property</AppButton
                >
            </template>
        </EmptyState>

        <ul
            v-else-if="layout === 'grid'"
            class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3"
        >
            <li v-for="property in properties.data" :key="property.id">
                <Link
                    :href="`/properties/${property.id}`"
                    class="flex h-full flex-col gap-2 rounded-lg border bg-card p-4 transition-colors duration-150 ease-out hover:bg-accent/60"
                >
                    <div class="flex items-start justify-between gap-2">
                        <div class="flex min-w-0 flex-col">
                            <span
                                class="truncate text-13 font-semibold text-foreground"
                                >{{
                                    formatAddress(property.address).line1 ||
                                    property.name
                                }}</span
                            >
                            <span
                                class="truncate text-[11px] text-muted-foreground"
                                >{{
                                    formatAddress(property.address).line2 ||
                                    property.typeLabel
                                }}</span
                            >
                        </div>
                        <StatusBadge
                            domain="property"
                            :state="property.status"
                        />
                    </div>
                    <p
                        v-if="facts(property)"
                        class="text-[11px] text-muted-foreground"
                    >
                        {{ facts(property) }}
                    </p>
                    <div class="flex-1"></div>
                    <p class="text-[11px] text-muted-foreground">
                        {{ formatCount(property.dealCount, 'deal') }}
                    </p>
                </Link>
            </li>
        </ul>

        <div
            v-else
            class="flex flex-col overflow-hidden rounded-lg border bg-card"
        >
            <ul class="flex flex-col">
                <li
                    v-for="property in properties.data"
                    :key="property.id"
                    class="border-b last:border-b-0"
                >
                    <Link
                        :href="`/properties/${property.id}`"
                        class="flex min-h-11 items-center gap-3 px-4 py-2.5 transition-colors duration-150 ease-out hover:bg-accent/60"
                    >
                        <span class="flex min-w-0 flex-1 flex-col">
                            <span
                                class="truncate text-13 font-medium text-foreground"
                                >{{
                                    formatAddress(property.address).line1 ||
                                    property.name
                                }}</span
                            >
                            <span
                                class="truncate text-[11px] text-muted-foreground"
                                >{{
                                    [
                                        formatAddress(property.address).line2,
                                        facts(property),
                                    ]
                                        .filter(Boolean)
                                        .join(' · ') || property.typeLabel
                                }}</span
                            >
                        </span>
                        <span class="text-[11px] text-muted-foreground">{{
                            formatCount(property.dealCount, 'deal')
                        }}</span>
                        <StatusBadge
                            domain="property"
                            :state="property.status"
                        />
                    </Link>
                </li>
            </ul>
        </div>

        <nav
            v-if="properties.last_page > 1"
            class="flex items-center justify-between gap-2"
            aria-label="Pagination"
        >
            <p class="text-[11px] text-muted-foreground">
                Page {{ properties.current_page }} of
                {{ properties.last_page }}
            </p>
            <div class="flex items-center gap-2">
                <AppButton
                    variant="ghost"
                    :href="properties.prev_page_url ?? undefined"
                    :disabled="!properties.prev_page_url"
                    >Previous</AppButton
                >
                <AppButton
                    variant="ghost"
                    :href="properties.next_page_url ?? undefined"
                    :disabled="!properties.next_page_url"
                    >Next</AppButton
                >
            </div>
        </nav>
    </div>

    <PropertyFormDialog
        v-model:open="creating"
        :property-types="propertyTypes"
        :property-statuses="propertyStatuses"
    />
</template>
