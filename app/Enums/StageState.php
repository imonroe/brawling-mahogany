<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\ProvidesOptions;

/** IA §8 stage state. A stage is a period, not a moment (IA §3). */
enum StageState: string implements HasLabel
{
    use ProvidesOptions;

    case Pending = 'pending';
    case Active = 'active';
    case Blocked = 'blocked';
    case Complete = 'complete';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Upcoming',
            self::Active => 'In Progress',
            self::Blocked => 'Blocked',
            self::Complete => 'Complete',
            self::Skipped => 'Skipped',
        };
    }

    /**
     * What a client sees, or null when the stage is hidden from them.
     *
     * `blocked` is never surfaced: a client reads it as "something has gone
     * wrong", when it usually means a checkbox is unticked (IA §8).
     */
    public function clientLabel(): ?string
    {
        return match ($this) {
            self::Pending => 'Coming Up',
            self::Active, self::Blocked => 'In Progress',
            self::Complete => 'Done',
            self::Skipped => null,
        };
    }
}
