<script setup lang="ts">
/**
 * S18 — a deal's Dates & Deadlines (PRD §4.8 F8.2 · IA §2 · issue #107).
 *
 * IA §2 and §11: the code says `key_dates`, this screen says **Dates &
 * Deadlines** — Emily's exact phrase — and a client would see *Important
 * Dates*. Never "Key dates" in front of a person, and never "Milestone", which
 * means a moment on a stage now.
 *
 * ## A derived date says why it is what it is
 *
 * #107: *"derived dates show their anchor and offset, so a user can see **why**
 * a date is what it is."* The sentence is composed on the server
 * (`DealDates::derivation()`) rather than assembled here, because it carries a
 * pluralisation rule and a before/after that a template is the wrong place for
 * — and because S59 renders the same line.
 *
 * ## Past due is unmissable, and never leaves this screen
 *
 * These are legally significant deadlines: a missed inspection objection has
 * consequences. IA §9 keeps *overdue* off the client surface entirely, which
 * is why the status page is a different layout rather than this one with fewer
 * rows.
 */
import { Head, router } from '@inertiajs/vue3';
import { CalendarClock, Flag, Pencil, Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
import AppButton from '@/components/app/AppButton.vue';
import Card from '@/components/app/Card.vue';
import type { DealHeaderProps } from '@/components/app/DealHeader.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import Heading from '@/components/app/Heading.vue';
import IconButton from '@/components/app/IconButton.vue';
import KeyDateFormDialog from '@/components/app/KeyDateFormDialog.vue';
import type {
    AnchorOption,
    KeyDateRow,
} from '@/components/app/KeyDateFormDialog.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { formatDate, formatRelativeDate } from '@/lib/formatters';

const props = defineProps<{
    dealHeader: DealHeaderProps;
    dealUrl: string;
    dates: KeyDateRow[];
    anchorOptions: AnchorOption[];
    offsetBases: Record<string, string>;
    canManage: boolean;
}>();

const dialogOpen = ref(false);
const editing = ref<KeyDateRow | null>(null);

const dealId = computed(() => props.dealUrl.split('/').pop() ?? '');

/**
 * How many deadlines this deal actually has.
 *
 * An extracted date nobody has confirmed is **not** counted (#116). It is
 * shown, because somebody has to be able to agree to it, and it is excluded,
 * because a proposal is not a deadline — the two halves of #107's rule, and
 * the reason `KeyDate::isPending()` exists rather than a `source` check at
 * each caller.
 */
const counted = computed(() => props.dates.filter((date) => !date.isPending));

const subtitle = computed(() => {
    const overdue = counted.value.filter((date) => date.isPastDue).length;

    const total = `${counted.value.length} ${counted.value.length === 1 ? 'date' : 'dates'}`;

    return overdue > 0 ? `${total} · ${overdue} past due` : total;
});

function openAdd(): void {
    editing.value = null;
    dialogOpen.value = true;
}

function openEdit(date: KeyDateRow): void {
    editing.value = date;
    dialogOpen.value = true;
}

function remove(id: string): void {
    const date = props.dates.find((row) => row.id === id);

    /*
     * IA §10: name the object and the consequence. Dates counted from this one
     * do not vanish with it — they stay where they are and stop following —
     * so the confirmation says so rather than letting somebody assume the
     * worse thing.
     */
    if (
        !window.confirm(
            `Remove “${date?.name ?? 'this date'}”? Any dates counted from it keep the day they have now.`,
        )
    ) {
        return;
    }

    router.delete(`${props.dealUrl}/dates/${id}`, {
        preserveScroll: true,
        onSuccess: () => {
            dialogOpen.value = false;
        },
    });
}
</script>

<template>
    <Head title="Dates & Deadlines" />

    <div class="flex flex-col gap-4">
        <div class="flex items-center gap-3">
            <Heading
                title="Dates &amp; Deadlines"
                :description="subtitle"
                variant="small"
            />
            <div class="flex-1"></div>
            <AppButton v-if="canManage" @click="openAdd">
                <Plus class="size-4" />
                Add date
            </AppButton>
        </div>

        <Card class="p-0">
            <EmptyState
                v-if="dates.length === 0"
                :icon="CalendarClock"
                title="No dates yet"
                description="Mutual acceptance, the inspection objection deadline, closing. Add the first and count the rest from it."
            />

            <ul v-else class="divide-y">
                <li
                    v-for="date in dates"
                    :key="date.id"
                    class="flex items-start gap-3 p-3"
                >
                    <Flag
                        class="mt-0.5 size-4 shrink-0"
                        :class="
                            date.isPastDue
                                ? 'text-state-danger'
                                : date.isCritical
                                  ? 'text-state-warning'
                                  : 'text-muted-foreground'
                        "
                        :stroke-width="2"
                        aria-hidden="true"
                    />

                    <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-sm font-medium">{{
                                date.name
                            }}</span>

                            <StatusBadge
                                v-if="date.isCritical"
                                tone="warning"
                                label="Critical"
                                dotless
                            />
                            <!--
                                Slice 5's extracted-pending. It has to be
                                visibly not-yet-real: a proposal rendered like
                                a deadline is a date somebody plans around.
                            -->
                            <StatusBadge
                                v-if="date.isPending"
                                tone="neutral"
                                label="Suggested — not confirmed"
                                dotless
                            />
                            <StatusBadge
                                v-if="date.isPastDue"
                                tone="danger"
                                label="Past due"
                                dotless
                            />
                        </div>

                        <p class="tabular text-13 text-muted-foreground">
                            {{ formatDate(date.date) }} ·
                            {{ formatRelativeDate(date.date) }}
                        </p>

                        <p
                            v-if="date.derivation"
                            class="text-[11px] text-muted-foreground"
                        >
                            {{ date.derivation }}
                        </p>

                        <p
                            v-if="date.notes"
                            class="text-[11px] text-muted-foreground"
                        >
                            {{ date.notes }}
                        </p>
                    </div>

                    <IconButton
                        v-if="canManage"
                        :icon="Pencil"
                        label="Edit date"
                        @click="openEdit(date)"
                    />
                </li>
            </ul>
        </Card>

        <KeyDateFormDialog
            v-if="canManage"
            v-model:open="dialogOpen"
            :deal-id="dealId"
            :key-date="editing"
            :anchor-options="anchorOptions"
            :offset-bases="offsetBases"
            @delete="remove"
        />
    </div>
</template>
