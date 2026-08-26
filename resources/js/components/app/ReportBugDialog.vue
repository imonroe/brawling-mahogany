<script setup lang="ts">
/**
 * Report a bug (issue #176).
 *
 * The form is somebody else's page — an n8n form trigger that turns a
 * submission into a GitHub issue on this repository — framed here rather than
 * linked to. The reason is the audience: the first people to use this product
 * are agents, not developers, and *"open a new tab, find the form again, lose
 * the screen you were looking at"* is where a bug report stops being written.
 *
 * ## Three things about the frame are decisions, not defaults
 *
 * **It is only fetched once somebody asks for it.** `DialogContent` renders
 * through a portal that mounts nothing while the dialog is closed, so the
 * iframe — and the request to n8n behind it — exists only after the button is
 * pressed. A frame mounted with the shell would call a third party on every
 * page view of the application.
 *
 * **The sandbox names what the form needs and nothing else.** Scripts, its own
 * form submission, its own origin, and a popup — the frame may not navigate
 * the page it is sitting in, open a modal over it, or start a download.
 * `allow-same-origin` means *the form keeps its own origin*, which is what
 * lets it use its own storage and its own cookies; it is not our origin, and
 * the operator who sets `BUG_REPORT_URL` is the only person who chooses what
 * is framed.
 *
 * **No referrer.** The URL of the screen somebody is standing on carries a
 * deal id, and that is a client's transaction. n8n has no use for it and this
 * product does not hand it over.
 *
 * ## The way out that does not depend on the frame working
 *
 * A form can refuse to be framed — `X-Frame-Options`, or a `frame-ancestors`
 * that does not name this host — and the refusal looks like a blank rectangle
 * from out here, because a cross-origin frame tells the embedder nothing about
 * what it rendered. There is no event to branch on. So the footer carries a
 * plain link to the same URL: ADR 0003's shape rather than its letter, which
 * is that a person is never left with one way to reach something.
 *
 * ## Closing
 *
 * PRD-adjacent, but the issue asks for it in as many words: *"a user should be
 * able to close the pop up at any time."* Escape and the overlay come from
 * `Dialog` — but **neither reaches us while the cursor is inside the frame**,
 * because a keystroke typed into a cross-origin document is delivered to that
 * document and never to this one. So the footer carries a real Close button,
 * which is the one control that works from the middle of filling the form in.
 */
import { ref, watch } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import AppButton from './AppButton.vue';

const props = defineProps<{
    open: boolean;
    /** Where the n8n form lives. Held to http/https on the server. */
    url: string;
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();

/**
 * A blank rectangle for however long n8n takes to answer reads as broken, so
 * the frame is covered until it loads. Reset on every open: the frame is
 * unmounted with the dialog, so the next open is a fresh fetch.
 */
const loaded = ref(false);

watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) {
            loaded.value = false;
        }
    },
);
</script>

<template>
    <Dialog :open="open" @update:open="(value) => emit('update:open', value)">
        <DialogContent
            class="flex h-[85svh] max-h-[720px] flex-col gap-0 overflow-hidden p-0 sm:max-w-[640px]"
        >
            <div class="flex flex-col gap-1 border-b px-6 py-5">
                <DialogTitle class="text-lg font-semibold"
                    >Report a bug</DialogTitle
                >
                <DialogDescription class="text-13 text-muted-foreground">
                    Tell us what went wrong and what you were doing at the time.
                    You do not need an account anywhere else — this goes
                    straight to the people who fix it.
                </DialogDescription>
            </div>

            <div class="relative min-h-0 flex-1 bg-background">
                <iframe
                    :src="url"
                    sandbox="allow-scripts allow-forms allow-same-origin allow-popups"
                    referrerpolicy="no-referrer"
                    title="Report a bug"
                    class="size-full border-0"
                    @load="loaded = true"
                ></iframe>

                <div
                    v-if="!loaded"
                    class="absolute inset-0 flex items-center justify-center bg-background text-13 text-muted-foreground"
                >
                    Loading the form…
                </div>
            </div>

            <div class="flex items-center gap-2.5 border-t bg-muted px-6 py-4">
                <!--
                    Not an `AppButton`: it renders an Inertia `Link` when given
                    an `href`, and an Inertia visit to somebody else's domain
                    is not a navigation this application can make.
                -->
                <a
                    :href="url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="text-13 text-muted-foreground underline underline-offset-2 transition-colors duration-150 ease-out hover:text-foreground"
                    >Open in a new tab</a
                >
                <span class="flex-1" />
                <AppButton
                    variant="secondary"
                    @click="emit('update:open', false)"
                    >Close</AppButton
                >
            </div>
        </DialogContent>
    </Dialog>
</template>
