<script setup lang="ts">
/**
 * S50 — every document the team holds (PRD §4.6 F6.1 · issue #98).
 *
 * The deal tab (S21) answers *"what is on this deal"*. This answers *"where is
 * that disclosure"*, which is asked from a standing start with no deal in
 * mind — so it is a filtered list rather than a grouped one, and every row
 * carries a way back to whatever it hangs off.
 *
 * ## Storage used is reported, never enforced
 *
 * Screen Inventory lists it as a state, and it is deliberately a plain number.
 * There is no plan tier to exceed and no behaviour that changes at a
 * threshold, so a progress bar toward an invented limit would be a lie about
 * how the product works — and the kind of lie somebody later builds a billing
 * assumption on.
 *
 * ## "Not scanned" is not "clean"
 *
 * `ReadableText::from()` returns null rather than an empty string for an image
 * or a text-free PDF, so `scanState` distinguishes *read and found nothing*
 * from *could not read*. The list says which. A column that drew "clean" over
 * a photograph of a cheque would be believed, which is worse than saying
 * nothing.
 */
import { Head, router } from '@inertiajs/vue3';
import { FileText } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import AppInput from '@/components/app/AppInput.vue';
import AppSelect from '@/components/app/AppSelect.vue';
import Card from '@/components/app/Card.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import { formatCount, formatDateShort, formatFileSize } from '@/lib/formatters';

type DocumentRow = {
    id: string;
    name: string;
    caption: string | null;
    category: string;
    categoryLabel: string;
    visibility: string;
    sizeBytes: number;
    uploadedAt: string | null;
    uploadedBy: string | null;
    scanState: string | null;
    subjectLabel: string;
    subjectUrl: string | null;
};

const props = defineProps<{
    documents: DocumentRow[];
    total: number;
    filters: {
        category: string | null;
        visibility: string | null;
        deal: string | null;
        q: string | null;
    };
    categories: Record<string, string>;
    visibilities: Record<string, string>;
    deals: { id: string; label: string }[];
    storageUsed: number;
}>();

const search = ref(props.filters.q ?? '');

let debounce: ReturnType<typeof setTimeout> | undefined;

watch(search, (value) => {
    clearTimeout(debounce);

    debounce = setTimeout(() => {
        go({ q: value || undefined });
    }, 250);
});

/**
 * Every filter travels together, because dropping one on a change to another
 * is the bug where narrowing by category silently forgets which deal you were
 * looking at.
 */
function go(changed: Record<string, string | undefined>): void {
    router.get(
        '/documents',
        {
            category: props.filters.category ?? undefined,
            visibility: props.filters.visibility ?? undefined,
            deal: props.filters.deal ?? undefined,
            q: search.value || undefined,
            ...changed,
        },
        { preserveState: true, replace: true },
    );
}

// `AppSelect` takes value → label, and an empty value is "no filter".
const categoryOptions = computed(() => ({
    '': 'Every category',
    ...props.categories,
}));

const visibilityOptions = computed(() => ({
    '': 'Internal and client-visible',
    ...props.visibilities,
}));

const dealOptions = computed(() => ({
    '': 'Every deal',
    ...Object.fromEntries(props.deals.map((deal) => [deal.id, deal.label])),
}));

const subtitle = computed(
    () =>
        `${formatCount(props.total, 'document')} · ${formatFileSize(
            props.storageUsed,
        )} stored`,
);

const isFiltered = computed(
    () =>
        search.value.trim().length > 0 ||
        props.filters.category !== null ||
        props.filters.visibility !== null ||
        props.filters.deal !== null,
);
</script>

<template>
    <Head title="Documents" />

    <div class="flex flex-col gap-4 p-4 md:p-6">
        <PageHeader title="Documents" :subtitle="subtitle" />

        <div class="flex flex-wrap items-center gap-2">
            <AppInput
                v-model="search"
                size="filter"
                type="search"
                placeholder="Search by file name or caption"
                aria-label="Search documents"
                class="w-full sm:w-72"
            />

            <AppSelect
                :model-value="filters.category ?? ''"
                size="filter"
                :options="categoryOptions"
                aria-label="Filter by category"
                @update:model-value="
                    (value: string | null) =>
                        go({ category: value || undefined })
                "
            />

            <AppSelect
                :model-value="filters.deal ?? ''"
                size="filter"
                :options="dealOptions"
                aria-label="Filter by deal"
                @update:model-value="
                    (value: string | null) => go({ deal: value || undefined })
                "
            />

            <AppSelect
                :model-value="filters.visibility ?? ''"
                size="filter"
                :options="visibilityOptions"
                aria-label="Filter by who can see it"
                @update:model-value="
                    (value: string | null) =>
                        go({ visibility: value || undefined })
                "
            />
        </div>

        <EmptyState
            v-if="documents.length === 0 && !isFiltered"
            title="No documents yet"
            description="Documents added to a deal or a property show up here."
        />

        <EmptyState
            v-else-if="documents.length === 0"
            title="Nothing matches those filters"
            description="Try a different category, deal, or search term."
        />

        <Card v-else class="divide-y divide-border p-0">
            <div
                v-for="row in documents"
                :key="row.id"
                class="flex items-start gap-3 px-4 py-3"
            >
                <FileText
                    class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />

                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium">{{ row.name }}</p>
                    <p
                        v-if="row.caption"
                        :class="[
                            'truncate',
                            'text-13',
                            'text-muted-foreground',
                        ]"
                    >
                        {{ row.caption }}
                    </p>
                    <p :class="['text-13', 'text-muted-foreground']">
                        <a
                            v-if="row.subjectUrl"
                            :href="row.subjectUrl"
                            class="underline-offset-2 hover:underline"
                        >
                            {{ row.subjectLabel }}
                        </a>
                        <span v-else>{{ row.subjectLabel }}</span>
                        · {{ row.categoryLabel }} ·
                        {{ formatFileSize(row.sizeBytes) }}
                        <template v-if="row.uploadedAt">
                            · {{ formatDateShort(row.uploadedAt) }}
                        </template>
                    </p>
                </div>

                <span
                    v-if="row.visibility === 'client_visible'"
                    :class="[
                        'shrink-0',
                        'rounded-full',
                        'bg-state-info-bg',
                        'px-2',
                        'py-[3px]',
                        'text-11',
                        'text-state-info-fg',
                    ]"
                >
                    Client-visible
                </span>
            </div>
        </Card>
    </div>
</template>
