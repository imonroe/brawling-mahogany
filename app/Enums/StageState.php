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

    /**
     * The states a person is standing in and has not left.
     *
     * **Blocked counts.** A refused advance marks the stage blocked, and it is
     * a display state for a stage somebody cannot leave rather than one they
     * have left — `Workflow::activeStage()` says what leaving it out cost.
     *
     * Written once, because two callers now read it: the relation query and
     * the in-memory pass over an already-loaded stage list. Two spellings of
     * "which stage is current" is how one of them ends up disagreeing.
     *
     * @return list<string>
     */
    public static function inProgress(): array
    {
        return [self::Active->value, self::Blocked->value];
    }

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
