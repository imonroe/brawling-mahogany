<script setup lang="ts">
/**
 * S33 — contact import.
 *
 * F2.8: *"Nobody retypes a client list."* The screen has one job beyond
 * uploading a file, and it is the one the inventory warns about: showing what
 * will merge and what will be created, and letting somebody change it,
 * **before anything is written**.
 *
 * Partial failure is a first-class state, not an error page. Row 340 being
 * malformed imports the other 339 and says which one was the problem.
 */
import { Head, router, useForm } from '@inertiajs/vue3';
import { Upload } from '@lucide/vue';
import { computed, ref } from 'vue';
import AppButton from '@/components/app/AppButton.vue';
import AppSelect from '@/components/app/AppSelect.vue';
import Card from '@/components/app/Card.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { Label } from '@/components/ui/label';
import { formatCount, formatPersonName } from '@/lib/formatters';

type ImportRow = {
    row: number;
    first_name: string;
    last_name: string | null;
    email: string | null;
    phone: string | null;
    action: 'create' | 'merge' | 'skip';
};

type ImportSummary = {
    id: string;
    source: string;
    sourceLabel: string;
    state: string;
    stateLabel: string;
    filename: string | null;
    summary: Record<string, number> | null;
    failureCount: number;
    createdAt: string | null;
    completedAt: string | null;
    columnMapping?: Record<string, string>;
    preview?: ImportRow[];
    failures?: { row: number; reason: string }[];
};

const props = defineProps<{
    sources: Record<string, string>;
    recent: ImportSummary[];
    import?: ImportSummary;
}>();

const upload = useForm<{ source: string; file: File | null }>({
    source: 'csv',
    file: null,
});

/**
 * What a person may do with one previewed row.
 *
 * Value → label, which is what `AppSelect` takes and what every
 * `Enum::options()` returns on the server, so the two shapes match.
 */
const ROW_ACTIONS: Record<ImportRow['action'], string> = {
    create: 'Add as new',
    merge: 'Already have them',
    skip: 'Skip',
};

// The parser's guesses, which the person may override before committing.
const actions = ref<Record<number, ImportRow['action']>>(
    Object.fromEntries(
        (props.import?.preview ?? []).map((row) => [row.row, row.action]),
    ),
);

const awaitingReview = computed(
    () => props.import?.state === 'awaiting_review',
);

const working = computed(() =>
    ['pending', 'parsing', 'importing'].includes(props.import?.state ?? ''),
);

const counts = computed(() => {
    const rows = props.import?.preview ?? [];

    return {
        create: rows.filter((row) => actions.value[row.row] === 'create')
            .length,
        merge: rows.filter((row) => actions.value[row.row] === 'merge').length,
        skip: rows.filter((row) => actions.value[row.row] === 'skip').length,
    };
});

function submitFile(): void {
    upload.post('/people/import', { forceFormData: true });
}

function commit(): void {
    router.post(`/people/import/${props.import?.id}`, {
        actions: actions.value,
    });
}

function refresh(): void {
    router.reload({ only: ['import'] });
}
</script>

