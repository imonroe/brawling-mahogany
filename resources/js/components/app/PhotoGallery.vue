<script setup lang="ts">
/**
 * S38 — a property's photographs (PRD §4.6 F6.4–F6.6, §7.14 · issue #63).
 *
 * ## F6.6's warning is not decoration
 *
 * *"A prominent, unmissable warning at **every** upload point."* This is the
 * product's first upload point, so the warning ships with it rather than
 * waiting for Slice 3's `UploadZone`. It names **cheques** explicitly, because
 * #63's residual window is precisely that: between this and #100 there is no
 * content scan, PRD §14.3 calls uploaded financial instruments the single
 * largest liability in the product, and a photographed cheque is an image —
 * exactly what a photo gallery accepts.
 *
 * ## Reordering has no drag library, deliberately
 *
 * Design System §15's next actions ask for *"the sortable library, needed by
 * S38, S41 and S42"*. The answer recorded here is **none**: explicit move
 * controls. Drag-and-drop needs a keyboard path to be usable at all, which is
 * the whole feature rebuilt beside the drag; twenty photographs reorder
 * perfectly well with two buttons; and §13.2 rule 3 admits a third-party
 * library only when nothing composes. S41 and S42 order longer lists and may
 * revisit it — this is a decision they can overturn cheaply, not one they
 * inherit.
 *
 * The whole order is sent at once rather than a move-one request, because a
 * reorder is one intention: two adjacent swaps racing each other produce an
 * order neither person chose.
 */
import { router, useForm } from '@inertiajs/vue3';
import { ChevronDown, ChevronUp, Star, TriangleAlert } from '@lucide/vue';
import { ref } from 'vue';
import AppButton from './AppButton.vue';
import EmptyState from './EmptyState.vue';

type Photo = {
    id: string;
    url: string;
    originalName: string;
    caption: string | null;
    isPrimary: boolean;
};

const props = defineProps<{
    propertyId: string;
    photos: Photo[];
    canManage: boolean;
}>();

const form = useForm<{ photo: File | null }>({ photo: null });
const input = ref<HTMLInputElement | null>(null);

function upload(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0];

    if (!file) {
        return;
    }

    form.photo = file;
    form.post(`/properties/${props.propertyId}/photos`, {
        preserveScroll: true,
        onFinish: () => {
            form.reset();

            if (input.value) {
                input.value.value = '';
            }
        },
    });
}

/** Move one photo by one place, and send the whole resulting order. */
function move(index: number, by: number): void {
    const next = index + by;

    if (next < 0 || next >= props.photos.length) {
        return;
    }

    const ids = props.photos.map((photo) => photo.id);
    const [moved] = ids.splice(index, 1);

    ids.splice(next, 0, moved);

    router.patch(
        `/properties/${props.propertyId}/photos`,
        { ids },
        { preserveScroll: true },
    );
}

function setPrimary(photo: Photo): void {
    router.post(
        `/properties/${props.propertyId}/photos/${photo.id}/primary`,
        {},
        { preserveScroll: true },
    );
}

function remove(photo: Photo): void {
    // IA §10: name the object and the consequence.
    if (
        !window.confirm(`Delete ${photo.originalName}? The file goes with it.`)
    ) {
        return;
    }

    router.delete(`/properties/${props.propertyId}/photos/${photo.id}`, {
        preserveScroll: true,
    });
}
</script>

<template>
    <div class="flex flex-col">
        <!--
            F6.6, at the upload point, worded for what this path actually
            accepts. Not a tooltip and not a link to a policy: "prominent,
            unmissable" is the requirement, and somebody about to photograph a
            deposit needs the sentence before they do it.
        -->
        <div
            v-if="canManage"
            class="flex items-start gap-2.5 border-b bg-state-warning-bg px-4 py-3"
            data-slot="upload-warning"
        >
            <TriangleAlert
                class="mt-0.5 size-4 shrink-0 text-state-warning"
                aria-hidden="true"
            />
            <p class="text-xs text-secondary-foreground">
                Photographs of the property only. Never upload cheques, bank
                statements, lending packets or identity documents — this product
                refuses to hold them, and a photograph of one is still one.
            </p>
        </div>

        <EmptyState
            v-if="photos.length === 0"
            title="No photos yet"
            description="Add photographs of the property. They are private to your team — nothing here is served from a public address."
        />

        <ul v-else class="flex flex-col">
            <li
                v-for="(photo, index) in photos"
                :key="photo.id"
                class="flex items-center gap-3 border-b px-4 py-2.5 last:border-b-0"
            >
                <img
                    :src="photo.url"
                    :alt="photo.caption ?? photo.originalName"
                    class="size-12 shrink-0 rounded-md border object-cover"
                    loading="lazy"
                />

                <span class="flex min-w-0 flex-1 flex-col">
                    <span class="truncate text-13 text-foreground">{{
                        photo.caption ?? photo.originalName
                    }}</span>
                    <span
                        v-if="photo.isPrimary"
                        class="text-[11px] text-muted-foreground"
                        >Primary</span
                    >
                </span>

                <template v-if="canManage">
                    <AppButton
                        variant="ghost"
                        size="compact"
                        :disabled="index === 0 || undefined"
                        aria-label="Move up"
                        @click="move(index, -1)"
                    >
                        <ChevronUp class="size-3.5" aria-hidden="true" />
                    </AppButton>
                    <AppButton
                        variant="ghost"
                        size="compact"
                        :disabled="index === photos.length - 1 || undefined"
                        aria-label="Move down"
                        @click="move(index, 1)"
                    >
                        <ChevronDown class="size-3.5" aria-hidden="true" />
                    </AppButton>
                    <AppButton
                        v-if="!photo.isPrimary"
                        variant="ghost"
                        size="compact"
                        aria-label="Make primary"
                        @click="setPrimary(photo)"
                    >
                        <Star class="size-3.5" aria-hidden="true" />
                    </AppButton>
                    <AppButton
                        variant="ghost"
                        size="compact"
                        @click="remove(photo)"
                        >Delete</AppButton
                    >
                </template>
            </li>
        </ul>

        <div v-if="canManage" class="flex flex-col gap-2 border-t px-4 py-3">
            <label class="text-xs font-medium" for="photo-upload"
                >Add a photograph</label
            >
            <input
                id="photo-upload"
                ref="input"
                type="file"
                accept="image/jpeg,image/png,image/webp,image/heic,image/heif"
                class="text-xs"
                @change="upload"
            />
            <p v-if="form.errors.photo" class="text-xs text-destructive">
                {{ form.errors.photo }}
            </p>
        </div>
    </div>
</template>
