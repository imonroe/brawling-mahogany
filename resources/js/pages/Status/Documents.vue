<script setup lang="ts">
/**
 * S63 — the documents a client may have (PRD §4.7 F7.4 · issue #111).
 *
 * **Download only**, for anything explicitly scoped client-visible (#98).
 * Empty is the normal state, which is why the empty case here is a sentence
 * rather than an apology.
 *
 * ## What is deliberately not on this page
 *
 * No `scan_state`. No category. No size, no uploader, no date. Every one of
 * those is internal vocabulary, and *"not scanned"* in particular is exactly
 * the kind of word IA §9's no-alarming-words rule exists to keep out — a badge
 * reading *clean* over a photograph of a cheque would be believed. S63 shows a
 * document or does not show it.
 */
import { Head } from '@inertiajs/vue3';
import ClientLayout from '@/layouts/ClientLayout.vue';

defineProps<{
    token: string;
    team: {
        name: string;
        accent: string | null;
        accentForeground: string | null;
        logo: string | null;
    };
    contact: { name: string; phone: string | null; email: string | null };
    documents: { id: string; name: string; url: string }[];
}>();
</script>

<template>
    <Head title="Documents" />

    <ClientLayout
        :team-name="team.name"
        :brand-color="team.accent"
        :brand-foreground="team.accentForeground"
        :logo="team.logo"
        :agent-name="contact.name"
        :agent-phone="contact.phone"
    >
        <div class="flex flex-col gap-5 p-5">
            <!--
                The one piece of navigation on the client surface, and it goes
                backwards. IA §5.4 forbids a *menu*; a way back from the one
                sub-page is not a decision somebody has to make.
            -->
            <a
                :href="`/s/${token}`"
                class="inline-flex min-h-[44px] items-center text-base font-semibold text-brand"
            >
                <span aria-hidden="true" class="mr-2">←</span>
                Back
            </a>

            <h1 class="text-[24px] font-bold">Documents</h1>

            <p
                v-if="documents.length === 0"
                class="text-base text-muted-foreground"
            >
                There is nothing here to download at the moment. Anything
                {{ contact.name }} shares with you will appear on this page.
            </p>

            <ul v-else class="flex flex-col gap-2">
                <li v-for="document in documents" :key="document.id">
                    <a
                        :href="document.url"
                        class="flex min-h-[52px] items-center rounded-lg border px-4 text-base font-semibold text-brand"
                        >{{ document.name }}</a
                    >
                </li>
            </ul>
        </div>
    </ClientLayout>
</template>
