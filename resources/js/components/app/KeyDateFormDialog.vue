<script setup lang="ts">
/**
 * S18's add-and-edit, with the cascade preview (PRD §4.8 F8.2 · #106, #107).
 *
 * ## The preview is the whole reason this is a dialog
 *
 * #106: *"a cascade must be previewable before it is applied. Moving a closing
 * date by three days can move eleven other dates, and the user must see that
 * before agreeing."* So the form asks the server what would happen, shows the
 * list, and only then offers Save. Anything less makes the most consequential
 * write in the product a single unconfirmed click.
 *
 * The preview comes from `SaveKeyDate::preview()`, which is the same
 * computation `edit()` applies — so what somebody agreed to and what happened
 * cannot differ.
 *
 * ## Typed or derived is one choice, not two fields
 *
 * The shape `SaveAutomationRequest` argues for: a form that narrows, rather
 * than independent inputs that can be combined into a state the server
 * refuses. A derived date sends **no** day at all, because the server reads a
 * day as *"somebody typed over this"* and detaches the row from its anchor.
 */
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppButton from '@/components/app/AppButton.vue';
import AppInput from '@/components/app/AppInput.vue';
import AppTextarea from '@/components/app/AppTextarea.vue';
import InputError from '@/components/forms/InputError.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { writeHeaders } from '@/lib/csrf';
import { formatDate } from '@/lib/formatters';

export type KeyDateRow = {
    id: string;
    name: string;
    date: string;
    isCritical: boolean;
    notes: string | null;
    isDerived: boolean;
    wasDetached: boolean;
    anchor: { id: string; name: string } | null;
    offsetDays: number | null;
    offsetBasis: string | null;
    derivation: string | null;
    source: string;
    isPending: boolean;
    reminderDays: number[];
    /*
     * Whether the schedule above is **stored** or is the default for this kind
     * of date. `reminderDays` cannot say: it resolves the default, so a form
     * that read only that would write today's default onto every row somebody
     * opened and saved, and the date would stop following the rule.
     */
    remindersAreSet: boolean;
    isPastDue: boolean;
    deal?: { label: string; url: string } | null;
};

export type AnchorOption = {
    id: string;
    name: string;
    date: string;
    /** Rows that may not anchor to this one — they already depend on it. */
    blockedFor: string[];
};

type Move = {
    id: string;
    name: string;
    isCritical: boolean;
    from: string;
    to: string;
    days: number;
};

const props = defineProps<{
    open: boolean;
    dealId: string;
    keyDate: KeyDateRow | null;
    anchorOptions: AnchorOption[];
    offsetBases: Record<string, string>;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    delete: [id: string];
}>();

const form = useForm({
    keyDateId: '',
    name: '',
    mode: 'typed' as 'typed' | 'derived',
    date: '',
    anchorKeyDateId: '',
    offsetDays: 0,
    offsetBasis: 'calendar',
    isCritical: false,
    notes: '',
    /*
     * Null and an empty array are different answers, all the way down to the
     * column: absent means *"use the default for this kind of date"* — seven
     * days and one, or five reminders on a critical date — and `[]` means
     * somebody turned them off deliberately. So this is `number[] | null`
     * rather than a list that happens to be empty, and the server reads the
     * distinction the same way.
     */
    reminderOffsets: null as number[] | null,
});

/**
 * The reminder schedule as a person edits it: a comma-separated list of days
 * before the date.
 *
 * A text field rather than five checkboxes, because the useful answers are not
 * a fixed set — a financing contingency wants 14, 7 and 1, and a walkthrough
 * wants 1. Parsed leniently and validated on the server, which already refuses
 * anything outside 0–90 and more than six of them.
 */
/**
 * What the server uses when nothing is stored — `KeyDate::DEFAULT_REMINDERS`
 * and `::CRITICAL_REMINDERS`. Shown rather than left blank, because an empty
 * field reads as *"no reminders"* and that is the one thing it does not mean.
 */
const defaultReminders = computed((): number[] =>
    form.isCritical ? [14, 7, 3, 1, 0] : [7, 1],
);

const reminderText = computed({
    get: (): string =>
        form.reminderOffsets === null
            ? defaultReminders.value.join(', ')
            : form.reminderOffsets.join(', '),
    set: (value: string): void => {
        /*
         * The empty parts are dropped **before** `Number`, not after. `Number('')`
         * is `0`, not `NaN`, so `''.split(',')` → `['']` → `[0]` — clearing
         * the field to turn reminders off *added* a same-day reminder instead,
         * and a trailing comma mid-typing (`'7,'`) silently added one too. The
         * hint under the field promises the opposite of both.
         */
        const days = value
            .split(',')
            .map((part) => part.trim())
            .filter((part) => part !== '')
            .map(Number)
            .filter((day) => Number.isInteger(day) && day >= 0);

        form.reminderOffsets = days;
    },
});

const remindersAreDefault = computed(
    (): boolean => form.reminderOffsets === null,
);

const moves = ref<Move[]>([]);
const previewing = ref(false);
const previewed = ref(false);

