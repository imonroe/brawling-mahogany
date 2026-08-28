<script setup lang="ts">
/**
 * S21 — a deal's documents (PRD §4.6 F6.1–F6.3, F6.7 · issues #98, #99, #100).
 *
 * ## The refusal is the feature, not the error path
 *
 * PRD §14.3 calls uploaded financial instruments the largest liability in this
 * product, and Slice 2 avoided the problem by never building a general upload:
 * #63 restricted the *context* — images only, against a property only —
 * because *"a photographed check is an image, exactly what a photo gallery
 * accepts."* This screen exists because the bytes are inspected now.
 *
 * So a refusal gets a dialog rather than a red line under a field, and the
 * dialog says three things: what was refused, why, and **where to put it
 * instead**. #99 is explicit that the third is what makes this *"acceptable
 * rather than infuriating"*, and a form error carrying one sentence would lose
 * the other two.
 *
 * ## Internal by default, and the default is deliberate
 *
 * The visibility picker starts at Internal on every open and nothing remembers
 * otherwise — the same rule F4.11's notes carry, for the reason #72 gives: an
 * agent who shared one document last Tuesday must not silently publish the
 * next one. The two mistakes do not cost the same.
 *
 * ## "Not scanned" is shown, and never as "clean"
 *
 * `ReadableText::from()` returns null rather than an empty string for an image
 * or a text-free PDF, so `scan_state` distinguishes *read and found nothing*
 * from *could not read*. The badge says which. A screen that drew "clean" over
 * a photograph of a cheque would be believed, and that is worse than saying
 * nothing.
 */
import { Head, router, useForm } from '@inertiajs/vue3';
import { FileText, TriangleAlert, Trash2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import AppButton from '@/components/app/AppButton.vue';
import AppInput from '@/components/app/AppInput.vue';
import AppSelect from '@/components/app/AppSelect.vue';
import Card from '@/components/app/Card.vue';
import type { DealHeaderProps } from '@/components/app/DealHeader.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import ExtractDocumentDialog from '@/components/app/ExtractDocumentDialog.vue';
import UploadZone from '@/components/app/UploadZone.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatDateShort, formatFileSize } from '@/lib/formatters';

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
    /**
     * The most recent attempt at reading this document, or null (#115).
     *
     * The row offers **Review** rather than **Extract** once there is something
     * to look at, and neither while one is running — a second press on a queued
     * document would spend the money twice and produce a review screen with
     * every proposal on it twice.
     */
    extraction: {
        id: string;
        state: 'queued' | 'processing' | 'complete' | 'failed' | 'blocked';
        kind: 'contract' | 'inspection';
        url: string;
        pending: number;
    } | null;
};

type Refusal = {
    category: string;
    reason: string;
    alternative: string;
};

const props = defineProps<{
    dealHeader: DealHeaderProps;
    dealUrl: string;
    documents: DocumentRow[];
    categories: Record<string, string>;
    visibilities: Record<string, string>;
    maxBytes: number;
    refusal: Refusal | null;
    extract: {
        available: boolean;
        unavailableReason: string | null;
        spend: {
            used: string;
            cap: string;
            percent: number;
            warn: boolean;
            resetsAt: string;
        };
    };
    can: { upload: boolean; extract: boolean };
}>();

/*
 * S65 opens against one document, so the dialog needs to know which — and it
 * is one dialog rather than one per row, because a dialog per row is a mounted
 * component per document on a screen that can hold a hundred.
 */
const extracting = ref<DocumentRow | null>(null);

const uploadOpen = ref(false);
const file = ref<File | null>(null);

const form = useForm({
    document: null as File | null,
    category: 'other',
    // Internal, every time. See the header comment.
    visibility: 'internal',
    caption: '',
});

/*
 * The refusal dialog opens on the prop rather than on a submit callback,
 * because `store()` refuses by redirecting: by the time this page renders
 * again the request that was refused is over.
 */
const refusalOpen = ref(props.refusal !== null);

watch(
    () => props.refusal,
    (value) => {
        refusalOpen.value = value !== null;
    },
);

watch(uploadOpen, (open) => {
    if (!open) {
        form.reset();
        file.value = null;
    }
});

function select(chosen: File): void {
    file.value = chosen;
    form.document = chosen;
}

function submit(): void {
    form.post(`${props.dealUrl}/documents`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            uploadOpen.value = false;
        },
    });
}

function remove(row: DocumentRow): void {
    router.delete(`${props.dealUrl}/documents/${row.id}`, {
        preserveScroll: true,
    });
}

/*
 * The server's answer, not a second one. `can.upload` is
 * `$person->can('update', $deal)` — the same policy the route enforces — so
 * asking `usePermissions()` here as well would be a second rule that can
 * disagree with the one that actually decides.
 */
