<script setup lang="ts">
/**
 * S92 — the manual's contents page (issue #170).
 *
 * Sections in the order somebody meets the product rather than alphabetically:
 * signing in before running a deal, configuration last because it is found
 * once. `HelpLibrary::SECTIONS` fixes that order server-side, so this renders
 * what it is given and decides nothing.
 *
 * **Planned features are listed, not hidden.** #170 asks for placeholders, and
 * the reason to honour that literally is that a manual with a gap teaches
 * nothing while one that says *"documents arrive in a later release"* answers
 * the question somebody opened it with — and stops that question reaching
 * Emily by phone.
 */
import { Head, Link } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import Card from '@/components/app/Card.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';

defineProps<{
    sections: {
        key: string;
        title: string;
        blurb: string;
        articles: {
            slug: string;
            title: string;
            summary: string;
            arrivesWith: string | null;
        }[];
    }[];
}>();
</script>

<template>
    <Head title="Help" />

    <div class="flex flex-col gap-4 p-4 md:p-6">
        <PageHeader
            title="Help"
            subtitle="How this app works, and what it is for."
        />

        <Card
            v-for="section in sections"
            :key="section.key"
            :title="section.title"
        >
            <p class="border-b px-4 py-2.5 text-13 text-muted-foreground">
                {{ section.blurb }}
            </p>

            <ul class="flex flex-col">
                <li
                    v-for="article in section.articles"
                    :key="article.slug"
                    class="border-b last:border-b-0"
                >
                    <Link
                        :href="`/help/${article.slug}`"
                        class="flex items-center gap-3 px-4 py-3 transition-colors duration-150 ease-out hover:bg-accent/60"
                    >
                        <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                            <span class="flex items-center gap-2">
                                <span
                                    class="truncate text-13 font-medium text-foreground"
                                    >{{ article.title }}</span
                                >
                                <!--
                                    Neutral, not warning: a feature that has
                                    not arrived is not a problem, and tinting
                                    it like one would make the manual read as
                                    a list of things that are wrong.
                                -->
                                <StatusBadge
                                    v-if="article.arrivesWith"
                                    tone="neutral"
                                    label="Coming later"
                                    dotless
                                />
                            </span>
                            <span
                                class="truncate text-[11px] text-muted-foreground"
                                >{{ article.summary }}</span
                            >
                        </span>
                        <ChevronRight
                            class="size-4 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                    </Link>
                </li>
            </ul>
        </Card>
    </div>
</template>
