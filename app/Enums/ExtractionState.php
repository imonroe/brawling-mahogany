<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\ProvidesOptions;

/**
 * Where one extraction attempt got to — PRD §6.2, §8.4, Screen Inventory S65.
 *
 * ## Why `blocked` is its own state and not a kind of failure
 *
 * #113: *"Hitting the cap stops extraction and tells the user plainly — it
 * does not silently degrade."* Folding that into `failed` would make the one
 * outcome the operator can actually fix look identical to a model outage, and
 * S65 would have to reconstruct the difference from an error string. It is a
 * refusal, not a breakage: nothing went wrong, and nothing will go right
 * until somebody raises the cap or the month turns over.
 *
 * ## Why `processing` is a state and not a spinner
 *
 * Screen Inventory names it as a key state, and it is a real duration — a
 * contract is several pages through a vision model. A person must be able to
 * leave the screen and come back to it, which means the fact has to be in the
 * database rather than in a request that is still open.
 */
enum ExtractionState: string implements HasLabel
{
    use ProvidesOptions;

    case Queued = 'queued';
    case Processing = 'processing';
    case Complete = 'complete';
    case Failed = 'failed';
    case Blocked = 'blocked';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Processing => 'Reading',
            self::Complete => 'Ready to Review',
            self::Failed => 'Failed',
            self::Blocked => 'Stopped',
        };
    }
}
