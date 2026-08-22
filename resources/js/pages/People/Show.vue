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
import { Head, router, useForm } from '@inertiajs/vue3';
import { MessageSquarePlus, Pencil } from '@lucide/vue';
import { computed, ref } from 'vue';
import AppButton from '@/components/app/AppButton.vue';
import AppInput from '@/components/app/AppInput.vue';
import Card from '@/components/app/Card.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import PersonAvatar from '@/components/app/PersonAvatar.vue';
import PersonFormDialog from '@/components/app/PersonFormDialog.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/composables/usePermissions';
import {
    formatCurrency,
    formatDate,
    formatPersonName,
    formatTime,
} from '@/lib/formatters';
import type { ActivityEntry, PersonDetail } from '@/types';

const props = defineProps<{
    membership: PersonDetail;
    activity: ActivityEntry[];
    lifecycleStates: Record<string, string>;
}>();

const { can } = usePermissions();

const editing = ref(false);
const logging = ref(false);

const name = computed(() => formatPersonName(props.membership));

const contactLog = useForm({
    contact_type: 'phone_call',
    note: '',
});

const CONTACT_TYPES: Record<string, string> = {
    phone_call: 'Phone call',
    email: 'Email',
    text: 'Text',
    meeting: 'Meeting',
    showing: 'Showing',
    other: 'Other',
};

function logContact(): void {
    contactLog.post(`/people/${props.membership.id}/contact-log`, {
        preserveScroll: true,
        onSuccess: () => {
            contactLog.reset();
            logging.value = false;
        },
    });
}

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
                    @click="logging = !logging"
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
                <form
                    v-if="logging"
                    class="flex flex-col gap-3 border-b px-4 py-3"
                    @submit.prevent="logContact"
                >
                    <div class="flex flex-col gap-1.5">
                        <Label for="contact_type">What happened</Label>
                        <select
                            id="contact_type"
                            v-model="contactLog.contact_type"
                            class="h-11 rounded-md border bg-background px-3 text-base md:h-10 md:text-sm"
                        >
                            <option
                                v-for="(label, value) in CONTACT_TYPES"
                                :key="value"
                                :value="value"
                            >
                                {{ label }}
                            </option>
                        </select>
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="note">Note</Label>
                        <AppInput
                            id="note"
                            v-model="contactLog.note"
                            placeholder="Walked through the listing timeline."
                        />
                    </div>
                    <div class="flex justify-end gap-2">
                        <AppButton
                            variant="ghost"
                            type="button"
                            @click="logging = false"
                            >Cancel</AppButton
                        >
                        <AppButton
                            type="submit"
                            :disabled="contactLog.processing"
                            >Log it</AppButton
                        >
                    </div>
                </form>

                <EmptyState
                    v-if="activity.length === 0"
                    title="Nothing logged yet"
                    description="Every call, email, and showing you log shows up here, newest first."
                />

                <ol v-else class="flex flex-col">
                    <li
                        v-for="entry in activity"
                        :key="entry.id"
                        class="flex flex-col gap-0.5 border-b px-4 py-2.5 last:border-b-0"
                    >
                        <div class="flex items-baseline gap-2">
                            <span class="text-13 font-medium text-foreground">{{
                                entry.summary
                            }}</span>
                            <span
                                class="tabular text-[11px] text-muted-foreground"
                                >{{ formatDate(entry.occurredAt) }},
                                {{ formatTime(entry.occurredAt) }}</span
                            >
                        </div>
                        <p
                            v-if="entry.payload?.note"
                            class="text-13 text-muted-foreground"
                        >
                            {{ entry.payload.note }}
                        </p>
                        <p
                            v-if="entry.actorName"
                            class="text-[11px] text-muted-foreground"
                        >
                            {{ entry.actorName }}
                        </p>
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
</template>
