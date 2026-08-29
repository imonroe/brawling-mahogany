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
 * ## Why the Advance dialog and its answer both live up here
 *
 * §8.4 puts **Advance** in the header, which means any of the eight tabs can
 * start one. `AdvanceWorkflowController` sends the person back to the tab they
 * were standing on and flashes what happened, so the alert has to be
 * somewhere all eight of them share — rendered on the Overview alone, an
 * advance refused from the People tab would have said nothing at all.
 *
 * The same argument puts S23's dialog (#77) here and mounts it **once**. The
 * Overview's per-workflow cards open the same instance through
 * `useAdvanceDialog()`, because a persistent layout cannot hand a callback to
 * the page it renders into its slot, and two instances would be two things to
 * keep in step.
 */
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AdvanceStageDialog from '@/components/app/AdvanceStageDialog.vue';
import DealHeader from '@/components/app/DealHeader.vue';
import type { DealHeaderProps } from '@/components/app/DealHeader.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { useAdvanceDialog } from '@/composables/useAdvanceDialog';
import { useCurrentUrl } from '@/composables/useCurrentUrl';

type AdvanceFlash = { refused: boolean; reasons: string[] };

const page = usePage();
const { currentUrl } = useCurrentUrl();
const { target, openAdvance, closeAdvance } = useAdvanceDialog();

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
 *
 * ## One segment is deliberately not its own tab
 *
 * `/deals/{id}/extractions/{id}` — S66 and S67 (#116, #117) — wears the deal
 * chrome and is not a peer of the eight tabs. It is reached *from* a document
 * and goes back to one, so it borrows the Documents tab rather than
 * highlighting nothing: a screen with the tab bar visible and no tab active
 * reads as somewhere you have fallen out of the deal.
 *
 * Mapped here rather than added to `DealHeader`'s list because a tab in that
 * list is a thing with a URL somebody can press, and there is no extraction to
 * press towards until a document has been read.
 */
const activeSegment = computed<string | null>(() => {
    const segment = currentUrl.value.split('/')[3] ?? null;

    return segment === 'extractions' ? 'documents' : segment;
});

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

/**
 * The header's button opens S23; it no longer posts.
 *
 * Design System §7.4 is explicit that the advance action never ships without
 * the "What happens when you advance" block, and #77's own standard is that
 * the refusal has to be explained where the decision is made. A header button
 * that posted straight through was the interim shape (#75), and it could only
 * ever report a refusal after the fact.
 */
function startAdvance(workflowId: string, stageId: string): void {
    if (!deal.value) {
        return;
    }

    openAdvance({ dealId: deal.value.id, workflowId, stageId });
}
</script>

<template>
    <div class="flex min-h-full flex-col">
        <DealHeader
            v-if="deal"
            :deal="deal"
            :active="activeSegment"
            @advance="startAdvance"
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

        <!--
            S23, mounted once for every deal tab and for the Overview's
            per-workflow cards alike. `useAdvanceDialog()` is what they all
            reach for; nothing else on a deal screen posts an advance.
        -->
        <AdvanceStageDialog :target="target" @close="closeAdvance" />
    </div>
</template>
