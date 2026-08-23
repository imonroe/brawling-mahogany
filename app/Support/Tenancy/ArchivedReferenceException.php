<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use RuntimeException;

/**
 * A lookup row was used after it was archived.
 *
 * Separate from `ForeignReferenceException` because the two mean different
 * things and deserve different answers. A foreign row is somebody reaching
 * into another team and has no innocent reading; an archived one is ordinary
 * and usually means a stale form — the type was on the screen when it loaded
 * and a colleague archived it before it was submitted.
 *
 * It throws rather than refuses because there is no caller yet: the deal form
 * is #74, and when it lands it should be catching this and re-rendering with
 * the type gone from the picker. A silent fallback would have been worse than
 * a loud stop, because a deal quietly opened on the wrong type is a workflow
 * quietly instantiated from the wrong template.
 *
 * The message carries ids and never a name — a deal type's name is a team's
 * process (PRD §9 keeps team-visible text out of logs the same way it keeps
 * PII out).
 */
final class ArchivedReferenceException extends RuntimeException
{
    public static function for(string $table, string $id): self
    {
        return new self("[{$table}] row [{$id}] is archived and cannot be used by a new record.");
    }
}
