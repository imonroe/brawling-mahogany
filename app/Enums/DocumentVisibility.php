<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\ProvidesOptions;

/**
 * Who can see an uploaded document (PRD §4.6 F6.3 · issue #98).
 *
 * **Internal by default. Client-visible is explicit.** The same rule notes
 * carry (#72) and for the same reason: the cost of the two mistakes is not
 * symmetric. A document that should have been shared and was not is a
 * conversation; a document that should not have been shared and was is not
 * recoverable, and this product holds inspection reports and disclosures about
 * somebody's house.
 *
 * So the default lives in three places that must agree — the enum's own
 * default, the column default, and the storage service — and a test holds
 * each. A default that is only in the form is a default a second caller does
 * not have.
 */
enum DocumentVisibility: string implements HasLabel
{
    use ProvidesOptions;

    case Internal = 'internal';
    case ClientVisible = 'client_visible';

    public function label(): string
    {
        return match ($this) {
            self::Internal => 'Internal',
            self::ClientVisible => 'Client-visible',
        };
    }

    /**
     * What a person is told this setting does.
     *
     * IA §9 bans jargon on the client surface, and this label is on the team's
     * side of the line — but the *consequence* is about the client, so it says
     * so plainly rather than leaving somebody to infer it from a toggle.
     */
    public function description(): string
    {
        return match ($this) {
            self::Internal => 'Only your team can see this.',
            self::ClientVisible => 'Your client can see this on their status page.',
        };
    }

    public function isClientVisible(): bool
    {
        return $this === self::ClientVisible;
    }
}