watch(
    () => [props.open, props.keyDate?.id] as const,
    ([open]) => {
        if (!open) {
            return;
        }

        const row = props.keyDate;

        form.clearErrors();
        form.keyDateId = row?.id ?? '';
        form.name = row?.name ?? '';
        form.mode = row?.isDerived ? 'derived' : 'typed';
        form.date = row?.date ?? '';
        form.anchorKeyDateId = row?.anchor?.id ?? '';
        form.offsetDays = row?.offsetDays ?? 0;
        form.offsetBasis = row?.offsetBasis ?? 'calendar';
        form.isCritical = row?.isCritical ?? false;
        form.notes = row?.notes ?? '';
        /*
         * `reminderDays` is what the row *uses*, defaults included, so it
         * cannot tell a stored schedule from an unset one. `remindersAreSet`
         * is the column's own answer, and without it opening a date and
         * saving it would silently freeze today's default onto the row.
         */
        form.reminderOffsets = row?.remindersAreSet
            ? [...(row?.reminderDays ?? [])]
            : null;

        moves.value = [];
        previewed.value = false;
    },
    { immediate: true },
);

/*
 * A fresh preview is owed whenever the thing being previewed changes. Without
 * this, somebody could preview a three-day move, change it to thirty, and
 * press Save under a list describing the three-day one — which is the exact
 * failure the preview exists to prevent, arriving through the back door.
 */
watch(
    () => [
        form.mode,
        form.date,
        form.anchorKeyDateId,
        form.offsetDays,
        form.offsetBasis,
    ],
    () => {
        previewed.value = false;
        moves.value = [];
    },
);

/**
 * The anchors this row may use.
 *
 * The server sends, for each candidate, the rows that may **not** anchor to it
 * — everything already downstream of them. Hiding those is better than
 * refusing them after the fact, and the answer is the server's because the
 * graph is.
 */
const availableAnchors = computed(() =>
    props.anchorOptions.filter(
        (option) =>
            option.id !== props.keyDate?.id &&
            !(props.keyDate && option.blockedFor.includes(props.keyDate.id)),
    ),
);

/** Adding a date can move nothing: nothing points at a row that is not there. */
const needsPreview = computed(() => props.keyDate !== null);

async function preview(): Promise<void> {
    if (!needsPreview.value) {
        previewed.value = true;

        return;
    }

    previewing.value = true;

    try {
        const response = await fetch(`/deals/${props.dealId}/dates/preview`, {
            method: 'POST',
            headers: writeHeaders(),
            credentials: 'same-origin',
            body: JSON.stringify(form.data()),
        });

        const body = (await response.json()) as {
            moved?: Move[];
            error?: string;
        };

        if (!response.ok) {
            form.setError(
                'anchorKeyDateId',
                body.error ?? 'That change cannot be saved.',
            );

            return;
        }

        moves.value = body.moved ?? [];
        previewed.value = true;
    } catch {
        /*
         * Offline, or the session expired. Saying so beats letting Save look
         * available under an empty list, which would read as *"nothing else
         * moves"* — a statement nobody checked.
         */
        form.setError(
            'name',
            'Could not check what else would move. Try again.',
        );
    } finally {
        previewing.value = false;
    }
}

