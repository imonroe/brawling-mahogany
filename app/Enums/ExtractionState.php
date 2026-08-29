<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\ProvidesOptions;

/**
 * Where one extraction attempt got to — PRD §6.2, §8.4, Screen Inventory S65.
 *
 * ## The labels are IA's words, not this file's
 *
 * `Extracting` rather than "Reading", and review caught the difference: IA §11
 * bans **Read** alongside Scan, Parse, Analyze and AI, because the feature has
 * one name and *Extract* is it. "Reading" read naturally and was wrong for
 * exactly the reason the ban exists — a second word for one thing is how a
 * screen and a help article stop describing the same feature. Prose may still
 * say a document is read; a **label** may not.
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
            self::Processing => 'Extracting',
            self::Complete => 'Ready to Review',
            self::Failed => 'Failed',
            self::Blocked => 'Stopped',
        };
    }

    /**
     * Nothing more will happen to this row on its own.
     *
     * One reader, and it is the one that matters: `RunDocumentExtraction::failed()`
     * stands down rather than overwriting an outcome a later attempt already
     * recorded. "Not mine" and "broken" are different refusals — the rule
     * `SendDecision::standDown()` records one subsystem over.
     */
    public function isFinal(): bool
    {
        return $this === self::Complete || $this === self::Failed || $this === self::Blocked;
    }
}
