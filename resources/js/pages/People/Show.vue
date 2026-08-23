<script setup lang="ts">
/**
 * S31 — a person, as this team knows them.
 *
 * Key states from the inventory, all of them real rather than decorative:
 * the contact log, related deals (Slice 2), **no login**, past client, and the
 * vendor fields. The "no login" line matters — PRD F2.1 makes credentials the
 * exception, and a screen that implies an account exists sends somebody
 * looking for a password reset that will never arrive.
 */
import { Head, router } from '@inertiajs/vue3';
import { MessageSquarePlus, Pencil } from '@lucide/vue';
import { computed, ref } from 'vue';
import ActivityItem from '@/components/app/ActivityItem.vue';
import AppButton from '@/components/app/AppButton.vue';
import Card from '@/components/app/Card.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import LogContactDialog from '@/components/app/LogContactDialog.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import PersonAvatar from '@/components/app/PersonAvatar.vue';
import PersonFormDialog from '@/components/app/PersonFormDialog.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { usePermissions } from '@/composables/usePermissions';
import { activityDescriptor } from '@/lib/activity';
import {
    formatCurrency,
    formatDate,
    formatDateTime,
    formatPersonName,
} from '@/lib/formatters';
import type { ActivityFeedRow, LoggableDeal, PersonDetail } from '@/types';

const props = defineProps<{
    membership: PersonDetail;
    activity: ActivityFeedRow[];
    /** Deals this person is on, for the modal's optional attachment (F2.5). */
    deals: LoggableDeal[];
    lifecycleStates: Record<string, string>;
}>();

const { can } = usePermissions();

const editing = ref(false);
const logging = ref(false);

const name = computed(() => formatPersonName(props.membership));

/**
 * The same decoration the feed does, from the same table.
 *
 * This screen used to render its own list of contact types and its own row
 * layout, which meant a phone call looked like one thing here and another on
 * S12. Both now go through `lib/activity.ts` and `ActivityItem`.
 */
const entries = computed(() =>
    props.activity.map((event) => ({
        event,
        ...activityDescriptor(event),
        time: formatDateTime(event.occurredAt),
    })),
);

/** The person this screen is about — the modal never has to ask. */
const subject = computed(() => ({ id: props.membership.id, name: name.value }));

function remove(): void {
    // IA §10: name the object and the consequence.
    if (
        !window.confirm(
            `Remove ${name.value} from this team? Their record stays with any other team that knows them, and your notes about them are deleted.`,
        )
    ) {
        return;
    }

    router.delete(`/people/${props.membership.id}`);
}
</script>

