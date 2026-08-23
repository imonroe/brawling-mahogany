<script setup lang="ts">
/**
 * S20 — deal properties.
 *
 * ## Two screens wearing one route
 *
 * A **sell-side** deal has one house and has it from the moment it opens; the
 * useful surface is a header, and a second property is the rare case. A
 * **buy-side** deal has twelve, none of them the subject until an offer is
 * accepted, each carrying an opinion that changes across showings — the useful
 * surface is a ranked list. Issue #62 names both, and the shape below follows
 * that rather than rendering one list and hoping.
 *
 * ## Promotion is a rename, and the screen says so
 *
 * IA §10 derives a deal's name from its subject property's street, so
 * promoting a candidate renames the deal. That is correct and it is also
 * surprising, so the confirmation names the consequence — except on a deal
 * somebody has typed a name for, where nothing visible changes and promising a
 * rename would be a lie.
 */
import { Head, router, useForm } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, Home, Plus, Star } from '@lucide/vue';
import { computed, ref } from 'vue';
import AppButton from '@/components/app/AppButton.vue';
import AppSelect from '@/components/app/AppSelect.vue';
import Card from '@/components/app/Card.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import Heading from '@/components/app/Heading.vue';
import LinkPropertyDialog from '@/components/app/LinkPropertyDialog.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import TextLink from '@/components/app/TextLink.vue';
import { formatAddress, formatPropertyFacts } from '@/lib/formatters';
import type { DealPropertyLink } from '@/types';

const props = defineProps<{
    deal: {
        id: string;
        name: string;
        sideLabel: string;
        isBuySide: boolean;
        hasManualName: boolean;
    };
    links: DealPropertyLink[];
    interestStatuses: Record<string, string>;
}>();

const linking = ref(false);

const subject = computed(() => props.links.find((link) => link.isSubject));
const candidates = computed(() =>
    props.links.filter((link) => !link.isSubject),
);

const interest = useForm({ interest_status: null as string | null });

function addressOf(link: DealPropertyLink): { line1: string; line2: string } {
    return formatAddress(link.property?.address ?? {});
}

function titleOf(link: DealPropertyLink): string {
    return addressOf(link).line1 || link.property?.name || 'A deleted property';
}

/**
 * Null is a real value: *nobody has said*, which is a different fact from
 * "Interested" and is what every candidate starts as. `AppSelect` maps the
 * empty option to null so this does not have to.
 */
function setInterest(link: DealPropertyLink, value: string | null): void {
    interest.interest_status = value;

    interest.patch(`/deals/${props.deal.id}/properties/${link.id}`, {
        preserveScroll: true,
    });
}

/**
 * The ranking #62 asks for by name: *"`sort_order` exists so an agent can rank
 * candidates."*
 *
 * Up/down buttons rather than a drag. They are keyboard-reachable and screen
 * readable without a second implementation, they need no library, and an agent
 * moving one house to the top of nine does it in one click either way. A drag
 * can land later over the same route.
 *
 * The subject is not in this list and is never renumbered — it sorts above the
 * candidates regardless of rank.
 */
function move(index: number, direction: -1 | 1): void {
    const order = candidates.value.map((link) => link.id);
    const target = index + direction;

    if (target < 0 || target >= order.length) {
        return;
    }

    [order[index], order[target]] = [order[target], order[index]];

    router.put(
        `/deals/${props.deal.id}/properties/order`,
        { order },
        { preserveScroll: true },
    );
}

/**
 * IA §10: name the object and the consequence — and only true consequences.
 * A deal with a typed name is not renamed by this, so it is not promised one.
 */
function promote(link: DealPropertyLink): void {
    const rename = props.deal.hasManualName
        ? 'The deal keeps the name you typed.'
        : `The deal will be renamed after it.`;

    if (
        !window.confirm(`Make ${titleOf(link)} the subject property? ${rename}`)
    ) {
        return;
    }

    router.post(
        `/deals/${props.deal.id}/properties/${link.id}/subject`,
        {},
        { preserveScroll: true },
    );
}

