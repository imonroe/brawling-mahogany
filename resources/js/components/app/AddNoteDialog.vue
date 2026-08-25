<script setup lang="ts">
/**
 * Write a note on a deal (PRD §4.4 F4.11 · IA §9 · issue #72).
 *
 * ## Internal by default, and the default is the whole feature
 *
 * F4.11 is *"internal by default, with an explicit client-visible toggle"*,
 * and #72 says why in one sentence: *"an agent who made one note
 * client-visible last Tuesday must not silently publish the next one."* So the
 * toggle starts off on every open — `form.reset()` on the watcher below — and
 * nothing anywhere remembers it. The safe answer is the one you get by not
 * thinking about it.
 *
 * ## Publishing is shown before it happens
 *
 * A client-visible note has to survive IA §9's rules — no jargon, no internal
 * stage names, no gate language, no instructions aimed at the client, no
 * alarming words — and a human writes it, so no code can enforce that. What
 * code *can* do is stop it being a surprise: turning the toggle on reveals the
 * note rendered in the client's own typography (`.client-surface`, §9.6's
 * 16px reading size), under a line saying plainly who will read it. Somebody
 * about to publish "waiting on the lender, this is a mess" should see it
 * dressed as the client will.
 */
import { useForm } from '@inertiajs/vue3';
import { Eye, EyeOff } from '@lucide/vue';
import { computed, watch } from 'vue';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import AppButton from './AppButton.vue';
import AppTextarea from './AppTextarea.vue';

const props = defineProps<{
    open: boolean;
    /** Where the note goes — `/deals/{id}`. */
    dealUrl: string;
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();

const form = useForm({ body: '', is_client_visible: false });

/**
 * Every open is a fresh decision.
 *
 * Watching `open` rather than resetting on submit, because a dialog closed
 * without submitting has to forget too — otherwise the toggle survives a
 * cancel, which is the sticky behaviour #72 forbids by another route.
 */
watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) {
            form.reset();
            form.clearErrors();
        }
    },
);

const visible = computed({
    get: () => form.is_client_visible,
    set: (value: boolean) => {
        form.is_client_visible = value;
    },
});

function submit(): void {
    form.post(`${props.dealUrl}/notes`, {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="(value) => emit('update:open', value)">
        <DialogContent
            class="flex max-h-[85svh] flex-col gap-0 overflow-hidden p-0 sm:max-w-[600px]"
        >
            <div class="flex flex-col gap-1 border-b px-6 py-5">
                <DialogTitle class="text-lg font-semibold"
                    >Add note</DialogTitle
                >
                <DialogDescription class="text-13 text-muted-foreground">
                    Notes stay inside the team unless you say otherwise.
                </DialogDescription>
            </div>

            <div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
                <section class="flex flex-col gap-3 px-6 py-[18px]">
                    <label
                        for="note-body"
                        class="text-sm font-medium text-foreground"
                        >Note</label
                    >

                    <AppTextarea
                        id="note-body"
                        v-model="form.body"
                        :invalid="Boolean(form.errors.body)"
                        placeholder="Sellers are away until the 12th; inspection access needs the lockbox code."
                    />

                    <p v-if="form.errors.body" class="text-xs text-destructive">
                        {{ form.errors.body }}
                    </p>
                </section>

                <section class="flex flex-col gap-3 border-t px-6 py-[18px]">
                    <label
                        class="flex cursor-pointer items-start gap-3"
                        for="note-visible"
                    >
                        <Checkbox
                            id="note-visible"
                            v-model="visible"
                            class="mt-0.5"
                        />
                        <span class="flex min-w-0 flex-col gap-0.5">
                            <span
                                class="flex items-center gap-1.5 text-sm font-medium text-foreground"
                            >
                                <component
                                    :is="visible ? Eye : EyeOff"
                                    class="size-[15px] text-muted-foreground"
                                    aria-hidden="true"
                                />
                                Show this note to the client
                            </span>
                            <span class="text-xs text-muted-foreground">
                                Off by default, and off again next time. Each
                                note is its own decision.
                            </span>
                        </span>
                    </label>

                    <!--
                        The preview, and only when it applies. IA §9 governs
                        everything a client reads; a human writes this, so the
                        product cannot enforce those rules — what it can do is
                        make sure nobody publishes without having seen it as
                        the client will.
                    -->
                    <div
                        v-if="visible"
                        class="flex flex-col gap-2 rounded-md border bg-muted p-3.5"
                        data-slot="client-preview"
                    >
                        <span
                            class="text-xs font-semibold text-muted-foreground uppercase"
                            >What your client sees</span
                        >
                        <p
                            class="client-surface whitespace-pre-line text-foreground"
                        >
                            {{ form.body || 'Your note will appear here.' }}
                        </p>
                        <span class="text-xs text-muted-foreground">
                            Plain language, no stage names, and nothing that
                            reads as an instruction — they cannot reply to it
                            here.
                        </span>
                    </div>
                </section>
            </div>

            <div class="flex items-center gap-2.5 border-t bg-muted px-6 py-4">
                <span class="flex-1" />
                <AppButton variant="ghost" @click="emit('update:open', false)"
                    >Cancel</AppButton
                >
                <AppButton :disabled="form.processing" @click="submit">{{
                    visible ? 'Add and show client' : 'Add note'
                }}</AppButton>
            </div>
        </DialogContent>
    </Dialog>
</template>
