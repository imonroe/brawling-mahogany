<script setup lang="ts">
/**
 * S14 — create a deal (PRD §5.2 · Design System §9.4 P5 Wizard · issue #74).
 *
 * ## Resume is the state that earns the screen
 *
 * Issue #74: *"Heather is creating this on a phone, from a car, between
 * showings. A half-finished deal must survive a dropped connection. Persist
 * progress rather than holding it in component state."*
 *
 * So every step posts before it advances, and the only thing this component
 * remembers is which panel is open. Reloading, closing the tab, or losing
 * signal costs the step in flight and nothing before it — and the banner says
 * so when a draft is picked back up, because a wizard that silently reopened
 * on step three would read as a bug the first time it happened.
 *
 * ## Nothing is created until the last button, with two exceptions
 *
 * A client created inline is a directory entry and a property created inline
 * is a property; both are real the moment they are made, because somebody who
 * adds a contact and abandons a deal has still added a contact. The deal, its
 * participant and its workflow are one transaction on the last button.
 *
 * ## The workflow step can be skipped
 *
 * F4.7 allows several workflows attached at different times, and S28 is how
 * the later ones arrive. A deal opened before a pack is installed is still a
 * deal, so this step offers "not yet" rather than blocking the last button.
 */
import { Head, router, useForm } from '@inertiajs/vue3';
import { Check, Search } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import AppButton from '@/components/app/AppButton.vue';
import AppInput from '@/components/app/AppInput.vue';
import AppSelect from '@/components/app/AppSelect.vue';
import Card from '@/components/app/Card.vue';
import Heading from '@/components/app/Heading.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Label } from '@/components/ui/label';
import { formatAddress, formatPropertyFacts } from '@/lib/formatters';
import type { PropertyRow } from '@/types';

type Step = 'type' | 'client' | 'property' | 'template';

type ChosenPerson = { id: string; name: string; email: string | null };

const props = defineProps<{
    draft: {
        step: Step;
        dealTypeId: string | null;
        name: string | null;
        membershipId: string | null;
        participantRole: string | null;
        propertyId: string | null;
        workflowTemplateId: string | null;
        resumed: boolean;
    };
    steps: { value: Step; label: string; position: number }[];
    dealTypes: { id: string; name: string; sideLabel: string }[];
    impliedRole: { value: string; label: string } | null;
    participantRoles: Record<string, string>;
    propertyTypes: Record<string, string>;
    propertyStatuses: Record<string, string>;
    templates: {
        id: string;
        name: string;
        description: string | null;
        stageCount: number;
        isSystem: boolean;
    }[];
    chosen: {
        membership: ChosenPerson | null;
        property: PropertyRow | null;
    };
}>();

/** The panel that is open. The *answers* live on the server. */
const step = ref<Step>(props.draft.step);

const stepOne = useForm({
    step: 'type' as Step,
    deal_type_id: props.draft.dealTypeId ?? '',
    name: props.draft.name ?? '',
});

const stepTwo = useForm({
    step: 'client' as Step,
    team_membership_id: props.draft.membershipId ?? '',
    participant_role: props.draft.participantRole ?? null,
});

const newClient = useForm({
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    /*
     * Carried on this form too. Creating the client inline is another way to
     * answer step two, not a different step two — and when the deal type
     * implies no role, the server requires this on both endpoints. The version
     * that omitted it could not produce a participant on a rental at all.
     */
    participant_role: props.draft.participantRole ?? null,
});

const stepThree = useForm({
    step: 'property' as Step,
    property_id: props.draft.propertyId ?? '',
});

const newProperty = useForm({
    street: '',
    city: '',
    state_code: '',
    postal_code: '',
    type: 'single_family',
    status: 'pre_listing',
});

const stepFour = useForm({
    step: 'template' as Step,
    workflow_template_id: props.draft.workflowTemplateId ?? '',
});

const finish = useForm({});

/**
 * What the last button can refuse.
 *
 * `CreateDealFromDraft` throws on a deal type archived while the draft sat in
 * a pocket, and on a client with no resolvable role. Both are answerable — but
 * only by somebody who is told, and this form had nowhere to render either.
 */
const finishError = computed<string | null>(() => {
    /*
     * Cast because `useForm` types its error bag from its *data* keys, and the
     * last button posts no fields — the whole answer is already in the draft.
     * The server can still refuse it, on either of these two.
     */
    const errors = finish.errors as Record<string, string | undefined>;

    return (
        errors.deal_type_id ??
        errors.participant_role ??
        errors.team_membership_id ??
        errors.workflow_template_id ??
        null
    );
});

