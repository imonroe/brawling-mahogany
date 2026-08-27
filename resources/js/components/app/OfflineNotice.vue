<script setup lang="ts">
/**
 * S56 — the offline state (#102 · PRD §4.12 F12.1).
 *
 * ## Three things to say, and they are not the same thing
 *
 * Issue #102 asks for honesty rather than reassurance:
 *
 * > Read-only offline. A stale banner that says **when** the data is from.
 * > **"Action queued" must not lie** — if a completion cannot be sent, say so
 * > rather than showing a tick.
 *
 * So this distinguishes *offline with a saved copy* (say when it is from),
 * *offline with nothing saved* (say that plainly — an empty task list looks
 * exactly like a finished day), and *back online* (say so once, briefly, then
 * get out of the way).
 *
 * ## Nothing is queued, and the banner says so
 *
 * There is no background-sync queue behind this, deliberately. In this
 * product a replayed mutation is not a neutral act: completing a task clears
 * a gate, and clearing a gate can send a client an email that cannot be
 * recalled. A tick shown now for a write that will be attempted from a pocket
 * two hours later is precisely the lie #102 forbids, so the banner promises
 * the opposite — **you can read, nothing will be saved** — which is a promise
 * the code actually keeps.
 */
import { computed, ref, watch } from 'vue';
import { useOfflineState } from '@/composables/useOfflineState';
import { formatDateTime } from '@/lib/formatters';

const { online, cachedAt } = useOfflineState();

/**
 * Shown for a moment after the connection returns, then gone.
 *
 * Not a state anybody needs to act on, but the one moment somebody is
 * watching the banner — having just walked back into signal — and a banner
 * that vanishes silently leaves them unsure whether it worked.
 */
const reconnected = ref(false);

watch(online, (isOnline, wasOnline) => {
    if (isOnline && wasOnline === false) {
        reconnected.value = true;
        setTimeout(() => (reconnected.value = false), 4000);
    }
});

const visible = computed(() => !online.value || reconnected.value);
</script>

<template>
    <div
        v-if="visible"
        :class="[
            'flex',
            'min-h-11',
            'flex-wrap',
            'items-center',
            'gap-x-2.5',
            'gap-y-1',
            'px-4',
            'py-2',
            online ? 'bg-state-success' : 'bg-state-warning',
            'text-primary-foreground',
        ]"
        role="status"
        aria-live="polite"
        data-slot="offline-notice"
    >
        <template v-if="online">
            <p class="flex-1 text-13 font-medium">
                Back online. Everything works again.
            </p>
        </template>

        <template v-else>
            <p class="flex-1 text-13 font-medium">
                You are offline.
                <template v-if="cachedAt">
                    <!-- The time it was saved, not a vague "this may be old".
                         A reader deciding whether to trust a task list needs
                         to know whether it is from this morning or Tuesday. -->
                    <span class="font-normal">
                        <!-- The day as well as the time. `formatTime` alone
                             renders a three-day-old copy as "saved at 3:09am",
                             which reads as this morning — the exact "today or
                             Tuesday" distinction this banner exists to draw,
                             and what `help/mobile.md` promises. -->
                        This is what was saved
                        {{ formatDateTime(cachedAt.toISOString()) }}.
                    </span>
                </template>
                <template v-else>
                    <span class="font-normal">
                        This page was not saved for offline use, so there is
                        nothing to show from it.
                    </span>
                </template>
            </p>
            <p class="w-full text-[11px] opacity-90">
                You can read, but nothing will be saved until you are back
                online — including completing a task.
            </p>
        </template>
    </div>
</template>
