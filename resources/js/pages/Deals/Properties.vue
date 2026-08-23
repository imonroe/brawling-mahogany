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
import { Home, Plus, Star } from '@lucide/vue';
import { computed, ref } from 'vue';
import AppButton from '@/components/app/AppButton.vue';
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

function setInterest(link: DealPropertyLink, value: string): void {
    // An empty select value means "nobody has said", which is a real state
    // and different from Interested.
    interest.interest_status = value === '' ? null : value;

    interest.patch(`/deals/${props.deal.id}/properties/${link.id}`, {
        preserveScroll: true,
    });
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
                <p v-else class="px-4 py-3 text-xs text-muted-foreground">
                    None yet. A buyer’s deal takes its name from the client
                    until an offer is accepted — promote a candidate below when
                    one is.
                </p>
            </Card>

            <Card v-if="candidates.length > 0" title="Candidates">
                <ul class="flex flex-col">
                    <li
                        v-for="link in candidates"
                        :key="link.id"
                        class="flex min-h-11 flex-wrap items-center gap-3 border-b px-4 py-2.5 last:border-b-0"
                    >
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
                            <select
                                :value="link.interestStatus ?? ''"
                                class="h-8 rounded-md border bg-background px-2.5 text-xs"
                                @change="
                                    setInterest(
                                        link,
                                        ($event.target as HTMLSelectElement)
                                            .value,
                                    )
                                "
                            >
                                <option value="">Not said</option>
                                <option
                                    v-for="(label, value) in interestStatuses"
                                    :key="value"
                                    :value="value"
                                >
                                    {{ label }}
                                </option>
                            </select>
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