const creatingClient = ref(false);
const creatingProperty = ref(false);

const clientSearch = ref('');
const clientResults = ref<ChosenPerson[]>([]);
const propertySearch = ref('');
const propertyResults = ref<PropertyRow[]>([]);

let clientTimer: ReturnType<typeof setTimeout> | undefined;
let propertyTimer: ReturnType<typeof setTimeout> | undefined;

const dealTypeOptions = computed(() =>
    Object.fromEntries(
        props.dealTypes.map((each) => [
            each.id,
            `${each.name} · ${each.sideLabel}`,
        ]),
    ),
);

/**
 * The role the client takes. The deal type decides it on a sale or a purchase;
 * a rental expects neither, so the wizard asks rather than inventing one —
 * `DealRoster`'s own stance, surfaced.
 */
const mustChooseRole = computed(() => props.impliedRole === null);

/** Which form the visible role select writes to depends on which half is open. */
const chosenRole = computed({
    get: () =>
        creatingClient.value
            ? newClient.participant_role
            : stepTwo.participant_role,
    set: (value: string | null) => {
        stepTwo.participant_role = value;
        newClient.participant_role = value;

        /*
         * Saved, not just held. Step two has no Next button — it advances when
         * a client is picked — so once one *is* picked, changing the role had
         * nothing left that would ever PATCH it. Reachable without doing
         * anything odd: choose a client on a Sale, go back and switch the type
         * to Rental, and the select you are then shown writes nowhere.
         *
         * **The membership comes from the draft, not from `stepTwo`.** That
         * field is a local mirror written only by the initializer and by
         * `chooseClient()` — `saveNewClient()` posts to a different endpoint
         * and never touches it, and `useForm` state is not re-derived from
         * props on a visit. So the mirror is empty after an inline create (the
         * PATCH silently did nothing) and *stale* after pick-then-inline-create
         * (the PATCH carried the previous membership and reverted the client).
         * `props.draft` is what the server actually holds.
         */
        const membership = props.draft.membershipId;

        /*
         * Clearing it is an answer too. `AppSelect` renders the placeholder as
         * a selectable option mapping to null, so choosing it is a real action
         * — and guarding on `value !== null` meant the screen showed no role
         * while the server still held the old one, then created the deal with
         * the role the screen said was unanswered. The server refuses a null
         * where one is required, with the sentence that says why.
         */
        if (membership) {
            stepTwo.team_membership_id = membership;
            stepTwo.patch('/deals/create', { preserveScroll: true });
        }
    },
});

/**
 * Nothing on step two can be saved until the role is answered, where one has
 * to be. The server refuses it either way; disabling says so before the round
 * trip rather than after it.
 */
const roleMissing = computed(() => mustChooseRole.value && !chosenRole.value);

const roleError = computed(
    () => stepTwo.errors.participant_role ?? newClient.errors.participant_role,
);

async function search(
    url: string,
    term: string,
    key: 'people' | 'properties',
): Promise<unknown[]> {
    try {
        const response = await fetch(`${url}?q=${encodeURIComponent(term)}`, {
            headers: { Accept: 'application/json' },
        });

        return response.ok ? ((await response.json())[key] ?? []) : [];
    } catch {
        return [];
    }
}

/*
 * The server can forget an answer without the screen asking it to.
 *
 * `RecordDealDraft::invalidateDerived()` clears the role and the template when
 * step one's answer changes, because both are derived from it. Nothing told
 * this component: `router.patch` preserves state, so `useForm` initializers
 * never re-run, and `stepTwo.participant_role` and
 * `stepFour.workflow_template_id` are mirrors of exactly the two keys that get
 * deleted.
 *
 * The visible failure was a template row still drawn as selected — tick,
 * `aria-pressed="true"` — over a draft that no longer held it, so Create
 * produced a deal with no workflow, no error, and a success toast. That is the
 * outcome `CreateDealFromDraft`'s own docblock calls *worse* than a half-made
 * record, because it looks finished.
 *
 * So the mirrors follow the draft. This fires only when the server's answer
 * actually changes, which is the one moment it is authoritative.
 */
