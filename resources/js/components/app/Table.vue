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
import { ChevronDown, ChevronUp } from '@lucide/vue';
import { cn } from '@/lib/utils';
import type { DealRowColumn } from './dealRow';

const props = defineProps<{
    columns: DealRowColumn[];
    /** Rendered in the footer: "25 active · 4 closed this quarter". */
    caption?: string | null;
    footerNote?: string | null;
    /**
     * Whether the parent handles `@sort`.
     *
     * An explicit opt-in rather than sniffing `$attrs.onSort`, which is what
     * the first version did and which never worked: declaring `defineEmits`
     * for `sort` removes `onSort` from `$attrs`, so the check was permanently
     * false and every header rendered as plain text. The feature was invisible
     * and the whole suite was green, because the backend sort tests hit the
     * URL directly and never rendered a header.
     */
    sortable?: boolean;
    /** Which sortable column is sorted, and which way. */
    sort?: string | null;
    direction?: 'asc' | 'desc' | null;
    class?: string;
}>();

/*
 * §8.8: "sortable columns adding a 12px chevron-down". `dealRow.ts` has
 * marked two columns `sortable` since #33 and nothing rendered it, so a
 * column advertised itself as sortable and did nothing when pressed — which
 * is worse than not offering it.
 *
 * Two conditions, both required: the column says it can be sorted, and the
 * table says somebody is listening. A header that is a button on a screen
 * with no handler is an affordance that leads nowhere.
 */
const emit = defineEmits<{ sort: [key: string] }>();

function isSortable(column: DealRowColumn): boolean {
    return column.sortable === true && props.sortable === true;
}

/** What a screen reader is told about a sorted column. */
function ariaSort(
    column: DealRowColumn,
): 'ascending' | 'descending' | 'none' | undefined {
    if (!isSortable(column)) {
        return undefined;
    }

    if (props.sort !== column.key) {
        return 'none';
    }

    return props.direction === 'desc' ? 'descending' : 'ascending';
}
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
                            :aria-sort="ariaSort(column)"
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
                            <button
                                v-if="isSortable(column)"
                                type="button"
                                class="inline-flex items-center gap-1 hover:text-foreground"
                                :aria-label="`Sort by ${column.label}`"
                                @click="emit('sort', column.key)"
                            >
                                {{ column.label }}
                                <!--
                                    Point-up for ascending, which is the way
                                    round every table the reader has ever used
                                    does it. The unsorted state keeps the
                                    down-chevron §8.8 specifies, at 40% — an
                                    invitation rather than a claim.
                                -->
                                <component
                                    :is="
                                        sort === column.key &&
                                        direction === 'asc'
                                            ? ChevronUp
                                            : ChevronDown
                                    "
                                    class="size-3"
                                    :class="
                                        sort === column.key
                                            ? 'text-foreground'
                                            : 'opacity-40'
                                    "
                                    aria-hidden="true"
                                />
                            </button>
                            <span
                                v-else
                                :class="column.label ? '' : 'sr-only'"
                                >{{ column.label || column.key }}</span
                            >
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
