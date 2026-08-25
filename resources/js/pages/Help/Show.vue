<script setup lang="ts">
/**
 * S92 — one page of the manual (issue #170).
 *
 * ## The prose styles are written from tokens rather than pulled in
 *
 * `@tailwindcss/typography` would style this in one class and would bring its
 * own greys with it, which Design System §13.2 rule 5 forbids — *"no raw
 * colours in components. If a colour is needed and no token expresses it, the
 * answer is a new token."* So the rules below are hand-written against the
 * same tokens every other screen uses, which is why the manual looks like the
 * app rather than like a README.
 *
 * `:deep()` because the content arrives through `v-html` and scoped styles do
 * not otherwise reach it.
 *
 * ## `v-html` is safe here, and is configured as though it were not
 *
 * The content is Markdown files in this repository, not customer input.
 * `HelpLibrary` still renders with `html_input: strip` and
 * `allow_unsafe_links: false`, because the people editing those files are
 * thinking about prose rather than about CommonMark's HTML passthrough, and
 * this is the one place in the app where a stray tag would execute.
 */
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, ArrowRight, ChevronLeft } from '@lucide/vue';
import { computed } from 'vue';
import Card from '@/components/app/Card.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';

/** A neighbour article, as the index renders it. Not the `Card` component. */
type ArticleCard = {
    slug: string;
    title: string;
    summary: string;
    arrivesWith: string | null;
};

const props = defineProps<{
    article: {
        slug: string;
        title: string;
        summary: string;
        section: string;
        arrivesWith: string | null;
        html: string;
        headings: { level: number; text: string; id: string }[];
    };
    previous: ArticleCard | null;
    next: ArticleCard | null;
}>();

/*
 * A contents list earns its place on a long page and is clutter on a short
 * one. Three headings is the threshold: below that the page fits on a screen
 * and the list is a second copy of what the reader can already see.
 */
const contents = computed(() =>
    props.article.headings.filter((heading) => heading.level === 2),
);

const showContents = computed(() => contents.value.length >= 3);
</script>

<template>
    <Head :title="article.title" />

    <div class="flex flex-col gap-4 p-4 md:p-6">
        <Link
            href="/help"
            class="flex w-fit items-center gap-1 text-13 text-muted-foreground transition-colors duration-150 ease-out hover:text-foreground"
        >
            <ChevronLeft class="size-4" aria-hidden="true" />
            All help
        </Link>

        <PageHeader :title="article.title" :subtitle="article.summary">
            <template v-if="article.arrivesWith" #actions>
                <StatusBadge tone="neutral" label="Coming later" dotless />
            </template>
        </PageHeader>

        <!--
            Said once, at the top, rather than repeated through the page. A
            reader who has seen this knows the whole article describes
            something they cannot do yet.
        -->
        <p
            v-if="article.arrivesWith"
            class="rounded-md border bg-muted px-4 py-2.5 text-xs text-muted-foreground"
        >
            This describes something that is not built yet. It is here so you
            know what is coming and can plan around it — nothing on this page is
            available in the app today.
        </p>

        <div class="flex flex-col gap-4 lg:flex-row lg:items-start">
            <!--
                The contents come **first in the DOM** and are moved to the
                right visually, rather than the other way round. Reading order
                is what a keyboard and a screen reader follow, and a contents
                list reached only after the article it indexes has already been
                read is not a contents list. It also sticks on a wide screen,
                because the longest article scrolls it away exactly when it
                starts being useful.
            -->
            <Card
                v-if="showContents"
                title="On this page"
                class="w-full shrink-0 lg:sticky lg:top-4 lg:order-2 lg:max-h-[calc(100vh-8rem)] lg:w-56 lg:overflow-y-auto"
            >
                <ul class="flex flex-col px-4 py-3">
                    <li v-for="heading in contents" :key="heading.id">
                        <a
                            :href="`#${heading.id}`"
                            class="block py-1 text-13 text-muted-foreground transition-colors duration-150 ease-out hover:text-foreground"
                            >{{ heading.text }}</a
                        >
                    </li>
                </ul>
            </Card>

            <Card
                class="min-w-0 flex-1 lg:order-1"
                body-class="px-5 py-4 md:px-6 md:py-5"
            >
                <article class="help-prose" v-html="article.html" />
            </Card>
        </div>

        <nav
            v-if="previous || next"
            class="flex flex-col gap-2 sm:flex-row"
            aria-label="More help"
        >
            <Link
                v-if="previous"
                :href="`/help/${previous.slug}`"
                class="flex flex-1 items-center gap-2 rounded-lg border px-4 py-3 transition-colors duration-150 ease-out hover:bg-accent/60"
            >
                <ArrowLeft
                    class="size-4 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
                <span class="flex min-w-0 flex-col">
                    <span class="text-[11px] text-muted-foreground"
                        >Previous</span
                    >
                    <span class="truncate text-13 font-medium">{{
                        previous.title
                    }}</span>
                </span>
            </Link>

            <Link
                v-if="next"
                :href="`/help/${next.slug}`"
                class="flex flex-1 items-center justify-end gap-2 rounded-lg border px-4 py-3 text-right transition-colors duration-150 ease-out hover:bg-accent/60"
            >
                <span class="flex min-w-0 flex-col">
                    <span class="text-[11px] text-muted-foreground">Next</span>
                    <span class="truncate text-13 font-medium">{{
                        next.title
                    }}</span>
                </span>
                <ArrowRight
                    class="size-4 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
            </Link>
        </nav>
    </div>
