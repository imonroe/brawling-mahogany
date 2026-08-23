<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Deal;
use App\Models\DealProperty;
use App\Models\Person;
use App\Policies\Concerns\ChecksTeamPermissions;
use App\Support\Permissions;

/**
 * The properties a deal is about (S20 · issue #62).
 *
 * Reading is the deal's permission, like `DealParticipantPolicy`: which houses
 * a deal concerns is part of what the deal *is*, and PRD §4.2 F2.2's Read Only
 * role has to see them without being able to change them.
 *
 * Writing splits three ways, and the split is deliberate rather than tidy:
 *
 * - **Linking and removing** touch both records — a property gains a deal and
 *   a deal gains a property — so they ask for both permissions, exactly as
 *   `PropertyPolicy::link()` does from the other side. Somebody who may edit
 *   deals but not properties should not be able to attach one from here, and
 *   the mirror is the rule S36 already follows.
 * - **Promoting** changes only the deal: it renames it (IA §10). `deals.manage`.
 * - **Interest and ranking** are the deal's opinion of a house, held on the
 *   link row and touching nothing about the property itself. `deals.manage`.
 *
 * The tempting simplification is to ask for both everywhere. It would mean a
 * Team Member who may run deals but not the property directory could not say a
 * buyer had passed on a house, which is deal work in every sense.
 */
class DealPropertyPolicy
{
    use ChecksTeamPermissions;

    public function viewAny(Person $person, Deal $deal): bool
    {
        return $this->belongsToCurrentTeam($deal)
            && $this->allows($person, Permissions::VIEW_DEALS);
    }

    public function view(Person $person, DealProperty $link): bool
    {
        return $this->belongsToCurrentTeam($link)
            && $this->allows($person, Permissions::VIEW_DEALS);
    }

    /** Attaching a property is changing both records. */
    public function create(Person $person, Deal $deal): bool
    {
        return $this->belongsToCurrentTeam($deal)
            && $this->allows($person, Permissions::MANAGE_DEALS)
            && $this->allows($person, Permissions::MANAGE_PROPERTIES);
    }

    /** The buyer's opinion and the agent's ranking. Deal work. */
    public function update(Person $person, DealProperty $link): bool
    {
        return $this->belongsToCurrentTeam($link)
            && $this->allows($person, Permissions::MANAGE_DEALS);
    }

    /**
     * Promoting renames the deal, and nothing else.
     *
     * The property is untouched — it is the same house before and after — so
     * this is the deal's permission alone.
     */
    public function promote(Person $person, DealProperty $link): bool
    {
        return $this->update($person, $link);
    }

    /**
     * IA §7: **Remove** detaches, **Delete** destroys.
     *
     * This detaches, and both records feel it, so it asks for what `create()`
     * asks for. The property stays in the directory and keeps every other deal
     * it is on.
     */
    public function remove(Person $person, DealProperty $link): bool
    {
        return $this->belongsToCurrentTeam($link)
            && $this->allows($person, Permissions::MANAGE_DEALS)
            && $this->allows($person, Permissions::MANAGE_PROPERTIES);
    }
}
