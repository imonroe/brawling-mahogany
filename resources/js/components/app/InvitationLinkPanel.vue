<script setup lang="ts">
/**
 * The invitation link, shown once (ADR 0003 · S74, S83).
 *
 * ## Why it appears and then never again
 *
 * Only the SHA-256 hash of a token is stored, so there is nothing to read
 * back on a later visit — asking for the link again mints a new one. That is
 * a deliberate cost of not keeping a recoverable credential in the database,
 * and the panel says so rather than leaving somebody to discover it when the
 * link they emailed yesterday stops working.
 *
 * Two screens show it: the team's own members screen, and the platform
 * console's team detail, which is where the *first* owner of a team is
 * invited and therefore where a fresh install with no mail transport is
 * otherwise stuck.
 */
import { Copy, Check } from '@lucide/vue';
import { ref } from 'vue';
import AppButton from '@/components/app/AppButton.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';

const props = defineProps<{
    link: { id: string; email: string; url: string };
}>();

const copied = ref(false);

/**
 * `navigator.clipboard` is unavailable over plain HTTP and on older
 * browsers, and a Copy button that silently does nothing is worse than no
 * Copy button — so the URL is always on screen and selectable, and this is
 * only ever a convenience on top of it.
 */
async function copy(): Promise<void> {
    try {
        await navigator.clipboard.writeText(props.link.url);
        copied.value = true;
        window.setTimeout(() => (copied.value = false), 2000);
    } catch {
        copied.value = false;
    }
}
</script>

<template>
    <Alert>
        <AlertDescription class="flex flex-col gap-2">
            <p class="text-13 font-medium">
                Invitation link for {{ props.link.email }}
            </p>
            <div class="flex flex-wrap items-center gap-2">
                <code
                    class="min-w-0 flex-1 truncate rounded bg-muted px-2.5 py-1.5 text-[11px] text-muted-foreground"
                    >{{ props.link.url }}</code
                >
                <AppButton variant="ghost" @click="copy">
                    <component
                        :is="copied ? Check : Copy"
                        class="size-4"
                        aria-hidden="true"
                    />
                    {{ copied ? 'Copied' : 'Copy' }}
                </AppButton>
            </div>
            <p class="text-[11px] text-muted-foreground">
                Send this however you like. It replaces any link already emailed
                to this address, it expires with the invitation, and it is not
                stored — leave this page and you will have to generate another.
            </p>
        </AlertDescription>
    </Alert>
</template>