watch(
    () => [
        props.draft.participantRole,
        props.draft.workflowTemplateId,
        props.draft.membershipId,
    ],
    ([role, template, membership]) => {
        stepTwo.participant_role = role ?? null;
        newClient.participant_role = role ?? null;
        stepFour.workflow_template_id = template ?? '';
        stepTwo.team_membership_id = membership ?? '';
    },
);

watch(clientSearch, (term) => {
    clearTimeout(clientTimer);
    clientTimer = setTimeout(() => {
        void search('/deals/create/clients', term, 'people').then((people) => {
            // Drop a response the search has already moved past.
            if (term === clientSearch.value) {
                clientResults.value = people as ChosenPerson[];
            }
        });
    }, 250);
});

watch(propertySearch, (term) => {
    clearTimeout(propertyTimer);
    propertyTimer = setTimeout(() => {
        void search('/deals/create/properties', term, 'properties').then(
            (found) => {
                if (term === propertySearch.value) {
                    propertyResults.value = found as PropertyRow[];
                }
            },
        );
    }, 250);
});

function saveStepOne(): void {
    stepOne.patch('/deals/create', {
        preserveScroll: true,
        onSuccess: () => (step.value = 'client'),
    });
}

function chooseClient(person: ChosenPerson): void {
    stepTwo.team_membership_id = person.id;

    stepTwo.patch('/deals/create', {
        preserveScroll: true,
        onSuccess: () => (step.value = 'property'),
    });
}

function saveNewClient(): void {
    newClient.post('/deals/create/clients', {
        preserveScroll: true,
        onSuccess: () => {
            creatingClient.value = false;
            newClient.reset();
            // `reset()` returns every field to its initial value, the shared
            // role included — so reopening this half showed an empty select
            // and a disabled button while `stepTwo` still held the answer.
            newClient.participant_role = stepTwo.participant_role;
            step.value = 'property';
        },
    });
}

function chooseProperty(property: PropertyRow | null): void {
    stepThree.property_id = property?.id ?? '';

    stepThree.patch('/deals/create', {
        preserveScroll: true,
        onSuccess: () => (step.value = 'template'),
    });
}

function saveNewProperty(): void {
    newProperty.post('/deals/create/properties', {
        preserveScroll: true,
        onSuccess: () => {
            creatingProperty.value = false;
            newProperty.reset();
            step.value = 'template';
        },
    });
}

function chooseTemplate(id: string | null): void {
    stepFour.workflow_template_id = id ?? '';
    stepFour.patch('/deals/create', { preserveScroll: true });
}

function create(): void {
    finish.post('/deals/create');
}

function abandon(): void {
    if (
        !window.confirm(
            'Discard this draft? Anything you already added to your people or properties stays.',
        )
    ) {
        return;
    }

    router.delete('/deals/create');
}
</script>

