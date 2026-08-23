<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The defaults every business model inherits.
 *
 * See docs/adr/0001-data-and-persistence-conventions.md. Each of these is
 * cheap to set now and expensive to retrofit across forty tables:
 *
 *  - **ULID keys.** Anything client-facing carries a ULID, never a sequential
 *    integer (PRD §7.14, IA §6). A sequential id in a URL leaks how many deals
 *    a team has, and how many the product has.
 *  - **Soft deletes.** PRD §9 gives a 30-day recovery window before a hard
 *    delete. A model without SoftDeletes cannot honour that.
 *  - **Timestamps**, which the migration convention creates.
 *
 * Tenancy is deliberately not here. `team_id` and its global scope arrive with
 * the BelongsToTeam trait in Slice 1 (docs/adr/0002), because the scope needs
 * a resolved team to be worth anything.
 */
trait HasProductDefaults
{
    use HasUlids;
    use SoftDeletes;
}
