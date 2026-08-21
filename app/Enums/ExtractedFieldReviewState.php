<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\ProvidesOptions;

/**
 * IA §8 extracted field review state.
 *
 * PRD §6.2: nothing reaches `key_dates` or `tasks` except through a row here
 * that a human confirmed. Confidence is a separate vocabulary and is not a
 * state (Design System §2.5).
 */
enum ExtractedFieldReviewState: string implements HasLabel
{
    use ProvidesOptions;

    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Edited = 'edited';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Needs Review',
            self::Confirmed => 'Confirmed',
            self::Edited => 'Edited',
            self::Rejected => 'Rejected',
        };
    }
}
