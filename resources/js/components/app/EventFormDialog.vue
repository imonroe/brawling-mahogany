<script setup lang="ts">
/**
 * S58 — the event modal (PRD §4.8 F8.1 · issue #105).
 *
 * One dialog for add and edit, for the reason `TaskFormDialog` gives: the same
 * fields, and a second form is a second set of answers about which of them are
 * optional.
 *
 * ## The times are wall clock, and they stay wall clock
 *
 * `<input type="datetime-local">` has no timezone, which is exactly right
 * here: a person picking 9am means 9am where the team is, and
 * `SaveEventRequest` reads it in the team's zone. A serialised instant would
 * carry the *browser's* zone, so a colleague filing an inspection from an
 * airport would book it an hour out — silently, because the value would look
 * correct on their own screen.
 */
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import AppButton from '@/components/app/AppButton.vue';
import AppInput from '@/components/app/AppInput.vue';
import AppTextarea from '@/components/app/AppTextarea.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { isoDateIn } from '@/lib/formatters';

export type EventFormValues = {
    id: string;
    type: string;
    title: string;
    description: string | null;
    location: string | null;
    startsAt: string;
    endsAt: string | null;
    isAllDay: boolean;
    dealId: string | null;
    propertyId: string | null;
    attendees: string[];
    recurrence: {
        frequency: string;
        interval: number;
        until: string | null;
    } | null;
};

const props = defineProps<{
    open: boolean;
    event: EventFormValues | null;
    /** Preselected day when the person pressed Add on a cell. */
    defaultDay?: string | null;
    eventTypes: Record<string, string>;
    dealOptions: { id: string; label: string }[];
    attendeeOptions: { id: string; name: string }[];
    /** Where to send them back to — the view and day they were looking at. */
    returnView: string;
    returnDate: string;
    /** The team's zone, for the day "today" means when nothing was pressed. */
    timezone: string;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    delete: [id: string];
}>();

const form = useForm({
    type: 'showing',
    title: '',
    description: '',
    location: '',
    startsAt: '',
    endsAt: '',
    isAllDay: false,
    dealId: '',
    attendees: [] as string[],
    repeats: false,
    recurrenceFrequency: 'weekly',
    recurrenceInterval: 1,
    recurrenceUntil: '',
    returnView: props.returnView,
    returnDate: props.returnDate,
});

/*
 * Filled on open rather than on mount: the page mounts this once and reuses it
 * for every row, so reopening it on a second event must not show the first
 * one's title — or worse, its recurrence, which decides how many days the
 * thing lands on.
 */
watch(
    () => [props.open, props.event?.id] as const,
    ([open]) => {
        if (!open) {
            return;
        }

        form.clearErrors();

        const event = props.event;

        form.type = event?.type ?? 'showing';
        form.title = event?.title ?? '';
        form.description = event?.description ?? '';
        form.location = event?.location ?? '';
        /*
         * `datetime-local` wants `YYYY-MM-DDTHH:mm`. The server sends ISO 8601
         * already in the team's zone, so the first sixteen characters are the
         * value — sliced rather than reformatted, because putting it through a
         * `Date` would put it through the browser's zone.
         */
        form.startsAt = event?.startsAt?.slice(0, 16) ?? defaultStart();
        form.endsAt = event?.endsAt?.slice(0, 16) ?? '';
        form.isAllDay = event?.isAllDay ?? false;
        form.dealId = event?.dealId ?? '';
        form.attendees = [...(event?.attendees ?? [])];
        form.repeats = event?.recurrence != null;
        form.recurrenceFrequency = event?.recurrence?.frequency ?? 'weekly';
        form.recurrenceInterval = event?.recurrence?.interval ?? 1;
        form.recurrenceUntil = event?.recurrence?.until ?? '';
        form.returnView = props.returnView;
        form.returnDate = props.returnDate;
    },
    { immediate: true },
);

/**
 * 9am on the day they pressed Add, or on today.
 *
 * The **team's** today. `toISOString()` is UTC, so from six in the evening in
 * Denver onwards "Add event" opened on tomorrow — the same defect the calendar
 * grid's today-ring had, one component along, and the same fix: `isoDateIn`,
 * which is what every date-distance question in this product reads.
 */
function defaultStart(): string {
    const day = props.defaultDay ?? isoDateIn(new Date(), props.timezone);

    return `${day}T09:00`;
}

const typeOptions = computed(() => Object.entries(props.eventTypes));

