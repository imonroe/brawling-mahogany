<script setup lang="ts">
/**
 * Put this property on a deal (S36 · issue #61).
 *
 * Search-and-pick rather than a full list: a team three years in has hundreds
 * of deals, and the candidates endpoint already excludes the ones this
 * property is on so the list cannot offer a duplicate.
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

type Candidate = {
    id: string;
    name: string;
    state: string;
    sideLabel: string;
};

const props = defineProps<{ open: boolean; propertyId: string }>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();

const search = ref('');
const candidates = ref<Candidate[]>([]);
const loading = ref(false);

const form = useForm({ deal_id: '' });

let debounce: ReturnType<typeof setTimeout> | undefined;

async function load(): Promise<void> {
    loading.value = true;

    try {
        const response = await fetch(
            `/properties/${props.propertyId}/deals/candidates?q=${encodeURIComponent(search.value)}`,
            { headers: { Accept: 'application/json' } },
        );

        candidates.value = response.ok
            ? ((await response.json()).deals as Candidate[])
            : [];
    } finally {
        loading.value = false;
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

function choose(deal: Candidate): void {
    form.deal_id = deal.id;

    form.post(`/properties/${props.propertyId}/deals`, {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="(value) => emit('update:open', value)">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Link a deal</DialogTitle>
                <DialogDescription>
                    A property can be on more than one deal — a listing that
                    fell through and the one that closed are both worth keeping.
                </DialogDescription>
            </DialogHeader>

            <AppInput
                v-model="search"
                type="search"
                placeholder="Search deals"
                aria-label="Search deals"
            />

            <p v-if="form.errors.deal_id" class="text-[11px] text-state-danger">
                {{ form.errors.deal_id }}
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
                    v-else-if="candidates.length === 0"
                    class="px-3 py-2.5 text-xs text-muted-foreground"
                >
                    No deals to link. Every deal this property could go on is
                    already on it.
                </li>
                <li
                    v-for="deal in candidates"
                    v-else
                    :key="deal.id"
                    class="border-b last:border-b-0"
                >
                    <button
                        type="button"
                        class="flex min-h-11 w-full items-center gap-2 px-3 py-2.5 text-left transition-colors duration-150 ease-out hover:bg-accent/60"
                        :disabled="form.processing"
                        @click="choose(deal)"
                    >
                        <span class="flex min-w-0 flex-1 flex-col">
                            <span
                                class="truncate text-13 font-medium text-foreground"
                                >{{ deal.name }}</span
                            >
                            <span
                                class="truncate text-[11px] text-muted-foreground"
                                >{{ deal.sideLabel }}</span
                            >
                        </span>
                        <StatusBadge domain="deal" :state="deal.state" />
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
