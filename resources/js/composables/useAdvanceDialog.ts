import { ref } from 'vue';

/**
 * Which workflow the advance dialog (S23) is open on, if any.
 *
 * ## Why this is module state rather than a prop
 *
 * Design System §8.4 puts **Advance Stage** in the deal header, which every
 * one of the eight deal tabs wears — and S15's overview puts a second one on
 * each running workflow's card. The header lives in `DealLayout`; the cards
 * live in the page `DealLayout` renders into its default slot. A persistent
 * layout cannot hand a page a callback (the page is rendered through
 * `<slot />`, not a scoped slot it controls), and a page cannot reach up.
 *
 * The alternatives were two dialog instances, which is two things to keep in
 * step, or an event bus, which is the same thing with more moving parts. One
 * ref, one dialog, mounted once in `DealLayout`.
 *
 * ## The stage id is not decoration
 *
 * It travels to the server as `expected_stage_id`, and `AdvanceWorkflow`
 * refuses when it no longer matches: the difference between "advance the stage
 * I read" and "advance whatever is current now". On a two-agent team the
 * second is how somebody skips a stage they never saw.
 */
export type AdvanceTarget = {
    dealId: string;
    workflowId: string;
    stageId: string;
};

const target = ref<AdvanceTarget | null>(null);

export function useAdvanceDialog() {
    function openAdvance(next: AdvanceTarget): void {
        target.value = next;
    }

    function closeAdvance(): void {
        target.value = null;
    }

    return { target, openAdvance, closeAdvance };
}
