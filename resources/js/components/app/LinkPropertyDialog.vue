<script setup lang="ts">
/**
 * Put a property on a deal (S20 · issue #62).
 *
 * The mirror of `LinkDealDialog`, which does the same job from S36. Both go
 * through `PropertyDeals::link()`, so the rule about what becomes the subject
 * is in one place rather than in whichever screen was written first.
 */
import { useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import AppButton from '@/components/app/AppButton.vue';
import AppInput from '@/components/app/AppInput.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatAddress, formatPropertyFacts } from '@/lib/formatters';
import type { PropertyRow } from '@/types';

const props = defineProps<{ open: boolean; dealId: string }>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();

const search = ref('');
const candidates = ref<PropertyRow[]>([]);
const loading = ref(false);
const failed = ref(false);

const form = useForm({ property_id: '' });

let debounce: ReturnType<typeof setTimeout> | undefined;

/**
 * The same two guards `LinkDealDialog` carries, and for the same reasons: a
 * session that expired answers with HTML that `response.json()` rejects on,
 * and a 250ms debounce lets an older request answer after a newer one.
 */
async function load(): Promise<void> {
    const term = search.value;

    loading.value = true;
    failed.value = false;

    try {
        const response = await fetch(
            `/deals/${props.dealId}/properties/candidates?q=${encodeURIComponent(term)}`,
            { headers: { Accept: 'application/json' } },
        );

        const properties = response.ok
            ? ((await response.json()).properties as PropertyRow[])
            : null;

        if (term !== search.value) {
            return;
        }

        candidates.value = properties ?? [];
        failed.value = properties === null;
    } catch {
        if (term === search.value) {
            candidates.value = [];
            failed.value = true;
        }
    } finally {
        if (term === search.value) {
            loading.value = false;
        }
    }
}

watch(
    () => props.open,
    (open) => {
        if (open) {
            search.value = '';
            form.reset();
            form.clearErrors();
            void load();
        }
    },
);

watch(search, () => {
    clearTimeout(debounce);
    debounce = setTimeout(() => void load(), 250);
});

function choose(property: PropertyRow): void {
    form.property_id = property.id;

    form.post(`/deals/${props.dealId}/properties`, {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="(value) => emit('update:open', value)">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Link a property</DialogTitle>
                <DialogDescription>
                    Everything this deal is about — the house being sold, or
                    every house the buyer is looking at.
                </DialogDescription>
            </DialogHeader>

            <AppInput
                v-model="search"
                type="search"
                placeholder="Search by address or parcel number"
                aria-label="Search properties"
            />

            <p
                v-if="form.errors.property_id"
                class="text-[11px] text-state-danger"
            >
                {{ form.errors.property_id }}
            </p>

            <ul
                class="flex max-h-72 flex-col overflow-y-auto rounded-md border"
            >
                <li
                    v-if="loading"
                    class="px-3 py-2.5 text-xs text-muted-foreground"
                >
                    Looking…
                </li>
                <li
                    v-else-if="failed"
                    class="px-3 py-2.5 text-xs text-state-danger"
                >
                    Couldn’t load your properties. Refresh the page and try
                    again.
                </li>
                <li
                    v-else-if="candidates.length === 0"
                    class="px-3 py-2.5 text-xs text-muted-foreground"
                >
                    Nothing to link. Add the property first, then come back — a
                    property can be on more than one deal.
                </li>
                <li
                    v-for="property in candidates"
                    v-else
                    :key="property.id"
                    class="border-b last:border-b-0"
                >
                    <button
                        type="button"
                        class="flex min-h-11 w-full items-center gap-2 px-3 py-2.5 text-left transition-colors duration-150 ease-out hover:bg-accent/60"
                        :disabled="form.processing"
                        @click="choose(property)"
                    >
                        <span class="flex min-w-0 flex-1 flex-col">
                            <span
                                class="truncate text-13 font-medium text-foreground"
                                >{{
                                    formatAddress(property.address).line1 ||
                                    property.name
                                }}</span
                            >
                            <span
                                class="truncate text-[11px] text-muted-foreground"
                                >{{
                                    [
                                        formatAddress(property.address).line2,
                                        formatPropertyFacts(property),
                                    ]
                                        .filter(Boolean)
                                        .join(' · ') || property.typeLabel
                                }}</span
                            >
                        </span>
                        <StatusBadge
                            domain="property"
                            :state="property.status"
                        />
                    </button>
                </li>
            </ul>

            <DialogFooter>
                <AppButton
                    variant="ghost"
                    type="button"
                    @click="emit('update:open', false)"
                    >Cancel</AppButton
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
