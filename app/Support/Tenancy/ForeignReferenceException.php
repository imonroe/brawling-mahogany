<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use RuntimeException;

/**
 * A team-scoped row pointed at a lookup or template belonging to another team.
 *
 * The gap the composite keys cannot cover. ADR 0002's second layer makes a
 * cross-tenant pointer *unrepresentable* wherever both tables carry `team_id`
 * — but `deal_types` and `workflow_templates` are shared tables where a null
 * team means "everybody's", so a composite key from a NOT NULL `team_id` can
 * never match the shared row and cannot be used. This is what stands in.
 *
 * Thrown rather than scoped away. A query that silently returns nothing is
 * ADR 0002's stated worst case — *"a silent empty list looks like 'no deals
 * yet' to the person reading it and like a working feature to the developer
 * who wrote it"* — and here the caller has named a specific row, so there is
 * no ambiguity about intent to report.
 *
 * The message carries ids and the table, never a name: a template name is a
 * team's process and a deal type name can be their wording.
 */
final class ForeignReferenceException extends RuntimeException
{
    public static function for(string $table, string $id, ?string $resolvedTeam): self
    {
        return new self(
            "[{$table}] row [{$id}] belongs to another team and cannot be used by team "
            .'['.($resolvedTeam ?? 'none').'].',
        );
    }
}
