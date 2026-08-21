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
}
