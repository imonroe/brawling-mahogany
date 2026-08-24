<script setup lang="ts">
/**
 * S26 — log a contact, in a modal.
 *
 * ## The two-click target is the design, not a nicety
 *
 * Screen Inventory marks S26 a **two-click target**, and PRD F12.3 says why:
 * Heather logs a call from a car between showings. Once this is open and the
 * person is known, a saved entry is two clicks — pick the type, then Log it.
 * Everything else on the screen is optional and adds none.
 *
 * That is the constraint every decision here answers to:
 *
 * - **The type is a tile, not a `<select>`.** A native picker on a phone is
 *   two taps and a scroll wheel; six 44px tiles are one tap, and the icon
 *   carries the meaning before the label is read.
 * - **Nothing is required but the type.** Not the note, not when it happened,
 *   not the deal. `occurred_at` left empty means now, which is true of a call
 *   being logged as it ends.
 * - **The person is preselected wherever it can be.** Opened from a person
 *   record or against a participant, there is nothing to choose. Only the
 *   shell's button has to ask, and it asks first so the two clicks that follow
 *   are still the last two.
 *
 * IA §7: the verb is **Log** — never "Add note", "Record", or "Track". A note
 * is written; a contact is logged, and they are different records.
 */
import { useForm, usePage } from '@inertiajs/vue3';
import { Search } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import AppButton from '@/components/app/AppButton.vue';
import AppInput from '@/components/app/AppInput.vue';
import AppSelect from '@/components/app/AppSelect.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { contactTypeIcon } from '@/lib/activity';
import type { LoggableDeal } from '@/types';

type Candidate = {
    id: string;
    name: string;
    email: string | null;
    deals: LoggableDeal[];
};

const props = withDefaults(
    defineProps<{
        open: boolean;
        /**
         * Who the contact was with. When absent the modal asks first — which
         * is the shell's entry point, and only that one.
         */
        membership?: { id: string; name: string } | null;
        /** Deals this contact can be attached to (F2.5 makes it optional). */
        deals?: LoggableDeal[];
        /** Preselects the deal, for the entry point that already is one. */
        dealId?: string | null;
    }>(),
    { membership: null, deals: () => [], dealId: null },
);

const emit = defineEmits<{ 'update:open': [value: boolean] }>();

const page = usePage();

/*
 * PRD §6.3's contact types come from the server's own enum, read here rather
 * than passed in by each of the three entry points. A copy of the list in TS
 * is a copy that drifts from `App\Enums\ContactType`, and one that three
 * pages each pass in is three chances to pass a different one.
 */
const contactTypes = computed<Record<string, string>>(
    () => page.props.lookups?.contactTypes ?? {},
);

const form = useForm({
    contact_type: '',
    note: '',
    occurred_at: '',
    deal_id: null as string | null,
});

/** Set only when the shell's entry point had to ask. */
const picked = ref<Candidate | null>(null);
const term = ref('');
const candidates = ref<Candidate[]>([]);

const person = computed(() => props.membership ?? picked.value);

const attachableDeals = computed<LoggableDeal[]>(() =>
    props.membership ? props.deals : (picked.value?.deals ?? []),
);

const dealOptions = computed<Record<string, string>>(() =>
    Object.fromEntries(
        attachableDeals.value.map((deal) => [deal.id, deal.name]),
    ),
);

/**
 * The two-click contract, as a value the template reads.
 *
 * Disabled until a type is chosen, so the second click cannot land on nothing
 * — and never disabled for want of anything else, because anything else would
 * be a third click.
 */
const ready = computed(
    () => form.contact_type !== '' && person.value !== null && !form.processing,
);

let searchTimer: ReturnType<typeof setTimeout> | undefined;
let inFlight: AbortController | undefined;

watch([term, () => props.open], ([value, open]) => {
    clearTimeout(searchTimer);
    // A slower earlier response must not land on top of a newer one, and one
    // still in flight when the modal closes must not write stale rows that
    // then show for a moment on reopen.
    inFlight?.abort();

    if (!open || props.membership) {
        return;
    }

    searchTimer = setTimeout(() => {
        const controller = new AbortController();
        inFlight = controller;

        void fetch(`/people/candidates?q=${encodeURIComponent(value)}`, {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        })
            .then((response) =>
                response.ok ? response.json() : { candidates: [] },
            )
            .then((body: { candidates: Candidate[] }) => {
                candidates.value = body.candidates;
            })
            // Offline, or a navigation that aborted the request. Neither is
            // worth a Sentry event, and an unhandled rejection is what one
            // becomes.
            .catch(() => {
                if (!controller.signal.aborted) {
                    candidates.value = [];
                }
            });
    }, 250);
});

// Reopening must not show the last thing somebody logged.
watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        form.reset();
        form.clearErrors();
        form.deal_id = props.dealId;
        picked.value = null;
        term.value = '';
        candidates.value = [];
    },
);

/*
 * A deal preselected after the modal is already mounted — the deal page
 * renders one instance and points it at whichever participant was clicked —
 * still has to reach the form. Without this the first open is right and every
 * one after it carries the previous deal.
 */
watch(
    () => props.dealId,
    (value) => {
        if (props.open) {
            form.deal_id = value;
        }
    },
);

function choose(candidate: Candidate): void {
    picked.value = candidate;
    // The deal list belongs to the person, so a change of person invalidates
    // whatever was chosen against the last one.
    form.deal_id = null;
}

