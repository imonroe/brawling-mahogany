<script setup lang="ts">
/**
 * S25 — add a participant, in a modal.
 *
 * PRD §5.2: the client is added *"from imported contacts or created inline"*.
 * Both paths live here, and creating inline must not require leaving the deal.
 *
 * ## The duplicate warning warns
 *
 * Issue #60 asks for a warning *"rather than duplicating"*, and the two halves
 * of that are different rules. The same person in the **same** role twice is a
 * duplicate with no meaning and the database refuses it outright. The same
 * person in a **second** role is unusual rather than impossible — a co-agent
 * who is also the stager — so it is surfaced and allowed. Which roles somebody
 * already holds arrives with the search results, so the warning is on screen
 * before the choice rather than after the submit.
 */
import { useForm } from '@inertiajs/vue3';
import { Search, UserPlus } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import AppButton from '@/components/app/AppButton.vue';
import AppInput from '@/components/app/AppInput.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';

type Candidate = {
    id: string;
    name: string;
    email: string | null;
    /** Roles this person already holds on this deal. */
    heldRoles: string[];
};

const props = defineProps<{
    open: boolean;
    dealId: string;
    participantRoles: Record<string, string>;
    /** Preselects the role, so the empty-state call to action can say which. */
    suggestedRole?: string | null;
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();

/** `search` picks somebody the team already knows; `create` makes a new one. */
const mode = ref<'search' | 'create'>('search');

const term = ref('');
const candidates = ref<Candidate[]>([]);
const selected = ref<Candidate | null>(null);

const form = useForm({
    team_membership_id: '',
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    participant_role: props.suggestedRole ?? '',
    is_primary: false,
    notes: '',
});

/*
 * The warning, computed rather than fetched on submit: the roles came back
 * with the candidate.
 */
const alreadyHolds = computed(() => selected.value?.heldRoles ?? []);

const roleLabel = computed(
    () => props.participantRoles[form.participant_role] ?? '',
);

const wouldDuplicateRole = computed(
    () =>
        roleLabel.value !== '' && alreadyHolds.value.includes(roleLabel.value),
);

let searchTimer: ReturnType<typeof setTimeout> | undefined;
let inFlight: AbortController | undefined;

watch([term, () => props.open], ([value, open]) => {
    clearTimeout(searchTimer);
    /*
     * A slower earlier response must not land on top of a newer one, and one
     * still in flight when the dialog closes must not write stale rows that
     * then show for a moment on reopen.
     */
    inFlight?.abort();

    if (!open) {
        return;
    }

    searchTimer = setTimeout(() => {
        const controller = new AbortController();
        inFlight = controller;

        void fetch(
            `/deals/${props.dealId}/people/candidates?q=${encodeURIComponent(value)}`,
            {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            },
        )
            .then((response) =>
                response.ok ? response.json() : { candidates: [] },
            )
            .then((body: { candidates: Candidate[] }) => {
                candidates.value = body.candidates;
            })
            /*
             * Offline, or a navigation that aborted the request. Neither is
             * worth a Sentry event, and an unhandled rejection is what one
             * becomes — `app.ts` wires Sentry up.
             */
            .catch(() => {
                if (!controller.signal.aborted) {
                    candidates.value = [];
                }
            });
    }, 250);
});

// Reopening should not show the last person somebody picked.
watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        mode.value = 'search';
        term.value = '';
        selected.value = null;
        stashed = null;
        form.reset();
        form.participant_role = props.suggestedRole ?? '';
        form.clearErrors();
    },
);

function choose(candidate: Candidate): void {
    selected.value = candidate;
    form.team_membership_id = candidate.id;
}

/** What an abandoned create was carrying, so going back is not destructive. */
let stashed: {
    first_name: string;
    last_name: string;
    email: string;
    phone: string;
} | null = null;

function startCreating(): void {
    mode.value = 'create';
    selected.value = null;
    form.team_membership_id = '';

    if (stashed) {
        // Coming back after a look at the directory. Restore what they typed
        // rather than making them type it twice — checking whether somebody is
        // already in there is the single most likely reason to have gone back.
        form.first_name = stashed.first_name;
        form.last_name = stashed.last_name;
        form.email = stashed.email;
        form.phone = stashed.phone;
        stashed = null;

        return;
    }

    // First time in: what they typed is almost always the name they meant.
    form.first_name = term.value;
}

/*
 * Going back drops what the create branch collected.
 *
 * The server decides which branch to take on whether a membership was picked,
 * so leftover `first_name`/`email` from an abandoned create are fields it will
 * never use — and, before the rule was gated on the right question, an email
 * left behind here got the submit refused for an address that was not going to
 * be written. Clearing them keeps the two halves of the modal from leaking
 * into each other regardless.
 */
function backToSearch(): void {
    // Stashed, not lost. The server picks its branch on whether a membership
    // was chosen, so these must not travel with a pick — but they are still
    // what somebody typed, and "Create someone new" brings them back.
    stashed = {
        first_name: form.first_name,
        last_name: form.last_name,
        email: form.email,
        phone: form.phone,
    };

    mode.value = 'search';
    form.first_name = '';
    form.last_name = '';
    form.email = '';
    form.phone = '';
    form.clearErrors();
}

