<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\ProvidesOptions;

/** IA §8 workflow state. */
enum WorkflowState: string implements HasLabel
{
    use ProvidesOptions;

    case NotStarted = 'not_started';
    case Active = 'active';
    case OnHold = 'on_hold';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::NotStarted => 'Not Started',
            self::Active => 'Active',
            self::OnHold => 'On Hold',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    /** The only state an advance may start from. */
    public function isRunning(): bool
    {
        return $this === self::Active;
    }

    /**
     * Why an advance was refused, in a sentence S23 can render.
     *
     * The state lives here rather than in `AdvanceWorkflow` because the enum
     * is the thing that gains a case: a sixth workflow state would otherwise
     * leave the service with a `match` it never noticed was incomplete, and
     * the failure mode of a missed case in an advance guard is a workflow that
     * advances when it should not.
     */
    public function advanceRefusal(): string
    {
        return match ($this) {
            self::Active => 'This workflow is running.',
            self::NotStarted => 'This workflow has not started yet, so there is no stage to advance past.',
            self::OnHold => 'This workflow is on hold. Take it off hold before advancing it.',
            self::Completed => 'This workflow is already finished.',
            self::Cancelled => 'This workflow was cancelled and cannot be advanced.',
        };
    }
}
