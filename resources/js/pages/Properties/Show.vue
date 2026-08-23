<script setup lang="ts">
/**
 * S36 — property detail.
 *
 * The Screen Inventory lists four key states, and three of them are here: no
 * photos, linked deals, and external links. The gallery itself is S38 (F6.5)
 * and a separate issue, so this screen says where it will be rather than
 * pretending it is not coming — a section that quietly did not exist would
 * read as a bug to whoever looks for it.
 *
 * **Links open safely.** `rel="noopener noreferrer"` on every one, because
 * `target="_blank"` without it hands the opened page a `window.opener` handle
 * back to this one. The scheme is checked on the server as well (`SafeUrl`):
 * an attribute on a link somebody else typed is not somewhere to be trusting.
 */
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ExternalLink as ExternalLinkIcon,
    Images,
    Link2,
    Pencil,
    Trash2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import AppButton from '@/components/app/AppButton.vue';
import Card from '@/components/app/Card.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import LinkDealDialog from '@/components/app/LinkDealDialog.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import PropertyFormDialog from '@/components/app/PropertyFormDialog.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import {
    formatAddress,
    formatCount,
    formatPropertyFacts,
} from '@/lib/formatters';
import type { ExternalLinkRow, LinkedDeal, PropertyDetail } from '@/types';

const props = defineProps<{
    property: PropertyDetail;
    links: ExternalLinkRow[];
    deals: LinkedDeal[];
    propertyTypes: Record<string, string>;
    propertyStatuses: Record<string, string>;
    can: { update: boolean; link: boolean };
}>();

const editing = ref(false);
const linking = ref(false);

const address = computed(() => formatAddress(props.property.address));

const facts = computed(() => formatPropertyFacts(props.property));

function unlink(deal: LinkedDeal): void {
    router.delete(`/properties/${props.property.id}/deals/${deal.id}`, {
        preserveScroll: true,
    });
}

function destroy(): void {
    if (
        !window.confirm(
            `Delete ${props.property.name}? It comes off ${formatCount(props.deals.length, 'deal')} and can be restored for 30 days.`,
        )
    ) {
        return;
    }

    router.delete(`/properties/${props.property.id}`);
}
</script>

