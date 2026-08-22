<script setup lang="ts">
/**
 * S32 — create and edit a person, in a modal.
 *
 * The state that makes this screen more than a form is the **duplicate email
 * warning**: an address already in this team's directory produces a warning
 * and an offer to open the existing record, not a hard failure. A person who
 * types a client's address twice should be told, not stopped.
 *
 * The vendor fields appear only when the vendor flag is on. IA §13.3 settled
 * Vendor as a flag rather than a lifecycle value precisely because somebody
 * can be a past client and a vendor at once.
 */
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppButton from '@/components/app/AppButton.vue';
import AppInput from '@/components/app/AppInput.vue';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import type { PersonDetail } from '@/types';

const props = defineProps<{
    open: boolean;
    lifecycleStates: Record<string, string>;
    /** Absent when creating. */
    membership?: PersonDetail | null;
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();

const editing = computed(() => Boolean(props.membership));

const form = useForm({
    first_name: props.membership?.firstName ?? '',
    last_name: props.membership?.lastName ?? '',
    email: props.membership?.email ?? '',
    phone: props.membership?.phone ?? '',
    status: props.membership?.status ?? 'lead',
    notes: props.membership?.notes ?? '',
    is_vendor: props.membership?.isVendor ?? false,
    vendor_specialties: props.membership?.vendor.specialties ?? [],
    vendor_typical_cost: props.membership?.vendor.typicalCost ?? null,
    vendor_service_area: props.membership?.vendor.serviceArea ?? '',
    vendor_rating: props.membership?.vendor.rating ?? null,
    vendor_notes: props.membership?.vendor.notes ?? '',
});

const duplicate = ref<{ id: string; name: string; url: string } | null>(null);

let lookupTimer: ReturnType<typeof setTimeout> | undefined;

watch(
    () => form.email,
    (email) => {
        clearTimeout(lookupTimer);
        duplicate.value = null;

        // Editing this person's own record is not a duplicate of itself.
        if (editing.value || !email.includes('@')) {
            return;
        }

        lookupTimer = setTimeout(() => {
            void fetch(`/people/lookup?email=${encodeURIComponent(email)}`, {
                headers: { Accept: 'application/json' },
            })
                .then((response) =>
                    response.ok ? response.json() : { duplicate: null },
                )
                .then((body: { duplicate: typeof duplicate.value }) => {
                    duplicate.value = body.duplicate;
                });
        }, 350);
    },
);

const specialtiesText = computed({
    get: () => form.vendor_specialties.join(', '),
    set: (value: string) => {
        form.vendor_specialties = value
            .split(',')
            .map((item) => item.trim())
            .filter(Boolean);
    },
});

function submit(): void {
    if (editing.value && props.membership) {
        form.patch(`/people/${props.membership.id}`, {
            onSuccess: () => emit('update:open', false),
        });

        return;
    }

    form.post('/people', {
        onSuccess: () => {
            form.reset();
            emit('update:open', false);
        },
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="(value) => emit('update:open', value)">
        <DialogContent class="max-h-[85svh] overflow-y-auto sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{
                    editing ? 'Edit person' : 'Add person'
                }}</DialogTitle>
                <DialogDescription>
                    Names, contact details, and what this team knows about them.
                    Notes stay with your team.
                </DialogDescription>
            </DialogHeader>

            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="flex flex-col gap-1.5">
                        <Label for="first_name">First name</Label>
                        <AppInput
                            id="first_name"
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
                        <Label for="last_name">Last name</Label>
                        <AppInput id="last_name" v-model="form.last_name" />
                    </div>
                </div>

                <div class="flex flex-col gap-1.5">
                    <Label for="email">Email</Label>
                    <AppInput
                        id="email"
                        v-model="form.email"
                        type="email"
                        autocomplete="off"
                    />
                    <p
                        v-if="duplicate"
                        class="text-[11px] text-muted-foreground"
                    >
                        {{ duplicate.name }} already has this address in your
                        directory.
                        <button
                            type="button"
                            class="font-semibold underline"
                            @click="router.visit(duplicate.url)"
                        >
                            Open their record
                        </button>
                        — or carry on, and we’ll add this to the same person.
                    </p>
                    <p
                        v-if="form.errors.email"
                        class="text-[11px] text-state-danger"
                    >
                        {{ form.errors.email }}
                    </p>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="flex flex-col gap-1.5">
                        <Label for="phone">Phone</Label>
                        <AppInput id="phone" v-model="form.phone" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="status">Status</Label>
                        <select
                            id="status"
                            v-model="form.status"
                            class="h-11 rounded-md border bg-background px-3 text-base md:h-10 md:text-sm"
                        >
                            <option
                                v-for="(label, value) in lifecycleStates"
                                :key="value"
                                :value="value"
                            >
                                {{ label }}
                            </option>
                        </select>
                    </div>
                </div>

                <div class="flex flex-col gap-1.5">
                    <Label for="notes">Notes</Label>
                    <textarea
                        id="notes"
                        v-model="form.notes"
                        rows="3"
                        class="rounded-md border bg-background p-[11px] text-base md:text-sm"
                    ></textarea>
                    <p class="text-[11px] text-muted-foreground">
                        Only your team can read this. Another team who knows
                        this person never sees it.
                    </p>
                </div>

                <div class="flex items-center gap-2">
                    <Checkbox
                        id="is_vendor"
                        :model-value="form.is_vendor"
                        @update:model-value="
                            (value) => (form.is_vendor = value === true)
                        "
                    />
                    <Label for="is_vendor">This person is a vendor</Label>
                </div>

                <div
                    v-if="form.is_vendor"
                    class="flex flex-col gap-3 rounded-md border p-3"
                >
                    <div class="flex flex-col gap-1.5">
                        <Label for="vendor_specialties">Specialties</Label>
                        <AppInput
                            id="vendor_specialties"
                            v-model="specialtiesText"
                            placeholder="staging, photography"
                        />
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="flex flex-col gap-1.5">
                            <Label for="vendor_service_area"
                                >Service area</Label
                            >
                            <AppInput
                                id="vendor_service_area"
                                v-model="form.vendor_service_area"
                            />
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label for="vendor_rating">Rating (1–5)</Label>
                            <AppInput
                                id="vendor_rating"
                                v-model="form.vendor_rating"
                                type="text"
                                inputmode="numeric"
                            />
                        </div>
                    </div>
                </div>

                <DialogFooter>
                    <AppButton
                        variant="ghost"
                        type="button"
                        @click="emit('update:open', false)"
                        >Cancel</AppButton
                    >
                    <AppButton type="submit" :disabled="form.processing">{{
                        editing ? 'Save changes' : 'Add person'
                    }}</AppButton>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
