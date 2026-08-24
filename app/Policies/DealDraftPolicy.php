<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DealDraft;
use App\Models\Person;
use App\Policies\Concerns\ChecksTeamPermissions;
use App\Support\Permissions;

/**
 * A half-finished deal (S14 · issue #74).
 *
 * The deal's permission, because a draft becomes one: somebody who may not
 * create a deal has no business filling in the form that creates it. There is
 * no separate "drafts" capability to invent.
 *
 * **A draft is the author's, not the team's.** Every other team-scoped record
 * in this product is shared by construction — that is what `team_id` means —
 * and this is the one exception, for a reason worth stating: two agents
 * starting deals at the same time are doing two different things, and
 * resuming into a colleague's half-typed address would lose work rather than
 * share it. The wizard resolves the draft *by actor* and never by an id in a
 * URL, so this is a second line rather than the only one.
 */
class DealDraftPolicy
{
    use ChecksTeamPermissions;

    public function create(Person $person): bool
    {
        return $this->allows($person, Permissions::MANAGE_DEALS);
    }

    public function view(Person $person, DealDraft $draft): bool
    {
        return $this->belongsToCurrentTeam($draft)
            && $draft->created_by_person_id === $person->getKey()
            && $this->allows($person, Permissions::MANAGE_DEALS);
    }

    public function update(Person $person, DealDraft $draft): bool
    {
        return $this->view($person, $draft);
    }

    /** Abandoning it. Soft, so `records:purge` sweeps it (PRD §9). */
    public function delete(Person $person, DealDraft $draft): bool
    {
        return $this->view($person, $draft);
    }
}