<template>
    <Head title="New deal" />

    <div class="mx-auto flex w-full max-w-2xl flex-col gap-4 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                title="New deal"
                description="Four steps. Everything saves as you go."
            />
            <AppButton variant="ghost" @click="abandon">Discard</AppButton>
        </div>

        <!--
            Said out loud, because a wizard that silently reopens on step three
            reads as a bug the first time it happens.
        -->
        <Alert v-if="draft.resumed">
            <AlertDescription>
                Picking up where you left off. Nothing is created until the last
                step.
            </AlertDescription>
        </Alert>

        <ol class="flex flex-wrap items-center gap-2">
            <li v-for="each in steps" :key="each.value">
                <button
                    type="button"
                    class="flex min-h-11 items-center gap-1.5 rounded-md px-2.5 text-xs md:h-8 md:min-h-0"
                    :class="
                        step === each.value
                            ? 'bg-accent font-semibold text-primary'
                            : 'font-medium text-muted-foreground'
                    "
                    :aria-current="step === each.value ? 'step' : undefined"
                    @click="step = each.value"
                >
                    <span class="tabular">{{ each.position }}</span>
                    {{ each.label }}
                </button>
            </li>
        </ol>

        <!-- Step 1 — the deal type, which decides everything after it. -->
        <Card v-if="step === 'type'" title="What kind of deal?">
            <form
                class="flex flex-col gap-4 px-4 py-3"
                @submit.prevent="saveStepOne"
            >
                <div class="flex flex-col gap-1.5">
                    <Label for="deal_type_id">Deal type</Label>
                    <AppSelect
                        id="deal_type_id"
                        :model-value="stepOne.deal_type_id || null"
                        :options="dealTypeOptions"
                        placeholder="Choose one"
                        size="default"
                        @update:model-value="
                            (value) => (stepOne.deal_type_id = value ?? '')
                        "
                    />
                    <p
                        v-if="stepOne.errors.deal_type_id"
                        class="text-[11px] text-state-danger"
                    >
                        {{ stepOne.errors.deal_type_id }}
                    </p>
                    <p class="text-[11px] text-muted-foreground">
                        It decides who the client is on this deal, and which
                        workflows you’ll be offered.
                    </p>
                </div>

                <div class="flex flex-col gap-1.5">
                    <Label for="name">Name it yourself (optional)</Label>
                    <AppInput id="name" v-model="stepOne.name" />
                    <p class="text-[11px] text-muted-foreground">
                        Leave this empty and the deal takes its name from the
                        property address, or from your client.
                    </p>
                </div>

                <div class="flex justify-end">
                    <AppButton
                        type="submit"
                        :disabled="!stepOne.deal_type_id || stepOne.processing"
                        >Next</AppButton
                    >
                </div>
            </form>
        </Card>

        <!-- Step 2 — the client. -->
        <Card v-if="step === 'client'" title="Who is the client?">
            <div class="flex flex-col gap-3 px-4 py-3">
                <p v-if="chosen.membership" class="text-13 text-foreground">
                    <StatusBadge
                        tone="success"
                        :label="chosen.membership.name"
                        dotless
                    />
                </p>

                <div v-if="mustChooseRole" class="flex flex-col gap-1.5">
                    <Label for="participant_role"
                        >Their part in this deal</Label
                    >
                    <AppSelect
                        id="participant_role"
                        :model-value="chosenRole"
                        :options="participantRoles"
                        placeholder="Choose one"
                        size="default"
                        @update:model-value="(value) => (chosenRole = value)"
                    />
                    <p v-if="roleError" class="text-[11px] text-state-danger">
                        {{ roleError }}
                    </p>
                    <p v-else class="text-[11px] text-muted-foreground">
                        {{
                            draft.dealTypeId
                                ? 'This deal type doesn’t imply one, so pick it.'
                                : 'Choose a deal type first and this may fill itself in.'
                        }}
                    </p>
                </div>
                <p
                    v-else-if="impliedRole"
                    class="text-[11px] text-muted-foreground"
                >
                    They’ll be added as the
                    <strong>{{ impliedRole.label }}</strong
                    >.
                </p>

                <template v-if="!creatingClient">
                    <label class="flex flex-col gap-1.5">
                        <span class="sr-only">Search people</span>
                        <AppInput
                            v-model="clientSearch"
                            size="default"
                            type="search"
                            placeholder="Search your people"
                        />
                    </label>

                    <ul
                        v-if="clientResults.length > 0"
                        class="flex max-h-56 flex-col overflow-y-auto rounded-md border"
                    >
                        <li
                            v-for="person in clientResults"
                            :key="person.id"
                            class="border-b last:border-b-0"
                        >
                            <button
                                type="button"
                                class="flex min-h-11 w-full items-center gap-2 px-3 py-2.5 text-left hover:bg-accent/60 disabled:opacity-50"
                                :disabled="roleMissing"
                                @click="chooseClient(person)"
                            >
                                <span class="flex min-w-0 flex-1 flex-col">
                                    <span
                                        class="truncate text-13 font-medium text-foreground"
                                        >{{ person.name }}</span
                                    >
                                    <span
                                        class="truncate text-[11px] text-muted-foreground"
                                        >{{ person.email ?? 'No email' }}</span
                                    >
                                </span>
                                <Search
                                    class="size-3.5 text-muted-foreground"
                                    aria-hidden="true"
                                />
                            </button>
                        </li>
                    </ul>

                    <p
                        v-if="stepTwo.errors.team_membership_id"
                        class="text-[11px] text-state-danger"
                    >
                        {{ stepTwo.errors.team_membership_id }}
                    </p>

                    <AppButton
                        variant="secondary"
                        @click="creatingClient = true"
                        >Add somebody new</AppButton
                    >
                </template>

                <!-- Created inline (PRD §5.2), through /people's own action. -->
                <form
                    v-else
                    class="flex flex-col gap-3"
                    @submit.prevent="saveNewClient"
                >
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="flex flex-col gap-1.5">
                            <Label for="first_name">First name</Label>
                            <AppInput
                                id="first_name"
                                v-model="newClient.first_name"
                                required
                            />
                            <p
                                v-if="newClient.errors.first_name"
                                class="text-[11px] text-state-danger"
                            >
                                {{ newClient.errors.first_name }}
                            </p>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label for="last_name">Last name</Label>
                            <AppInput
                                id="last_name"
                                v-model="newClient.last_name"
                            />
                        </div>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="flex flex-col gap-1.5">
                            <Label for="client_email">Email</Label>
                            <AppInput
                                id="client_email"
                                v-model="newClient.email"
                                type="email"
                            />
                            <p
                                v-if="newClient.errors.email"
                                class="text-[11px] text-state-danger"
                            >
                                {{ newClient.errors.email }}
                            </p>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label for="client_phone">Phone</Label>
                            <AppInput
                                id="client_phone"
                                v-model="newClient.phone"
                            />
                        </div>
                    </div>
                    <div class="flex justify-end gap-2">
                        <AppButton
                            variant="ghost"
                            type="button"
                            @click="creatingClient = false"
                            >Back to search</AppButton
                        >
                        <AppButton
                            type="submit"
                            :disabled="newClient.processing || roleMissing"
                            >Add and continue</AppButton
                        >
                    </div>
                </form>
            </div>
        </Card>

        <!-- Step 3 — the property, which a buyer's deal may not have yet. -->
        <Card v-if="step === 'property'" title="Which property?">
            <div class="flex flex-col gap-3 px-4 py-3">
                <p v-if="chosen.property" class="text-13 text-foreground">
                    <StatusBadge
                        tone="success"
                        :label="
                            formatAddress(chosen.property.address).line1 ||
                            chosen.property.name
                        "
                        dotless
                    />
                </p>

                <template v-if="!creatingProperty">
                    <label class="flex flex-col gap-1.5">
                        <span class="sr-only">Search properties</span>
                        <AppInput
                            v-model="propertySearch"
                            size="default"
                            type="search"
                            placeholder="Search by address or parcel number"
                        />
                    </label>

                    <ul
                        v-if="propertyResults.length > 0"
                        class="flex max-h-56 flex-col overflow-y-auto rounded-md border"
                    >
                        <li
                            v-for="property in propertyResults"
                            :key="property.id"
                            class="border-b last:border-b-0"
                        >
                            <button
                                type="button"
                                class="flex min-h-11 w-full items-center gap-2 px-3 py-2.5 text-left hover:bg-accent/60"
                                @click="chooseProperty(property)"
                            >
                                <span class="flex min-w-0 flex-1 flex-col">
                                    <span
                                        class="truncate text-13 font-medium text-foreground"
                                        >{{
                                            formatAddress(property.address)
                                                .line1 || property.name
                                        }}</span
                                    >
                                    <span
                                        class="truncate text-[11px] text-muted-foreground"
                                        >{{
                                            [
                                                formatAddress(property.address)
                                                    .line2,
                                                formatPropertyFacts(property),
                                            ]
                                                .filter(Boolean)
                                                .join(' · ') ||
                                            property.typeLabel
                                        }}</span
                                    >
                                </span>
                            </button>
                        </li>
                    </ul>

                    <div class="flex flex-wrap gap-2">
                        <AppButton
                            variant="secondary"
                            @click="creatingProperty = true"
                            >Add a new property</AppButton
                        >
                        <!--
                            IA §13.4: a buyer's deal is opened before there is
                            a property to buy, and that is the normal way round
                            rather than an edge case.
                        -->
                        <AppButton variant="ghost" @click="chooseProperty(null)"
                            >No property yet</AppButton
                        >
                    </div>
                </template>

                <form
                    v-else
                    class="flex flex-col gap-3"
                    @submit.prevent="saveNewProperty"
                >
                    <div class="flex flex-col gap-1.5">
                        <Label for="street">Street</Label>
                        <AppInput id="street" v-model="newProperty.street" />
                    </div>
                    <div class="grid gap-3 sm:grid-cols-[2fr_1fr_1fr]">
                        <div class="flex flex-col gap-1.5">
                            <Label for="city">City</Label>
                            <AppInput id="city" v-model="newProperty.city" />
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label for="state_code">State</Label>
                            <AppInput
                                id="state_code"
                                v-model="newProperty.state_code"
                                maxlength="2"
                            />
                            <p
                                v-if="newProperty.errors.state_code"
                                class="text-[11px] text-state-danger"
                            >
                                {{ newProperty.errors.state_code }}
                            </p>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label for="postal_code">ZIP</Label>
                            <AppInput
                                id="postal_code"
                                v-model="newProperty.postal_code"
                            />
                        </div>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="flex flex-col gap-1.5">
                            <Label for="property_type">Type</Label>
                            <AppSelect
                                id="property_type"
                                :model-value="newProperty.type"
                                :options="propertyTypes"
                                size="default"
                                @update:model-value="
                                    (value) =>
                                        (newProperty.type =
                                            value ?? 'single_family')
                                "
                            />
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label for="property_status">Status</Label>
                            <AppSelect
                                id="property_status"
                                :model-value="newProperty.status"
                                :options="propertyStatuses"
                                size="default"
                                @update:model-value="
                                    (value) =>
                                        (newProperty.status =
                                            value ?? 'pre_listing')
                                "
                            />
                        </div>
                    </div>
                    <div class="flex justify-end gap-2">
                        <AppButton
                            variant="ghost"
                            type="button"
                            @click="creatingProperty = false"
                            >Back to search</AppButton
                        >
                        <AppButton
                            type="submit"
                            :disabled="newProperty.processing"
                            >Add and continue</AppButton
                        >
                    </div>
                </form>
            </div>
        </Card>

        <!-- Step 4 — the workflow, which can wait (F4.7, S28). -->
        <Card v-if="step === 'template'" title="Which process?">
            <div class="flex flex-col gap-3 px-4 py-3">
                <ul v-if="templates.length > 0" class="flex flex-col gap-2">
                    <li
                        v-for="template in templates"
                        :key="template.id"
                        class="rounded-md border"
                        :class="
                            stepFour.workflow_template_id === template.id
                                ? 'border-primary bg-accent/40'
                                : 'border-border'
                        "
                    >
                        <button
                            type="button"
                            class="flex min-h-11 w-full items-center gap-2 px-3 py-2.5 text-left"
                            :aria-pressed="
                                stepFour.workflow_template_id === template.id
                            "
                            @click="chooseTemplate(template.id)"
                        >
                            <span class="flex min-w-0 flex-1 flex-col">
                                <span
                                    class="truncate text-13 font-medium text-foreground"
                                    >{{ template.name }}</span
                                >
                                <span
                                    class="truncate text-[11px] text-muted-foreground"
                                    >{{ template.stageCount }} stages<template
                                        v-if="template.description"
                                    >
                                        · {{ template.description }}</template
                                    ></span
                                >
                            </span>
                            <Check
                                v-if="
                                    stepFour.workflow_template_id ===
                                    template.id
                                "
                                class="size-4 text-primary"
                                aria-hidden="true"
                            />
                        </button>
                    </li>
                </ul>

                <p v-else class="text-[11px] text-muted-foreground">
                    No workflow templates for this deal type yet. Create the
                    deal and attach one later — install a pack from Templates
                    when you’re ready.
                </p>

                <!--
                    Always rendered, never gated on the list having anything in
                    it. The refusal `CreateDealFromDraft` throws when a chosen
                    template has been withdrawn says "choose a workflow again,
                    or skip it" — and when that template was the only one on
                    offer, deactivating it empties `templates`, which took this
                    button away with it. The advice was then impossible to
                    follow and the only exit was discarding four steps of work.
                    Skipping is legal on every deal (F4.7), so it is offered
                    wherever there is something to skip *past* — which includes
                    a draft still holding a template that has since been
                    withdrawn, and excludes a team with no templates at all,
                    where the button would PATCH null over null next to a
                    paragraph already saying the same thing.
                -->
                <AppButton
                    v-if="templates.length > 0 || draft.workflowTemplateId"
                    variant="ghost"
                    @click="chooseTemplate(null)"
                    >Not yet — I’ll attach one later</AppButton
                >

                <p
                    v-if="stepFour.errors.workflow_template_id"
                    class="text-[11px] text-state-danger"
                >
                    {{ stepFour.errors.workflow_template_id }}
                </p>

                <p v-if="finishError" class="text-[11px] text-state-danger">
                    {{ finishError }}
                </p>

                <div class="flex justify-end">
                    <AppButton :disabled="finish.processing" @click="create"
                        >Create deal</AppButton
                    >
                </div>
            </div>
        </Card>
    </div>
</template>
