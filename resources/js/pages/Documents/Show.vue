<script setup lang="ts">
/**
 * S52 — one document (PRD §4.6 F6.4 · issue #98).
 *
 * ## The preview is decided by the stored type, and refuses to guess
 *
 * `mimeType` is derived from the bytes by `finfo` at upload, never from the
 * filename the browser sent, so it is true of the file. An image previews, a
 * PDF previews in an object frame, and **everything else says so plainly** —
 * Screen Inventory lists "unsupported type" as a state, and a blank box
 * somebody stares at is not one.
 *
 * The preview loads through the subject's own audited route, so a rendered
 * preview is an access with an entry behind it exactly like a download (PRD
 * §9). One path to the bytes, one place the authorization lives.
 *
 * ## Publishing is shown before it happens
 *
 * The visibility control is the only thing here that writes, and it is a
 * disclosure decision: it is the moment somebody outside the team can read a
 * seller's inspection report. So the client-visible option carries a line
 * saying who will be able to read it, in the same spirit as F4.11's note
 * toggle — the safe answer should be the one you get by not thinking about it,
 * and the unsafe one should be a sentence you had to read.
 */
import { Head, router } from '@inertiajs/vue3';
import { ArrowLeft, FileQuestion } from '@lucide/vue';
import { computed } from 'vue';
import AppButton from '@/components/app/AppButton.vue';
import AppSelect from '@/components/app/AppSelect.vue';
import Card from '@/components/app/Card.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import { formatDateShort, formatFileSize } from '@/lib/formatters';

const props = defineProps<{
    document: {
        id: string;
        name: string;
        caption: string | null;
        categoryLabel: string;
        visibility: string;
        sizeBytes: number;
        uploadedAt: string | null;
        uploadedBy: string | null;
        scanState: string | null;
        subjectLabel: string;
        subjectUrl: string | null;
        mimeType: string;
        missing: boolean;
    };
    downloadUrl: string | null;
    subjectUrl: string | null;
    visibilities: Record<string, string>;
    can: { update: boolean };
}>();

const isImage = computed(() => props.document.mimeType.startsWith('image/'));
const isPdf = computed(() => props.document.mimeType === 'application/pdf');
const canPreview = computed(
    () => !props.document.missing && (isImage.value || isPdf.value),
);

function setVisibility(visibility: string | null): void {
    if (!visibility) {
        return;
    }

    router.patch(
        `/documents/${props.document.id}/visibility`,
        { visibility },
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head :title="document.name" />

    <div class="flex flex-col gap-4 p-4 md:p-6">
        <PageHeader
            :title="document.name"
            :subtitle="`${document.categoryLabel} · ${formatFileSize(document.sizeBytes)}`"
        >
            <template #actions>
                <AppButton
                    v-if="downloadUrl"
                    variant="ghost"
                    :as="'a'"
                    :href="downloadUrl"
                >
                    Download
                </AppButton>
            </template>
        </PageHeader>

        <a
            v-if="subjectUrl"
            :href="subjectUrl"
            :class="[
                'inline-flex',
                'items-center',
                'gap-1.5',
                'text-13',
                'text-muted-foreground',
                'underline-offset-2',
                'hover:underline',
            ]"
        >
            <ArrowLeft class="size-3.5" aria-hidden="true" />
            {{ document.subjectLabel }}
        </a>

        <Card class="overflow-hidden p-0">
            <img
                v-if="canPreview && isImage && downloadUrl"
                :src="downloadUrl"
                :alt="document.caption ?? document.name"
                class="max-h-[70vh] w-full bg-muted object-contain"
            />

            <object
                v-else-if="canPreview && isPdf && downloadUrl"
                :data="downloadUrl"
                type="application/pdf"
                class="h-[70vh] w-full"
            >
                <!--
                    The fallback matters: a browser with no PDF plugin renders
                    an empty frame, and an empty frame reads as "the file is
                    broken" rather than "your browser will not show this".
                -->
                <p class="p-6 text-sm">
                    This browser will not display the PDF here.
                    <a
                        :href="downloadUrl"
                        class="text-primary underline-offset-2 hover:underline"
                        >Download it instead</a
                    >.
                </p>
            </object>

            <div
                v-else
                class="flex flex-col items-center gap-2 px-6 py-12 text-center"
            >
                <FileQuestion
                    class="size-6 text-muted-foreground"
                    aria-hidden="true"
                />
                <p class="text-sm font-medium">
                    {{
                        document.missing
                            ? 'This file is no longer on disk'
                            : 'This kind of file cannot be shown here'
                    }}
                </p>
                <p :class="['text-13', 'text-muted-foreground']">
                    {{
                        document.missing
                            ? 'The record is still here, but the file behind it has been removed.'
                            : 'Download it to open it in whatever you normally use.'
                    }}
                </p>
            </div>
        </Card>

        <Card class="flex flex-col gap-4">
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <p :class="['text-13', 'text-muted-foreground']">Added</p>
                    <p class="text-sm">
                        {{
                            document.uploadedAt
                                ? formatDateShort(document.uploadedAt)
                                : '—'
                        }}
                        <template v-if="document.uploadedBy">
                            by {{ document.uploadedBy }}
                        </template>
                    </p>
                </div>

                <div>
                    <p :class="['text-13', 'text-muted-foreground']">
                        Content check
                    </p>
                    <!--
                        "Not scanned" is not "clean". `ReadableText::from()`
                        returns null rather than '' for an image or a text-free
                        PDF, so these are different facts and the screen says
                        which — a badge reading "clean" over a photograph of a
                        cheque would be believed.
                    -->
                    <p class="text-sm">
                        {{
                            document.scanState === 'clean'
                                ? 'Text read, nothing refused'
                                : 'No readable text to check'
                        }}
                    </p>
                </div>
            </div>

            <div v-if="can.update" class="flex flex-col gap-1.5">
                <label for="visibility" class="text-sm font-medium"
                    >Who can see it</label
                >
                <AppSelect
                    id="visibility"
                    :model-value="document.visibility"
                    :options="visibilities"
                    class="sm:w-64"
                    @update:model-value="setVisibility"
                />
                <p
                    v-if="document.visibility === 'client_visible'"
                    :class="['text-13', 'text-state-warning-fg']"
                >
                    Anyone with the status page link for this deal can read
                    this.
                </p>
            </div>
        </Card>
    </div>
</template>
