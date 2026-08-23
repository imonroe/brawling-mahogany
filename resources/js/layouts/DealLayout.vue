<script setup lang="ts">
/**
 * The chrome every deal tab wears (Design System §8.4, §9.2 · #75).
 *
 * §9.2's P2 Detail: *"Content region is `flex flex-col` with **no padding** —
 * the `DealHeader` is full-bleed. The tab body below it carries its own
 * `p-6`."* So this owns the header and the scroll column; each page owns its
 * own `p-6`.
 *
 * Resolved centrally in `app.ts` rather than imported by each page, per
 * Frontend conventions §1: *"so no page picks its own chrome by accident."*
 * `Deals/Index` is deliberately not on that list — it is the list of deals,
 * not a deal, and it wears the ordinary `AppLayout`.
 *
 * ## Why the Advance button and its answer both live up here
 *
 * §8.4 puts **Advance** in the header, which means any of the eight tabs can
 * post one. `AdvanceWorkflowController` sends the person back to the tab they
 * were standing on and flashes what happened, so the alert has to be
 * somewhere all eight of them share — rendered on the Overview alone, an
 * advance refused from the People tab would have said nothing at all.
 */
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import DealHeader from '@/components/app/DealHeader.vue';
import type { DealHeaderProps } from '@/components/app/DealHeader.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { useCurrentUrl } from '@/composables/useCurrentUrl';

type AdvanceFlash = { refused: boolean; reasons: string[] };

const page = usePage();
const { currentUrl } = useCurrentUrl();

const deal = computed(
    () => (page.props as { dealHeader?: DealHeaderProps }).dealHeader ?? null,
);

/*
 * The tab segment, read off the URL rather than mapped from the page name.
 *
 * `/deals/{id}` is the overview and carries no segment; `/deals/{id}/people`
 * carries `people`. Deriving it means the tab list in `DealHeader` stays the
 * one place that knows which segments exist — a second table here would be a
 * second thing to update when S16 lands.
 */
const activeSegment = computed<string | null>(
    () => currentUrl.value.split('/')[3] ?? null,
);

/**
 * What the last advance attempt said, if it refused.
 *
 * Flash, so it survives exactly one render and never reappears on a back
 * button. **Every** reason, not the first: `AdvanceResult` carries them all
 * because being told about one gate, clearing it and being told about the next
 * is three round trips to learn what one screen could have said.
 */
const advance = computed<AdvanceFlash | null>(
    () =>
        (page.props.flash as { advance?: AdvanceFlash } | undefined)?.advance ??
        null,
);

function submitAdvance(workflowId: string, stageId: string): void {
    if (!deal.value) {
        return;
    }

    router.post(
        `/deals/${deal.value.id}/workflows/${workflowId}/advance`,
        { expected_stage_id: stageId },
        { preserveScroll: true },
    );
}
</script>

<template>
    <div class="flex min-h-full flex-col">
        <DealHeader
            v-if="deal"
            :deal="deal"
            :active="activeSegment"
            @advance="submitAdvance"
        />

        <!--
            IA §10: what happened, then what to do. A refusal names something
            somebody did on purpose — a hold, or a colleague who got there
            first — and an unmet gate names something to go and chase, so the
            two do not share a heading.
        -->
        <div v-if="advance" class="px-6 pt-6">
            <Alert variant="destructive">
                <AlertTitle>{{
                    advance.refused
                        ? 'This workflow did not move'
                        : 'Nothing advanced yet'
                }}</AlertTitle>
                <AlertDescription>
                    <ul class="flex list-disc flex-col gap-1 pl-4">
                        <li v-for="reason in advance.reasons" :key="reason">
                            {{ reason }}
                        </li>
                    </ul>
                </AlertDescription>
            </Alert>
        </div>

        <slot />
    </div>
</template>
