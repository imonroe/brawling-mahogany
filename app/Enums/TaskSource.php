<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\ProvidesOptions;

/**
 * Where a task came from.
 *
 * Provenance rather than vocabulary, which is why it is not one of PRD §6.3's
 * lookups: nobody picks a source from a menu. It is recorded because Slice 5
 * has to be able to say *"the machine put this here"* on a screen. PRD §4.10
 * is firm that extraction never writes into a live record without human
 * confirmation, and a task nobody can tell apart from one Heather typed is a
 * task that has quietly done exactly that.
 *
 * IA §11 bans "Extract" being called Scan, Parse, or AI — hence `Extracted`.
 */
enum TaskSource: string implements HasLabel
{
    use ProvidesOptions;

    case Manual = 'manual';
    case Template = 'template';
    case Extracted = 'extracted';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Added by hand',
            self::Template => 'From the workflow',
            self::Extracted => 'From Extract',
        };
    }
}
