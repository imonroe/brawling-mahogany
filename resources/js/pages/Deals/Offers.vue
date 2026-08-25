<script setup lang="ts">
/**
 * S22 — a deal's offers (PRD §4.3 F3.6, §7.9 · issue #73).
 *
 * PRD §7.9 names the gap: *"Nothing covers offers or the chain of dates
 * governing a live transaction."*
 *
 * ## This is not the contract, and the screen says so
 *
 * PRD §2.2 confirms e-signature is unnecessary — Emily's market signs through
 * CTM — and §10 leaves the executed document and its security obligation
 * there. So there is no upload here, no signature, and a line under the
 * heading saying where the signed version lives. A team that thinks this is
 * the contract is a team that stops keeping the real one.
 *
 * ## Countered is a state, not a replacement
 *
 * #73 asks for *"multiple offers per deal, including counters, with a clear
 * current status"*. A counter that overwrote the row it answered would lose
 * the negotiation, so the countered offer stays and the counter is its own
 * row — which is why this is a list and not a single record.
 */
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppButton from '@/components/app/AppButton.vue';
import Card from '@/components/app/Card.vue';
import type { DealHeaderProps } from '@/components/app/DealHeader.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { usePermissions } from '@/composables/usePermissions';
import { formatCurrency, formatDateShort } from '@/lib/formatters';

type OfferRow = {
    id: string;
    direction: string;
    directionLabel: string;
    status: string;
    statusLabel: string;
    amount: number;
    earnestMoney: number | null;
    terms: string | null;
    contingencies: string[];
    submittedAt: string | null;
    expiresAt: string | null;
    propertyId: string | null;
    propertyLabel: string | null;
};

const props = defineProps<{
    dealHeader: DealHeaderProps;
    dealUrl: string;
    offers: OfferRow[];
    directions: Record<string, string>;
    statuses: Record<string, string>;
    properties: { id: string; label: string }[];
}>();

const { can } = usePermissions();

const adding = ref(false);

/**
 * Typed in dollars, stored in cents.
 *
 * The column is integer cents (ADR 0001) and `formatCurrency` reads cents, but
 * nobody types "48500000" to mean $485,000. The conversion happens once, here,
 * at the boundary between the two — the same place `PersonFormDialog` does it.
 */
const dollars = ref('');
const earnest = ref('');

const form = useForm({
    direction: 'received',
    status: 'submitted',
    amount: 0,
    earnest_money: null as number | null,
    terms: '',
    submitted_on: '',
    expires_on: '',
    property_id: null as string | null,
});

function cents(typed: string): number | null {
    const value = Number.parseFloat(typed.replace(/[$,\s]/g, ''));

    return Number.isFinite(value) ? Math.round(value * 100) : null;
}

function submit(): void {
    form.amount = cents(dollars.value) ?? 0;
    form.earnest_money = cents(earnest.value);

    form.post(`${props.dealUrl}/offers`, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            dollars.value = '';
            earnest.value = '';
            adding.value = false;
        },
    });
}

function setStatus(offer: OfferRow, status: string): void {
    router.patch(
        `${props.dealUrl}/offers/${offer.id}`,
        {
            direction: offer.direction,
            status,
            amount: offer.amount,
            earnest_money: offer.earnestMoney,
            terms: offer.terms,
            submitted_on: offer.submittedAt,
            expires_on: offer.expiresAt,
            property_id: offer.propertyId,
        },
        { preserveScroll: true },
    );
}

function remove(offer: OfferRow): void {
    // IA §10: name the object and the consequence.
    if (
        !window.confirm(
            `Remove this ${formatCurrency(offer.amount)} offer? The record of it goes with it.`,
        )
    ) {
        return;
    }

    router.delete(`${props.dealUrl}/offers/${offer.id}`, {
        preserveScroll: true,
    });
}

const accepted = computed(() =>
    props.offers.find((offer) => offer.status === 'accepted'),
);
</script>