<template>
    <Head :title="name" />

    <div class="flex flex-col gap-4 p-4 md:p-6">
        <PageHeader
            :title="name"
            :subtitle="membership.email ?? membership.phone"
        >
            <template #actions>
                <AppButton
                    v-if="can('people.manage')"
                    variant="ghost"
                    @click="logging = true"
                >
                    <MessageSquarePlus class="size-4" aria-hidden="true" />
                    Log contact
                </AppButton>
                <AppButton v-if="can('people.manage')" @click="editing = true">
                    <Pencil class="size-4" aria-hidden="true" />
                    Edit
                </AppButton>
            </template>
        </PageHeader>

        <div class="grid gap-4 lg:grid-cols-[320px_1fr]">
            <div class="flex flex-col gap-4">
                <Card title="Details">
                    <div class="flex items-center gap-3 px-4 py-3">
                        <PersonAvatar :person="membership" :size="46" />
                        <div class="flex min-w-0 flex-col gap-1">
                            <StatusBadge
                                domain="person"
                                :state="membership.status"
                            />
                            <p
                                v-if="!membership.hasLogin"
                                class="text-[11px] text-muted-foreground"
                            >
                                No login. This person can’t sign in — most
                                people here can’t.
                            </p>
                        </div>
                    </div>
                    <dl class="flex flex-col border-t">
                        <div class="flex gap-3 px-4 py-2.5">
                            <dt class="w-24 text-[11px] text-muted-foreground">
                                Email
                            </dt>
                            <dd class="min-w-0 flex-1 truncate text-13">
                                {{ membership.email ?? '—' }}
                            </dd>
                        </div>
                        <div class="flex gap-3 border-t px-4 py-2.5">
                            <dt class="w-24 text-[11px] text-muted-foreground">
                                Phone
                            </dt>
                            <dd class="min-w-0 flex-1 truncate text-13">
                                {{ membership.phone ?? '—' }}
                            </dd>
                        </div>
                        <div
                            v-if="membership.joinedAt"
                            class="flex gap-3 border-t px-4 py-2.5"
                        >
                            <dt class="w-24 text-[11px] text-muted-foreground">
                                Known since
                            </dt>
                            <dd class="min-w-0 flex-1 truncate text-13">
                                {{ formatDate(membership.joinedAt) }}
                            </dd>
                        </div>
                    </dl>
                </Card>

                <Card v-if="membership.isVendor" title="Vendor">
                    <dl class="flex flex-col">
                        <div class="flex gap-3 px-4 py-2.5">
                            <dt class="w-24 text-[11px] text-muted-foreground">
                                Specialties
                            </dt>
                            <dd class="min-w-0 flex-1 text-13">
                                {{
                                    membership.vendor.specialties.join(', ') ||
                                    '—'
                                }}
                            </dd>
                        </div>
                        <div class="flex gap-3 border-t px-4 py-2.5">
                            <dt class="w-24 text-[11px] text-muted-foreground">
                                Typical cost
                            </dt>
                            <dd class="tabular min-w-0 flex-1 text-13">
                                <!--
                                    `formatCurrency` takes **cents**, and the
                                    column is cents (ADR 0001). Converting
                                    here would divide twice and quietly show
                                    a $1,200 stager as $12.00.
                                -->
                                {{
                                    membership.vendor.typicalCost === null
                                        ? '—'
                                        : formatCurrency(
                                              membership.vendor.typicalCost,
                                          )
                                }}
                            </dd>
                        </div>
                        <div class="flex gap-3 border-t px-4 py-2.5">
                            <dt class="w-24 text-[11px] text-muted-foreground">
                                Service area
                            </dt>
                            <dd class="min-w-0 flex-1 text-13">
                                {{ membership.vendor.serviceArea ?? '—' }}
                            </dd>
                        </div>
                        <div class="flex gap-3 border-t px-4 py-2.5">
                            <dt class="w-24 text-[11px] text-muted-foreground">
                                Rating
                            </dt>
                            <dd class="tabular min-w-0 flex-1 text-13">
                                {{
                                    membership.vendor.rating === null
                                        ? '—'
                                        : `${membership.vendor.rating} of 5`
                                }}
                            </dd>
                        </div>
                    </dl>
                </Card>

                <Card v-if="membership.notes" title="Team notes">
                    <p class="px-4 py-3 text-13 whitespace-pre-line">
                        {{ membership.notes }}
                    </p>
                </Card>

                <div v-if="can('people.manage')" class="flex">
                    <AppButton variant="ghost" @click="remove"
                        >Remove from team</AppButton
                    >
                </div>
            </div>

            <Card title="Contact Log">
                <EmptyState
                    v-if="entries.length === 0"
                    title="Nothing logged yet"
                    description="Every call, email, and showing you log shows up here, newest first."
                >
                    <template v-if="can('people.manage')" #action>
                        <AppButton @click="logging = true"
                            >Log contact</AppButton
                        >
                    </template>
                </EmptyState>

                <ol v-else class="flex flex-col divide-y">
                    <li
                        v-for="entry in entries"
                        :key="entry.event.id"
                        class="px-4"
                    >
                        <ActivityItem
                            :icon="entry.icon"
                            :tone="entry.tone"
                            :text="entry.event.summary"
                            :time="entry.time"
                        >
                            <p
                                v-if="entry.event.note"
                                class="mt-0.5 text-13 text-muted-foreground"
                            >
                                {{ entry.event.note }}
                            </p>
                            <p
                                v-if="entry.event.deal || entry.event.actorName"
                                class="mt-0.5 flex flex-wrap items-center gap-x-2 text-[11px] text-muted-foreground"
                            >
                                <!--
                                    The deal a contact was attached to (F2.5).
                                    Named, not linked: a deal has no detail
                                    screen yet (S15 is #78).
                                -->
                                <span v-if="entry.event.deal">{{
                                    entry.event.deal.label
                                }}</span>
                                <span v-if="entry.event.actorName">{{
                                    entry.event.actorName
                                }}</span>
                            </p>
                        </ActivityItem>
                    </li>
                </ol>
            </Card>
        </div>
    </div>

    <PersonFormDialog
        v-model:open="editing"
        :membership="membership"
        :lifecycle-states="lifecycleStates"
    />

    <!--
        S26, with the person already known — which is what makes it the
        two-click target the inventory asks for.
    -->
    <LogContactDialog
        v-model:open="logging"
        :membership="subject"
        :deals="deals"
    />
</template>
