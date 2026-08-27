<script setup lang="ts">
/**
 * P7 — the client status page surface (Design System §9.6 · issues #111, #112).
 *
 * A different design language, deliberately: 16px base, comfortable density, a
 * single centred column, **no navigation**, and the team's own accent. Nothing
 * in §4 or §7 applies here except the tokens.
 *
 * The footer statement is a compliance position (PRD §10), not a courtesy:
 * this page is a summary, and signed documents live in the team's e-signature
 * system of record.
 *
 * ## The accent is the team's; the text on it is computed
 *
 * §15.6 settles warn-versus-adjust by surface, and the deciding fact is
 * whether anybody is standing there. On S72 the owner is looking at a preview
 * and is warned. Here nobody is — a client reads this once, on a phone — so
 * `AccentContrast::foregroundFor()` picks black or white on the band and the
 * server sends both. A team who picked a pale yellow gets a legible heading
 * rather than a warning nobody will see.
 *
 * ## Accessibility (PRD §9: AA here, best effort internally)
 *
 * `<header>`, `<main>` and `<footer>` are real landmarks. The brand bar's text
 * is a real heading level nothing competes with. The phone number is a `tel:`
 * link at 52px, which is §9.6's action size. Nothing on this surface animates,
 * so `prefers-reduced-motion` has nothing to suppress — recorded rather than
 * implied, because an audit that finds no motion should know it was a choice.
 */
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        teamName: string;
        /** The team's accent, validated as a hex at save time (S72). */
        brandColor?: string | null;
        /** Black or white on it, computed server-side. See §15.6. */
        brandForeground?: string | null;
        /** A data URI: the bytes are private and a client has no session. */
        logo?: string | null;
        /** Where a client goes when they need a human (F7.6). */
        agentName?: string | null;
        agentPhone?: string | null;
        eSignatureName?: string | null;
    }>(),
    { brandColor: null, brandForeground: null, logo: null },
);

const brandStyle = computed(() => ({
    ...(props.brandColor ? { '--brand': props.brandColor } : {}),
    ...(props.brandForeground
        ? { '--brand-foreground': props.brandForeground }
        : {}),
}));
</script>

<template>
    <div class="client-surface min-h-svh bg-background" :style="brandStyle">
        <header class="flex h-15 items-center gap-3 bg-brand px-5">
            <!--
                A raster asset cannot participate in the token layer (§2.6), so
                the logo gets a plate that stays light in both schemes — the
                same decision the email layout makes, and `--logo-plate` is the
                token §2.6 already defines for it rather than a raw white.
            -->
            <span
                v-if="logo"
                class="flex h-9 items-center rounded-sm bg-logo-plate px-2"
            >
                <img :src="logo" :alt="teamName" class="max-h-7 max-w-28" />
            </span>
            <span v-else class="text-base font-bold text-brand-foreground">{{
                teamName
            }}</span>
        </header>

        <main class="mx-auto w-full max-w-[480px]">
            <slot />
        </main>

        <footer class="mx-auto w-full max-w-[480px] border-t p-5">
            <!--
                F7.6 — "call or email your agent", and **not** a messaging
                system. Heather's professionalism point: chasing a client
                through an interface reads as less professional than a phone
                call, not more.
            -->
            <p v-if="agentName && agentPhone" class="text-base">
                Questions? Call {{ agentName }} at
                <a
                    class="inline-flex min-h-[44px] items-center font-semibold text-brand underline"
                    :href="`tel:${agentPhone}`"
                    >{{ agentPhone }}</a
                >.
            </p>

            <p class="mt-2 text-base text-muted-foreground">
                This page is a summary of where things stand. Signed documents
                are kept in
                {{ eSignatureName ?? 'your agent’s e-signature system' }}, not
                here.
            </p>
        </footer>
    </div>
</template>
