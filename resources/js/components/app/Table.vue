<script setup lang="ts">
/**
 * Design System §8.8. Three parts sharing one set of column widths: the
 * column header, the rows, and the footer. No zebra striping — the row
 * border does the separating.
 *
 * Column widths come from the `columns` prop and are applied through a
 * `<colgroup>`, so the header and every row are laid out by the same values
 * rather than by two lists that can drift apart.
 */
import { cn } from '@/lib/utils';
import type { DealRowColumn } from './dealRow';

const props = defineProps<{
    columns: DealRowColumn[];
    /** Rendered in the footer: "25 active · 4 closed this quarter". */
    caption?: string | null;
    footerNote?: string | null;
    class?: string;
}>();
</script>

<template>
    <div
        :class="
            cn(
                'flex flex-col overflow-hidden rounded-lg border bg-card',
                props.class,
            )
        "
        data-slot="table-card"
    >
        <div class="flex-1 overflow-x-auto">
            <table class="w-full table-fixed border-collapse" data-slot="table">
                <caption v-if="caption" class="sr-only">
                    {{
                        caption
                    }}
                </caption>
                <colgroup>
                    <col
                        v-for="column in columns"
                        :key="column.key"
                        :style="
                            column.width
                                ? { width: `${column.width}px` }
                                : undefined
                        "
                    />
                </colgroup>
                <thead>
                    <tr class="h-8 border-b bg-muted">
                        <th
                            v-for="column in columns"
                            :key="column.key"
                            scope="col"
                            :class="
                                cn(
                                    'px-4 text-xs font-medium text-muted-foreground',
                                    column.align === 'center'
                                        ? 'text-center'
                                        : column.align === 'right'
                                          ? 'text-right'
                                          : 'text-left',
                                )
                            "
                        >
                            <span :class="column.label ? '' : 'sr-only'">{{
                                column.label || column.key
                            }}</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <slot />
                </tbody>
            </table>
        </div>
        <slot name="empty" />
        <footer
            v-if="$slots.footer || footerNote"
            class="flex h-11 items-center gap-2 border-t px-4"
        >
            <span
                v-if="footerNote"
                class="tabular text-xs text-muted-foreground"
                >{{ footerNote }}</span
            >
            <div class="flex-1"></div>
            <slot name="footer" />
        </footer>
    </div>
</template>
