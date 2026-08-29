<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Deal;
use App\Models\KeyDate;
use App\Models\Person;
use App\Policies\Concerns\ChecksTeamPermissions;
use App\Support\Permissions;

/**
 * Dates & Deadlines (PRD §4.8 F8.2 · S18, S59 · issue #106, #107).
 *
 * The deal's permissions, for the reason `TaskPolicy` gives: a key date is a
 * fact about a deal, so seeing it is `deals.view` and moving it is
 * `deals.manage`. There is no `key_dates.*` pair, because a key nobody's role
 * holds is a key that only makes the catalogue longer.
 *
 * Moving one is deliberately not a heavier permission than editing a task,
 * even though the consequences are larger. PRD §4.2's roles do not draw a line
 * there — the person who runs the transaction is the person who reads the
 * contract — and a permission no role distinguishes would mean the *only* way
 * to move a closing date was to be an owner, which is the shape that gets
 * worked around by giving everybody owner.
 *
 * ## `viewAny` and `create` take the deal
 *
 * Both are asked *about a deal*, and the deal is what carries the team. S59's
 * cross-deal list asks `viewAny` against `Deal::class` with no instance, which
 * is why the parameter is nullable rather than a second method.
 */
class KeyDatePolicy
{
    use ChecksTeamPermissions;

    public function viewAny(Person $person, ?Deal $deal = null): bool
    {
        if ($deal instanceof Deal && ! $this->belongsToCurrentTeam($deal)) {
            return false;
        }

        return $this->allows($person, Permissions::VIEW_DEALS);
    }

    public function view(Person $person, KeyDate $keyDate): bool
    {
        return $this->belongsToCurrentTeam($keyDate)
            && $this->allows($person, Permissions::VIEW_DEALS);
    }

    public function create(Person $person, Deal $deal): bool
    {
        return $this->belongsToCurrentTeam($deal)
            && $this->allows($person, Permissions::MANAGE_DEALS);
    }

    public function update(Person $person, KeyDate $keyDate): bool
    {
        return $this->belongsToCurrentTeam($keyDate)
            && $this->allows($person, Permissions::MANAGE_DEALS);
    }

    public function delete(Person $person, KeyDate $keyDate): bool
    {
        return $this->update($person, $keyDate);
    }
}
