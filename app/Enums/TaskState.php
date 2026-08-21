<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\ProvidesOptions;

/**
 * IA §8 task state.
 *
 * `overdue` is derived from the due date, never stored — a stored copy is a
 * second source of truth that goes stale at midnight.
 */
enum TaskState: string implements HasLabel
{
    use ProvidesOptions;

    case Open = 'open';
    case Completed = 'completed';
    case Overdue = 'overdue';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Completed => 'Completed',
            self::Overdue => 'Overdue',
        };
    }

    /** The states a task row may actually hold in the database. */
    public static function stored(): array
    {
        return [self::Open, self::Completed];
    }
}
