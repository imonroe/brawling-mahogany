<script setup lang="ts">
/**
 * S37 — create and edit a property, in a modal.
 *
 * The half that makes this more than an address form is the **links**
 * repeater. PRD §7.13 replaced per-site URL columns with labelled rows, so
 * adding the twelfth site an agent cares about happens here rather than in a
 * migration.
 *
 * And what it deliberately cannot do: PRD §10 permits storing the link and
 * never the listing behind it. There is no "fetch details" button, and adding
 * one would be a licensing decision rather than a convenience.
 */
import { useForm } from '@inertiajs/vue3';
import { Plus, X } from '@lucide/vue';
import { computed } from 'vue';
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
import type { ExternalLinkRow, PropertyDetail } from '@/types';

const props = defineProps<{
    open: boolean;
    propertyTypes: Record<string, string>;
    propertyStatuses: Record<string, string>;
    /** Absent when creating. */
    property?: PropertyDetail | null;
    links?: ExternalLinkRow[];
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();

const editing = computed(() => Boolean(props.property));

const form = useForm({
    street: props.property?.address.street ?? '',
    unit: props.property?.address.unit ?? '',
    city: props.property?.address.city ?? '',
    state_code: props.property?.address.state ?? '',
    postal_code: props.property?.address.postalCode ?? '',
    parcel_number: props.property?.parcelNumber ?? '',
    type: props.property?.type ?? 'single_family',
    status: props.property?.status ?? 'pre_listing',
    beds: props.property?.beds ?? null,
    baths: props.property?.baths ?? '',
    sqft: props.property?.sqft ?? null,
    year_built: props.property?.yearBuilt ?? null,
    notes: props.property?.notes ?? '',
    links: (props.links ?? []).map((link) => ({ ...link })),
});

function addLink(): void {
    form.links.push({ id: null, label: '', url: '' });
}

function removeLink(index: number): void {
    form.links.splice(index, 1);
}

/**
 * Per-row errors, which Laravel keys as `links.0.url`.
 *
 * Read through a helper rather than in the template so a missing key is an
 * empty string rather than a Vue warning about an index expression.
 */
function linkError(index: number, field: 'label' | 'url'): string | undefined {
    return (form.errors as Record<string, string | undefined>)[
        `links.${index}.${field}`
    ];
}

function submit(): void {
    if (editing.value && props.property) {
        form.patch(`/properties/${props.property.id}`, {
            onSuccess: () => emit('update:open', false),
        });

        return;
    }

    form.post('/properties', {
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
                    editing ? 'Edit property' : 'Add property'
                }}</DialogTitle>
                <DialogDescription>
                    Where it is, what it is, and where to read more about it.
                    Every field is optional except the type and the status — a
                    property often starts as a parcel number.
                </DialogDescription>
            </DialogHeader>

            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <div class="grid gap-3 sm:grid-cols-[2fr_1fr]">
                    <div class="flex flex-col gap-1.5">
                        <Label for="street">Street</Label>
                        <AppInput id="street" v-model="form.street" />
                        <p
                            v-if="form.errors.street"
                            class="text-[11px] text-state-danger"
                        >
                            {{ form.errors.street }}
                        </p>
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="unit">Unit</Label>
                        <AppInput id="unit" v-model="form.unit" />
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-[2fr_1fr_1fr]">
                    <div class="flex flex-col gap-1.5">
                        <Label for="city">City</Label>
                        <AppInput id="city" v-model="form.city" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="state_code">State</Label>
                        <AppInput
                            id="state_code"
                            v-model="form.state_code"
                            maxlength="2"
                            placeholder="CO"
                        />
                        <p
                            v-if="form.errors.state_code"
                            class="text-[11px] text-state-danger"
                        >
                            {{ form.errors.state_code }}
                        </p>
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="postal_code">ZIP</Label>
                        <AppInput id="postal_code" v-model="form.postal_code" />
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="flex flex-col gap-1.5">
                        <Label for="type">Type</Label>
                        <select
                            id="type"
                            v-model="form.type"
                            class="h-11 rounded-md border bg-background px-3 text-base md:h-10 md:text-sm"
                        >
                            <option
                                v-for="(label, value) in propertyTypes"
                                :key="value"
                                :value="value"
                            >
                                {{ label }}
                            </option>
                        </select>
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="status">Status</Label>
                        <select
                            id="status"
                            v-model="form.status"
                            class="h-11 rounded-md border bg-background px-3 text-base md:h-10 md:text-sm"
                        >
                            <option
                                v-for="(label, value) in propertyStatuses"
                                :key="value"
                                :value="value"
                            >
                                {{ label }}
                            </option>
                        </select>
                        <p class="text-[11px] text-muted-foreground">
                            Where the house is with the market. How the work is
                            going belongs to the deal’s stages.
                        </p>
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-4">
                    <div class="flex flex-col gap-1.5">
                        <Label for="beds">Beds</Label>
                        <AppInput
                            id="beds"
                            v-model="form.beds"
                            inputmode="numeric"
                        />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="baths">Baths</Label>
                        <AppInput
                            id="baths"
                            v-model="form.baths"
                            inputmode="decimal"
                            placeholder="2.5"
                        />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="sqft">Sqft</Label>
                        <AppInput
                            id="sqft"
                            v-model="form.sqft"
                            inputmode="numeric"
                        />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="year_built">Built</Label>
                        <AppInput
                            id="year_built"
                            v-model="form.year_built"
                            inputmode="numeric"
                        />
                    </div>
                </div>

                <div class="flex flex-col gap-1.5">
                    <Label for="parcel_number">Parcel number</Label>
                    <AppInput id="parcel_number" v-model="form.parcel_number" />
                    <p
                        v-if="form.errors.parcel_number"
                        class="text-[11px] text-state-danger"
                    >
                        {{ form.errors.parcel_number }}
                    </p>
                </div>

                <div class="flex flex-col gap-2 rounded-md border p-3">
                    <div class="flex items-center gap-2">
                        <h4 class="text-13 font-semibold text-foreground">
                            Links
                        </h4>
                        <div class="flex-1"></div>
                        <AppButton
                            variant="ghost"
                            type="button"
                            @click="addLink"
                        >
                            <Plus class="size-4" aria-hidden="true" />
                            Add link
                        </AppButton>
                    </div>
                    <p class="text-[11px] text-muted-foreground">
                        The listing, the county assessor, a virtual tour —
                        anywhere worth going back to. We keep the link, never a
                        copy of what is on the other end.
                    </p>

                    <div
                        v-for="(link, index) in form.links"
                        :key="index"
                        class="grid gap-2 sm:grid-cols-[1fr_2fr_auto]"
                    >
                        <div class="flex flex-col gap-1">
                            <AppInput
                                v-model="link.label"
                                :aria-label="`Link ${index + 1} label`"
                                placeholder="Zillow"
                            />
                            <p
                                v-if="linkError(index, 'label')"
                                class="text-[11px] text-state-danger"
                            >
                                {{ linkError(index, 'label') }}
                            </p>
                        </div>
                        <div class="flex flex-col gap-1">
                            <AppInput
                                v-model="link.url"
                                :aria-label="`Link ${index + 1} address`"
                                placeholder="https://…"
                            />
                            <p
                                v-if="linkError(index, 'url')"
                                class="text-[11px] text-state-danger"
                            >
                                {{ linkError(index, 'url') }}
                            </p>
                        </div>
                        <AppButton
                            variant="ghost"
                            type="button"
                            :aria-label="`Remove link ${index + 1}`"
                            @click="removeLink(index)"
                        >
                            <X class="size-4" aria-hidden="true" />
                        </AppButton>
                    </div>
                </div>

                <div class="flex flex-col gap-1.5">
                    <Label for="property_notes">Notes</Label>
                    <textarea
                        id="property_notes"
                        v-model="form.notes"
                        rows="3"
                        class="rounded-md border bg-background p-[11px] text-base md:text-sm"
                    ></textarea>
                </div>

                <DialogFooter>
                    <AppButton
                        variant="ghost"
                        type="button"
                        @click="emit('update:open', false)"
                        >Cancel</AppButton
                    >
                    <AppButton type="submit" :disabled="form.processing">{{
                        editing ? 'Save changes' : 'Add property'
                    }}</AppButton>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
