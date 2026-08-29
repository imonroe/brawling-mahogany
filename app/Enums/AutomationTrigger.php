<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\ProvidesOptions;

/**
 * What makes an automation fire (PRD §4.5 F5.2 · issue #91).
 *
 * The definition-layer half: an automation hangs off a `stage_template`, and
 * this says when the runtime instance it produces should run.
 *
 * ## Not every trigger is offerable, and the gap is the point
 *
 * Two of these need data that does not exist yet. *N days before a key date*
 * needs `key_dates`, which is Slice 4 (#109); *N days after closing* needs the
 * nurture layer, which is Slice 6. A picker offering either would let somebody
 * build an automation that can never fire — and, worse, believe they had set a
 * deadline reminder.
 *
 * So they are cases (a pack or a later slice may carry one, and the editor
 * renders its name rather than its key) and they are not
 * {@see self::selectableOptions()}. Same split, and the same argument, as
 * {@see \App\Support\Workflow\Gates\GateRegistry::selectableOptions()}.
 */
enum AutomationTrigger: string implements HasLabel
{
    use ProvidesOptions;

    case StageStart = 'stage_start';
    case StageCompletion = 'stage_completion';
    case WorkflowStart = 'workflow_start';
    case WorkflowCompletion = 'workflow_completion';
    case GateCleared = 'gate_cleared';
    case KeyDateOffset = 'key_date_offset';
    case PostClosingOffset = 'post_closing_offset';

    public function label(): string
    {
        return match ($this) {
            self::StageStart => 'When the stage starts',
            self::StageCompletion => 'When the stage completes',
            self::WorkflowStart => 'When the workflow starts',
            self::WorkflowCompletion => 'When the workflow completes',
            self::GateCleared => 'When a requirement clears',
            self::KeyDateOffset => 'A number of days from a key date',
            self::PostClosingOffset => 'A number of days after closing',
        };
    }

    /**
     * Whether anything in the product can raise this trigger today.
     *
     * The issue each unavailable one waits on, so the editor can say which
     * rather than leaving a name greyed out with no explanation.
     */
    public function availableFrom(): ?string
    {
        return match ($this) {
            self::PostClosingOffset => 'Keep in Touch arrives in Slice 6.',
            default => null,
        };
    }

    public function isAvailable(): bool
    {
        return $this->availableFrom() === null;
    }

    /**
     * Whether the trigger names a specific gate on the stage.
     *
     * The only one that carries a second choice today, and the editor narrows
     * to the gates that stage template actually has — a trigger naming a gate
     * that is not there is an automation that never fires.
     */
    public function needsGate(): bool
    {
        return $this === self::GateCleared;
    }

    /**
     * Whether the trigger names a key date and an offset from it (#106).
     *
     * The second trigger to carry a further choice, and it carries **two**: a
     * date and a signed number of days. Named rather than pointed at, because
     * an automation lives on a *template* and a template has never met the
     * deal it will run on — there is no `key_dates` row for it to reference,
     * and PRD §8.1 keeps the definition layer out of the runtime layer.
     *
     * So the two sides meet on the word a team uses. `SnapshotAutomation::
     * namesKeyDate()` folds case and whitespace, because the template author
     * and whoever set the deal up typed it months apart.
     */
    public function needsKeyDate(): bool
    {
        return $this === self::KeyDateOffset;
    }

    /**
     * @return array<string, string>
     */
    public static function selectableOptions(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            if ($case->isAvailable()) {
                $options[$case->value] = $case->label();
            }
        }

        return $options;
    }
}
