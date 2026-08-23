<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\ProvidesOptions;

/** IA §8 deal state. */
enum DealState: string implements HasLabel
{
    use ProvidesOptions;

    case Active = 'active';
    case Closed = 'closed';
    case Nurture = 'nurture';
    case FellThrough = 'fell_through';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Closed => 'Closed',
            self::Nurture => 'Past Client',
            self::FellThrough => 'Fell Through',
            self::Cancelled => 'Cancelled',
        };
    }

    /** What a client sees, or null when the state is never surfaced (IA §9). */
    public function clientLabel(): ?string
    {
        return match ($this) {
            self::Active => 'In Progress',
            self::Closed => 'Complete',
            default => null,
        };
    }
}
