<?php

declare(strict_types=1);

namespace App\Support\Workflow;

use App\Models\Stage;
use App\Support\Workflow\Gates\GateRegistry;

/**
 * What is stopping this stage, asked without trying to move it (S15 · #75).
 *
 * ## Why this exists at all
 *
 * PRD §4.3 F3.7 puts *"what blocks advance"* on the deal overview, and issue
 * #75 is blunt about the standard: *"If a user has to scroll or click to learn
 * what is blocking the deal, the screen has failed."*
 *
 * The only thing that could answer that was `AdvanceWorkflow::handle()`, which
 * answers it **by attempting the advance** — a blocked attempt writes
 * `stages.state = blocked` and refreshes the `gates.is_met` cache. A hub screen
 * must not mutate the record it is describing merely by being looked at, and
 * `AdvanceResult` is not reachable without that mutation.
 *
 * ## Why it is not a second mutation path
 *
 * It writes nothing, and that is a property of the code rather than an
 * intention: every evaluator in `Gates\Evaluators` is pure — none of the seven
 * contains a `save()`, an `update()`, a `forceFill()`, a `delete()` or a `DB::`
 * call — and this class only composes them. `AdvanceWorkflow` keeps its
 * monopoly on writing workflow state, `tests/Unit/SingleMutationPathTest.php`
 * is untouched by this because that test guards writes, and
 * `DealOverviewTest`'s *"changes nothing"* case pins the claim against a stage
 * an advance attempt really would mark blocked.
 *
 * `AdvanceWorkflow::evaluateGates()` is deliberately **not** reused: it caches
 * `is_met` back onto each gate, which is right on the advance path and wrong
 * here. Sharing it would mean every render of every deal overview rewriting a
 * column.
 *
 * ## The cached column is not consulted either
 *
 * `Gate::is_met` is a cache, and `Gate`'s own docblock says nothing may treat
 * it as authoritative. It is refreshed only when an advance is attempted, so a
 * screen rendering from it would show a gate somebody cleared this morning as
 * still unmet. Evaluating costs one pass over a handful of gates on a page
 * already loading a deal; being right is worth it.
 */
final readonly class DescribeBlockers
{
    public function __construct(private GateRegistry $gates) {}

    /**
     * Split this stage's gates the way an advance would, and write nothing.
     *
     * The split mirrors `AdvanceWorkflow` exactly, `Gate::blocksAdvance()`
     * included — which is `is_blocking && ! overridden`, so a gate somebody
     * has already overridden shows as an advisory rather than as a blocker.
     * Two places deciding "does this stop the advance" differently is the
     * defect this codebase keeps finding; both ask the same method.
     *
     * Gates already met are dropped. F3.7 asks the overview for *what blocks
     * advance*, not for a checklist of everything that does not — the full
     * gate list belongs to S23's advance modal.
     */
    public function forStage(Stage $stage): StageReadiness
    {
        $blocking = [];
        $advisories = [];

        foreach ($stage->gates as $gate) {
            /*
             * The evaluators that walk upward — `field_populated` reads the
             * deal, `required_tasks_complete` reads the stage's tasks — reach
             * the parent through `$gate->stage`. Handing them the stage they
             * came from keeps that a pointer rather than a query per gate.
             * Same object, so it cannot disagree with itself.
             */
            if (! $gate->relationLoaded('stage')) {
                $gate->setRelation('stage', $stage);
            }

            $verdict = $this->gates->evaluate($gate);

            if ($verdict->met) {
                continue;
            }

            $entry = ['gate' => $gate, 'verdict' => $verdict];

            if ($gate->blocksAdvance()) {
                $blocking[$gate->getKey()] = $entry;

                continue;
            }

            $advisories[$gate->getKey()] = $entry;
        }

        return new StageReadiness($blocking, $advisories);
    }
}
