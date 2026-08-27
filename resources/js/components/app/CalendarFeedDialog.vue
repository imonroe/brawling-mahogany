<script setup lang="ts">
/**
 * S60 — subscribe a calendar to this one (PRD §4.8 F8.3 · issue #108).
 *
 * F8.3 chose read-only iCal over two-way Google sync because it *"works
 * everywhere, no OAuth"*, and this modal is where that promise is kept or
 * broken: a person has to be able to get a URL into their own calendar in one
 * step, from a phone, without being told what iCal is.
 *
 * ## Four states, and *revoke* is the one that earns the modal
 *
 * Generate, revoke, copy, and personal-versus-per-deal. The URL is a bearer
 * token — pasted into Google, emailed to a colleague, forgotten — so taking
 * one back has to be one click, and it has to be obvious which row it takes
 * back. That is why a feed carries a name and why the list shows whether
 * anything is still reading it.
 *
 * ## Generating replaces
 *
 * A person who presses Generate twice means *"give me a URL"*, not *"give me
 * two"*. The consequence is said out loud rather than discovered: it breaks
 * the subscription already in somebody's calendar.
 */
import { router, useForm, usePage } from '@inertiajs/vue3';
import { Check, Copy } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
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
import { formatRelativeDate } from '@/lib/formatters';

export type CalendarFeedRow = {
    id: string;
    name: string;
    url: string;
    dealLabel: string | null;
    lastFetchedAt: string | null;
    fetchCount: number;
};

const props = defineProps<{
    open: boolean;
    feeds: CalendarFeedRow[];
    dealOptions: { id: string; label: string }[];
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();

const form = useForm({ name: '', dealId: '' });

const page = usePage();

/**
 * The URL just generated, flashed by the server.
 *
 * Every feed's URL is readable from the list as well — a feed that could only
 * be read once would have to be revoked and re-added on every device, which is
 * the opposite of what a subscription is for. This is only about drawing
 * attention to the new one.
 */
const justCreated = computed(
    () =>
        (page.props.calendarFeed ?? null) as { id: string; url: string } | null,
);

const copiedId = ref<string | null>(null);

watch(
    () => props.open,
    (open) => {
        if (open) {
            form.reset();
            form.clearErrors();
        }
    },
);

/**
 * `navigator.clipboard` is unavailable over plain HTTP and on older browsers,
 * and a Copy button that silently does nothing is worse than none — so the URL
 * is always on screen and selectable, and this is a convenience on top.
 */
async function copy(feed: CalendarFeedRow): Promise<void> {
    try {
        await navigator.clipboard.writeText(feed.url);
        copiedId.value = feed.id;
        window.setTimeout(() => (copiedId.value = null), 2000);
    } catch {
        copiedId.value = null;
    }
}

function generate(): void {
    form.post('/calendar/feeds', { preserveScroll: true });
}

function revoke(feed: CalendarFeedRow): void {
    if (
        !window.confirm(
            `Revoke “${feed.name}”? Any calendar subscribed to it stops updating straight away.`,
        )
    ) {
        return;
    }

    router.delete(`/calendar/feeds/${feed.id}`, { preserveScroll: true });
}

function readLine(feed: CalendarFeedRow): string {
    if (!feed.lastFetchedAt) {
        return 'Not read yet';
    }

    return `Read ${formatRelativeDate(feed.lastFetchedAt)}`;
}
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Subscribe to this calendar</DialogTitle>
                <DialogDescription>
                    Paste one of these into Google Calendar, Apple Calendar, or
                    anything else that reads a calendar URL. They are read-only:
                    nothing you change there comes back here.
                </DialogDescription>
            </DialogHeader>

            <div class="flex flex-col gap-4">
                <ul v-if="feeds.length > 0" class="flex flex-col gap-3">
                    <li
                        v-for="feed in feeds"
                        :key="feed.id"
                        class="flex flex-col gap-1.5 rounded-md border p-3"
                        :class="
                            justCreated?.id === feed.id ? 'border-primary' : ''
                        "
                    >
                        <div class="flex items-center gap-2">
                            <span
                                class="min-w-0 flex-1 truncate text-13 font-medium"
                                >{{ feed.name }}</span
                            >
                            <span class="text-[11px] text-muted-foreground">{{
                                readLine(feed)
                            }}</span>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <code
                                class="min-w-0 flex-1 truncate rounded bg-muted px-2.5 py-1.5 text-[11px] text-muted-foreground"
                                >{{ feed.url }}</code
                            >
                            <AppButton variant="ghost" @click="copy(feed)">
                                <component
                                    :is="copiedId === feed.id ? Check : Copy"
                                    class="size-4"
                                    aria-hidden="true"
                                />
                                {{ copiedId === feed.id ? 'Copied' : 'Copy' }}
                            </AppButton>
                            <AppButton variant="ghost" @click="revoke(feed)"
                                >Revoke</AppButton
                            >
                        </div>
                    </li>
                </ul>

                <p v-else class="text-13 text-muted-foreground">
                    No feeds yet. Generate one below and paste it into your
                    calendar.
                </p>

                <form
                    class="flex flex-col gap-3 border-t pt-4"
                    @submit.prevent="generate"
                >
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="flex flex-col gap-1.5">
                            <Label for="feed_name">Name it</Label>
                            <AppInput
                                id="feed_name"
                                v-model="form.name"
                                placeholder="My work calendar"
                            />
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <Label for="feed_deal">What it covers</Label>
                            <!--
                                F8.3 asks for both: a personal feed carries
                                everything this person can see, and a per-deal
                                one carries that deal alone — which is the one
                                somebody shares with a client's other agent.
                            -->
                            <select
                                id="feed_deal"
                                v-model="form.dealId"
                                class="h-11 rounded-md border bg-background px-3 text-base md:h-10 md:text-sm"
                            >
                                <option value="">Everything I can see</option>
                                <option
                                    v-for="deal in dealOptions"
                                    :key="deal.id"
                                    :value="deal.id"
                                >
                                    {{ deal.label }}
                                </option>
                            </select>
                        </div>
                    </div>

                    <p class="text-[11px] text-muted-foreground">
                        Generating replaces the feed you already have for the
                        same thing, and any calendar subscribed to the old URL
                        stops updating.
                    </p>

                    <DialogFooter class="gap-2">
                        <AppButton
                            type="button"
                            variant="secondary"
                            @click="emit('update:open', false)"
                            >Close</AppButton
                        >
                        <AppButton type="submit" :disabled="form.processing"
                            >Generate</AppButton
                        >
                    </DialogFooter>
                </form>
            </div>
        </DialogContent>
    </Dialog>
</template>
