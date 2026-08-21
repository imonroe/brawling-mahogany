<script setup lang="ts">
/**
 * Design System §7.3. A 36px row whose cells are generic by name, so the same
 * component serves the dashboard, the deals index, and anything else that
 * lists deals.
 */
import { Link } from '@inertiajs/vue3';
import type { InertiaLinkProps } from '@inertiajs/vue3';
import { cn } from '@/lib/utils';
import DateChip from './DateChip.vue';
import PersonAvatar from './PersonAvatar.vue';
import StatusBadge from './StatusBadge.vue';
import type { DealRowColumn } from './dealRow';
import type { NameParts } from '@/lib/formatters';

type Props = {
    columns: DealRowColumn[];
    primary: string;
    href?: NonNullable<InertiaLinkProps['href']>;
    meta1?: string | null;
    meta2?: string | null;
    /** A deal state code from IA §8 — the badge resolves its own label. */
    state?: string | null;
    date?: string | number | Date | null;
    owner?: (NameParts & { name?: string | null }) | null;
    selected?: boolean;
    class?: string;
};

const props = defineProps<Props>();
defineEmits<{ 'update:selected': [value: boolean] }>();

const has = (key: DealRowColumn['key']) => props.columns.some((column) => column.key === key);
</script>

<template>
    <tr :class="cn('h-9 border-b last:border-b-0', props.class)" data-slot="deal-row">
        <td v-if="has('select')" class="px-4 text-center">
            <input
                type="checkbox"
                class="size-4 rounded-sm border accent-primary"
                :checked="selected"
                :aria-label="`Select ${primary}`"
                @change="$emit('update:selected', ($event.target as HTMLInputElement).checked)"
            />
        </td>
        <td v-if="has('primary')" class="truncate px-4 text-13 font-medium text-foreground">
            <Link v-if="href" :href="href" class="hover:text-primary">{{ primary }}</Link>
            <template v-else>{{ primary }}</template>
        </td>
        <td v-if="has('meta1')" class="truncate px-4 text-13 text-muted-foreground">
            {{ meta1 }}
        </td>
        <td v-if="has('meta2')" class="truncate px-4 text-13 text-muted-foreground">
            {{ meta2 }}
        </td>
        <td v-if="has('state')" class="px-4">
            <StatusBadge v-if="state" domain="deal" :state="state" />
        </td>
        <td v-if="has('date')" class="px-4">
            <DateChip v-if="date" :date="date" />
        </td>
        <td v-if="has('owner')" class="px-4 text-center">
            <PersonAvatar v-if="owner" :person="owner" :size="24" />
        </td>
    </tr>
</template>
