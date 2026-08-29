<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\ProvidesOptions;

/**
 * Where a key date's value came from (PRD §4.8 F8.2, §4.10 · issue #106).
 *
 * Provenance rather than vocabulary — nobody picks this from a menu, the same
 * argument `TaskSource` makes. It is recorded because Slice 5 has to be able
 * to say *"the machine put this here"* on a screen, and PRD §4.10 is firm that
 * extraction never writes into a live record without human confirmation.
 *
 * ## Why this is not on its own enough to say "unconfirmed"
 *
 * `Extracted` stays true after somebody agrees to the date, which is correct —
 * the provenance does not stop being the provenance. What changes is
 * `confirmed_at`. So the *extracted-pending* state S18 draws is the pair, and
 * `KeyDate::isPending()` is the one place the pair is read: a rule spelled out
 * at each caller is a rule the next caller is written without.
 */
enum KeyDateSource: string implements HasLabel
{
    use ProvidesOptions;

    case Manual = 'manual';

    /**
     * Read off a contract by Slice 5's extraction (F10.2).
     *
     * IA §11 bans calling it Scan, Parse, or AI — the product word is
     * **Extract**, and this is the noun form of it.
     */
    case Extracted = 'extracted';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Added by hand',
            self::Extracted => 'From Extract',
        };
    }
}
