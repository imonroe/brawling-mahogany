<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use RuntimeException;

/**
 * A team-scoped query ran with no team resolved.
 *
 * ADR 0002: *"It throws. Loudly... The alternative — returning an empty result
 * — is worse in exactly the way that matters: a silent empty list looks like
 * 'no deals yet' to the person reading it and like a working feature to the
 * developer who wrote it."*
 *
 * The message names the model and the context and never the data, so this is
 * safe to let reach Sentry (PRD §9: no PII in logs, ever).
 */
final class MissingTeamContextException extends RuntimeException
{
    public static function for(string $context): self
    {
        return new self(
            "No team is resolved, so [{$context}] cannot be scoped. ".
            'A request resolves one through middleware; a job carries one in its payload; '.
            'a scheduled command iterates teams explicitly.',
        );
    }
}
