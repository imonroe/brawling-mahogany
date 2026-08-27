<script setup lang="ts">
/**
 * The drop target for S51 (Design System §7.1 · issues #98, #99).
 *
 * ## A drop zone that is only a drop zone excludes people
 *
 * Dragging a file is a pointer gesture with no keyboard equivalent, so the
 * visible affordance is a **button** that opens the file picker, and the drop
 * handling is layered on top of it. That order matters: built the other way,
 * the keyboard path becomes a thing somebody remembers to add, and PRD §9
 * holds this product to WCAG 2.1 AA.
 *
 * ## It refuses nothing
 *
 * No `accept` filter, no client-side size check, no extension list. Not an
 * oversight — a rule enforced here would be a rule the server has to enforce
 * anyway, and two copies of an allowlist disagree the moment one is edited.
 * `DocumentStorage` decides from the **bytes**, which is the only check that
 * cannot be lied to; this component's job is to hand it the file and show
 * what came back.
 *
 * The size limit *is* shown, because telling somebody the ceiling before they
 * pick a 40MB scan is different from validating their choice after.
 */
import { Upload } from '@lucide/vue';
import { ref } from 'vue';
import { formatFileSize } from '@/lib/formatters';

const props = defineProps<{
    /** The ceiling the server enforces, shown so nobody discovers it late. */
    maxBytes: number;
    /** A file already chosen, so the zone can show it instead of the prompt. */
    selected?: File | null;
    disabled?: boolean;
}>();

const emit = defineEmits<{ (e: 'select', file: File): void }>();

const input = ref<HTMLInputElement | null>(null);
const dragging = ref(false);

function choose(): void {
    if (!props.disabled) {
        input.value?.click();
    }
}

function fromInput(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0];

    if (file) {
        emit('select', file);
    }
}

function onDrop(event: DragEvent): void {
    dragging.value = false;

    if (props.disabled) {
        return;
    }

    /*
     * The first file only. A multi-file drop into a form with one category
     * and one visibility would have to guess how those apply to the rest,
     * and guessing wrong on *visibility* publishes somebody's document.
     */
    const file = event.dataTransfer?.files?.[0];

    if (file) {
        emit('select', file);
    }
}
</script>

<template>
    <div
        :class="[
            'flex flex-col items-center justify-center gap-2 rounded-lg border border-dashed px-6 py-8 text-center transition-colors',
            dragging
                ? 'border-primary bg-primary/5'
                : 'border-border bg-muted/30',
            disabled ? 'opacity-60' : '',
        ]"
        @dragover.prevent="dragging = !disabled"
        @dragleave.prevent="dragging = false"
        @drop.prevent="onDrop"
    >
        <Upload class="size-5 text-muted-foreground" aria-hidden="true" />

        <template v-if="selected">
            <p class="text-sm font-medium">{{ selected.name }}</p>
            <p :class="['text-13', 'text-muted-foreground']">
                {{ formatFileSize(selected.size) }}
            </p>
        </template>

        <template v-else>
            <p class="text-sm">
                <button
                    type="button"
                    class="font-medium text-primary underline-offset-2 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :disabled="disabled"
                    @click="choose"
                >
                    Choose a file
                </button>
                <span class="text-muted-foreground"> or drop one here</span>
            </p>
            <p :class="['text-13', 'text-muted-foreground']">
                Up to {{ formatFileSize(maxBytes) }}
            </p>
        </template>

        <button
            v-if="selected"
            type="button"
            :class="[
                'text-13',
                'text-primary',
                'underline-offset-2',
                'hover:underline',
            ]"
            :disabled="disabled"
            @click="choose"
        >
            Choose a different file
        </button>

        <!--
            Visually hidden rather than `display: none`, and never the only
            control: the button above is what a keyboard reaches.
        -->
        <input
            ref="input"
            type="file"
            class="sr-only"
            :disabled="disabled"
            @change="fromInput"
        />
    </div>
</template>
