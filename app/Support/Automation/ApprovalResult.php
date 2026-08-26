<?php

declare(strict_types=1);

namespace App\Support\Automation;

use App\Models\ActionInstance;

/**
 * What a reviewer's decision produced (issue #93).
 *
 * A result object rather than an exception on refusal, for the reason
 * `AdvanceResult` records: *"this message is already Sent"* is an **ordinary
 * outcome**, not an error. Two people opening the approval queue at once is
 * the normal case — it is a shared list and both see the same top item — and
 * the second one deserves a sentence, not a 500.
 */
final readonly class ApprovalResult
{
    private function __construct(
        public bool $applied,
        public ?ActionInstance $instance = null,
        public ?string $refusal = null,
    ) {}

    /**
     * Released.
     *
     * Whether anything is now on its way out is deliberately **not** a field
     * here: `ApproveMessage::dispatch()` asks the instance, and a flag set at
     * this moment would be a second answer to a question the row already
     * answers — the shape of duplication that lets a manual prompt, which is
     * finished rather than queued, be reported as queued.
     */
    public static function approved(ActionInstance $instance): self
    {
        return new self(true, $instance);
    }

    public static function cancelled(ActionInstance $instance): self
    {
        return new self(true, $instance);
    }

    public static function refused(string $refusal): self
    {
        return new self(false, refusal: $refusal);
    }
}
