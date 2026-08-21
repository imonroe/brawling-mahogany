<script setup lang="ts">
/**
 * P7 — the client status page surface (Design System §9.6).
 *
 * A different design language, deliberately: 16px base, comfortable density,
 * a single centred column, no navigation, and the team's own accent. Nothing
 * from the internal app's density rules applies here.
 *
 * The footer statement is a compliance position (PRD §10), not a courtesy:
 * this page is a summary, and signed documents live in the team's
 * e-signature system of record.
 */
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        teamName: string;
        /** The team's accent, as an oklch or hex value validated at save time. */
        brandColor?: string | null;
        brandForeground?: string | null;
        /** Where a client goes when they need a human. */
        agentName?: string | null;
        agentPhone?: string | null;
        eSignatureName?: string | null;
    }>(),
    { brandColor: null, brandForeground: null },
);

const brandStyle = computed(() => ({
    ...(props.brandColor ? { '--brand': props.brandColor } : {}),
    ...(props.brandForeground ? { '--brand-foreground': props.brandForeground } : {}),
}));
</script>

<template>
    <div class="client-surface min-h-svh bg-background" :style="brandStyle">
        <header class="flex h-15 items-center bg-brand px-5">
            <span class="text-base font-bold text-brand-foreground">{{ teamName }}</span>
        </header>

        <main class="mx-auto w-full max-w-[480px]">
            <slot />
        </main>

        <footer class="mx-auto w-full max-w-[480px] border-t p-5">
            <p class="text-sm text-muted-foreground">
                This page is a summary of where things stand. Signed documents are kept in
                {{ eSignatureName ?? 'your agent’s e-signature system' }}, not here.
            </p>
            <p v-if="agentName && agentPhone" class="mt-2 text-sm text-muted-foreground">
                Questions? Call {{ agentName }} at
                <a class="font-semibold text-brand" :href="`tel:${agentPhone}`">{{ agentPhone }}</a
                >.
            </p>
        </footer>
    </div>
</template>
