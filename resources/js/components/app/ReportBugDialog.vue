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
 * `allow-same-origin` means *the form keeps its own origin*, which is what lets
 * it use its own storage and its own cookies. That it is not **our** origin is
 * now enforced rather than assumed: `BugReportForm` refuses a URL on a host and
 * port this application answers on, because `allow-scripts allow-same-origin`
 * on a same-origin document is not a sandbox at all — the frame reaches
 * `window.parent` and reads the session. A self-host that proxies n8n under the
 * app's domain is an ordinary layout, not a contrivance; n8n on its own port
 * beside the app is a different origin and is allowed.
 *
 * **No referrer.** The URL of the screen somebody is standing on carries a
 * deal id, and that is a client's transaction. n8n has no use for it and this
 * product does not hand it over.
 *
 * ## The warning lives inside the description, not beside it
 *
 * PRD §10 records that this product has no control over what reaches a public
 * issue tracker — only a warning — so the warning *is* the mitigation, and a
 * mitigation that renders as a coloured box is one a screen reader never
 * reaches. Drawn as a sibling of `DialogDescription` it was outside
 * `aria-describedby` and was not a tab stop either, so the one reader who
 * cannot see the amber was the one reader never told. `as="div"` is what lets
 * a block sit inside the description element; the inner nodes are `span`s
 * because a `p` may not contain one.
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
 * ## Closing, and where focus starts
 *
 * The issue asks for it in as many words: *"a user should be able to close the
 * pop up at any time."* Escape and the overlay come from `Dialog` — but
 * **neither reaches us while the cursor is inside the frame**, because a
 * keystroke typed into a cross-origin document is delivered to that document
 * and never to this one. So the footer carries a real Close button, which is
 * the one control that works from the middle of filling the form in.
 *
 * **`show-close-button` is off so that it stays the one.** `DialogContent`
 * draws its own corner ✕ by default, DOM-last — which put two adjacent
 * controls both named "Close" in the tab order, and made this paragraph's
 * *"the one control"* and the next one's *"one Tab away"* untrue by exactly
 * one. `finding-your-way.md` already told people to use "the Close button at
 * the bottom" and never mentioned a ✕.
 *
 * **And that is why `open-auto-focus` is prevented.** Reka focuses the first
 * tabbable node in the dialog, and in this one that is the `<iframe>` — so the
 * default put every keyboard user *inside somebody else's document on open*,
 * with Escape dead before they had typed anything and the dialog's own title
 * and description never announced. Focus goes to Close instead: inside our
 * chrome, so Escape works and the dialog names itself, and one Tab away from
 * the form for somebody who came here to fill it in.
 *
 * `.prevent` alone is not enough. Reka's focus trap pulls focus back when it
 * sits outside the scope, and with nothing focused it stays on the trigger in
 * the top bar — so the handler has to name what to focus.
 */
import { TriangleAlert } from '@lucide/vue';
import { ref, watch } from 'vue';
import type { ComponentPublicInstance } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
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

/** See the docblock: the default focuses the frame, which kills Escape. */
const closeButton = ref<ComponentPublicInstance | null>(null);

function focusOurOwnChrome(event: Event): void {
    event.preventDefault();

    const button = closeButton.value?.$el;

    // `instanceof` rather than a nullish guard: `$el` is the root *node*, and
    // a component that grew a second root would hand back a fragment anchor,
    // where `.focus()` is a TypeError swallowed inside reka's dispatch —
    // leaving focus on the trigger with nothing anywhere saying why.
    if (button instanceof HTMLElement) {
        // `preventScroll` because this runs during the content's own
        // `zoom-in-95` enter animation, on a fixed, translated element. Reka's
        // internal `focus()` helper passes it for the same reason.
        button.focus({ preventScroll: true });
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="(value) => emit('update:open', value)">
        <DialogContent
            class="flex h-[85svh] max-h-[720px] flex-col gap-0 overflow-hidden p-0 sm:max-w-[640px]"
            :show-close-button="false"
            @open-auto-focus="focusOurOwnChrome"
        >
            <div class="flex flex-col gap-3 border-b px-6 py-5">
                <DialogTitle class="text-lg font-semibold"
                    >Report a bug</DialogTitle
                >

                <!--
                    Both halves inside the description, and `as="div"` so a
                    block may live in it — see the docblock. The warning is the
                    whole of PRD §10's mitigation, so it has to reach the one
                    reader who never sees a colour: `aria-describedby` names
                    this element, and a screen reader reads what is in it.
                -->
                <DialogDescription as="div" class="flex flex-col gap-3">
                    <p class="text-13 text-muted-foreground">
                        Tell us what went wrong and what you were doing at the
                        time. The screen you were on and what you had just
                        clicked is usually enough.
                    </p>

                    <span
                        class="flex items-start gap-2.5 rounded-md bg-state-warning-bg px-3 py-2.5"
                        data-slot="publication-warning"
                    >
                        <TriangleAlert
                            class="mt-0.5 size-4 shrink-0 text-state-warning"
                            aria-hidden="true"
                        />
                        <span class="text-xs text-secondary-foreground">
                            Reports are published publicly. Leave your clients
                            out of one — no names, no address, nothing about the
                            deal.
                        </span>
                    </span>
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

                <!--
                    Rendered always, because a live region announces *changes
                    to itself* and one that arrives already populated has no
                    change to report. `v-if` on the wrapper meant it announced
                    neither the wait nor the end of it.
                -->
                <div
                    role="status"
                    :class="
                        cn(
                            'absolute inset-0 flex items-center justify-center bg-background text-13 text-muted-foreground',
                            loaded && 'pointer-events-none opacity-0',
                        )
                    "
                >
                    {{ loaded ? 'The form is ready.' : 'Loading the form…' }}
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
                    ref="closeButton"
                    variant="secondary"
                    @click="emit('update:open', false)"
                    >Close</AppButton
                >
            </div>
        </DialogContent>
    </Dialog>
</template>
