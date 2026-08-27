<script setup lang="ts">
/**
 * S54 — the install prompt (#102 · Screen Inventory §J).
 *
 * ## Why this screen is mandatory rather than a nicety
 *
 * Screen Inventory §J:
 *
 * > **iOS makes S54 mandatory rather than optional.** Web push on iOS only
 * > works once the PWA has been added to the home screen, so the install
 * > prompt is a prerequisite for notifications rather than a nicety.
 * > **Budget real copy time for it, because it is asking a user to do
 * > something unfamiliar.**
 *
 * PRD §3.1 says Emily will not read documentation. So the iOS half has to
 * work as a picture and three sentences — the Share glyph drawn rather than
 * named, because *"tap Share"* means nothing to somebody who has never
 * noticed which icon that is.
 *
 * ## And why it leads with the reason
 *
 * Asking somebody to add a website to their home screen is a strange request
 * with no obvious payoff, and *"install our app"* has been trained into
 * people as something to dismiss. The one honest reason — this is how
 * notifications reach your phone — goes first, because it is the only thing
 * that makes the request worth reading.
 */
import { Share, SquarePlus, X } from '@lucide/vue';
import AppButton from '@/components/app/AppButton.vue';
import { useInstallPrompt } from '@/composables/useInstallPrompt';

const { canPrompt, needsInstructions, installed, dismissed, install, dismiss } =
    useInstallPrompt();
</script>

<template>
    <!--
        Nothing at all once it is installed or has been declined. The
        already-installed case is not an empty state to draw: an installed app
        showing a card about installing it is the clearest possible signal
        that nobody checked.
    -->
    <div
        v-if="!installed && !dismissed && (canPrompt || needsInstructions)"
        class="rounded-lg border border-border bg-card p-4"
        data-slot="install-prompt"
    >
        <div class="flex items-start gap-3">
            <div class="min-w-0 flex-1">
                <p class="text-13 font-semibold">
                    Put Goldieflow on your home screen
                </p>
                <p class="mt-1 text-13 text-muted-foreground">
                    It opens like an app, and it is the only way notifications
                    can reach this phone.
                </p>

                <!-- Android and desktop Chrome: the browser handed us a
                     prompt, so this is one tap and no instructions. -->
                <AppButton
                    v-if="canPrompt"
                    class="mt-3"
                    variant="secondary"
                    @click="install"
                >
                    Add to home screen
                </AppButton>

                <!-- iOS: there is no API, so the only thing that works is
                     saying which buttons to press. The glyphs are drawn
                     because that is what somebody is looking for on screen —
                     naming them is what makes instructions unfollowable. -->
                <ol v-else class="mt-3 space-y-2 text-13 text-muted-foreground">
                    <li class="flex items-center gap-2">
                        <span class="shrink-0">1.</span>
                        <span>Tap</span>
                        <Share class="size-4 shrink-0" aria-hidden="true" />
                        <span>at the bottom of the screen.</span>
                    </li>
                    <li class="flex items-center gap-2">
                        <span class="shrink-0">2.</span>
                        <span>Scroll down and tap</span>
                        <SquarePlus
                            class="size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <span>Add to Home Screen.</span>
                    </li>
                    <li class="flex items-center gap-2">
                        <span class="shrink-0">3.</span>
                        <span>Tap Add.</span>
                    </li>
                </ol>
            </div>

            <button
                type="button"
                class="shrink-0 rounded-md p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground"
                aria-label="Not now"
                @click="dismiss"
            >
                <X class="size-4" />
            </button>
        </div>
    </div>
</template>