function submit(): void {
    const target = person.value;

    if (target === null) {
        return;
    }

    form.post(`/people/${target.id}/contact-log`, {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent class="sm:max-w-[600px]">
            <DialogHeader>
                <DialogTitle>Log contact</DialogTitle>
                <DialogDescription>
                    {{
                        person
                            ? `Something that already happened with ${person.name}.`
                            : 'Something that already happened. Who was it with?'
                    }}
                </DialogDescription>
            </DialogHeader>

            <form
                class="flex max-h-[60vh] flex-col gap-4 overflow-y-auto"
                @submit.prevent="submit"
            >
                <!--
                    The shell's entry point only. From a person record or a
                    participant row there is nothing to ask, and asking would
                    cost the click the whole screen is budgeted around.
                -->
                <div v-if="!membership" class="flex flex-col gap-2">
                    <Label for="log_contact_person">Who</Label>
                    <div class="relative">
                        <Search
                            class="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <AppInput
                            id="log_contact_person"
                            v-model="term"
                            class="pl-9"
                            placeholder="Search your people"
                            autocomplete="off"
                        />
                    </div>

                    <ul
                        v-if="candidates.length > 0 && picked === null"
                        class="max-h-40 overflow-y-auto rounded-md border"
                    >
                        <li
                            v-for="candidate in candidates"
                            :key="candidate.id"
                            class="border-b last:border-b-0"
                        >
                            <button
                                type="button"
                                class="flex min-h-11 w-full flex-col items-start justify-center px-3 py-2 text-left hover:bg-accent"
                                @click="choose(candidate)"
                            >
                                <span class="text-13 font-medium">{{
                                    candidate.name
                                }}</span>
                                <span
                                    v-if="candidate.email"
                                    class="text-[11px] text-muted-foreground"
                                    >{{ candidate.email }}</span
                                >
                            </button>
                        </li>
                    </ul>

                    <p
                        v-else-if="picked === null && term !== ''"
                        class="text-[11px] text-muted-foreground"
                    >
                        Nobody in your people matches “{{ term }}”.
                    </p>

                    <p v-else-if="picked" class="text-13">
                        <span class="font-medium">{{ picked.name }}</span>
                        <button
                            type="button"
                            class="ml-2 text-[11px] text-primary underline"
                            @click="picked = null"
                        >
                            Change
                        </button>
                    </p>
                </div>

                <fieldset class="flex flex-col gap-2">
                    <legend class="text-xs font-semibold text-muted-foreground">
                        What happened
                    </legend>
                    <div class="grid grid-cols-3 gap-2">
                        <button
                            v-for="(label, value) in contactTypes"
                            :key="value"
                            type="button"
                            :aria-pressed="form.contact_type === value"
                            :class="[
                                'flex min-h-11 flex-col items-center justify-center gap-1 rounded-md border px-2 py-2 text-xs transition-colors duration-150 ease-out',
                                form.contact_type === value
                                    ? 'border-primary bg-accent font-semibold text-primary'
                                    : 'border-border text-foreground hover:bg-accent/60',
                            ]"
                            @click="form.contact_type = value"
                        >
                            <component
                                :is="contactTypeIcon(value)"
                                class="size-4"
                                aria-hidden="true"
                            />
                            {{ label }}
                        </button>
                    </div>
                    <p
                        v-if="form.errors.contact_type"
                        class="text-[11px] text-state-danger"
                    >
                        {{ form.errors.contact_type }}
                    </p>
                </fieldset>

                <div class="flex flex-col gap-1.5">
                    <Label for="log_contact_note">Note (optional)</Label>
                    <AppInput
                        id="log_contact_note"
                        v-model="form.note"
                        placeholder="Walked through the listing timeline."
                    />
                    <p
                        v-if="form.errors.note"
                        class="text-[11px] text-state-danger"
                    >
                        {{ form.errors.note }}
                    </p>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="flex flex-col gap-1.5">
                        <Label for="log_contact_when">When (optional)</Label>
                        <AppInput
                            id="log_contact_when"
                            v-model="form.occurred_at"
                            type="datetime-local"
                        />
                        <p class="text-[11px] text-muted-foreground">
                            Leave it empty for now.
                        </p>
                        <p
                            v-if="form.errors.occurred_at"
                            class="text-[11px] text-state-danger"
                        >
                            {{ form.errors.occurred_at }}
                        </p>
                    </div>

                    <div
                        v-if="attachableDeals.length > 0"
                        class="flex flex-col gap-1.5"
                    >
                        <Label for="log_contact_deal">Deal (optional)</Label>
                        <AppSelect
                            id="log_contact_deal"
                            v-model="form.deal_id"
                            size="default"
                            :options="dealOptions"
                            placeholder="No deal"
                        />
                        <p class="text-[11px] text-muted-foreground">
                            Attaching it puts the entry on the deal too.
                        </p>
                        <p
                            v-if="form.errors.deal_id"
                            class="text-[11px] text-state-danger"
                        >
                            {{ form.errors.deal_id }}
                        </p>
                    </div>
                </div>
            </form>

            <DialogFooter>
                <AppButton variant="ghost" @click="emit('update:open', false)"
                    >Cancel</AppButton
                >
                <AppButton :disabled="!ready" @click="submit">Log it</AppButton>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