<template>
    <Head :title="property.name" />

    <div class="flex flex-col gap-4 p-4 md:p-6">
        <PageHeader
            :title="address.line1 || property.name"
            :subtitle="address.line2 || property.typeLabel"
        >
            <template #actions>
                <AppButton
                    v-if="can.link"
                    variant="ghost"
                    @click="linking = true"
                >
                    <Link2 class="size-4" aria-hidden="true" />
                    Link a deal
                </AppButton>
                <AppButton
                    v-if="can.update"
                    variant="ghost"
                    @click="editing = true"
                >
                    <Pencil class="size-4" aria-hidden="true" />
                    Edit
                </AppButton>
                <AppButton v-if="can.update" variant="ghost" @click="destroy">
                    <Trash2 class="size-4" aria-hidden="true" />
                    Delete
                </AppButton>
            </template>
        </PageHeader>

        <div class="flex flex-wrap items-center gap-2">
            <StatusBadge domain="property" :state="property.status" />
            <StatusBadge tone="neutral" :label="property.typeLabel" dotless />
            <p v-if="facts" class="text-xs text-muted-foreground">
                {{ facts }}
            </p>
        </div>

        <div class="grid gap-4 lg:grid-cols-[2fr_1fr]">
            <div class="flex flex-col gap-4">
                <Card title="Photos">
                    <EmptyState
                        :icon="Images"
                        title="No photos yet"
                        description="The gallery lands with S38. Until then, a link to the listing is the fastest way back to the pictures."
                    />
                </Card>

                <Card title="Deals">
                    <template #action>
                        <AppButton
                            v-if="can.link"
                            variant="ghost"
                            @click="linking = true"
                            >Link a deal</AppButton
                        >
                    </template>

                    <EmptyState
                        v-if="deals.length === 0"
                        :icon="Link2"
                        title="Not on a deal yet"
                        description="Link this property to a deal and it shows up on both. A house can be on more than one over time — a listing that fell through and the sale that followed are both worth keeping."
                    />

                    <ul v-else class="flex flex-col">
                        <li
                            v-for="deal in deals"
                            :key="deal.id"
                            class="flex min-h-11 items-center gap-3 border-b px-4 py-2.5 last:border-b-0"
                        >
                            <Link
                                :href="`/deals/${deal.dealId}/people`"
                                class="flex min-w-0 flex-1 flex-col"
                            >
                                <span
                                    class="truncate text-13 font-medium text-foreground"
                                    >{{ deal.name }}</span
                                >
                                <span
                                    class="truncate text-[11px] text-muted-foreground"
                                    >{{ deal.sideLabel }}</span
                                >
                            </Link>
                            <StatusBadge
                                v-if="deal.isSubject"
                                tone="info"
                                label="Subject"
                                dotless
                            />
                            <StatusBadge domain="deal" :state="deal.state" />
                            <AppButton
                                v-if="can.link"
                                variant="ghost"
                                :aria-label="`Remove ${deal.name}`"
                                @click="unlink(deal)"
                                >Remove</AppButton
                            >
                        </li>
                    </ul>
                </Card>

                <Card v-if="property.notes" title="Notes">
                    <p
                        class="px-4 py-3 text-sm whitespace-pre-line text-foreground"
                    >
                        {{ property.notes }}
                    </p>
                </Card>
            </div>

            <div class="flex flex-col gap-4">
                <Card title="Links">
                    <EmptyState
                        v-if="links.length === 0"
                        :icon="ExternalLinkIcon"
                        title="No links yet"
                        description="The listing, the county assessor, a virtual tour. We keep the link, never a copy of what is on the other end."
                    />

                    <ul v-else class="flex flex-col">
                        <li
                            v-for="link in links"
                            :key="link.id ?? link.url"
                            class="border-b last:border-b-0"
                        >
                            <a
                                :href="link.url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="flex min-h-11 items-center gap-2 px-4 py-2.5 transition-colors duration-150 ease-out hover:bg-accent/60"
                            >
                                <span class="flex min-w-0 flex-1 flex-col">
                                    <span
                                        class="truncate text-13 font-medium text-foreground"
                                        >{{ link.label }}</span
                                    >
                                    <span
                                        class="truncate text-[11px] text-muted-foreground"
                                        >{{ link.url }}</span
                                    >
                                </span>
                                <ExternalLinkIcon
                                    class="size-3.5 text-muted-foreground"
                                    aria-hidden="true"
                                />
                            </a>
                        </li>
                    </ul>
                </Card>

                <Card title="Details">
                    <dl class="flex flex-col">
                        <div
                            class="flex items-center justify-between gap-2 border-b px-4 py-2.5 last:border-b-0"
                        >
                            <dt class="text-[11px] text-muted-foreground">
                                Status
                            </dt>
                            <dd class="text-13 text-foreground">
                                {{ property.statusLabel }}
                            </dd>
                        </div>
                        <div
                            class="flex items-center justify-between gap-2 border-b px-4 py-2.5 last:border-b-0"
                        >
                            <dt class="text-[11px] text-muted-foreground">
                                Type
                            </dt>
                            <dd class="text-13 text-foreground">
                                {{ property.typeLabel }}
                            </dd>
                        </div>
                        <div
                            v-if="property.parcelNumber"
                            class="flex items-center justify-between gap-2 border-b px-4 py-2.5 last:border-b-0"
                        >
                            <dt class="text-[11px] text-muted-foreground">
                                Parcel
                            </dt>
                            <dd class="tabular text-13 text-foreground">
                                {{ property.parcelNumber }}
                            </dd>
                        </div>
                    </dl>
                </Card>
            </div>
        </div>
    </div>

    <PropertyFormDialog
        v-model:open="editing"
        :property="property"
        :links="links"
        :property-types="propertyTypes"
        :property-statuses="propertyStatuses"
    />

    <LinkDealDialog v-model:open="linking" :property-id="property.id" />
</template>