const canUpload = computed(() => props.can.upload);

/*
 * Two permissions, not one, and the split is deliberate (see
 * `ExtractionPolicy`): starting an extraction spends the team's money and
 * sends a document to a third party, so it is `deals.manage` — while
 * *confirming* what comes back is its own key, which the review screen checks.
 */
const canExtract = computed(() => props.can.extract);
</script>

<template>
    <Head :title="`Documents · ${dealHeader.name}`" />

    <div class="flex flex-col gap-4">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 class="text-base font-semibold">Documents</h2>
                <p :class="['text-13', 'text-muted-foreground']">
                    Inspection reports, disclosures and correspondence.
                    Contracts and anything with banking details on it belong in
                    your e-signature system.
                </p>
            </div>

            <AppButton
                v-if="canUpload"
                size="compact"
                @click="uploadOpen = true"
            >
                Add a document
            </AppButton>
        </div>

        <EmptyState
            v-if="documents.length === 0"
            title="No documents yet"
            :description="
                canUpload
                    ? 'Add an inspection report, a disclosure, or anything else this deal needs on file.'
                    : 'Nothing has been added to this deal yet.'
            "
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
                    <!--
                        A real link, not a button that calls the router. The
                        response is a file download, so the browser's own
                        handling is what should take it — and a link is what a
                        keyboard, a screen reader and "open in new tab" all
                        already understand.
                    -->
                    <a
                        :href="`${dealUrl}/documents/${row.id}`"
                        class="block truncate text-sm font-medium underline-offset-2 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        {{ row.name }}
                    </a>
                    <p
                        v-if="row.caption"
                        :class="['text-13', 'text-muted-foreground']"
                    >
                        {{ row.caption }}
                    </p>
                    <p :class="['text-13', 'text-muted-foreground']">
                        {{ row.categoryLabel }} ·
                        {{ formatFileSize(row.sizeBytes) }}
                        <template v-if="row.uploadedAt">
                            · {{ formatDateShort(row.uploadedAt) }}
                        </template>
                        <template v-if="row.uploadedBy">
                            · {{ row.uploadedBy }}
                        </template>
                    </p>
                </div>

                <span
                    v-if="row.scanState !== 'clean'"
                    :class="[
                        'shrink-0',
                        'rounded-full',
                        'bg-muted',
                        'px-2',
                        'py-[3px]',
                        'text-11',
                        'text-muted-foreground',
                    ]"
                    title="There was no readable text in this file to check."
                >
                    Not scanned
                </span>

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

                <!--
                    S65's entry point (#115).

                    Three shapes, and which one shows is decided by the row's
                    own most recent attempt rather than by a global flag: a
                    document with something to review links to it, a document
                    being read says so and offers nothing, and a document with
                    no attempt offers Extract.

                    IA §7: the verb is **Extract**. Never Scan, Parse or
                    Analyze, and never AI.
                -->
                <a
                    v-if="row.extraction && row.extraction.state === 'complete'"
                    :href="row.extraction.url"
                    :class="[
                        'shrink-0',
                        'rounded-md',
                        'px-2',
                        'py-[3px]',
                        'text-11',
                        'font-medium',
                        row.extraction.pending > 0
                            ? 'text-state-warning-fg bg-state-warning-bg'
                            : 'bg-muted text-muted-foreground',
                    ]"
                >
                    {{
                        row.extraction.pending > 0
                            ? `Review ${row.extraction.pending}`
                            : 'Reviewed'
                    }}
                </a>

                <span
                    v-else-if="
                        row.extraction &&
                        (row.extraction.state === 'queued' ||
                            row.extraction.state === 'processing')
                    "
                    :class="[
                        'shrink-0',
                        'rounded-full',
                        'bg-muted',
                        'px-2',
                        'py-[3px]',
                        'text-11',
                        'text-muted-foreground',
                    ]"
                >
                    Reading…
                </span>

                <a
                    v-else-if="row.extraction"
                    :href="row.extraction.url"
                    :class="[
                        'shrink-0',
                        'rounded-md',
                        'px-2',
                        'py-[3px]',
                        'text-11',
                        'font-medium',
                        'bg-state-danger-bg',
                        'text-state-danger-fg',
                    ]"
                >
                    {{
                        row.extraction.state === 'blocked'
                            ? 'Stopped'
                            : 'Failed'
                    }}
                </a>

                <button
                    v-else-if="canExtract && row.scanState === 'clean'"
                    type="button"
                    :class="[
                        'shrink-0',
                        'rounded-md',
                        'px-2',
                        'py-[3px]',
                        'text-11',
                        'font-medium',
                        'text-primary',
                        'hover:bg-muted',
                        'focus-visible:ring-2',
                        'focus-visible:ring-ring',
                        'focus-visible:outline-none',
                    ]"
                    @click="extracting = row"
                >
                    Extract
                </button>

                <button
                    v-if="canUpload"
                    type="button"
                    class="shrink-0 rounded-md p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :aria-label="`Remove ${row.name}`"
                    @click="remove(row)"
                >
                    <Trash2 class="size-4" aria-hidden="true" />
                </button>
            </div>
        </Card>
    </div>

    <!-- S51 — the upload dialog -->
    <Dialog v-model:open="uploadOpen">
        <DialogContent class="sm:max-w-lg">
            <DialogTitle>Add a document</DialogTitle>
            <DialogDescription>
                Inspection reports, disclosures, correspondence, photographs.
            </DialogDescription>

            <!--
                Screen Inventory calls this a **compliance control**, not copy:
                *"S51 and S53 carry legal weight, not just UX… neither can be
                quietly softened later for being annoying."* So it is a panel
                with a tone, above the control it governs, rather than a line
                of description somebody's eye slides past on the way to the
                button.

                It names the four refused categories rather than saying
                "sensitive documents", because the whole failure mode is
                somebody believing their file is the exception.

                Four, not five: the executed contract left this list in #209.
                Every case that remains is a **financial or identity**
                document, which is a property of the bytes. A signed contract
                is neither — it was refused on a different argument, that this
                product is not its system of record, and F10.1 exists to read
                exactly that document.
            -->
            <div
                class="text-state-warning-fg flex gap-2 rounded-md bg-state-warning-bg p-3"
            >
                <TriangleAlert
                    class="mt-0.5 size-4 shrink-0"
                    aria-hidden="true"
                />
                <div :class="['text-13']">
                    <p class="font-medium">
                        Do not upload financial or identity documents.
                    </p>
                    <p class="mt-1">
                        Earnest money instruments, lending packets, bank
                        statements and government IDs are refused — this product
                        is not the system of record for them. Files are checked
                        before they are stored.
                    </p>
                </div>
            </div>

            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <UploadZone
                    :max-bytes="maxBytes"
                    :selected="file"
                    :disabled="form.processing"
                    @select="select"
                />

                <p
                    v-if="form.errors.document"
                    :class="['text-13', 'text-state-danger']"
                >
                    {{ form.errors.document }}
                </p>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="flex flex-col gap-1.5">
                        <label for="category" class="text-sm font-medium"
                            >Category</label
                        >
                        <AppSelect
                            id="category"
                            v-model="form.category"
                            :options="categories"
                        />
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label for="visibility" class="text-sm font-medium"
                            >Who can see it</label
                        >
                        <AppSelect
                            id="visibility"
                            v-model="form.visibility"
                            :options="visibilities"
                        />
                    </div>
                </div>

                <div class="flex flex-col gap-1.5">
                    <label for="caption" class="text-sm font-medium">
                        Caption
                        <span class="font-normal text-muted-foreground"
                            >(optional)</span
                        >
                    </label>
                    <AppInput id="caption" v-model="form.caption" />
                </div>

                <div class="flex justify-end gap-2">
                    <AppButton
                        type="button"
                        variant="ghost"
                        @click="uploadOpen = false"
                    >
                        Cancel
                    </AppButton>
                    <AppButton
                        type="submit"
                        :disabled="form.processing || file === null"
                    >
                        Add document
                    </AppButton>
                </div>
            </form>
        </DialogContent>
    </Dialog>

    <!-- S53 — the refusal, with somewhere to go -->
    <Dialog v-model:open="refusalOpen">
        <DialogContent v-if="refusal" class="sm:max-w-md">
            <DialogTitle>That file was not stored</DialogTitle>
            <DialogDescription>
                It looks like {{ refusal.category.toLowerCase() }}.
            </DialogDescription>

            <div class="flex flex-col gap-3 text-sm">
                <p>{{ refusal.reason }}</p>

                <div class="rounded-md bg-muted/50 p-3">
                    <p class="font-medium">What to do instead</p>
                    <p :class="['mt-1', 'text-13', 'text-muted-foreground']">
                        {{ refusal.alternative }}
                    </p>
                </div>
            </div>

            <div class="flex justify-end">
                <AppButton @click="refusalOpen = false">Understood</AppButton>
            </div>
        </DialogContent>
    </Dialog>

    <!--
            One dialog for the whole page rather than one per row — a hundred
            documents would otherwise be a hundred mounted dialogs. It is bound
            to whichever row was pressed.
        -->
    <ExtractDocumentDialog
        v-if="extracting"
        :open="extracting !== null"
        :document-id="extracting.id"
        :document-name="extracting.name"
        :deal-url="dealUrl"
        :available="extract.available"
        :unavailable-reason="extract.unavailableReason"
        :spend="extract.spend"
        @update:open="
            (open: boolean) => (extracting = open ? extracting : null)
        "
    />
</template>