function submit(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    };

    if (props.keyDate) {
        form.patch(`/deals/${props.dealId}/dates/${props.keyDate.id}`, options);

        return;
    }

    form.post(`/deals/${props.dealId}/dates`, options);
}
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{
                    keyDate ? 'Edit date' : 'Add date'
                }}</DialogTitle>
                <DialogDescription>
                    A named deadline on this deal. Moving one moves everything
                    counted from it — you will see what before it happens.
                </DialogDescription>
            </DialogHeader>

            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <div class="flex flex-col gap-1.5">
                    <Label for="key_date_name">Name</Label>
                    <AppInput
                        id="key_date_name"
                        v-model="form.name"
                        required
                        placeholder="Inspection objection"
                    />
                    <p
                        v-if="form.errors.name"
                        class="text-[11px] text-state-danger"
                    >
                        {{ form.errors.name }}
                    </p>
                </div>

                <div class="flex flex-col gap-2">
                    <Label>How this date is set</Label>
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="form.mode" type="radio" value="typed" />
                        A day I choose
                    </label>
                    <label
                        v-if="availableAnchors.length > 0"
                        class="flex items-center gap-2 text-sm"
                    >
                        <input
                            v-model="form.mode"
                            type="radio"
                            value="derived"
                        />
                        Counted from another date
                    </label>
                </div>

                <div v-if="form.mode === 'typed'" class="flex flex-col gap-1.5">
                    <Label for="key_date_date">Date</Label>
                    <AppInput
                        id="key_date_date"
                        v-model="form.date"
                        type="date"
                        required
                    />
                    <p
                        v-if="keyDate?.isDerived"
                        class="text-[11px] text-muted-foreground"
                    >
                        Setting a day by hand stops this date following
                        {{ keyDate.anchor?.name }}.
                    </p>
                    <p
                        v-if="form.errors.date"
                        class="text-[11px] text-state-danger"
                    >
                        {{ form.errors.date }}
                    </p>
                </div>

                <div v-else class="grid gap-3 sm:grid-cols-3">
                    <div class="flex flex-col gap-1.5 sm:col-span-3">
                        <Label for="key_date_anchor">Counted from</Label>
                        <select
                            id="key_date_anchor"
                            v-model="form.anchorKeyDateId"
                            class="h-11 rounded-md border bg-background px-3 text-base md:h-10 md:text-sm"
                        >
                            <option value="">Choose a date</option>
                            <option
                                v-for="option in availableAnchors"
                                :key="option.id"
                                :value="option.id"
                            >
                                {{ option.name }} —
                                {{ formatDate(option.date) }}
                            </option>
                        </select>
                        <p
                            v-if="form.errors.anchorKeyDateId"
                            class="text-[11px] text-state-danger"
                        >
                            {{ form.errors.anchorKeyDateId }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <Label for="key_date_offset">Days</Label>
                        <!--
                            Signed: three business days *before* closing is
                            -3. A separate before/after toggle would be a
                            second field holding half of one number.
                        -->
                        <!--
                            A plain `<input type="number">` rather than
                            `AppInput`, whose `type` is narrowed to the
                            text-like set because it binds a string. An offset
                            is a number, and it decides a date.
                        -->
                        <input
                            id="key_date_offset"
                            v-model.number="form.offsetDays"
                            type="number"
                            min="-365"
                            max="365"
                            class="h-11 rounded-md border bg-background px-3 text-base md:h-10 md:text-sm"
                        />
                    </div>

                    <div class="flex flex-col gap-1.5 sm:col-span-2">
                        <Label for="key_date_basis">Counting</Label>
                        <select
                            id="key_date_basis"
                            v-model="form.offsetBasis"
                            class="h-11 rounded-md border bg-background px-3 text-base md:h-10 md:text-sm"
                        >
                            <option
                                v-for="[value, label] in Object.entries(
                                    offsetBases,
                                )"
                                :key="value"
                                :value="value"
                            >
                                {{ label }}
                            </option>
                        </select>
                        <p class="text-[11px] text-muted-foreground">
                            Business days skip weekends. Public holidays are not
                            counted — if a contract counts them, set the day by
                            hand.
                        </p>
                    </div>
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <input v-model="form.isCritical" type="checkbox" />
                    Critical — remind more often, and earlier
                </label>

                <div class="flex flex-col gap-1.5">
                    <Label for="key_date_reminders"
                        >Remind this many days before</Label
                    >
                    <AppInput
                        id="key_date_reminders"
                        v-model="reminderText"
                        placeholder="7, 1"
                    />
                    <InputError :message="form.errors.reminderOffsets" />
                    <p class="text-[11px] text-muted-foreground">
                        <template v-if="remindersAreDefault">
                            Using the default for this kind of date. Type your
                            own, or clear the field to turn reminders off.
                        </template>
                        <template
                            v-else-if="form.reminderOffsets?.length === 0"
                        >
                            Reminders are off for this date. It still appears on
                            the calendar and in the lists.
                        </template>
                        <template v-else>
                            0 means the morning it falls. Clear the field to
                            turn reminders off.
                        </template>
                    </p>
                </div>

                <div class="flex flex-col gap-1.5">
                    <Label for="key_date_notes">Notes</Label>
                    <AppTextarea
                        id="key_date_notes"
                        v-model="form.notes"
                        :rows="2"
                    />
                </div>

                <!--
                    The cascade preview. Shown only once it has been asked for,
                    because an empty list before the question reads as "nothing
                    else moves" — a statement nobody checked.
                -->
                <div
                    v-if="previewed && moves.length > 0"
                    class="rounded-md border border-state-warning-bg bg-state-warning-bg p-3"
                >
                    <p class="text-xs font-semibold text-state-warning">
                        {{ moves.length }}
                        {{ moves.length === 1 ? 'other date' : 'other dates' }}
                        will move
                    </p>
                    <ul class="mt-2 flex flex-col gap-1">
                        <li
                            v-for="move in moves"
                            :key="move.id"
                            class="tabular text-[11px] text-state-warning"
                        >
                            {{ move.name }}: {{ formatDate(move.from) }} →
                            {{ formatDate(move.to) }}
                        </li>
                    </ul>
                </div>

                <p
                    v-else-if="previewed"
                    class="text-[11px] text-muted-foreground"
                >
                    Nothing else moves.
                </p>

                <DialogFooter class="gap-2">
                    <AppButton
                        v-if="keyDate"
                        type="button"
                        variant="ghost"
                        @click="emit('delete', keyDate.id)"
                        >Remove</AppButton
                    >
                    <div class="flex-1"></div>
                    <AppButton
                        type="button"
                        variant="secondary"
                        @click="emit('update:open', false)"
                        >Cancel</AppButton
                    >
                    <AppButton
                        v-if="!previewed"
                        type="button"
                        :disabled="previewing"
                        @click="preview"
                        >{{
                            previewing ? 'Checking…' : 'Check what moves'
                        }}</AppButton
                    >
                    <AppButton
                        v-else
                        type="submit"
                        :disabled="form.processing"
                        >{{ keyDate ? 'Save' : 'Add date' }}</AppButton
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
