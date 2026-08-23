<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Person;
use App\Models\TeamInvitation;
use App\Policies\Concerns\ChecksTeamPermissions;
use App\Support\Permissions;

class TeamInvitationPolicy
{
    use ChecksTeamPermissions;

    public function viewAny(Person $person): bool
    {
        return $this->allows($person, Permissions::MANAGE_TEAM_MEMBERS);
    }

    public function create(Person $person): bool
    {
        return $this->allows($person, Permissions::MANAGE_TEAM_MEMBERS);
    }

    /**
     * Issuing the accept link (ADR 0003).
     *
     * The same permission as sending the invitation, deliberately. Whoever
     * may invite an address may already revoke and re-invite it, so handing
     * them the link grants nothing they did not have — a narrower permission
     * here would be theatre.
     */
    public function issueLink(Person $person, TeamInvitation $invitation): bool
    {
        return $this->belongsToCurrentTeam($invitation)
            && $this->allows($person, Permissions::MANAGE_TEAM_MEMBERS);
    }

    public function delete(Person $person, TeamInvitation $invitation): bool
    {
        return $this->belongsToCurrentTeam($invitation)
            && $this->allows($person, Permissions::MANAGE_TEAM_MEMBERS);
    }
}
