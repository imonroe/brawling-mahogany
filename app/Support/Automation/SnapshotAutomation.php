<?php

declare(strict_types=1);

namespace App\Support\Automation;

use App\Enums\AutomationActionType;
use App\Enums\AutomationTrigger;

/**
 * One automation as the running workflow remembers it (issue #92).
 *
 * A typed reading of a JSONB entry, so the four places that act on one are not
 * four slightly different readings of an array of unknown shape — the same
 * argument `RecipientRule` makes for the same reason.
 */
final readonly class SnapshotAutomation
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        public ?string $actionDefinitionId,
        public AutomationTrigger $trigger,
        public AutomationActionType $actionType,
        public ?string $messageTemplateId,
        public array $config,
        /**
         * The `sort_order` of the gate a `gate_cleared` automation waits on.
         *
         * Null for every other trigger, and null for a `gate_cleared` whose
         * gate has since been deleted from the template — see
         * `InstantiateWorkflow::gateSortOrder()`. A null here means the
         * automation never fires, which is deliberately the safe direction.
         */
        public ?int $gateSortOrder,
        public bool $requiresApproval,
        public bool $isManual,
    ) {}

    /** Whether this is the automation waiting on the gate at `$sortOrder`. */
    public function waitsOnGate(int $sortOrder): bool
    {
        return $this->gateSortOrder === $sortOrder;
    }

    /**
     * Whether this automation is fully specified enough to raise at all.
     *
     * An automation that sends words and has none is the state S44 draws as
     * *"needs a template"* — it happens when a template is hard-deleted, which
     * nulls the pointer rather than cascading. Raising an instance from one
     * would put an empty message in the approval queue.
     */
    public function isComplete(): bool
    {
        return ! $this->actionType->needsMessageTemplate() || $this->messageTemplateId !== null;
    }
}
