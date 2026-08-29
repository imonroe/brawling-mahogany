<?php

declare(strict_types=1);

namespace App\Support\Dates;

use RuntimeException;

/**
 * A derived date was asked to follow something this deal does not have.
 *
 * The sibling of {@see AnchorWouldLoop}, and a different failure: that one is
 * a chain that comes back to itself, this one is a chain that goes nowhere.
 *
 * ## Why it throws rather than falling through
 *
 * `SaveKeyDate::applyAttributes()` used to drop through to the typed-date
 * branch when the anchor could not be resolved, so a *derived* payload saved
 * as a plain date — no anchor, no offset, and nothing on the screen to say the
 * request had not been honoured.
 *
 * `SaveKeyDateRequest::onThisDeal()` refuses this before it can reach here for
 * every HTTP caller, which means the only callers who can is one that has not
 * been written yet: F5.3's automation, an importer, Slice 5's extraction. That
 * is precisely when a silent fall-through costs the most, because nobody is
 * watching a screen to notice the date came out wrong.
 */
final class UnknownAnchor extends RuntimeException
{
    public static function id(string $anchorId): self
    {
        return new self(
            'No date on this deal has the id “'.$anchorId.'”, so nothing can be counted from it.',
        );
    }
}
