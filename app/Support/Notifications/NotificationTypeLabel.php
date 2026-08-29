<?php

declare(strict_types=1);

namespace App\Support\Notifications;

use App\Enums\NotificationType;

/**
 * The plural for a folded line, resolved from a stored value.
 *
 * A hair's breadth of indirection, and it earns it: the feed holds the type as
 * a string because that is what the screen is given, and calling
 * `NotificationType::from()` inline would throw on a row written by a version
 * of the product that knew a case this one does not. A panel is not the place
 * to fail closed on an unrecognised row — it is the place to render what the
 * row already says.
 */
final class NotificationTypeLabel
{
    public static function grouped(string $type, int $count): string
    {
        return NotificationType::tryFrom($type)?->grouped($count)
            ?? $count.' notifications';
    }
}