<template>
    <Head title="Offers" />

    <div class="flex flex-col gap-4 p-4 md:p-6">
        <Card title="Offers">
            <template #action>
                <AppButton
                    v-if="can('deals.manage')"
                    size="compact"
                    @click="adding = !adding"
                    >{{ adding ? 'Cancel' : 'Record an offer' }}</AppButton
                >
            </template>

            <!--
                PRD §10, said once and where it matters. A team that believes
                this is the contract is a team that stops keeping the real one.
            -->
            <p
                class="border-b bg-muted px-4 py-2.5 text-xs text-muted-foreground"
            >
                Your working record of terms and dates. The signed contract
                lives in your e-signature platform — nothing here replaces it.
            </p>

            <form
                v-if="adding"
                class="flex flex-col gap-3 border-b px-4 py-4"
                @submit.prevent="submit"
            >
                <div class="flex flex-wrap gap-3">
                    <label class="flex flex-col gap-1.5">
                        <span class="text-xs font-medium">Direction</span>
                        <select
                            v-model="form.direction"
                            class="h-9 rounded-md border bg-background px-3 text-sm"
                        >
                            <option
                                v-for="(label, value) in directions"
                                :key="value"
                                :value="value"
                            >
                                {{ label }}
                            </option>
                        </select>
                    </label>

                    <label class="flex flex-col gap-1.5">
                        <span class="text-xs font-medium">Status</span>
                        <select
                            v-model="form.status"
                            class="h-9 rounded-md border bg-background px-3 text-sm"
                        >
                            <option
                                v-for="(label, value) in statuses"
                                :key="value"
                                :value="value"
                            >
                                {{ label }}
                            </option>
                        </select>
                    </label>

                    <label class="flex flex-col gap-1.5">
                        <span class="text-xs font-medium">Amount</span>
                        <input
                            v-model="dollars"
                            inputmode="decimal"
                            placeholder="485000"
                            class="h-9 w-36 rounded-md border bg-background px-3 text-sm"
                        />
                    </label>

                    <label class="flex flex-col gap-1.5">
                        <span class="text-xs font-medium">Earnest money</span>
                        <input
                            v-model="earnest"
                            inputmode="decimal"
                            placeholder="5000"
                            class="h-9 w-32 rounded-md border bg-background px-3 text-sm"
                        />
                    </label>

                    <label class="flex flex-col gap-1.5">
                        <span class="text-xs font-medium">Expires</span>
                        <input
                            v-model="form.expires_on"
                            type="date"
                            class="h-9 rounded-md border bg-background px-3 text-sm"
                        />
                    </label>

                    <label
                        v-if="properties.length > 0"
                        class="flex flex-col gap-1.5"
                    >
                        <span class="text-xs font-medium">Property</span>
                        <select
                            v-model="form.property_id"
                            class="h-9 rounded-md border bg-background px-3 text-sm"
                        >
                            <option :value="null">Not specified</option>
                            <option
                                v-for="property in properties"
                                :key="property.id"
                                :value="property.id"
                            >
                                {{ property.label }}
                            </option>
                        </select>
                    </label>
                </div>

                <p v-if="form.errors.amount" class="text-xs text-destructive">
                    {{ form.errors.amount }}
                </p>

                <div class="flex">
                    <AppButton :disabled="form.processing" @click="submit"
                        >Record offer</AppButton
                    >
                </div>
            </form>

            <EmptyState
                v-if="offers.length === 0"
                title="No offers yet"
                description="Record what was offered, by whom, and when it expires. This is your working record — the signed contract stays where you sign it."
            />

            <ul v-else class="flex flex-col">
                <li
                    v-for="offer in offers"
                    :key="offer.id"
                    class="flex flex-wrap items-center gap-3 border-b px-4 py-3 last:border-b-0"
                >
                    <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                        <span class="flex items-center gap-2">
                            <span class="text-13 font-medium text-foreground">{{
                                formatCurrency(offer.amount)
                            }}</span>
                            <span class="text-[11px] text-muted-foreground">{{
                                offer.directionLabel
                            }}</span>
                        </span>
                        <span class="text-[11px] text-muted-foreground">
                            <template v-if="offer.submittedAt"
                                >{{ formatDateShort(offer.submittedAt) }} ·
                            </template>
                            <template v-if="offer.earnestMoney"
                                >{{ formatCurrency(offer.earnestMoney) }}
                                earnest ·
                            </template>
                            <template v-if="offer.expiresAt"
                                >expires
                                {{ formatDateShort(offer.expiresAt) }}</template
                            >
                            <template v-else>no expiry recorded</template>
                            <template v-if="offer.propertyLabel">
                                · {{ offer.propertyLabel }}</template
                            >
                        </span>
                    </span>

                    <StatusBadge
                        tone="neutral"
                        :label="offer.statusLabel"
                        dotless
                    />

                    <template v-if="can('deals.manage')">
                        <AppButton
                            v-if="offer.status !== 'accepted'"
                            variant="ghost"
                            size="compact"
                            @click="setStatus(offer, 'accepted')"
                            >Accept</AppButton
                        >
                        <AppButton
                            variant="ghost"
                            size="compact"
                            @click="remove(offer)"
                            >Remove</AppButton
                        >
                    </template>
                </li>
            </ul>
        </Card>

        <!--
            Accepting one rejects the rest, in the same transaction — a deal
            with two accepted offers is a deal whose closing-date chain has two
            answers. Said here so the button's effect is not a surprise.
        -->
        <p v-if="accepted" class="text-xs text-muted-foreground">
            One offer is accepted. Accepting another rejects it.
        </p>
    </div>
</template>
