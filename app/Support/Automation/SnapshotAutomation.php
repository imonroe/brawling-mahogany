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
     * Whether this automation hangs off a key date called `$name` (#106).
     *
     * Compared case- and whitespace-insensitively, because the two sides were
     * typed by people months apart: the template author wrote *"Inspection
     * Objection"* and whoever set the deal up wrote *"inspection objection "*.
     * Matching on the exact bytes would make the trigger fire on some deals
     * and not others for a reason nobody could see.
     *
     * `mb_strtolower` rather than `strtolower`, since a team is free to name a
     * date in any language they work in and byte-wise lowering mangles the
     * ones that are not ASCII.
     */
    public function namesKeyDate(string $name): bool
    {
        $wanted = $this->keyDateName();

        return $wanted !== null && $wanted === self::fold($name);
    }

    /** The key date this automation names, folded, or null if it names none. */
    public function keyDateName(): ?string
    {
        $value = $this->config['keyDateName'] ?? null;

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return self::fold($value);
    }

    /**
     * How many days from that date it fires — signed, so *"three days before
     * closing"* is `-3`.
     *
     * Zero rather than null when it is missing, which reads as *"on the day"*.
     * The alternative would be refusing to fire at all, and an automation
     * whose only defect is an absent number is one somebody meant to run.
     */
    public function keyDateOffsetDays(): int
    {
        $value = $this->config['offsetDays'] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    private static function fold(string $value): string
    {
        return mb_strtolower(trim($value));
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
