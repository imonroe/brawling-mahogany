<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use RuntimeException;

/**
 * A write was attempted against a team other than the resolved one.
 *
 * Caught by the trait rather than the database, so the message can say which
 * model and which two team ids — never which record, and never any of its
 * attributes.
 */
final class CrossTenantException extends RuntimeException
{
    public static function forWrite(string $model, string $resolved, string $attempted): self
    {
        return new self(
            "[{$model}] was written with team [{$attempted}] while team [{$resolved}] is resolved.",
        );
    }
}