function submit(): void {
    const payload = {
        ...form.data(),
        recurrence: form.repeats
            ? {
                  frequency: form.recurrenceFrequency,
                  interval: form.recurrenceInterval,
                  until: form.recurrenceUntil || null,
              }
            : null,
    };

    const options = {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    };

    if (props.event) {
        form.transform(() => payload).patch(
            `/calendar/events/${props.event.id}`,
            options,
        );

        return;
    }

    form.transform(() => payload).post('/calendar/events', options);
}
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <!-- IA §7: **Add** attaches to a parent, **Edit** changes. -->
                <DialogTitle>{{
                    event ? 'Edit event' : 'Add event'
                }}</DialogTitle>
                <DialogDescription>
                    Something somebody attends. A deadline is a different thing
                    — those live on the deal’s Dates &amp; Deadlines.
                </DialogDescription>
            </DialogHeader>

            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="flex flex-col gap-1.5">
                        <Label for="event_type">Kind</Label>
                        <select
                            id="event_type"
                            v-model="form.type"
                            class="h-11 rounded-md border bg-background px-3 text-base md:h-10 md:text-sm"
                        >
                            <option
                                v-for="[value, label] in typeOptions"
                                :key="value"
                                :value="value"
                            >
                                {{ label }}
                            </option>
                        </select>
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <Label for="event_deal">Deal</Label>
                        <select
                            id="event_deal"
                            v-model="form.dealId"
                            class="h-11 rounded-md border bg-background px-3 text-base md:h-10 md:text-sm"
                        >
                            <!--
                                An open house belongs to a property and to no
                                deal, so "None" is a real answer rather than an
                                unset default.
                            -->
                            <option value="">Not on a deal</option>
                            <option
                                v-for="deal in dealOptions"
                                :key="deal.id"
                                :value="deal.id"
                            >
                                {{ deal.label }}
                            </option>
                        </select>
                    </div>
                </div>

                <div class="flex flex-col gap-1.5">
                    <Label for="event_title">Title</Label>
                    <AppInput
                        id="event_title"
                        v-model="form.title"
                        required
                        placeholder="Inspection — 123 Main St"
                    />
                    <p
                        v-if="form.errors.title"
                        class="text-[11px] text-state-danger"
                    >
                        {{ form.errors.title }}
                    </p>
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <input v-model="form.isAllDay" type="checkbox" />
                    All day
                </label>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="flex flex-col gap-1.5">
                        <Label for="event_starts">Starts</Label>
                        <AppInput
                            id="event_starts"
                            v-model="form.startsAt"
                            :type="form.isAllDay ? 'date' : 'datetime-local'"
                            required
                        />
                        <p
                            v-if="form.errors.startsAt"
                            class="text-[11px] text-state-danger"
                        >
                            {{ form.errors.startsAt }}
                        </p>
                    </div>

                    <div v-if="!form.isAllDay" class="flex flex-col gap-1.5">
                        <Label for="event_ends">Ends</Label>
                        <AppInput
                            id="event_ends"
                            v-model="form.endsAt"
                            type="datetime-local"
                        />
                        <p
                            v-if="form.errors.endsAt"
                            class="text-[11px] text-state-danger"
                        >
                            {{ form.errors.endsAt }}
                        </p>
                    </div>
                </div>

                <div class="flex flex-col gap-1.5">
                    <Label for="event_location">Where</Label>
                    <AppInput
                        id="event_location"
                        v-model="form.location"
                        placeholder="Lockbox on the side gate"
                    />
                </div>

                <div class="flex flex-col gap-1.5">
                    <Label for="event_attendees">Who is coming</Label>
                    <!--
                        Membership ids, never typed names: the event stores
                        pointers so a six-week-old row shows the name the
                        directory holds today, and so the `.ics` feed that
                        leaves the building has no address in it to leak.
                    -->
                    <select
                        id="event_attendees"
                        v-model="form.attendees"
                        multiple
                        size="4"
                        class="rounded-md border bg-background px-3 py-2 text-base md:text-sm"
                    >
                        <option
                            v-for="person in attendeeOptions"
                            :key="person.id"
                            :value="person.id"
                        >
                            {{ person.name }}
                        </option>
                    </select>
                </div>

                <div class="flex flex-col gap-2">
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="form.repeats" type="checkbox" />
                        Repeats
                    </label>

                    <div v-if="form.repeats" class="grid gap-3 sm:grid-cols-3">
                        <div class="flex flex-col gap-1.5">
                            <Label for="event_frequency">How often</Label>
                            <select
                                id="event_frequency"
                                v-model="form.recurrenceFrequency"
                                class="h-11 rounded-md border bg-background px-3 text-base md:h-10 md:text-sm"
                            >
                                <option value="daily">Daily</option>
                                <option value="weekly">Weekly</option>
                                <option value="monthly">Monthly</option>
                            </select>
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <Label for="event_interval">Every</Label>
                            <!--
                                A plain `<input type="number">` rather than
                                `AppInput`: that component binds a string and
                                its `type` is narrowed to the text-like set for
                                exactly this reason — an interval is a number,
                                and `v-model.number` is what keeps it one all
                                the way to the server.
                            -->
                            <input
                                id="event_interval"
                                v-model.number="form.recurrenceInterval"
                                type="number"
                                min="1"
                                max="52"
                                class="h-11 rounded-md border bg-background px-3 text-base md:h-10 md:text-sm"
                            />
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <Label for="event_until">Until</Label>
                            <AppInput
                                id="event_until"
                                v-model="form.recurrenceUntil"
                                type="date"
                            />
                        </div>
                    </div>
                </div>

                <div class="flex flex-col gap-1.5">
                    <Label for="event_description">Notes</Label>
                    <AppTextarea
                        id="event_description"
                        v-model="form.description"
                        :rows="2"
                    />
                </div>

                <DialogFooter class="gap-2">
                    <!--
                        IA §7: **Remove** detaches and the record survives,
                        which is what a soft delete plus the retention purge
                        actually does. The page owns the confirmation.
                    -->
                    <AppButton
                        v-if="event"
                        type="button"
                        variant="ghost"
                        @click="emit('delete', event.id)"
                        >Remove</AppButton
                    >
                    <div class="flex-1"></div>
                    <AppButton
                        type="button"
                        variant="secondary"
                        @click="emit('update:open', false)"
                        >Cancel</AppButton
                    >
                    <AppButton type="submit" :disabled="form.processing">{{
                        event ? 'Save' : 'Add event'
                    }}</AppButton>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