function submit(): void {
    form.post(`/deals/${props.dealId}/people`, {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Add participant</DialogTitle>
                <DialogDescription>
                    Somebody's part in this deal — not their access to the
                    software.
                </DialogDescription>
            </DialogHeader>

            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <template v-if="mode === 'search'">
                    <div class="flex flex-col gap-1.5">
                        <Label for="participant_search">Who</Label>
                        <div class="relative">
                            <Search
                                class="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <AppInput
                                id="participant_search"
                                v-model="term"
                                class="pl-9"
                                placeholder="Search your people"
                                autocomplete="off"
                            />
                        </div>
                    </div>

                    <ul
                        v-if="candidates.length > 0"
                        class="max-h-56 overflow-y-auto rounded-md border"
                    >
                        <li
                            v-for="candidate in candidates"
                            :key="candidate.id"
                            class="border-b last:border-b-0"
                        >
                            <button
                                type="button"
                                class="flex min-h-11 w-full flex-col items-start px-3 py-2 text-left hover:bg-accent"
                                :class="
                                    selected?.id === candidate.id
                                        ? 'bg-accent'
                                        : ''
                                "
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
                                <span
                                    v-if="candidate.heldRoles.length > 0"
                                    class="text-[11px] text-state-warning"
                                    >Already on this deal as
                                    {{ candidate.heldRoles.join(', ') }}</span
                                >
                            </button>
                        </li>
                    </ul>

                    <p
                        v-else-if="term !== ''"
                        class="text-[11px] text-muted-foreground"
                    >
                        Nobody in your people matches “{{ term }}”.
                    </p>

                    <AppButton
                        variant="secondary"
                        size="compact"
                        @click="startCreating"
                    >
                        <UserPlus class="size-4" aria-hidden="true" />
                        Create someone new
                    </AppButton>
                </template>

                <template v-else>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="flex flex-col gap-1.5">
                            <Label for="participant_first_name"
                                >First name</Label
                            >
                            <AppInput
                                id="participant_first_name"
                                v-model="form.first_name"
                                required
                            />
                            <p
                                v-if="form.errors.first_name"
                                class="text-[11px] text-state-danger"
                            >
                                {{ form.errors.first_name }}
                            </p>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label for="participant_last_name">Last name</Label>
                            <AppInput
                                id="participant_last_name"
                                v-model="form.last_name"
                            />
                            <p
                                v-if="form.errors.last_name"
                                class="text-[11px] text-state-danger"
                            >
                                {{ form.errors.last_name }}
                            </p>
                        </div>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="flex flex-col gap-1.5">
                            <Label for="participant_email">Email</Label>
                            <AppInput
                                id="participant_email"
                                v-model="form.email"
                                type="email"
                            />
                            <p
                                v-if="form.errors.email"
                                class="text-[11px] text-state-danger"
                            >
                                {{ form.errors.email }}
                            </p>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label for="participant_phone">Phone</Label>
                            <AppInput
                                id="participant_phone"
                                v-model="form.phone"
                            />
                            <!--
                                Every writable field renders its error. A
                                pasted phone number over the limit used to
                                leave the modal open with no message, so the
                                submit simply appeared to do nothing.
                            -->
                            <p
                                v-if="form.errors.phone"
                                class="text-[11px] text-state-danger"
                            >
                                {{ form.errors.phone }}
                            </p>
                        </div>
                    </div>

                    <AppButton
                        variant="ghost"
                        size="compact"
                        @click="backToSearch"
                        >Back to search</AppButton
                    >
                </template>

                <div class="flex flex-col gap-1.5">
                    <Label for="participant_role">Role on this deal</Label>
                    <select
                        id="participant_role"
                        v-model="form.participant_role"
                        required
                        class="h-11 rounded-md border bg-background px-3 text-base md:h-10 md:text-sm"
                    >
                        <option value="" disabled>Choose a role</option>
                        <option
                            v-for="(label, value) in participantRoles"
                            :key="value"
                            :value="value"
                        >
                            {{ label }}
                        </option>
                    </select>
                    <p
                        v-if="form.errors.participant_role"
                        class="text-[11px] text-state-danger"
                    >
                        {{ form.errors.participant_role }}
                    </p>
                </div>

                <!--
                    The warning that warns rather than blocks. The same role
                    twice is refused by the database and by the rule above it;
                    a second, different role is merely unusual, so it is said
                    out loud and allowed.
                -->
                <p
                    v-if="wouldDuplicateRole"
                    class="text-[11px] text-state-danger"
                >
                    {{ selected?.name }} is already on this deal as
                    {{ roleLabel }}. Pick a different role.
                </p>
                <p
                    v-else-if="alreadyHolds.length > 0"
                    class="text-[11px] text-state-warning"
                >
                    {{ selected?.name }} is already on this deal as
                    {{ alreadyHolds.join(', ') }}. Adding them again as
                    {{ roleLabel || 'another role' }} is allowed — people do
                    hold two parts in one transaction.
                </p>

                <label class="flex items-center gap-2 text-13">
                    <input v-model="form.is_primary" type="checkbox" />
                    Main contact for this role
                </label>

                <p
                    v-if="form.errors.notes"
                    class="text-[11px] text-state-danger"
                >
                    {{ form.errors.notes }}
                </p>

                <p
                    v-if="form.errors.team_membership_id"
                    class="text-[11px] text-state-danger"
                >
                    {{ form.errors.team_membership_id }}
                </p>

                <!--
                    A backstop rather than a live surface. With the rule gated
                    on whether a membership was picked, the server does not
                    produce an `email` error on this branch — and both routes
                    into search mode clear the errors anyway. It stays because
                    an error with nowhere to render is a button that silently
                    does nothing, and this modal has already shipped that once.
                -->
                <p
                    v-if="mode === 'search' && form.errors.email"
                    class="text-[11px] text-state-danger"
                >
                    {{ form.errors.email }}
                </p>

                <DialogFooter>
                    <AppButton
                        variant="ghost"
                        @click="emit('update:open', false)"
                        >Cancel</AppButton
                    >
                    <AppButton
                        type="submit"
                        :disabled="
                            form.processing ||
                            wouldDuplicateRole ||
                            (mode === 'search' && !form.team_membership_id)
                        "
                        >Add to deal</AppButton
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
