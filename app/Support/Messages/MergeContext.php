<?php

declare(strict_types=1);

namespace App\Support\Messages;

use App\Models\Deal;
use App\Models\Stage;
use App\Models\Team;
use App\Models\Workflow;

/**
 * The facts a message is rendered against (F5.6).
 *
 * One object rather than three arguments threaded through the renderer, the
 * preview and the send path — and a **real deal**, always. Issue #90 is
 * emphatic about the preview: *"Live preview renders against real merge data
 * from a chosen deal, not lorem ipsum. The whole point is seeing what the
 * client will actually receive."* A context that could be faked would make
 * that promise unenforceable.
 */
final readonly class MergeContext
{
    private function __construct(
        public Deal $deal,
        public Team $team,
        public ?Stage $stage,
    ) {}

    public static function for(Deal $deal, Team $team, ?Stage $stage = null): self
    {
        return new self($deal, $team, $stage);
    }

    /**
     * The same context a send would build: this deal, at the stage it is
     * actually on.
     *
     * S46's preview and #92's send path both go through this, so what an
     * author approves and what a client receives are rendered from the same
     * facts. Two ways of choosing the stage would make the preview a
     * plausible guess rather than a rehearsal.
     *
     * A deal with two running workflows (PRD §7.5 allows it) has two active
     * stages and no single answer, so the **first** is used and the caller is
     * not asked to care: a preview is about the words, and `{{ stage }}` on a
     * deal mid-way through two processes is ambiguous however it is chosen.
     * The send path names its own stage explicitly and never lands here.
     */
    public static function forCurrentStageOf(Deal $deal, Team $team): self
    {
        $deal->loadMissing('workflows.stages');

        $stage = $deal->workflows
            ->filter(fn (Workflow $workflow): bool => $workflow->isRunning())
            ->map(fn (Workflow $workflow): ?Stage => $workflow->activeStage())
            ->filter()
            ->first();

        return new self($deal, $team, $stage instanceof Stage ? $stage : null);
    }
}