<template>
    <Head title="Import contacts" />

    <div class="flex flex-col gap-4 p-4 md:p-6">
        <PageHeader
            title="Import contacts"
            subtitle="CSV, vCard, or a Google Contacts export"
        >
            <template #actions>
                <AppButton variant="ghost" href="/people"
                    >Back to people</AppButton
                >
            </template>
        </PageHeader>

        <Card v-if="!props.import" title="Choose a file">
            <form
                class="flex flex-col gap-4 px-4 py-4"
                @submit.prevent="submitFile"
            >
                <div class="flex flex-col gap-1.5">
                    <Label for="source">Where is it from</Label>
                    <select
                        id="source"
                        v-model="upload.source"
                        class="h-11 rounded-md border bg-background px-3 text-base md:h-10 md:text-sm"
                    >
                        <option
                            v-for="(label, value) in sources"
                            :key="value"
                            :value="value"
                        >
                            {{ label }}
                        </option>
                    </select>
                </div>

                <div class="flex flex-col gap-1.5">
                    <Label for="file">The file</Label>
                    <input
                        id="file"
                        type="file"
                        class="min-h-11 rounded-md border bg-background px-3 py-2 text-base md:text-sm"
                        accept=".csv,.txt,.vcf,.json"
                        @change="
                            (event) =>
                                (upload.file =
                                    (event.target as HTMLInputElement)
                                        .files?.[0] ?? null)
                        "
                    />
                    <p
                        v-if="upload.errors.file"
                        class="text-[11px] text-state-danger"
                    >
                        {{ upload.errors.file }}
                    </p>
                    <p class="text-[11px] text-muted-foreground">
                        Nothing is added to your directory until you’ve seen
                        what this would do.
                    </p>
                </div>

                <div class="flex justify-end">
                    <AppButton
                        type="submit"
                        :disabled="upload.processing || !upload.file"
                    >
                        <Upload class="size-4" aria-hidden="true" />
                        Read the file
                    </AppButton>
                </div>
            </form>
        </Card>

        <template v-else>
            <Card :title="props.import.filename ?? props.import.sourceLabel">
                <template #badge>
                    <StatusBadge
                        tone="neutral"
                        :label="props.import.stateLabel"
                        dotless
                    />
                </template>

                <div
                    v-if="working"
                    class="flex items-center justify-between gap-3 px-4 py-3"
                >
                    <p class="text-13 text-muted-foreground">
                        Reading your file. Large lists take a moment.
                    </p>
                    <AppButton variant="ghost" @click="refresh"
                        >Check again</AppButton
                    >
                </div>

                <div
                    v-else-if="props.import.state === 'completed'"
                    class="flex flex-col gap-1 px-4 py-3"
                >
                    <p class="text-13 text-foreground">
                        {{
                            formatCount(
                                props.import.summary?.created ?? 0,
                                'person',
                            )
                        }}
                        added,
                        {{ props.import.summary?.merged ?? 0 }} already in your
                        directory,
                        {{ props.import.summary?.skipped ?? 0 }} skipped.
                    </p>
                    <p
                        v-if="props.import.failureCount > 0"
                        class="text-13 text-muted-foreground"
                    >
                        {{ formatCount(props.import.failureCount, 'row') }}
                        couldn’t be read. They’re listed below — fix them in
                        your file and import just those.
                    </p>
                    <div class="pt-2">
                        <AppButton href="/people">See your people</AppButton>
                    </div>
                </div>

                <div
                    v-else-if="props.import.state === 'failed'"
                    class="px-4 py-3"
                >
                    <p class="text-13 text-foreground">
                        We couldn’t read that file. Check it opens in a
                        spreadsheet, then try again.
                    </p>
                </div>
            </Card>

            <Card v-if="awaitingReview" title="What this will do">
                <template #badge>
                    <StatusBadge
                        tone="neutral"
                        :label="`${counts.create} new · ${counts.merge} matched · ${counts.skip} skipped`"
                        dotless
                    />
                </template>

                <EmptyState
                    v-if="(props.import.preview ?? []).length === 0"
                    title="No contacts in that file"
                    description="Nothing in it looked like a person with a name. Check the columns and try again."
                />

                <ul v-else class="flex flex-col">
                    <li
                        v-for="row in props.import.preview"
                        :key="row.row"
                        class="flex min-h-11 flex-wrap items-center gap-3 border-b px-4 py-2.5 last:border-b-0"
                    >
                        <span
                            class="tabular w-10 text-[11px] text-muted-foreground"
                            >{{ row.row }}</span
                        >
                        <span class="flex min-w-0 flex-1 flex-col">
                            <span class="truncate text-13 font-medium">{{
                                formatPersonName({
                                    firstName: row.first_name,
                                    lastName: row.last_name,
                                })
                            }}</span>
                            <span
                                class="truncate text-[11px] text-muted-foreground"
                                >{{
                                    row.email ??
                                    row.phone ??
                                    'No contact details'
                                }}</span
                            >
                        </span>
                        <AppSelect
                            :model-value="actions[row.row]"
                            :options="ROW_ACTIONS"
                            class="w-auto"
                            :aria-label="`What to do with row ${row.row}`"
                            @update:model-value="
                                (value) =>
                                    (actions[row.row] =
                                        (value as ImportRow['action']) ??
                                        'create')
                            "
                        />
                    </li>
                </ul>

                <div class="flex justify-end gap-2 border-t px-4 py-3">
                    <AppButton variant="ghost" href="/people/import"
                        >Start over</AppButton
                    >
                    <AppButton @click="commit">Import these</AppButton>
                </div>
            </Card>

            <Card
                v-if="(props.import.failures ?? []).length > 0"
                title="Rows we couldn’t read"
            >
                <ul class="flex flex-col">
                    <li
                        v-for="failure in props.import.failures"
                        :key="failure.row"
                        class="flex gap-3 border-b px-4 py-2 text-13 last:border-b-0"
                    >
                        <span
                            class="tabular w-10 text-[11px] text-muted-foreground"
                            >{{ failure.row }}</span
                        >
                        <span class="flex-1">{{ failure.reason }}</span>
                    </li>
                </ul>
            </Card>
        </template>

        <Card v-if="recent.length > 0" title="Recent imports">
            <ul class="flex flex-col">
                <li
                    v-for="entry in recent"
                    :key="entry.id"
                    class="flex min-h-11 items-center gap-3 border-b px-4 py-2.5 last:border-b-0"
                >
                    <span class="min-w-0 flex-1 truncate text-13">{{
                        entry.filename ?? entry.sourceLabel
                    }}</span>
                    <StatusBadge
                        tone="neutral"
                        :label="entry.stateLabel"
                        dotless
                    />
                    <AppButton
                        variant="ghost"
                        :href="`/people/import/${entry.id}`"
                        >Open</AppButton
                    >
                </li>
            </ul>
        </Card>
    </div>
</template>