/** IA §7: **Remove** detaches, **Delete** destroys. This detaches. */
function remove(link: DealPropertyLink): void {
    if (
        !window.confirm(
            `Remove ${titleOf(link)} from this deal? It stays in your properties and on every other deal it's on.`,
        )
    ) {
        return;
    }

    router.delete(`/deals/${props.deal.id}/properties/${link.id}`, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head :title="`Properties — ${deal.name}`" />

    <div class="flex flex-col gap-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                title="Properties"
                :description="
                    deal.isBuySide
                        ? `Every house ${deal.name} is looking at, and where the buyer stands on each.`
                        : `The house ${deal.name} is about.`
                "
            />
            <AppButton @click="linking = true">
                <Plus class="size-4" aria-hidden="true" />
                Link a property
            </AppButton>
        </div>

        <EmptyState
            v-if="links.length === 0"
            :icon="Home"
            title="No properties on this deal yet"
            :description="
                deal.isBuySide
                    ? 'Link every house the buyer is looking at. One of them becomes the subject when an offer is accepted — until then the deal is named after your client.'
                    : 'Link the house being sold. The deal takes its name from the address.'
            "
            class="rounded-lg border bg-card"
        >
            <template #action>
                <AppButton @click="linking = true">Link a property</AppButton>
            </template>
        </EmptyState>

        <template v-else>
            <Card title="Subject property">
                <div
                    v-if="subject"
                    class="flex min-h-11 flex-wrap items-center gap-3 px-4 py-2.5"
                >
                    <span class="flex min-w-0 flex-1 flex-col">
                        <TextLink
                            v-if="subject.property"
                            :href="`/properties/${subject.propertyId}`"
                            class="truncate text-13 font-medium"
                            >{{ titleOf(subject) }}</TextLink
                        >
                        <span
                            v-else
                            class="truncate text-13 font-medium text-muted-foreground"
                            >{{ titleOf(subject) }}</span
                        >
                        <span
                            class="truncate text-[11px] text-muted-foreground"
                            >{{
                                [
                                    addressOf(subject).line2,
                                    subject.property
                                        ? formatPropertyFacts(subject.property)
                                        : '',
                                ]
                                    .filter(Boolean)
                                    .join(' · ')
                            }}</span
                        >
                    </span>
                    <StatusBadge
                        v-if="subject.property"
                        domain="property"
                        :state="subject.property.status"
                    />
                    <AppButton
                        variant="ghost"
                        size="compact"
                        @click="remove(subject)"
                        >Remove</AppButton
                    >
                </div>

                <!--
                    The state issue #62 asks for by name: a buyer deal with no
                    subject is normal, not broken. Saying which name the deal
                    is using meanwhile is what stops it reading as a gap.
                -->
                <!--
                    Branched, because a sell-side deal reaches this card too:
                    link two properties, remove the subject, and `unlink()`
                    deliberately does not promote a replacement by guess.
                -->
                <p v-else class="px-4 py-3 text-xs text-muted-foreground">
                    {{
                        deal.isBuySide
                            ? 'None yet. A buyer’s deal takes its name from the client until an offer is accepted — promote a candidate below when one is.'
                            : 'None yet. Promote the house being sold below, and the deal takes its name from the address.'
                    }}
                </p>
            </Card>

            <Card v-if="candidates.length > 0" title="Candidates">
                <ul class="flex flex-col">
                    <li
                        v-for="(link, index) in candidates"
                        :key="link.id"
                        class="flex min-h-11 flex-wrap items-center gap-3 border-b px-4 py-2.5 last:border-b-0"
                    >
                        <span
                            v-if="candidates.length > 1"
                            class="flex items-center"
                        >
                            <AppButton
                                variant="ghost"
                                size="compact"
                                :disabled="index === 0"
                                :aria-label="`Move ${titleOf(link)} up`"
                                @click="move(index, -1)"
                            >
                                <ArrowUp aria-hidden="true" />
                            </AppButton>
                            <AppButton
                                variant="ghost"
                                size="compact"
                                :disabled="index === candidates.length - 1"
                                :aria-label="`Move ${titleOf(link)} down`"
                                @click="move(index, 1)"
                            >
                                <ArrowDown aria-hidden="true" />
                            </AppButton>
                        </span>

                        <span class="flex min-w-0 flex-1 flex-col">
                            <TextLink
                                v-if="link.property"
                                :href="`/properties/${link.propertyId}`"
                                class="truncate text-13 font-medium"
                                >{{ titleOf(link) }}</TextLink
                            >
                            <span
                                v-else
                                class="truncate text-13 font-medium text-muted-foreground"
                                >{{ titleOf(link) }}</span
                            >
                            <span
                                class="truncate text-[11px] text-muted-foreground"
                                >{{
                                    [
                                        addressOf(link).line2,
                                        link.property
                                            ? formatPropertyFacts(link.property)
                                            : '',
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')
                                }}</span
                            >
                        </span>

                        <StatusBadge
                            v-if="link.interestStatus"
                            domain="propertyInterest"
                            :state="link.interestStatus"
                        />

                        <label v-if="deal.isBuySide" class="contents">
                            <span class="sr-only"
                                >Interest in {{ titleOf(link) }}</span
                            >
                            <AppSelect
                                :model-value="link.interestStatus"
                                :options="interestStatuses"
                                placeholder="Not said"
                                class="w-auto"
                                @update:model-value="
                                    (value) => setInterest(link, value)
                                "
                            />
                        </label>

                        <AppButton
                            variant="ghost"
                            size="compact"
                            @click="promote(link)"
                        >
                            <Star class="size-3.5" aria-hidden="true" />
                            Make subject
                        </AppButton>
                        <AppButton
                            variant="ghost"
                            size="compact"
                            @click="remove(link)"
                            >Remove</AppButton
                        >
                    </li>
                </ul>
            </Card>
        </template>
    </div>

    <LinkPropertyDialog v-model:open="linking" :deal-id="deal.id" />
</template>
