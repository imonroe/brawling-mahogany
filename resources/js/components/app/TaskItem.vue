<script setup lang="ts">
/**
 * Design System §7.3. h-11 list row: checkbox, title and meta, date, assignee.
 * Completed tasks strike through and mute. The assignee avatar is hidden on
 * My Work, where it is always the current user.
 */
import type { NameParts } from '@/lib/formatters';
import { cn } from '@/lib/utils';
import DateChip from './DateChip.vue';
import PersonAvatar from './PersonAvatar.vue';

const props = defineProps<{
    title: string;
    /** Deal context across deals, or completion attribution inside one. */
    meta?: string | null;
    completed?: boolean;
    dueDate?: string | number | Date | null;
    assignee?: (NameParts & { name?: string | null }) | null;
    /**
     * Show the state without offering to change it (S16).
     *
     * The stage rail lists a stage's tasks so the reader can see what is owed,
     * and completing one is S17's endpoint, which does not exist yet. A
     * checkbox wired to nothing is the *"checkbox that selects into nothing"*
     * S13 refused to ship: it invites a click, does nothing, and teaches the
     * reader the screen is broken.
     *
     * Disabled rather than replaced by an icon, so the row keeps one anatomy
     * and the tick still means what it means everywhere else.
     */
    readonly?: boolean;
    class?: string;
}>();

defineEmits<{ 'update:completed': [value: boolean] }>();
</script>

<template>
    <div
        :class="
            cn(
                'flex h-11 items-center gap-2.5 border-b px-3 last:border-b-0',
                props.class,
            )
        "
        data-slot="task-item"
    >
        <input
            type="checkbox"
            :class="
                cn(
                    'size-4 shrink-0 rounded-sm border accent-primary',
                    readonly && 'cursor-default opacity-70',
                )
            "
            :checked="completed"
            :disabled="readonly || undefined"
            :aria-label="
                readonly
                    ? `${title}: ${completed ? 'complete' : 'not complete'}`
                    : `Complete ${title}`
            "
            @change="
                $emit(
                    'update:completed',
                    ($event.target as HTMLInputElement).checked,
                )
            "
        />
        <div class="flex min-w-0 flex-1 flex-col">
            <span
                :class="
                    cn(
                        'truncate text-sm',
                        completed
                            ? 'text-muted-foreground line-through'
                            : 'text-foreground',
                    )
                "
                >{{ title }}</span
            >
            <span v-if="meta" class="truncate text-xs text-muted-foreground">{{
                meta
            }}</span>
        </div>
        <DateChip
            v-if="dueDate"
            :date="dueDate"
            :tone="completed ? 'neutral' : undefined"
            class="shrink-0"
        />
        <PersonAvatar
            v-if="assignee"
            :person="assignee"
            :size="24"
            class="shrink-0"
        />
    </div>
</template>
