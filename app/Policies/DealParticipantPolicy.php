<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Deal;
use App\Models\DealParticipant;
use App\Models\Person;
use App\Policies\Concerns\ChecksTeamPermissions;
use App\Support\Permissions;

/**
 * Deal participants (S19, S25 · issue #60).
 *
 * The permissions are the *deal's*, not a set of their own. Who is on a deal
 * is part of what the deal is — PRD §4.2 F2.2's Read Only role must see the
 * seller and must not be able to change them — so `deals.view` reads and
 * `deals.manage` writes, and there is no third answer to invent.
 */
class DealParticipantPolicy
{
    use ChecksTeamPermissions;

    public function viewAny(Person $person, Deal $deal): bool
    {
        return $this->belongsToCurrentTeam($deal)
            && $this->allows($person, Permissions::VIEW_DEALS);
    }

    public function view(Person $person, DealParticipant $participant): bool
    {
        return $this->belongsToCurrentTeam($participant)
            && $this->allows($person, Permissions::VIEW_DEALS);
    }

    /** Adding somebody to a deal is changing the deal. */
    public function create(Person $person, Deal $deal): bool
    {
        return $this->belongsToCurrentTeam($deal)
            && $this->allows($person, Permissions::MANAGE_DEALS);
    }

    public function update(Person $person, DealParticipant $participant): bool
    {
        return $this->belongsToCurrentTeam($participant)
            && $this->allows($person, Permissions::MANAGE_DEALS);
    }

    /**
     * IA §7: **Remove** detaches, **Delete** destroys.
     *
     * This detaches. The person stays in the directory and keeps every other
     * deal they are on — taking the opposing agent off a deal that fell
     * through must never be a way to lose them from the address book.
     */
    public function remove(Person $person, DealParticipant $participant): bool
    {
        return $this->update($person, $participant);
    }
}
