<script setup lang="ts">
/**
 * S62 — the client status timeline (PRD §4.7 · IA §9 · Design System §9.6).
 *
 * ## The only screen a stranger uses unaided
 *
 * Screen Inventory's words, and everything about this page follows from them.
 * A homeowner, on a phone, four times over three months, who has never seen
 * this product and never will again. **Mobile first**, 16px base, comfortable
 * density, one column, no navigation (IA §5.4: *"adding navigation to a page a
 * client visits four times is adding a decision they did not ask to make"*).
 *
 * ## The status card is the most important element on the page
 *
 * §9.6 says so, and gives it two paragraphs. The second is the *"nothing is
 * happening"* state the Screen Inventory flags as mattering most — *"a client
 * checks in during a quiet week and needs to leave reassured rather than
 * worried"* — and §9.6 is explicit that it is **not an empty state to be
 * designed later**: it is the default copy, present in every status.
 *
 * ## The language rules are enforced on the server
 *
 * `ClientStatus` composes every string here, so this file cannot accidentally
 * render an internal stage name, a gate, a task, or an alarming word. That is
 * deliberate: a template holding the rows could reach for `stage.name` in one
 * place and forget, and the failure is silent until a seller reads *"Chase
 * lender"* on their own page.
 *
 * ## Accessibility is a requirement here, not best effort (PRD §9)
 *
 * WCAG 2.1 AA, on an audience that skews older. So: real landmarks, a real
 * heading order, the timeline as an ordered list rather than a pile of divs,
 * status conveyed in **words** as well as position, 44px minimum targets, and
 * `prefers-reduced-motion` honoured by having no motion to honour.
 */
import { Head } from '@inertiajs/vue3';
import ClientLayout from '@/layouts/ClientLayout.vue';

type Step = {
    id: string;
    label: string;
    position: 'done' | 'now' | 'next';
    when: string | null;
};

defineProps<{
    token: string;
    team: {
        name: string;
        accent: string | null;
        accentForeground: string | null;
        logo: string | null;
    };
    deal: { kind: string; addressLine1: string; addressLine2: string };
    status: { headline: string; reassurance: string };
    steps: Step[];
    dates: { id: string; name: string; date: string }[];
    contact: { name: string; phone: string | null; email: string | null };
    hasDocuments: boolean;
}>();

/**
 * The word beside each marker, for a screen reader and for anybody who cannot
 * tell the markers apart.
 *
 * Design System §11 does not allow colour or position to be the only channel,
 * and on this surface that matters more than anywhere else in the product:
 * this is the audience least likely to be looking closely.
 */
const POSITION_WORDS: Record<Step['position'], string> = {
    done: 'Finished',
    now: 'Happening now',
    next: 'Still to come',
};
</script>

<template>
    <Head :title="deal.kind" />

    <ClientLayout
        :team-name="team.name"
        :brand-color="team.accent"
        :brand-foreground="team.accentForeground"
        :logo="team.logo"
        :agent-name="contact.name"
        :agent-phone="contact.phone"
    >
        <div class="flex flex-col gap-6 p-5">
            <!-- The hero: what this is, and which one. -->
            <header>
                <p class="text-base text-muted-foreground">{{ deal.kind }}</p>
                <h1 class="mt-1 text-[30px] leading-tight font-bold">
                    {{ deal.addressLine1 || team.name }}
                </h1>
                <p
                    v-if="deal.addressLine2"
                    class="mt-1 text-base text-muted-foreground"
                >
                    {{ deal.addressLine2 }}
                </p>
            </header>

            <!--
                §9.6's status card. Two paragraphs, always: what is happening,
                and that there is nothing to do. The second is the state that
                matters most and it is present in every status rather than
                being a case somebody remembered.
            -->
            <section
                aria-labelledby="status-heading"
                class="rounded-lg border bg-card p-5"
            >
                <h2 id="status-heading" class="text-[18px] font-semibold">
                    {{ status.headline }}
                </h2>
                <p class="mt-2 text-base text-muted-foreground">
                    {{ status.reassurance }}
                    <template v-if="contact.name">
                        {{ contact.name }} will be in touch as things move.
                    </template>
                </p>
            </section>

            <!--
                The timeline, at client scale. An ordered list, because it is
                one — a screen reader reading "list, 6 items" and then each
                step with its state is the difference between a timeline and a
                pile of disconnected dates.
            -->
            <section v-if="steps.length > 0" aria-labelledby="steps-heading">
                <h2 id="steps-heading" class="text-[18px] font-semibold">
                    Where things stand
                </h2>

                <ol class="mt-3 flex flex-col">
                    <li
                        v-for="(step, index) in steps"
                        :key="step.id"
                        class="flex min-h-[76px] gap-4"
                    >
                        <!--
                            The rail. `aria-hidden` because the word beside it
                            says the same thing and better: a marker read out
                            as "img" is noise, and the state is text.
                        -->
                        <div
                            class="flex flex-col items-center"
                            aria-hidden="true"
                        >
                            <span
                                class="mt-1 block rounded-full"
                                :class="
                                    step.position === 'now'
                                        ? 'size-7 border-[3px] border-brand bg-background'
                                        : step.position === 'done'
                                          ? 'size-6 bg-brand'
                                          : 'size-6 border bg-background'
                                "
                            />
                            <span
                                v-if="index < steps.length - 1"
                                class="w-px flex-1"
                                :class="
                                    step.position === 'done'
                                        ? 'bg-brand'
                                        : 'bg-border'
                                "
                            />
                        </div>

                        <div class="flex-1 pb-6">
                            <p
                                class="text-[17px]"
                                :class="
                                    step.position === 'now'
                                        ? 'font-semibold'
                                        : 'font-normal'
                                "
                            >
                                {{ step.label }}
                            </p>
                            <p class="mt-0.5 text-[15px] text-muted-foreground">
                                {{ step.when ?? POSITION_WORDS[step.position] }}
                            </p>
                            <!--
                                The state in words, always, even when the line
                                above already carries a date. Visually hidden
                                rather than absent: §11 needs a second channel
                                and the sighted reader already has the marker.
                            -->
                            <span class="sr-only">{{
                                POSITION_WORDS[step.position]
                            }}</span>
                        </div>
                    </li>
                </ol>
            </section>

            <!-- IA §2's client label for `key_dates`: **Important Dates**. -->
            <section v-if="dates.length > 0" aria-labelledby="dates-heading">
                <h2 id="dates-heading" class="text-[18px] font-semibold">
                    Important dates
                </h2>
                <dl class="mt-3 flex flex-col gap-2">
                    <div
                        v-for="date in dates"
                        :key="date.id"
                        class="flex flex-wrap justify-between gap-2 border-b pb-2"
                    >
                        <dt class="text-base">{{ date.name }}</dt>
                        <dd class="text-base text-muted-foreground">
                            {{ date.date }}
                        </dd>
                    </div>
                </dl>
            </section>

            <!--
                F7.4. A link rather than the list, because IA §5.4 says one
                page scrolled and a documents list on a deal with fourteen of
                them would be most of the page. Absent when there are none,
                which §9.6 names as a state: "no documents" is the normal one.
            -->
            <section v-if="hasDocuments">
                <a
                    :href="`/s/${token}/documents`"
                    class="flex min-h-[52px] items-center justify-between rounded-lg border px-4 text-base font-semibold text-brand"
                >
                    Documents
                    <span aria-hidden="true">→</span>
                </a>
            </section>
        </div>
    </ClientLayout>
</template>
