<?php

declare(strict_types=1);

namespace App\Support\Dates;

use RuntimeException;

/**
 * An anchor chain that comes back to where it started (issue #106).
 *
 * *"Cycles are impossible: reject an anchor chain that loops."* Refused at the
 * write, not survived at the read — a cycle in this graph is not a rendering
 * problem, it is a set of dates with no defined value, and the only honest
 * moment to say so is when somebody tries to create one.
 */
final class AnchorWouldLoop extends RuntimeException
{
    public static function at(string $name): self
    {
        return new self(
            'Anchoring to “'.$name.'” would make this date depend on itself, '
            .'directly or through the dates in between.',
        );
    }
}
