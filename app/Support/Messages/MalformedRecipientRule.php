<?php

declare(strict_types=1);

namespace App\Support\Messages;

use RuntimeException;

/**
 * A stored `recipient_rule` that cannot resolve to anybody.
 *
 * Thrown rather than shrugged off, because the alternative is a message that
 * resolves to an empty recipient list and is never sent — with nothing on any
 * screen admitting it. PRD §1.1's second question is *"has the client been
 * told?"*, and silence is the one answer this product must never give.
 */
final class MalformedRecipientRule extends RuntimeException
{
    public static function unknownType(string $type): self
    {
        return new self(sprintf('[%s] is not a recipient rule type.', $type));
    }

    public static function missingParticipantRole(): self
    {
        return new self('A participant-role rule has to name the role.');
    }
}