</template>

<style scoped>
/*
 * Every value is a design token. The one thing worth explaining is the
 * measure: `max-w-[68ch]` on the body keeps a line at a readable length on a
 * wide monitor, which is the single biggest difference between documentation
 * that gets read and documentation that gets skimmed.
 */
.help-prose {
    max-width: 68ch;
    font-size: 0.875rem;
    line-height: 1.65;
    color: var(--color-foreground);
}

.help-prose :deep(h2) {
    margin-top: 1.75rem;
    margin-bottom: 0.5rem;
    font-size: 1rem;
    font-weight: 600;
    /* Scroll clear of the sticky top bar when a contents link lands here. */
    scroll-margin-top: 5rem;
}

.help-prose :deep(h3) {
    margin-top: 1.25rem;
    margin-bottom: 0.375rem;
    font-size: 0.8125rem;
    font-weight: 600;
    scroll-margin-top: 5rem;
}

.help-prose :deep(> :first-child) {
    margin-top: 0;
}

.help-prose :deep(p),
.help-prose :deep(ul),
.help-prose :deep(ol) {
    margin-bottom: 0.75rem;
}

.help-prose :deep(ul),
.help-prose :deep(ol) {
    padding-left: 1.125rem;
}

.help-prose :deep(ul) {
    list-style: disc;
}

.help-prose :deep(ol) {
    list-style: decimal;
}

.help-prose :deep(li) {
    margin-bottom: 0.25rem;
}

.help-prose :deep(a) {
    color: var(--color-primary);
    text-decoration: underline;
    text-underline-offset: 2px;
}

.help-prose :deep(strong) {
    font-weight: 600;
}

.help-prose :deep(code) {
    border-radius: 0.25rem;
    background-color: var(--color-muted);
    padding: 0.0625rem 0.25rem;
    font-size: 0.8125rem;
}

.help-prose :deep(blockquote) {
    margin-bottom: 0.75rem;
    border-left: 2px solid var(--color-state-warning);
    background-color: var(--color-state-warning-bg);
    padding: 0.625rem 0.875rem;
    color: var(--color-foreground);
}

.help-prose :deep(blockquote p:last-child) {
    margin-bottom: 0;
}

/*
 * Tables scroll inside themselves rather than widening the page — several
 * articles carry a permission or status table that is wider than a phone.
 */
.help-prose :deep(table) {
    display: block;
    margin-bottom: 1rem;
    width: 100%;
    overflow-x: auto;
    border-collapse: collapse;
    font-size: 0.8125rem;
}

.help-prose :deep(th) {
    border-bottom: 1px solid var(--color-border);
    padding: 0.375rem 0.75rem 0.375rem 0;
    text-align: left;
    font-weight: 600;
    white-space: nowrap;
}

.help-prose :deep(td) {
    border-bottom: 1px solid var(--color-border);
    padding: 0.375rem 0.75rem 0.375rem 0;
    vertical-align: top;
}

.help-prose :deep(tr:last-child td) {
    border-bottom: 0;
}

.help-prose :deep(hr) {
    margin: 1.5rem 0;
    border-color: var(--color-border);
}
</style>
